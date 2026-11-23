<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\UserGroups;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Helper\TemplateEmails;
use DateTimeZone;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Crawler IP lists: download, cache and query the IP ranges published by search engines and AI crawlers.
 *
 * The overriding concern is that this must never slow a site down or take it offline. See the design document
 * (docs/superpowers/specs/2026-11-03-crawler-ip-lists-design.md) for the rationale of everything below. In short:
 *
 * - The current copy of each list lives in Joomla's cache. The last good copy lives in its own #__admintools_storage
 *   row (Joomla's cache garbage collection would delete it).
 * - A download is attempted lazily, on a cache miss, and only if (a) the bot was not tried in the last 15 minutes,
 *   (b) this request has not already downloaded something and (c) the site-wide database lock could be taken. The
 *   download has a time limit.
 * - If the cache turns out not to store anything, or the downloads keep failing, the feature turns itself off and
 *   emails the Super Users.
 *
 * Everything which touches Joomla (database, cache, HTTP, WAF configuration, component options) is injected, or lives
 * in {@see self::getInstance()}, so the logic can be unit tested without a Joomla runtime. Whether the site is Pro is
 * injected too: ADMINTOOLS_PRO is a process-wide constant.
 *
 * @since   7.9.4
 */
class CrawlerIPLists
{
	/**
	 * A bot is not downloaded again within this many seconds of the last attempt.
	 *
	 * @since 7.9.4
	 */
	public const THROTTLE_SECONDS = 900;

	/**
	 * Either health counter going above this value turns the feature off.
	 *
	 * @since 7.9.4
	 */
	public const AUTO_DISABLE_THRESHOLD = 6;

	/**
	 * Seconds added to the download time limit to get the lifetime of the site-wide lock.
	 *
	 * @since 7.9.4
	 */
	private const LOCK_MARGIN = 10;

	/**
	 * The download time limit for HTML pages (Amazon) is this many times the configured limit.
	 *
	 * Amazon publishes its lists only as JSON embedded in 130–150 KB HTML pages, which routinely take 0.9–2 seconds to
	 * download; the JSON lists take well under one second. Operator's decision: give the HTML pages a longer limit.
	 *
	 * @since 7.9.4
	 */
	public const HTML_TIMEOUT_FACTOR = 3;

	/**
	 * The absolute maximum download time limit, in milliseconds (the Option's maximum).
	 *
	 * @since 7.9.4
	 */
	public const MAX_TIMEOUT_MS = 60000;

	/**
	 * The per-request instance.
	 *
	 * @var   self|null
	 * @since 7.9.4
	 */
	private static $instance = null;

	/**
	 * Bookkeeping in the database.
	 *
	 * @var   StateStore
	 * @since 7.9.4
	 */
	protected $state;

	/**
	 * Downloader.
	 *
	 * @var   FetcherInterface
	 * @since 7.9.4
	 */
	protected $fetcher;

	/**
	 * The cache for the current copy of each list.
	 *
	 * @var   CacheAdapterInterface
	 * @since 7.9.4
	 */
	protected $current;

	/**
	 * Reads a value from the WAF configuration: fn(string $key, $default).
	 *
	 * @var   callable
	 * @since 7.9.4
	 */
	protected $wafValue;

	/**
	 * Clamped options: lifetime (minutes), discardDays, timeoutMs.
	 *
	 * @var   array
	 * @since 7.9.4
	 */
	protected $options;

	/**
	 * Returns the current Unix time.
	 *
	 * @var   callable
	 * @since 7.9.4
	 */
	protected $clock;

	/**
	 * Is this the Professional edition?
	 *
	 * @var   bool
	 * @since 7.9.4
	 */
	protected $isPro;

	/**
	 * Memoised range sets, by bot. A bot without usable data is memoised as NULL.
	 *
	 * @var   array<string, IpRangeSet|null>
	 * @since 7.9.4
	 */
	private $rangeSets = [];

	/**
	 * Has this request already downloaded something?
	 *
	 * @var   bool
	 * @since 7.9.4
	 */
	private $downloadedThisRequest = false;

	/**
	 * Did the feature turn itself off during this request?
	 *
	 * @var   bool
	 * @since 7.9.4
	 */
	private $disabledThisRequest = false;

	/**
	 * Constructor.
	 *
	 * @param   StateStore             $state     Database bookkeeping.
	 * @param   FetcherInterface       $fetcher   Downloader.
	 * @param   CacheAdapterInterface  $current   Cache for the current copies.
	 * @param   callable               $wafValue  fn(string $key, $default): reads the WAF configuration.
	 * @param   array                  $options   lifetime (minutes), discardDays, timeoutMs; already clamped.
	 * @param   callable|null          $clock     Returns the current Unix time. Defaults to time().
	 * @param   bool|null              $isPro     Pro edition? Defaults to the ADMINTOOLS_PRO constant.
	 *
	 * @since   7.9.4
	 */
	public function __construct(
		StateStore $state, FetcherInterface $fetcher, CacheAdapterInterface $current, callable $wafValue,
		array $options = [], ?callable $clock = null, ?bool $isPro = null
	)
	{
		$this->state    = $state;
		$this->fetcher  = $fetcher;
		$this->current  = $current;
		$this->wafValue = $wafValue;
		$this->options  = array_merge(['lifetime' => 60, 'discardDays' => 7, 'timeoutMs' => 1000], $options);
		$this->clock    = $clock ?? 'time';
		$this->isPro    = $isPro ?? (defined('ADMINTOOLS_PRO') && ADMINTOOLS_PRO);
	}

	/**
	 * Get the real, per-request instance.
	 *
	 * @return  self
	 * @since   7.9.4
	 */
	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			$params  = ComponentHelper::getParams('com_admintools');
			$options = [
				'lifetime'    => self::clampOption($params->get('crawlerips_cache_lifetime', 60), 15, 10080, 60),
				'discardDays' => self::clampOption($params->get('crawlerips_discard_days', 7), 0, 365, 7),
				'timeoutMs'   => self::clampOption($params->get('crawlerips_timeout_ms', 1000), 500, 60000, 1000),
			];

			self::$instance = new self(
				new StateStore(Factory::getContainer()->get(DatabaseInterface::class)),
				new HttpFetcher(),
				new JoomlaCacheAdapter($options['lifetime']),
				function (string $key, $default = null) {
					return Storage::getInstance()->getValue($key, $default);
				},
				$options
			);
		}

		return self::$instance;
	}

	/**
	 * Replace (or, with NULL, discard) the per-request instance.
	 *
	 * @param   self|null  $instance  The instance to use from now on.
	 *
	 * @return  void
	 * @internal  For tests. Every test which touches the singleton must call setInstance(null) in tearDown().
	 * @since   7.9.4
	 */
	public static function setInstance(?self $instance): void
	{
		self::$instance = $instance;
	}

	/**
	 * The cache ID of the current copy of a bot's list.
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public static function cacheId(string $bot): string
	{
		return md5('com_admintools:crawlerips:v1:current:' . $bot);
	}

	/**
	 * Is the feature on? Pro, master switch on, and not turned off automatically during this request.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function isEnabled(): bool
	{
		return $this->isPro
		       && !$this->disabledThisRequest
		       && (int) ($this->wafValue)('crawlerips', 0) === 1;
	}

	/**
	 * What do we do with a bot? 'ignore' (default), 'block' or 'allow' (Do not block).
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public function getBotState(string $bot): string
	{
		if (!BotRegistry::has($bot))
		{
			return 'ignore';
		}

		$state = ($this->wafValue)(BotRegistry::settingKey($bot), 'ignore');

		return in_array($state, ['block', 'allow'], true) ? $state : 'ignore';
	}

	/**
	 * The bots which are in a given state.
	 *
	 * @param   string  $state  'ignore', 'block' or 'allow'.
	 *
	 * @return  string[]  Bot keys, in registry order.
	 * @since   7.9.4
	 */
	public function getBotsInState(string $state): array
	{
		return array_values(
			array_filter(BotRegistry::keys(), function (string $bot) use ($state) {
				return $this->getBotState($bot) === $state;
			})
		);
	}

	/**
	 * Is the IP in the ranges of any bot set to Block?
	 *
	 * @param   string  $ip  The IP address.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function isIpInBlockLists(string $ip): bool
	{
		return $this->isIpInState($ip, 'block');
	}

	/**
	 * Is the IP in the ranges of any bot set to Do not block?
	 *
	 * @param   string  $ip  The IP address.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function isIpInAllowLists(string $ip): bool
	{
		return $this->isIpInState($ip, 'allow');
	}

	/**
	 * The ranges of a bot, from the lazy path: the cache first, a download on a miss.
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  IpRangeSet|null  NULL unless we have usable data (see {@see self::isUsable()}).
	 * @since   7.9.4
	 */
	public function getRangeSet(string $bot): ?IpRangeSet
	{
		if (!BotRegistry::has($bot))
		{
			return null;
		}

		if (array_key_exists($bot, $this->rangeSets))
		{
			return $this->rangeSets[$bot];
		}

		$id  = self::cacheId($bot);
		$ran = false;

		$payload = $this->current->get($id, function () use ($bot, &$ran) {
			$ran = true;

			return $this->missCallback($bot, false);
		});

		if (!$ran && $payload)
		{
			$status = $payload['status'] ?? 'fresh';

			if ($status === 'deferred')
			{
				// Not a real cache entry: another bot, or the lock, stopped the download. Try again right now.
				$this->current->remove($id);

				$payload = $this->current->get($id, function () use ($bot) {
					return $this->missCallback($bot, true);
				});
			}
			elseif ($status === 'throttled')
			{
				// We got this from the cache without the callback running, so the cache evidently works.
				$this->state->resetTooSoon();

				$payload['status'] = 'fresh';
				$this->current->store($id, $payload);
			}
		}

		$set = ($payload && $this->isUsable($payload)) ? IpRangeSet::fromArray($payload['set']) : null;

		$this->rangeSets[$bot] = $set;

		return $set;
	}

	/**
	 * Refresh a bot's list now. The deliberate path: the scheduled task and the "Refresh now" button.
	 *
	 * There is no throttle and no one-download-per-request limit, and failures do not count towards the auto-disable.
	 * The site-wide lock is still respected.
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  array  ['bot' => string, 'ok' => bool, 'count' => int, 'error' => ?string]; empty if the feature is off.
	 * @since   7.9.4
	 */
	public function refresh(string $bot): array
	{
		if (!$this->isEnabled() || !BotRegistry::has($bot))
		{
			return [];
		}

		unset($this->rangeSets[$bot]);

		if (!$this->state->tryLock($this->now(), $this->lockTtl()))
		{
			return ['bot' => $bot, 'ok' => false, 'count' => 0, 'error' => 'busy'];
		}

		try
		{
			$result = $this->download($bot);
		}
		finally
		{
			$this->state->unlock();
		}

		return [
			'bot'   => $bot,
			'ok'    => $result['ok'],
			'count' => (int) ($result['payload']['set']['entries'] ?? 0),
			'error' => $result['error'],
		];
	}

	/**
	 * Refresh every bot which is not set to Ignore.
	 *
	 * @return  array[]  The results of {@see self::refresh()}; empty if the feature is off.
	 * @since   7.9.4
	 */
	public function refreshAll(): array
	{
		if (!$this->isEnabled())
		{
			return [];
		}

		$results = [];

		foreach (BotRegistry::keys() as $bot)
		{
			if ($this->getBotState($bot) === 'ignore')
			{
				continue;
			}

			$results[] = $this->refresh($bot);
		}

		return $results;
	}

	/**
	 * The reset: clears the download lock, both health counters, the last-attempt timestamps, the auto-disable
	 * reason/time and the "email sent" flag.
	 *
	 * It does not touch the cache, the last good copies or the master switch.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function reset(): void
	{
		$this->state->reset();
		$this->rangeSets = [];
	}

	/**
	 * Status information for the UI. Never downloads anything.
	 *
	 * @return  array  ['enabled', 'masterSwitch', 'locked', 'health', 'bots' => [...]]
	 * @since   7.9.4
	 */
	public function getStatus(): array
	{
		$attempts = $this->state->getAttempts();
		$bots     = [];

		foreach (BotRegistry::keys() as $bot)
		{
			$info    = BotRegistry::get($bot);
			$payload = $this->current->peek(self::cacheId($bot));

			if (!$this->hasData($payload))
			{
				$lastGood = $this->state->getLastGood($bot);
				$error    = $payload['lastError'] ?? null;
				$payload  = $this->hasData($lastGood) ? $lastGood : $payload;

				if ($error !== null && is_array($payload))
				{
					$payload['lastError'] = $error;
				}
			}

			$hasData = $this->hasData($payload);

			$bots[] = [
				'key'         => $bot,
				'provider'    => $info['provider'],
				'state'       => $this->getBotState($bot),
				'fetchedAt'   => $hasData ? (int) $payload['fetchedAt'] : null,
				'entries'     => $hasData ? (int) ($payload['set']['entries'] ?? 0) : 0,
				'lastError'   => is_array($payload) ? ($payload['lastError'] ?? null) : null,
				'usable'      => $hasData && $this->isUsable($payload),
				'stale'       => $hasData && !$this->isUsable($payload),
				'lastAttempt' => (int) ($attempts[$bot] ?? 0),
			];
		}

		$health = $this->state->getHealth();

		return [
			'enabled'      => $this->isEnabled(),
			'masterSwitch' => (int) ($this->wafValue)('crawlerips', 0) === 1,
			'locked'       => $this->state->isLocked($this->now()),
			'health'       => [
				'cause'      => $health['cause'],
				'disabledAt' => $health['disabledAt'],
				'toosoon'    => $health['toosoon'],
				'failures'   => $health['failures'],
				'emailSent'  => $health['emailSent'],
			],
			'bots'         => $bots,
		];
	}

	/**
	 * Turn the feature off because it is unhealthy, and tell the Super Users, once.
	 *
	 * @param   string  $cause  'cache' or 'downloads'.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	protected function autoDisable(string $cause): void
	{
		// Whatever happens next, nobody uses the lists for the rest of this request.
		$this->disabledThisRequest = true;

		$health = $this->state->getHealth();

		if (!empty($health['cause']))
		{
			// Already turned off (by this or another request). Nothing more to do.
			return;
		}

		$health['cause']      = $cause;
		$health['disabledAt'] = $this->now();
		$this->state->saveHealth($health);

		$this->disableMasterSwitch();

		if (!$health['emailSent'])
		{
			$this->notifySuperUsers($cause);

			$health['emailSent'] = true;
			$this->state->saveHealth($health);
		}
	}

	/**
	 * Turn the master switch off in the WAF configuration.
	 *
	 * This is the one exception to "never write cparams from a visitor request" (see the design). It follows the
	 * `deluge_notified` precedent: re-read cparams from the database immediately before writing, change only the
	 * master switch, and save.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	protected function disableMasterSwitch(): void
	{
		try
		{
			// A fresh instance re-reads cparams from the database.
			$fresh = new Storage();
			$fresh->setValue('crawlerips', 0, true);

			// Keep this request's in-memory configuration consistent, without another write.
			Storage::getInstance()->setValue('crawlerips', 0);
		}
		catch (Throwable $e)
		{
			// Never break a request.
		}
	}

	/**
	 * Email the Super Users that the feature turned itself off.
	 *
	 * @param   string  $cause  'cache' or 'downloads'.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	protected function notifySuperUsers(string $cause): void
	{
		if (!$this->isPro)
		{
			return;
		}

		try
		{
			$key = 'com_admintools.crawlerips_disabled';
			TemplateEmails::updateTemplate($key);

			$siteName = (string) Factory::getApplication()->get('sitename');
			$date     = $this->getEmailDate();
			$url      = Uri::root() . 'administrator/index.php?option=com_admintools&view=Configurewaf';

			foreach ($this->getRecipients() as $user)
			{
				TemplateEmails::sendMail(
					$key,
					function ($language) use ($cause, $siteName, $date, $url): array {
						return [
							'SITENAME' => $siteName,
							'DATE'     => $date,
							'CAUSE'    => $language->_('COM_ADMINTOOLS_CRAWLERIPS_CAUSE_' . strtoupper($cause)),
							'URL'      => $url,
						];
					},
					$user
				);
			}
		}
		catch (Throwable $e)
		{
			// Never break a request.
		}
	}

	/**
	 * The current time, for the email, in the timezone the other Admin Tools emails use.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	private function getEmailDate(): string
	{
		$app      = Factory::getApplication();
		$emailTz  = ComponentHelper::getParams('com_admintools')->get('email_timezone', 'AKEEBA/DEFAULT');
		$timezone = null;

		try
		{
			$timezone = new DateTimeZone($app->get('offset', 'GMT'));
		}
		catch (Throwable $e)
		{
			$timezone = null;
		}

		if (!empty($emailTz) && ($emailTz != 'AKEEBA/DEFAULT'))
		{
			try
			{
				$timezone = new DateTimeZone($emailTz);
			}
			catch (Throwable $e)
			{
				// An invalid timezone in the options. Keep the application's.
			}
		}

		$date = clone Factory::getDate();
		$date->setTimezone($timezone ?: new DateTimeZone('GMT'));

		return $date->format('Y-m-d H:i:s T', true);
	}

	/**
	 * The recipients: non-blocked Super Users who want email; if none does, all non-blocked Super Users.
	 *
	 * @return  \Joomla\CMS\User\User[]
	 * @since   7.9.4
	 */
	private function getRecipients(): array
	{
		$superGroups = UserGroups::superUserGroups();

		if (empty($superGroups))
		{
			return [];
		}

		$userIds = UserGroups::getUserIds($superGroups);

		$factory = Factory::getContainer()->get(UserFactoryInterface::class);
		$users   = [];

		foreach ($userIds as $userId)
		{
			$user = $factory->loadUserById((int) $userId);

			if ($user && !$user->block && !empty($user->email))
			{
				$users[] = $user;
			}
		}

		$wantEmail = array_values(array_filter($users, function ($user) {
			return !empty($user->sendEmail);
		}));

		return $wantEmail ?: $users;
	}

	/**
	 * The lazy path's cache-miss callback. Its return value is stored by Joomla as the current entry.
	 *
	 * @param   string  $bot                The bot key.
	 * @param   bool    $afterDeferredHit   True when this request just discarded a `deferred` entry. The entry proves
	 *                                      the cache works, so we must never count a "too soon" event after it.
	 *
	 * @return  array  The payload.
	 * @since   7.9.4
	 */
	private function missCallback(string $bot, bool $afterDeferredHit): array
	{
		$now = $this->now();

		// 1. Throttle: no download if the last attempt was recent.
		if ($now - $this->state->getLastAttempt($bot) < self::THROTTLE_SECONDS)
		{
			if (!$afterDeferredHit && !$this->state->isLocked($now))
			{
				$peek = $this->current->peek(self::cacheId($bot));

				if ($peek !== null)
				{
					/**
					 * The cache works; we only lost a race against a request which stored the data after we missed.
					 * Use what it stored, if it is a real entry. Count nothing.
					 */
					if (($peek['status'] ?? '') === 'fresh')
					{
						return $peek;
					}
				}
				elseif ($this->state->incrementTooSoon() > self::AUTO_DISABLE_THRESHOLD)
				{
					$this->autoDisable('cache');
				}
			}

			return $this->lastGoodOr($bot, 'throttled');
		}

		// 2. At most one download per request.
		if ($this->downloadedThisRequest)
		{
			return $this->lastGoodOr($bot, 'deferred');
		}

		// 3. The site-wide lock.
		if (!$this->state->tryLock($now, $this->lockTtl()))
		{
			return $this->lastGoodOr($bot, 'deferred');
		}

		// 4-6. Download, and store the result before the lock is released.
		try
		{
			$result = $this->download($bot);
		}
		finally
		{
			$this->state->unlock();
		}

		// Counting failures, and what may follow from it, happens outside the lock: it can send an email.
		if (!$result['ok'] && $this->state->incrementFailures() > self::AUTO_DISABLE_THRESHOLD)
		{
			$this->autoDisable('downloads');
		}

		return $result['payload'];
	}

	/**
	 * Download, parse and store one list. The caller holds the site-wide lock.
	 *
	 * The result is stored (in the current cache and, on success, as the last good copy) HERE, before the caller
	 * releases the lock. Were we to leave it to Joomla's CallbackController, a request arriving between "lock
	 * released" and "Joomla stored the callback's return value" would see a free lock, a recent attempt and an empty
	 * cache, and count a false "too soon" event on a perfectly healthy busy site.
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  array  ['ok' => bool, 'error' => ?string, 'payload' => array]
	 * @since   7.9.4
	 */
	private function download(string $bot): array
	{
		$info = BotRegistry::get($bot);
		$id   = self::cacheId($bot);
		$now  = $this->now();

		$this->state->setLastAttempt($bot, $now);
		$this->downloadedThisRequest = true;

		$error   = null;
		$entries = null;

		try
		{
			$fetched = $this->fetcher->fetch(
				$info['url'], self::timeoutFor((string) $info['format'], (int) $this->options['timeoutMs'])
			);
			$error   = $fetched['error'] ?? null;

			if ($error === null)
			{
				$entries = ($fetched['body'] ?? null) === null ? null : ListParser::parse($fetched['body'], $info['format']);

				if ($entries === null)
				{
					$error = 'No usable data in the response';
				}
			}
		}
		catch (Throwable $e)
		{
			$entries = null;
			$error   = $e->getMessage() ?: get_class($e);
		}

		if ($entries !== null)
		{
			$payload = $this->emptyPayload($bot, 'fresh', null);

			$payload['fetchedAt'] = $now;
			$payload['set']       = IpRangeSet::fromEntries($entries)->toArray();

			$this->state->setLastGood($bot, $payload);
			$this->current->store($id, $payload);
			$this->state->resetFailures();

			return ['ok' => true, 'error' => null, 'payload' => $payload];
		}

		// A failure: keep serving the last good copy. Never overwrite it.
		$payload = $this->lastGoodOr($bot, 'fresh', $error);
		$this->current->store($id, $payload);

		return ['ok' => false, 'error' => $error, 'payload' => $payload];
	}

	/**
	 * The last good payload with a new status (and error), or an empty payload if there is no last good copy.
	 *
	 * @param   string       $bot     The bot key.
	 * @param   string       $status  'fresh', 'deferred' or 'throttled'.
	 * @param   string|null  $error   The error to record; NULL to keep whatever the payload has.
	 *
	 * @return  array
	 * @since   7.9.4
	 */
	private function lastGoodOr(string $bot, string $status, ?string $error = null): array
	{
		$payload = $this->state->getLastGood($bot);

		if (!$this->hasData($payload))
		{
			return $this->emptyPayload($bot, $status, $error);
		}

		$payload['status'] = $status;

		if ($error !== null)
		{
			$payload['lastError'] = $error;
		}

		return $payload;
	}

	/**
	 * A payload with no data.
	 *
	 * @param   string       $bot     The bot key.
	 * @param   string       $status  'fresh', 'deferred' or 'throttled'.
	 * @param   string|null  $error   The error.
	 *
	 * @return  array
	 * @since   7.9.4
	 */
	private function emptyPayload(string $bot, string $status, ?string $error): array
	{
		return [
			'v'         => 1,
			'bot'       => $bot,
			'url'       => BotRegistry::get($bot)['url'] ?? '',
			'fetchedAt' => null,
			'lastError' => $error,
			'status'    => $status,
			'set'       => null,
		];
	}

	/**
	 * Does the payload carry ranges at all (whether or not they are still usable)?
	 *
	 * @param   mixed  $payload  The payload.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private function hasData($payload): bool
	{
		return is_array($payload)
		       && isset($payload['fetchedAt'])
		       && is_numeric($payload['fetchedAt'])
		       && isset($payload['set'])
		       && is_array($payload['set']);
	}

	/**
	 * Can the WAF act on this payload? It has data, and the data is not older than the cache lifetime plus the
	 * discard period (the "discard stale lists" rule, applied at read time).
	 *
	 * @param   array  $payload  The payload.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private function isUsable(array $payload): bool
	{
		if (!$this->hasData($payload))
		{
			return false;
		}

		return self::isFreshEnough(
			(int) $payload['fetchedAt'], (int) $this->options['lifetime'], (int) $this->options['discardDays'],
			$this->now()
		);
	}

	/**
	 * Is a copy downloaded at $fetchedAt still acceptable? It must not be older than the cache lifetime plus the
	 * discard period.
	 *
	 * @param   int  $fetchedAt    Unix time of the download.
	 * @param   int  $lifetimeMin  Cache lifetime, in minutes.
	 * @param   int  $discardDays  Discard period, in days.
	 * @param   int  $now          The current Unix time.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public static function isFreshEnough(int $fetchedAt, int $lifetimeMin, int $discardDays, int $now): bool
	{
		return $now <= $fetchedAt + $lifetimeMin * 60 + $discardDays * 86400;
	}

	/**
	 * Is the IP in the ranges of any bot in the given state?
	 *
	 * @param   string  $ip     The IP address.
	 * @param   string  $state  'block' or 'allow'.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private function isIpInState(string $ip, string $state): bool
	{
		if ($ip === '' || !$this->isEnabled())
		{
			return false;
		}

		foreach ($this->getBotsInState($state) as $bot)
		{
			// An auto-disable triggered by an earlier bot in this very loop stops us at once.
			if (!$this->isEnabled())
			{
				return false;
			}

			$set = $this->getRangeSet($bot);

			if ($set !== null && $set->contains($ip))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Lifetime of the site-wide lock, in seconds.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	private function lockTtl(): int
	{
		// Long enough for the slowest format's download.
		return (int) ceil(self::maxTimeoutMs((int) $this->options['timeoutMs']) / 1000) + self::LOCK_MARGIN;
	}

	/**
	 * The download time limit for a list format, in milliseconds.
	 *
	 * JSON lists use the configured limit; HTML pages (Amazon) get HTML_TIMEOUT_FACTOR times it, capped at
	 * MAX_TIMEOUT_MS.
	 *
	 * @param   string  $format     The list format: 'json' or 'plain' (data files), 'amazon_html' or 'html' (web pages).
	 * @param   int     $optionMs   The configured download time limit, in milliseconds.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	public static function timeoutFor(string $format, int $optionMs): int
	{
		if ($format === 'amazon_html' || $format === 'html')
		{
			return min(self::MAX_TIMEOUT_MS, $optionMs * self::HTML_TIMEOUT_FACTOR);
		}

		return $optionMs;
	}

	/**
	 * The longest download time limit of any format, in milliseconds.
	 *
	 * @param   int  $optionMs  The configured download time limit, in milliseconds.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	public static function maxTimeoutMs(int $optionMs): int
	{
		return max(self::timeoutFor('json', $optionMs), self::timeoutFor('amazon_html', $optionMs));
	}

	/**
	 * The current Unix time.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	private function now(): int
	{
		return (int) ($this->clock)();
	}

	/**
	 * Clamp an option to its allowed range; fall back to the default if it is not a number.
	 *
	 * @param   mixed  $raw      The raw option value.
	 * @param   int    $min      Minimum.
	 * @param   int    $max      Maximum.
	 * @param   int    $default  Default.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	public static function clampOption($raw, int $min, int $max, int $default): int
	{
		if ($raw === null || $raw === '' || !is_numeric($raw))
		{
			return $default;
		}

		return max($min, min($max, (int) $raw));
	}
}
