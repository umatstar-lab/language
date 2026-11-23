<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Model\ServerconfigmakerModel;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\CacheAdapterInterface;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\CrawlerIPLists;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\FetcherInterface;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\HttpFetcher;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\JoomlaCacheAdapter;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\ListParser;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\StateStore;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Throwable;

final class CloudIPRanges
{
	/**
	 * How many seconds will we cache IP ranges we fetch from the Internet.
	 *
	 * @since   7.8.0
	 */
	private const CACHE_LIFETIME = 3600;

	/**
	 * Seconds to wait after a failed download before trying that source again.
	 *
	 * @since   7.9.4
	 */
	private const THROTTLE_SECONDS = 900;

	/**
	 * Seconds added to the download time limit to get the lifetime of the download lock.
	 *
	 * @since   7.9.4
	 */
	private const LOCK_MARGIN = 10;

	/**
	 * The downloadable lists: source key => [URL, format, parser method].
	 *
	 * The format is the one CrawlerIPLists::timeoutFor() understands.
	 *
	 * @since   7.9.4
	 */
	private const SOURCES = [
		'cloudflare_v4' => ['https://www.cloudflare.com/ips-v4', 'plain', 'parseCloudFlareBody'],
		'cloudflare_v6' => ['https://www.cloudflare.com/ips-v6', 'plain', 'parseCloudFlareBody'],
		'sucuri'        => ['https://docs.sucuri.net/website-firewall/sucuri-firewall-troubleshooting-guide/', 'html', 'parseSucuriBody'],
		'bunnycdn_v4'   => ['https://bunnycdn.com/api/system/edgeserverlist', 'json', 'parseBunnyCDNBody'],
		'bunnycdn_v6'   => ['https://bunnycdn.com/api/system/edgeserverlist/IPv6', 'json', 'parseBunnyCDNBody'],
	];

	/**
	 * Language key of the reason why the last getIPRanges() call returned NULL.
	 *
	 * @var   string|null
	 * @since 7.9.4
	 */
	private static ?string $lastError = null;

	private static ?FetcherInterface $fetcher = null;

	private static ?CacheAdapterInterface $cache = null;

	private static ?StateStore $state = null;

	/** @var callable|null */
	private static $clock = null;

	/** @var array|null lifetime (minutes), discardDays, timeoutMs */
	private static ?array $options = null;

	private static ?ServerconfigmakerModel $serverconfigmakerModel = null;

	/**
	 * This constructor is private to prevent direct instantiation of the class.
	 *
	 * @return void
	 * @since   7.8.0
	 */
	private function __construct() {}

	/**
	 * Retrieves the IP ranges based on the specified type and conditions; null on error.
	 *
	 * Depending on the type, it fetches pre-defined IP ranges for supported services or returns null if no type is matched.
	 *
	 * Supported range types:
	 * - 'none' Returns an empty array.
	 * - 'custom' Configured IP ranges.
	 * - 'cloudflare' CloudFlare IP ranges.
	 * - 'sucuri' Sucuri IP ranges.
	 * - 'bunnycdn' BunnyCDN IP ranges.
	 *
	 * @param   string                       $type   The type of IP range to retrieve. Supported types: 'none', 'custom', 'cloudflare', 'sucuri', 'bunnycdn'.
	 * @param   bool                         $force  Whether to force refresh of the ranges.
	 * @param   ServerconfigmakerModel|null  $model  The server configuration maker model whose custom IP list will be
	 *                                               read for the 'custom' type. When NULL we use the model set with
	 *                                               setServerconfigmakerModel(), falling back to the .htaccess Maker.
	 *
	 * @return  array|null  An array of IP ranges if the type matches a supported service, null on error. In the
	 *                      latter case getLastError() tells why.
	 */
	public static function getIPRanges(
		string $type = 'none', bool $force = false, ?ServerconfigmakerModel $model = null
	): ?array
	{
		self::$lastError = null;

		switch ($type)
		{
			default:
			case 'none':
				return [];

			case 'custom':
				$model ??= self::$serverconfigmakerModel
				           ?: Factory::getApplication()
					           ->bootComponent('com_admintools')
					           ->getMVCFactory()
					           ->createModel('Htaccessmaker', 'Administrator');
				$config = $model->loadConfiguration();
				$ips = $config->restrictip_custom ?: [];

				if (empty($ips) || !is_array($ips))
				{
					return [];
				}

				$ips = array_map(function($entry) {
					if (is_string($entry))
					{
						return $entry;
					}

					if (is_object($entry))
					{
						return $entry->item ?? null;
					}

					if (is_array($entry))
					{
						return $entry['item'] ?? null;
					}

					return null;
				}, $ips);

				return array_values(array_map([IpExpressionValidator::class, 'normalise'], array_filter($ips) ?: []));

			case 'internal':
				return [
					'127.0.0.0/8',
					'::1',
					'10.0.0.0/8',
					'172.16.0.0/12',
					'192.168.0.0/16',
					'fc00::/7',
				];

			case 'cloudflare':
				return self::getFromSources(['cloudflare_v4', 'cloudflare_v6'], $force);

			case 'sucuri':
				return self::getFromSources(['sucuri'], $force);

			case 'bunnycdn':
				return self::getFromSources(['bunnycdn_v4', 'bunnycdn_v6'], $force);
		}
	}

	/**
	 * Sets the server configuration maker model.
	 *
	 * @param   ServerconfigmakerModel|null  $serverconfigmakerModel  The ServerconfigmakerModel instance to set, or null to unset.
	 *
	 * @return  void
	 * @since   7.8.0
	 */
	public static function setServerconfigmakerModel(?ServerconfigmakerModel $serverconfigmakerModel): void
	{
		self::$serverconfigmakerModel = $serverconfigmakerModel;
	}

	/**
	 * The reason why the last getIPRanges() call returned NULL: a language key, or NULL if it did not fail.
	 *
	 * @return  string|null
	 * @since   7.9.4
	 */
	public static function getLastError(): ?string
	{
		return self::$lastError;
	}

	/**
	 * Replace the collaborators (and reset the remembered error). Call with no arguments to go back to the real ones.
	 *
	 * @param   FetcherInterface|null      $fetcher  Downloader.
	 * @param   CacheAdapterInterface|null $cache    Cache for the current copies.
	 * @param   StateStore|null            $state    Lock, failure times and last good copies.
	 * @param   callable|null              $clock    Returns the current Unix time.
	 * @param   array|null                 $options  lifetime (minutes), discardDays, timeoutMs.
	 *
	 * @return  void
	 * @internal  For tests. Every test which calls this must call it with no arguments in tearDown().
	 * @since   7.9.4
	 */
	public static function setTestDoubles(
		?FetcherInterface $fetcher = null, ?CacheAdapterInterface $cache = null, ?StateStore $state = null,
		?callable $clock = null, ?array $options = null
	): void
	{
		self::$fetcher   = $fetcher;
		self::$cache     = $cache;
		self::$state     = $state;
		self::$clock     = $clock;
		self::$options   = $options;
		self::$lastError = null;
	}

	/**
	 * Parse the raw HTTP body returned by CloudFlare's ips-v4 / ips-v6 endpoints.
	 *
	 * The expected format is one CIDR / IP per line, separated by `\n`. Empty lines, surrounding whitespace and
	 * empty results are tolerated. An empty / whitespace-only body yields an empty array, i.e. a failure.
	 *
	 * @param   string  $body  The raw HTTP response body.
	 *
	 * @return  array  The list of CloudFlare IP ranges, or an empty array if the body could not be parsed.
	 *
	 * @since   7.9.2
	 */
	public static function parseCloudFlareBody(string $body): array
	{
		if (trim($body) === '')
		{
			return [];
		}

		$list = explode("\n", trim($body));

		if (empty($list))
		{
			return [];
		}

		return array_filter(array_map('trim', $list)) ?: [];
	}

	/**
	 * Parse the raw HTTP body returned by the BunnyCDN edge-server-list endpoints.
	 *
	 * The expected format is a top-level JSON array of CIDR/IPv4/IPv6 strings. An empty / malformed / non-array
	 * JSON body yields an empty array, i.e. a failure.
	 *
	 * @param   string  $body  The raw HTTP response body.
	 *
	 * @return  array  The list of BunnyCDN IP ranges, or an empty array if the body could not be parsed.
	 *
	 * @since   7.9.2
	 */
	public static function parseBunnyCDNBody(string $body): array
	{
		if (trim($body) === '')
		{
			return [];
		}

		try
		{
			$ips = @json_decode($body);
		}
		catch (\Exception $e)
		{
			return [];
		}

		if (!is_array($ips) || empty($ips))
		{
			return [];
		}

		return $ips;
	}

	/**
	 * Parse Sucuri's firewall troubleshooting page: the `Require ip` lines inside the HTML-escaped FilesMatch block.
	 *
	 * @param   string  $text  The raw HTTP response body.
	 *
	 * @return  array  The list of Sucuri IP ranges, or an empty array if the page could not be parsed.
	 *
	 * @since   7.9.4
	 */
	public static function parseSucuriBody(string $text): array
	{
		$marker = '&lt;FilesMatch &quot;.*&quot;&gt;';
		$start  = strpos($text, $marker);

		if ($start === false)
		{
			return [];
		}

		$start += strlen($marker);
		$end   = strpos($text, '&lt;/FilesMatch&gt;', $start + 1);

		if ($end === false)
		{
			return [];
		}

		$text = trim(str_replace("\r", "\n", substr($text, $start, $end - $start)));
		$ips  = [];

		foreach (explode("\n", $text) as $line)
		{
			$line = trim($line);

			if (str_starts_with(strtolower($line), 'require ip '))
			{
				$ips[] = trim(substr($line, 11));
			}
		}

		return array_values(array_filter($ips));
	}

	/**
	 * Merge the ranges of several sources. If any source has nothing usable, the result is NULL: a partial list would
	 * lock the administrator out of the site.
	 *
	 * @param   string[]  $sources  Keys of SOURCES.
	 * @param   bool      $force    Bypass the cache and the failure throttle.
	 *
	 * @return  array|null
	 * @since   7.9.4
	 */
	private static function getFromSources(array $sources, bool $force): ?array
	{
		$merged = [];

		foreach ($sources as $source)
		{
			$ranges = self::getSourceRanges($source, $force);

			if ($ranges === null)
			{
				return null;
			}

			$merged = array_merge($merged, $ranges);
		}

		return array_values(array_unique($merged)) ?: null;
	}

	/**
	 * Get the ranges of one source: from the cache, or from a download, or from the last good copy.
	 *
	 * @param   string  $source  Key of SOURCES.
	 * @param   bool    $force   Bypass the cache and the failure throttle.
	 *
	 * @return  array|null  NULL if nothing usable is available; self::$lastError is set.
	 * @since   7.9.4
	 */
	private static function getSourceRanges(string $source, bool $force): ?array
	{
		$options = self::getOptions();
		$cache   = self::getCache();
		$state   = self::getState();
		$id      = self::getCacheId($source);
		$now     = self::now();

		if (!$force)
		{
			$cached = $cache->peek($id);

			if (self::hasRanges($cached))
			{
				return $cached['ranges'];
			}

			// Throttle only after a failure; the row holds the time of the last FAILED attempt (0 after a success).
			$lastFailure = $state->getLastAttempt($source);

			if ($lastFailure > 0 && $now - $lastFailure < self::THROTTLE_SECONDS)
			{
				return self::lastGoodOrFail($source, 'COM_ADMINTOOLS_CLOUDIPRANGES_ERR_THROTTLED');
			}
		}

		[$url, $format, $parser] = self::SOURCES[$source];
		$timeoutMs               = CrawlerIPLists::timeoutFor($format, $options['timeoutMs']);

		if (!$state->tryLock($now, (int) ceil($timeoutMs / 1000) + self::LOCK_MARGIN))
		{
			return self::lastGoodOrFail($source, 'COM_ADMINTOOLS_CLOUDIPRANGES_ERR_BUSY');
		}

		$ranges = null;

		try
		{
			$fetched = self::getFetcher()->fetch($url, $timeoutMs);
			$body    = $fetched['body'] ?? null;

			if (($fetched['error'] ?? null) === null && is_string($body) && strlen($body) <= ListParser::MAX_BODY_BYTES)
			{
				$ranges = self::validate((array) call_user_func([self::class, $parser], $body));
			}
		}
		catch (Throwable $e)
		{
			$ranges = null;
		}
		finally
		{
			// Whatever happens, the lock must not outlive this download.
			if ($ranges === null)
			{
				$state->setLastAttempt($source, $now);
			}
			else
			{
				$state->setLastAttempt($source, 0);
			}

			$state->unlock();
		}

		if ($ranges === null)
		{
			return self::lastGoodOrFail($source, 'COM_ADMINTOOLS_CLOUDIPRANGES_ERR_DOWNLOAD');
		}

		$payload = ['v' => 1, 'fetchedAt' => $now, 'ranges' => $ranges];

		$state->setLastGood($source, $payload);
		$cache->store($id, $payload);

		return $ranges;
	}

	/**
	 * Return the last good copy of a source if it is recent enough; otherwise record the error and return NULL.
	 *
	 * @param   string  $source  Key of SOURCES.
	 * @param   string  $error   Language key of the reason why we are not using fresh data.
	 *
	 * @return  array|null
	 * @since   7.9.4
	 */
	private static function lastGoodOrFail(string $source, string $error): ?array
	{
		$options  = self::getOptions();
		$lastGood = self::getState()->getLastGood($source);

		if (
			self::hasRanges($lastGood)
			&& CrawlerIPLists::isFreshEnough(
				(int) ($lastGood['fetchedAt'] ?? 0), $options['lifetime'], $options['discardDays'], self::now()
			)
		)
		{
			return $lastGood['ranges'];
		}

		self::$lastError = $error;

		return null;
	}

	/**
	 * Keep only the well-formed entries (IP address or CIDR, not absurdly wide), canonicalised and unique.
	 *
	 * @param   array  $entries  The parsed entries.
	 *
	 * @return  array|null  NULL if nothing valid is left.
	 * @since   7.9.4
	 */
	private static function validate(array $entries): ?array
	{
		$valid = [];

		foreach ($entries as $entry)
		{
			if (!is_string($entry))
			{
				continue;
			}

			$normalised = ListParser::normaliseEntry($entry);

			if ($normalised !== null)
			{
				$valid[$normalised] = true;
			}
		}

		return empty($valid) ? null : array_map('strval', array_keys($valid));
	}

	/**
	 * Does the payload carry a non-empty list of ranges?
	 *
	 * @param   mixed  $payload  The payload.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private static function hasRanges($payload): bool
	{
		return is_array($payload) && !empty($payload['ranges']) && is_array($payload['ranges']);
	}

	/**
	 * The options shared with the crawler IP lists.
	 *
	 * @return  array{lifetime: int, discardDays: int, timeoutMs: int}
	 * @since   7.9.4
	 */
	private static function getOptions(): array
	{
		if (self::$options === null)
		{
			$params = ComponentHelper::getParams('com_admintools');

			self::$options = [
				'lifetime'    => (int) ceil(self::CACHE_LIFETIME / 60),
				'discardDays' => CrawlerIPLists::clampOption($params->get('crawlerips_discard_days', 7), 0, 365, 7),
				'timeoutMs'   => CrawlerIPLists::clampOption($params->get('crawlerips_timeout_ms', 1000), 500, 60000, 1000),
			];
		}

		return array_merge(['lifetime' => 60, 'discardDays' => 7, 'timeoutMs' => 1000], self::$options);
	}

	private static function getFetcher(): FetcherInterface
	{
		return self::$fetcher ??= new HttpFetcher();
	}

	private static function getCache(): CacheAdapterInterface
	{
		return self::$cache ??= new JoomlaCacheAdapter((int) ceil(self::CACHE_LIFETIME / 60));
	}

	private static function getState(): StateStore
	{
		return self::$state ??= new StateStore(Factory::getContainer()->get(DatabaseInterface::class), 'cloudipranges');
	}

	private static function now(): int
	{
		return self::$clock === null ? time() : (int) (self::$clock)();
	}

	/**
	 * Generates a unique cache identifier for a given source.
	 *
	 * @param   string  $item  The source key.
	 *
	 * @return  string  A unique cache identifier.
	 * @since   7.8.0
	 */
	private static function getCacheId(string $item): string
	{
		return hash('md5', sprintf('com_admintools:%s:cloudipranges:v2:%s', self::getCacheSalt(), $item));
	}

	/**
	 * Generates and returns a unique cache salt.
	 *
	 * The salt is derived from the directory, version, release date, and core/pro type of the component.
	 *
	 * @return  string  An MD5 hashed string to be used as a cache salt.
	 * @since   7.8.0
	 */
	private static function getCacheSalt(): string
	{
		$version = defined('ADMINTOOLS_VERSION') ? ADMINTOOLS_VERSION : '0.0.0';
		$date    = defined('ADMINTOOLS_DATE') ? ADMINTOOLS_DATE : '0000-00-00';
		$pro     = (defined('ADMINTOOLS_PRO') ? ADMINTOOLS_PRO : false) ? 'pro' : 'core';

		return hash('md5', __DIR__ . ':' . $version . ':' . $date . ':' . $pro);
	}
}
