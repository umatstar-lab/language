<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Utility;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\Helper\EmailLanguage;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Helper\TemplateEmails;
use DateTimeZone;
use Exception;
use Joomla\Application\ApplicationInterface;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

class BlockedRequestHandler implements DatabaseAwareInterface
{
	use DatabaseAwareTrait;

	/**
	 * Plugin parameters
	 *
	 * @var   Registry
	 * @since 7.0.0
	 */
	protected $pluginParams = null;

	/**
	 * WAF parameters
	 *
	 * @var   Storage
	 * @since 7.0.0
	 */
	protected $wafParams = null;

	/**
	 * Component parameters
	 *
	 * @var   Registry
	 * @since 7.0.0
	 */
	protected $cParams = null;

	private ApplicationInterface $application;

	public function __construct(Registry $pluginParams, Storage $wafParams, Registry $cParams)
	{
		$this->pluginParams = $pluginParams;
		$this->wafParams    = $wafParams;
		$this->cParams      = $cParams;
	}

	/**
	 * @param   string          $templateKey  The template key to send, e.g. 'com_admintools.blockedrequest'
	 * @param   User|null       $user         The user to send the email to. NULL for the currently logged in user.
	 * @param   array|callable  $data         Associative array for tag/variable replacement in the email template.
	 *                                        Pass a callable — receiving the recipient's Language and returning that
	 *                                        array — whenever any of the values is itself translated, so that it is
	 *                                        resolved in the recipient's language rather than the site's.
	 *
	 * @return bool
	 */
	public function sendEmail(string $templateKey, ?User $user = null, $data = []): bool
	{
		// Do not send emails in the Core version
		if (!defined('ADMINTOOLS_PRO') || !ADMINTOOLS_PRO)
		{
			return true;
		}

		$app  = $this->getApplication();
		$user = $user ?: $app->getIdentity();

		/**
		 * The variables are assembled lazily, inside TemplateEmails::sendMail's language scope. That is what lets a
		 * translated value such as {RESCUEINFO} come out in the same language as the template it is substituted into.
		 */
		$buildData = function ($language) use ($data, $user): array {
			$resolved = is_array($data) ? $data : (is_callable($data) ? (array) $data($language) : (array) $data);

			return $this->getEmailVariables(
				array_merge(
					[
						'USERNAME' => $user->username,
						'FULLNAME' => $user->name,
					], $resolved
				)
			);
		};

		try
		{
			TemplateEmails::updateTemplate($templateKey);

			return TemplateEmails::sendMail($templateKey, $buildData, $user);
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	/**
	 * Legacy shim to logRequest().
	 *
	 * @param   string  $reason
	 * @param   string  $extraLogInformation
	 * @param   string  $extraLogTableInformation
	 *
	 * @deprecated  8.0  Use the logRequest method; it works identically, it's just better named.
	 *
	 * @return  bool
	 * @see         self::logRequest()
	 */
	public function logWithoutBlocking(
		string $reason, string $extraLogInformation = '', string $extraLogTableInformation = ''
	): bool
	{
		return $this->logRequest($reason, $extraLogInformation, $extraLogTableInformation);
	}

	/**
	 * Logs a possibly malicious request and processes the IP auto-ban.
	 *
	 * This method DOES NOT block (HTTP 403) the request. It only logs it in the database and file.
	 *
	 * This is used when the request needs to be redirected (e.g. admin secret URL parameter), or when we are only
	 * logging potential problems (e.g. failed login).
	 *
	 * @param   string       $reason                    Block reason code
	 * @param   string|null  $extraLogInformation       Extra information to be written to the text log file
	 * @param   string|null  $extraLogTableInformation  Extra information to be written to the extradata field of the
	 *                                                  log
	 *
	 * Two exemption tiers apply (gh-382; see .claude/memory/waf-exemption-tiers.md):
	 * - Allow (Administrator Exclusive Allow IP List, Site IP Allow List): returns false; the caller must not block.
	 * - Never block (Never block these IPs, crawlers set to "Do not block", Never block these domains): returns true,
	 *   so the caller still blocks the request, but nothing is logged or emailed and no IP auto-ban is processed.
	 *
	 * @return  bool  False if the current IP address is allowed (must not be blocked); true otherwise.
	 */
	public function logRequest(
		string $reason, ?string $extraLogInformation = '', ?string $extraLogTableInformation = ''
	): bool
	{
		if ($this->isAllowedIP())
		{
			return false;
		}

		// Never-block tier: the request is still blocked, it is just never logged, so the IP is never auto-banned.
		if ($this->isNeverBlockedIP())
		{
			return true;
		}

		$extraLogInformation      ??= '';
		$extraLogTableInformation ??= '';

		// Collect the information we need to log
		$reasonAsText = $this->blockingReasonToHumanReadableText($reason, $extraLogTableInformation);
		$tokens       = $this->getEmailVariables(['REASON' => $reasonAsText]);

		// Should I log the request, based on its reason?
		if ($this->shouldLogThisReason($reason))
		{
			// Log to file
			$this->logRequestToFile($reason, $extraLogInformation, $reasonAsText, $tokens);

			// Log to the database table
			$this->logRequestToDatabase($reason, $extraLogTableInformation, $tokens);

			// Process automatic temporary and permanent IP blocking for repeat offenders
			$this->processIPAutoBan($reason);

			// Notify about a deluge of blocked requests
			$this->conditionalEmailOnBlockedRequestsDeluge();
		}

		// Send the email about the blocked request, if necessary
		if ($this->shouldEmailAboutBlockedRequest($reason))
		{
			$this->sendBlockedRequestEmail($reason, $tokens);
		}

		return true;
	}

	/**
	 * Logs the request, processes the IP auto-ban, and blocks the request.
	 *
	 * This always ends up with a blocked request (HTTP 403).
	 *
	 * This is the full request blocking experience, triggered when we need to immediately abort the request in
	 * progress
	 * to prevent a security issue from affecting the application.
	 *
	 * @param   string       $reason                    Block reason code
	 * @param   string|null  $message                   The message to be shown to the user
	 * @param   string|null  $extraLogInformation       Extra information to be written to the text log file
	 * @param   string|null  $extraLogTableInformation  Extra information to be written to the extradata field of the
	 *                                                  log table (useful for JSON format)
	 *
	 * @return  void  This function never returns BUT may throw an exception, hence not `never-return`.
	 * @throws  Exception
	 */
	public function blockRequest(
		string $reason = 'other', ?string $message = '', ?string $extraLogInformation = '',
		?string $extraLogTableInformation = ''
	): void
	{
		if (!$this->logRequest($reason, $extraLogInformation ?? '', $extraLogTableInformation ?? ''))
		{
			// Allow tier (Site IP Allow List, Administrator Exclusive Allow IP List): do not block. The Never-block tier
			// returns true from logRequest() and IS blocked, just not logged.
			return;
		}

		$message = $message
			?: trim($this->wafParams->getValue('custom403msg', ''))
				?: 'PLG_ADMINTOOLS_MSG_BLOCKED';

		// Merge the default translation with the current translation
		/** @var CMSApplication $app */
		$app = $this->getApplication();

		if ((Text::_('PLG_ADMINTOOLS_MSG_BLOCKED') == 'PLG_ADMINTOOLS_MSG_BLOCKED')
		    && ($message == 'PLG_ADMINTOOLS_MSG_BLOCKED'))
		{
			$message = "Access Denied";
		}
		else
		{
			$message = Text::_($message);
		}

		$message = RescueUrl::processRescueInfoInMessage($message);

		// Show the 403 message
		$use403View = $this->wafParams->getValue('use403view', 0);
		$isFrontend = $app->isClient('site');
		$isApi      = $app->isClient('api');

		if ($isApi)
		{
			@ob_end_clean();

			header('HTTP/1.1 403 Access Denied');

			echo $message;

			$app->close();
		}

		if (!$use403View || !$isFrontend)
		{
			// Using Joomla!'s error page
			$app->getInput()->set('template', null);
			$app->getInput()->set('layout', null);

			throw new Exception($message, 403);
		}

		// Using a view
		$session = $app->getSession();

		if (!$session->get('com_admintools.block', false))
		{
			// This is inside an if-block so that we don't end up in an infinite redirection loop
			$session->set('com_admintools.block', true);
			$session->set('com_admintools.message', $message);

			if ($app->isClient('site') || $app->isClient('administrator'))
			{
				$session->close();
			}

			$app->redirect(Uri::base(), 307);
		}
	}

	/**
	 * Checks if the Rescue URL is being accessed.
	 *
	 * This only applies when IP autoban is enabled and this is an administrator access.
	 *
	 * @return  void
	 */
	public function checkRescueURL(): void
	{
		$autoban = $this->wafParams->getValue('tsrenable', 0);

		if (!$autoban)
		{
			return;
		}

		// If IP auto-ban is enabled we need to check for a Rescue URL
		RescueUrl::processRescueURL($this);
	}

	public function setApplication(ApplicationInterface $application): void
	{
		$this->application = $application;
	}

	private function getApplication(): ApplicationInterface
	{
		return $this->application;
	}

	/**
	 * Get the variables we can use in emails as an associative list (variable => value).
	 *
	 * @param   array  $customVariables  An array of custom variables to add to the return.
	 *
	 * @return  array
	 */
	private function getEmailVariables($customVariables = [])
	{
		$app      = $this->getApplication();
		$siteName = $app->get('sitename');
		$cParams  = ComponentHelper::getParams('com_admintools');
		$emailTz  = $cParams->get('email_timezone', 'AKEEBA/DEFAULT');
		$app      = $this->getApplication();
		$userTz   = $app->getIdentity()->timezone ?? $app->get('offset', 'GMT');

		try
		{
			$timezone = new DateTimeZone($userTz);
		}
		catch (Exception $e)
		{
			$timezone = null;
		}

		if (!empty($emailTz) && ($emailTz != 'AKEEBA/DEFAULT'))
		{
			try
			{
				$forcedTimezone = new DateTimeZone($emailTz);
				$timezone       = $forcedTimezone;
			}
			catch (Exception $e)
			{
				// Just in case someone puts an invalid timezone in there (you can never be too paranoid).
			}
		}

		$date = clone Factory::getDate();
		$date->setTimezone($timezone ?: new DateTimeZone('GMT'));

		$ip = $this->getVisitorIPAddress() ?: '0.0.0.0';

		if ((strpos($ip, '::') === 0) && (strstr($ip, '.') !== false))
		{
			$ip = substr($ip, strrpos($ip, ':') + 1);
		}

		$currentUser = $app->getIdentity();

		if ($currentUser->guest)
		{
			$currentUser = 'Guest';
		}
		else
		{
			$currentUser = sprintf(
				"%s (%s <%s>)",
				$currentUser->username,
				$currentUser->name,
				$currentUser->email
			);
		}

		$ipLookupURL = 'https://' . $this->wafParams->getValue('iplookup', 'whatismyipaddress.com/ip/{ip}');
		$ipLookupURL = str_replace('{ip}', $ip, $ipLookupURL);
		$uri         = Uri::getInstance();
		$url         = $uri->toString(['scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment']);

		return array_merge(
			[
				'USER'     => $currentUser,
				'SITENAME' => $siteName,
				'DATE'     => ($date)->format('Y-m-d H:i:s T', true),
				'IP'       => $ip,
				'URL'      => $url,
				'LOOKUP'   => $ipLookupURL,
				'UA'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
			], $customVariables
		);
	}

	/**
	 * Is this IP exempt from logging and automatic IP blocking, in either exemption tier?
	 *
	 * True for the Allow tier (see isAllowedIP()) and the Never-block tier (see isNeverBlockedIP()). Use this to decide
	 * whether an IP may be logged or auto-banned, or whether an IP-list based block (crawler Block) should yield. Do NOT
	 * use it to decide whether a malicious request is blocked: the Never-block tier must still be blocked.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function isExemptIP(): bool
	{
		return $this->isAllowedIP() || $this->isNeverBlockedIP();
	}

	/**
	 * Allow tier: the IP is never blocked, logged or auto-banned.
	 *
	 * True when we cannot get the user's IP address, or the IP is in the Administrator Exclusive Allow IP List or the
	 * Site IP Allow List.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private function isAllowedIP(): bool
	{
		$ip = $this->getVisitorIPAddress();

		return empty($ip)
		       || $this->isIPInAdminExclusiveAllowList()
		       || $this->isIPInSiteAllowList();
	}

	/**
	 * Never-block tier: malicious requests ARE blocked, but never logged or emailed, and the IP is never auto-banned.
	 *
	 * True when the IP is in the "Never block these IPs" list, in the ranges of a crawler set to "Do not block" (Pro
	 * only), or resolves to a domain in the "Never block these domains" list.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private function isNeverBlockedIP(): bool
	{
		$ip = $this->getVisitorIPAddress();

		if (empty($ip))
		{
			return false;
		}

		return $this->isNeverBlockTheseIPs()
		       || $this->isCrawlerNeverBlock($ip)
		       || $this->isNeverBlockedDomain($ip);
	}

	/**
	 * Checks if an IP address should be automatically banned.
	 *
	 * This checks both for temporary and permanent IP blocking.
	 *
	 * @param   string  $reason  The reason of the ban
	 *
	 * @return  void
	 */
	private function processIPAutoBan($reason = 'other')
	{
		// The Core version does not support auto-banning IP addresses
		if (!defined('ADMINTOOLS_PRO') || !ADMINTOOLS_PRO)
		{
			return;
		}

		// Is the feature enabled
		if (!$this->wafParams->getValue('tsrenable', 0))
		{
			return;
		}

		// Get the IP
		$ip = $this->getVisitorIPAddress();

		// No point continuing if we can't get an address, right?
		if (empty($ip) || $ip === '0.0.0.0')
		{
			return;
		}

		// Get the database object
		try
		{
			/** @var DatabaseDriver $db */
			$db = $this->getDatabase();
		}
		catch (Throwable $e)
		{
			// Failure to get the database prevents anything else from working correctly.
			return;
		}

		$this->lockTables(
			['#__admintools_ipautoban', '#__admintools_ipautobanhistory', '#__admintools_ipblock', '#__admintools_log']
		);

		try
		{
			$until            = null;
			$isRepeatOffender = $this->isRepeatOffender($db, $ip, $reason, $until);
		}
		catch (Exception $e)
		{
			$isRepeatOffender = false;
		}
		finally
		{
			$this->unlockTables();
		}

		if (!$isRepeatOffender)
		{
			return;
		}

		// Should I send an optional email?
		if ($this->wafParams->getValue('emailafteripautoban', ''))
		{
			$this->sendIPAutoBanEmail($reason, $until);
		}
	}

	/**
	 * Immediately bans the visitor's IP address without waiting for the repeat-offender threshold.
	 *
	 * This is intended for clear-cut attack signals (e.g. login with a forbidden username that has no matching user)
	 * where waiting for the normal strike count would be inappropriate.
	 *
	 * Requires IP Blocking of Repeat Offenders to be enabled (tsrenable). Pro version only.
	 *
	 * @param   string  $reason  The reason code for the ban
	 *
	 * @return  void
	 */
	public function immediateIPAutoBan(string $reason): void
	{
		if (!defined('ADMINTOOLS_PRO') || !ADMINTOOLS_PRO)
		{
			return;
		}

		if (!$this->wafParams->getValue('tsrenable', 0))
		{
			return;
		}

		$ip = $this->getVisitorIPAddress();

		if (empty($ip) || $ip === '0.0.0.0')
		{
			return;
		}

		// Neither exemption tier may ever be auto-banned (gh-382).
		if ($this->isExemptIP())
		{
			return;
		}

		try
		{
			/** @var DatabaseDriver $db */
			$db = $this->getDatabase();
		}
		catch (Throwable $e)
		{
			return;
		}

		$this->lockTables(
			['#__admintools_ipautoban', '#__admintools_ipautobanhistory', '#__admintools_ipblock', '#__admintools_log']
		);

		try
		{
			$until   = null;
			$wasBanned = $this->banIPAddress($db, $ip, $reason, $until);
		}
		catch (Exception $e)
		{
			$wasBanned = false;
		}
		finally
		{
			$this->unlockTables();
		}

		if (!$wasBanned)
		{
			return;
		}

		if ($this->wafParams->getValue('emailafteripautoban', ''))
		{
			$this->sendIPAutoBanEmail($reason, $until);
		}
	}

	/**
	 * Get the visitor IP address.
	 *
	 * Return null if we cannot get an IP address or if we get 0.0.0.0 (broken IP forwarding).
	 *
	 * @return  null|string
	 */
	private function getVisitorIPAddress(): ?string
	{
		// Get our IP address
		try
		{
			$ip = Filter::getIp();
		}
		catch (Throwable $e)
		{
			return null;
		}

		if ((strpos($ip, '::') === 0) && (strstr($ip, '.') !== false))
		{
			$ip = substr($ip, strrpos($ip, ':') + 1);
		}

		// No point continuing if we can't get an address, right?
		if (empty($ip) || ($ip == '0.0.0.0'))
		{
			return null;
		}

		return $ip;
	}

	/**
	 * Is the IP address in the "Never block these IPs" (safe IPs) list?
	 *
	 * @return  bool
	 */
	private function isNeverBlockTheseIPs()
	{
		$safeIPs = $this->wafParams->getValue('neverblockips', '') ?: [];

		if (is_string($safeIPs))
		{
			$safeIPs = array_map('trim', explode(',', $safeIPs));
		}

		$safeIPs = array_map(
			function ($x) {
				return is_array($x) ? $x[0] : $x;
			}, is_array($safeIPs) ? $safeIPs : []
		);

		return !empty($safeIPs) && Filter::IPinList($safeIPs) ? true : false;
	}

	/**
	 * Is the IP address in the ranges of a crawler IP list set to "Do not block"? Pro only.
	 *
	 * The Utility directory ships in Core, the crawler IP helper does not, hence the checks. This is a cache read
	 * and a binary search, which is why it runs before the (DNS lookup based) Never block these domains check.
	 *
	 * @param   string  $ip  The visitor's IP address.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	private function isCrawlerNeverBlock($ip): bool
	{
		if (!defined('ADMINTOOLS_PRO') || !ADMINTOOLS_PRO)
		{
			return false;
		}

		$class = '\\Akeeba\\Component\\AdminTools\\Administrator\\Helper\\CrawlerIP\\CrawlerIPLists';

		if (!class_exists($class))
		{
			return false;
		}

		try
		{
			return (bool) $class::getInstance()->isIpInAllowLists((string) $ip);
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Is the IP address in the Administrator Exclusive Allow IP?
	 *
	 * @return  bool
	 */
	private function isIPInAdminExclusiveAllowList(): bool
	{
		if ($this->wafParams->getValue('ipwl', 0) != 1)
		{
			return false;
		}

		$ipTable = Cache::getCache('adminiplist');

		if (!empty($ipTable) && Filter::IPinList($ipTable))
		{
			return true;
		}

		return false;
	}

	/**
	 * Is the IP address in the Site IP Allow List?
	 *
	 * @return  bool
	 *
	 * @since   7.2.4
	 */
	private function isIPInSiteAllowList(): bool
	{
		$ipTable = Cache::getCache('ipallow');

		if (!empty($ipTable) && Filter::IPinList($ipTable))
		{
			return true;
		}

		return false;
	}

	/**
	 * Does the IP address resolve to a domain in the the Never Block These Domains list?
	 *
	 * @param   string  $ip
	 *
	 * @return  bool
	 */
	private function isNeverBlockedDomain($ip)
	{
		static $whitelistDomains = null;

		if (is_null($whitelistDomains))
		{
			$whitelistDomains = $this->wafParams->getValue('whitelist_domains', []);

			if (is_string($whitelistDomains))
			{
				$whitelistDomains = array_map('trim', explode(',', $whitelistDomains));
			}

			$whitelistDomains = array_map(
				function ($x) {
					return is_array($x) ? $x[0] : $x;
				}, is_array($whitelistDomains) ? $whitelistDomains : []
			);
		}

		return (new NeverBlockedDomainMatcher($whitelistDomains))->matches($ip);
	}

	/**
	 * Get the blocking reason in a human readable format
	 *
	 * @param   string  $reason
	 * @param   string  $extraLogTableInformation
	 *
	 * @return  string
	 */
	private function blockingReasonToHumanReadableText($reason, $extraLogTableInformation)
	{
		/**
		 * This text goes into the site's own log file and log table, so it is rendered in the site's current
		 * language — unlike an email, which is pinned to its recipient's language. We still resolve it through an
		 * isolated Language instance rather than loading our strings into the global one, because that load has no
		 * matching unload and would leave the rest of the request speaking a language nobody asked for.
		 */
		$appLang = $this->getApplication()->getLanguage();
		$tag     = is_object($appLang) ? $appLang->getTag() : EmailLanguage::FALLBACK_TAG;

		// Get the reason in human readable format
		$txtReason = EmailLanguage::getLanguage($tag)->_('COM_ADMINTOOLS_LOG_LBL_REASON_' . strtoupper($reason));

		if (empty($extraLogTableInformation))
		{
			return $txtReason;
		}

		// Get extra information
		[$logReason,] = explode('|', $extraLogTableInformation);

		return $txtReason . " ($logReason)";
	}

	/**
	 * Should I log a potentially malicious request with the specified reason?
	 *
	 * @param   string  $reason  The reason to check.
	 *
	 * @return  bool
	 */
	private function shouldLogThisReason(string $reason): bool
	{
		// If logging is disabled entirely, we should not log anything.
		if (!$this->wafParams->getValue('logbreaches', 0))
		{
			return false;
		}

		// Get the no logging reasons
		// The fallback mirrors the Configure WAF defaults; it only applies when the setting was never stored.
		$reasonsNoLog = $this->wafParams->getValue('reasons_nolog', ['ipbl', 'crawler']);

		// Handle legacy data
		if (is_string($reasonsNoLog))
		{
			$reasonsNoLog = explode(',', $reasonsNoLog);
		}

		$reasonsNoLog = is_array($reasonsNoLog) ? $reasonsNoLog : [];

		// We log as long as it isn't a no-log reason.
		return !in_array($reason, $reasonsNoLog);
	}

	/**
	 * Log a security exception to our log file
	 *
	 * @param   string       $reason
	 * @param   string|null  $extraLogInformation
	 * @param   string|null  $txtReason
	 * @param   array        $tokens
	 *
	 * @return  void
	 */
	private function logRequestToFile(string $reason, ?string $extraLogInformation, ?string $txtReason, array $tokens)
	{
		// Write to the log file only if we're told to
		if (!$this->wafParams->getValue('logfile', 0))
		{
			return;
		}

		// Get the log filename
		$logpath = $this->getApplication()->get('log_path');
		$fname   = $logpath . DIRECTORY_SEPARATOR . 'admintools_blocked.php';

		// -- Check the file size. If it's over 1Mb, archive and start a new log.
		if (@file_exists($fname))
		{
			$fsize = filesize($fname);

			if ($fsize > 1048756)
			{
				$altFile = substr($fname, 0, -4) . '.1.php';

				if (@file_exists($altFile))
				{
					unlink($altFile);
				}

				@copy($fname, $altFile);
				@unlink($fname);
			}
		}

		// If the main log file does not exist yet create a new one.
		if (!file_exists($fname))
		{
			$content = <<< END
php
/**
 * =====================================================================================================================
 * Admin Tools debug log file
 * =====================================================================================================================
 *
 * This file contains a dump of the requests which were blocked by Admin Tools. By definition, this file does contain
 * a lot of "hacking signatures" since this is what the Admin Tools component is designed to stop and this is the file
 * logging all these hacking attempts.
 *
 * You can disable the creation of this file by going to Components, Admin Tools, Web Application Firewall, Configure
 * WAF and setting the "Keep a debug log file" option to NO. This is the recommended setting. You should only set this
 * option to YES if you are troubleshooting an issue (Admin Tools is blocking access to your site).
 *
 * Some hosts will mistakenly report this file as suspicious or hacked. As a result they might issue an automated
 * warning and / or block access to your site. Should that happen please ask your host to look in this file and read
 * this header. This file is SAFE since the only executable statement is die() below which prevents the file from being
 * executed at all. If your host does not understand that this file is safe or does not know how to add an exception in
 * their automated scanner to exempt Joomla's log files (all files under this directory) from being flagged as hacked /
 * suspicious we strongly recommend going to a different host that understands how PHP works. It will be safer for you
 * as well. 
 */

die();
END;
			$content = "?$content?";
			$content .= ">\n\n";
			file_put_contents($fname, '<' . $content);
		}

		// -- Log the exception
		$fp = @fopen($fname, 'a');

		if ($fp === false)
		{
			return;
		}

		fwrite($fp, str_repeat('-', 79) . PHP_EOL);
		fwrite($fp, "Blocking reason: " . $reason . PHP_EOL . str_repeat('-', 79) . PHP_EOL);
		fwrite($fp, "Reason     : " . $txtReason . PHP_EOL);
		fwrite($fp, 'Timestamp  : ' . gmdate('Y-m-d H:i:s') . " GMT" . PHP_EOL);
		fwrite($fp, 'Local time : ' . $tokens['DATE'] . " " . PHP_EOL);
		fwrite($fp, 'URL        : ' . $this->escapeLogLine($tokens['URL']) . PHP_EOL);
		fwrite($fp, 'User       : ' . $this->escapeLogLine($tokens['USER']) . PHP_EOL);
		fwrite($fp, 'IP         : ' . $tokens['IP'] . PHP_EOL);
		fwrite($fp, 'UA         : ' . $this->escapeLogLine($tokens['UA']) . PHP_EOL);

		if (!empty($extraLogInformation))
		{
			fwrite($fp, $this->escapeLogLine($extraLogInformation) . PHP_EOL);
		}

		fwrite($fp, PHP_EOL . PHP_EOL);
		fclose($fp);
	}

	/**
	 * Replace CR and LF in attacker-controlled log data with the literal, visible sequences \r and \n.
	 *
	 * Without this, a request URL, username, or User-Agent containing a real CR/LF could forge extra log lines
	 * (log injection) in the plain-text debug log. Using visible escape sequences, rather than stripping the
	 * characters outright, keeps the original value fully legible for log analysis.
	 *
	 * @param   string  $value  The raw value about to be written to the log file.
	 *
	 * @return  string
	 * @since   7.9.3
	 */
	private function escapeLogLine(string $value): string
	{
		return str_replace(["\r", "\n"], ['\\r', '\\n'], $value);
	}

	/**
	 * Log a security exception to the database table
	 *
	 * @param   string  $reason
	 * @param   string  $extraLogInformation
	 * @param   array   $tokens
	 */
	private function logRequestToDatabase($reason, $extraLogTableInformation, $tokens)
	{
		try
		{
			/** @var DatabaseDriver $db */
			$db = $this->getDatabase();
		}
		catch (Throwable $e)
		{
			// Failure to get the database prevents anything else from working correctly.
			return;
		}

		$this->lockTables(
			['#__admintools_ipautoban', '#__admintools_ipautobanhistory', '#__admintools_ipblock', '#__admintools_log']
		);

		try
		{
			$date = clone Factory::getDate();
			$url  = $tokens['URL'];

			if (strlen($url) > 10240)
			{
				$url = substr($url, 0, 10240);
			}

			$logEntry = (object) [
				'logdate'   => $date->toSql(),
				'ip'        => $tokens['IP'],
				'url'       => $url,
				'reason'    => $reason,
				'extradata' => $extraLogTableInformation,
			];

			$db->insertObject('#__admintools_log', $logEntry);
		}
		catch (Exception $e)
		{
			/**
			 * During high intensity attacks we might get a deadlock in the database, which causes an exception to be
			 * raised. We just need to ignore it, and unlock the tables.
			 */
		}
		finally
		{
			$this->unlockTables();
		}
	}

	private function shouldEmailAboutBlockedRequest(string $reason): bool
	{
		// Cannot send email if not email address is entered
		$emailOnException = $this->wafParams->getValue('emailbreaches', '');

		if (empty($emailOnException))
		{
			return false;
		}

		// Cannot email if it's a no-email reason
		// The fallback mirrors the Configure WAF defaults; it only applies when the setting was never stored.
		$reasonsNoEmail = $this->wafParams->getValue('reasons_noemail', ['ipbl', 'crawler']) ?: [];
		$reasonsNoEmail = is_string($reasonsNoEmail) ? explode(',', $reasonsNoEmail) : $reasonsNoEmail;

		return !in_array($reason, $reasonsNoEmail);
	}

	/**
	 * Sends an email about a blocked request
	 *
	 * @param   string  $reason
	 * @param   array   $tokens
	 *
	 * @return  void
	 */
	private function sendBlockedRequestEmail($reason, $tokens)
	{
		$emailOnException = $this->wafParams->getValue('emailbreaches', '');

		// Send the email
		try
		{
			$recipients = explode(',', $emailOnException);
			$recipients = array_map('trim', $recipients);

			foreach ($recipients as $recipient)
			{
				if (empty($recipient))
				{
					continue;
				}

				// Resolves to the real account when the configured address belongs to one, so that account's
				// language preference decides the language of the email.
				$recipientUser = EmailLanguage::userForEmail($recipient);

				if (!$this->isSendingAllowedByEmailThrottling())
				{
					continue;
				}

				$this->sendEmail(
					'com_admintools.blockedrequest',
					$recipientUser,
					// {RESCUEINFO} is a translated string, so it must be built in the recipient's language.
					fn($language) => array_merge(
						$tokens,
						RescueUrl::getRescueInformation($recipient),
						[
							'REASON' => $reason,
						]
					)
				);
			}
		}
		catch (Exception $e)
		{
		}
	}

	/**
	 * Is sending an email allowed by the email throttling feature?
	 *
	 * @return  bool
	 *
	 * @since   7.2.2
	 */
	private function isSendingAllowedByEmailThrottling(): bool
	{
		// TODO Needs table locking

		$cParams = ComponentHelper::getParams('com_admintools');

		//  If the throttling feature is disabled allow sending the email.
		if ($cParams->get('email_throttle', 1) != 1)
		{
			return true;
		}

		// Get the frequency limit options
		$maxAllowedEmails = $cParams->get('email_num', 5);
		$timePeriod       = $cParams->get('email_numfreq', 15);
		$timeUOM          = $cParams->get('email_freq', 'minutes');

		switch ($timeUOM)
		{
			case 'seconds':
				$earliestDate = Factory::getDate()->sub(new \DateInterval('PT' . $timePeriod . 'S'));
				break;

			case 'minutes':
				$earliestDate = Factory::getDate()->sub(new \DateInterval('PT' . $timePeriod . 'M'));
				break;

			case 'hours':
				$earliestDate = Factory::getDate()->sub(new \DateInterval('PT' . $timePeriod . 'H'));
				break;

			case 'days':
				$earliestDate = Factory::getDate()->sub(new \DateInterval('P' . $timePeriod . 'D'));
				break;

			case 'ever':
			default:
				$earliestDate = Factory::getDate('2000-01-01 00:00:00');
				break;
		}

		$reasonsNoLog = $this->wafParams->getValue('reasons_nolog', ['ipbl', 'crawler']) ?: [];
		$reasonsNoLog = is_array($reasonsNoLog)
			? $reasonsNoLog
			: array_map('trim', @explode(',', $reasonsNoLog));

		/** @var DatabaseDriver $db */
		$db      = $this->getDatabase();
		$logDate = $earliestDate->toSql();
		$sql     = DbQuery::create($db)
			->select('COUNT(*)')
			->from($db->qn('#__admintools_log'))
			->where($db->qn('logdate') . ' >= :logDate')
			->bind(':logDate', $logDate);

		// Apply the where clause only if we have excluded any reason from logging
		if (!empty($reasonsNoLog))
		{
			$sql->whereNotIn($db->qn('reason'), $reasonsNoLog, ParameterType::STRING);
		}

		$db->setQuery($sql);

		try
		{
			$numOffenses = $db->loadResult() ?: 0;
		}
		catch (Exception $e)
		{
			$numOffenses = 0;
		}

		return $numOffenses <= $maxAllowedEmails;
	}

	/**
	 * Is the IP address specified a repeat offender? Also processes IP blocking.
	 *
	 * If the IP address is a repeat offender it is temporarily blocked. If too many temporary blocks have been issued,
	 * a permanent block will be issued (if configured).
	 *
	 * @param   DatabaseDriver  $db
	 * @param   string          $ip
	 * @param   string          $reason
	 * @param   string|null     $until
	 *
	 * @return  bool
	 */
	private function isRepeatOffender(
		DatabaseDriver $db, string $ip, string $reason, ?string &$until
	): bool
	{
		// Check for repeat offenses
		$strikes      = $this->wafParams->getValue('tsrstrikes', 3);
		$numfreq      = $this->wafParams->getValue('tsrnumfreq', 1);
		$frequency    = $this->wafParams->getValue('tsrfrequency', 'hour');
		$mindatestamp = 0;

		switch ($frequency)
		{
			case 'second':
				break;

			case 'minute':
				$numfreq *= 60;
				break;

			case 'hour':
				$numfreq *= 3600;
				break;

			case 'day':
				$numfreq *= 86400;
				break;

			case 'ever':
				$mindatestamp = 946706400; // January 1st, 2000
				break;
		}

		$jNow = clone Factory::getDate();

		if ($mindatestamp == 0)
		{
			$mindatestamp = $jNow->toUnix() - $numfreq;
		}

		$jMinDate = clone Factory::getDate($mindatestamp);
		$minDate  = $jMinDate->toSql();

		$sql = DbQuery::create($db)
			->select('COUNT(*)')
			->from($db->qn('#__admintools_log'))
			->where($db->qn('logdate') . ' >= :minDate')
			->where($db->qn('ip') . ' = :ip')
			->bind(':minDate', $minDate, ParameterType::STRING)
			->bind(':ip', $ip, ParameterType::STRING);
		$db->setQuery($sql);

		try
		{
			$numOffenses = $db->loadResult();
		}
		catch (Exception $e)
		{
			$numOffenses = 0;
		}

		if ($numOffenses < $strikes)
		{
			return false;
		}

		return $this->banIPAddress($db, $ip, $reason, $until);
	}

	/**
	 * Bans an IP address by inserting it into the auto-ban table.
	 *
	 * Handles ban duration calculation, permaban escalation, and cache invalidation.
	 * Called by both isRepeatOffender() (after threshold check) and immediateIPAutoBan() (direct ban).
	 *
	 * @param   DatabaseDriver  $db
	 * @param   string          $ip
	 * @param   string          $reason
	 * @param   string|null     $until
	 *
	 * @return  bool  False if the IP address is not valid.
	 */
	private function banIPAddress(DatabaseDriver $db, string $ip, string $reason, ?string &$until): bool
	{
		$myIP = @inet_pton($ip);

		if ($myIP === false)
		{
			return false;
		}

		$myIP = inet_ntop($myIP);

		$jNow      = clone Factory::getDate();
		$until     = $jNow->toUnix();
		$numfreq   = $this->wafParams->getValue('tsrbannum', 1);
		$frequency = $this->wafParams->getValue('tsrbanfrequency', 'hour');

		switch ($frequency)
		{
			case 'second':
				$until += $numfreq;
				break;

			case 'minute':
				$numfreq *= 60;
				$until   += $numfreq;
				break;

			case 'hour':
				$numfreq *= 3600;
				$until   += $numfreq;
				break;

			case 'day':
				$numfreq *= 86400;
				$until   += $numfreq;
				break;

			case 'ever':
				$until = 2145938400; // January 1st, 2038 (mind you, UNIX epoch runs out on January 19, 2038!)
				break;
		}

		$until = (clone Factory::getDate($until))->toSql();

		$record = (object) [
			'ip'     => $myIP,
			'reason' => $reason,
			'until'  => $until,
		];

		// If I'm here it means that we have to ban the user. Let's see if this is a simple autoban or
		// we have to issue a permaban as a result of several attacks
		if ($this->wafParams->getValue('permaban', 0))
		{
			// Ok I have to check the number of autoban
			$query = DbQuery::create($db)
				->select('COUNT(*)')
				->from($db->qn('#__admintools_ipautobanhistory'))
				->where($db->qn('ip') . ' = :myIP')
				->bind(':myIP', $myIP, ParameterType::STRING);

			try
			{
				$bans = $db->setQuery($query)->loadResult();
			}
			catch (Exception $e)
			{
				$bans = 0;
			}

			$limit = (int) $this->wafParams->getValue('permabannum', 0);

			if ($limit && ($bans >= $limit))
			{
				$block = (object) [
					'ip'          => $myIP,
					'description' => 'IP automatically blocked after being banned automatically ' . $bans . ' times',
				];

				try
				{
					$db->insertObject('#__admintools_ipblock', $block);
					Cache::resetCache('ipblock');
				}
				catch (Exception $e)
				{
					// This should never happen, however let's prevent a white page if anything goes wrong
				}
			}
		}

		try
		{
			$db->insertObject('#__admintools_ipautoban', $record);
			Cache::resetCache('ipautoban');
		}
		catch (Exception $e)
		{
			// If the IP was already blocked and I have to block it again, I'll have to update the current record
			$db->updateObject('#__admintools_ipautoban', $record, 'ip');
			Cache::resetCache('ipautoban');
		}

		return true;
	}

	/**
	 * @param   string       $reason
	 * @param   string|null  $until
	 *
	 * @return void
	 */
	private function sendIPAutoBanEmail(string $reason, ?string $until): void
	{
		$substitutions = [
			'REASON' => $reason,
			'UNTIL'  => $until,
		];

		// Send the email
		try
		{
			$recipients = explode(',', $this->wafParams->getValue('emailafteripautoban', ''));
			$recipients = array_map('trim', $recipients);

			foreach ($recipients as $recipient)
			{
				if (empty($recipient))
				{
					continue;
				}

				$recipientUser = EmailLanguage::userForEmail($recipient);

				$this->sendEmail(
					'com_admintools.ipautoban',
					$recipientUser,
					// {RESCUEINFO} is a translated string, so it must be built in the recipient's language.
					fn($language) => array_merge(RescueUrl::getRescueInformation($recipient), $substitutions)
				);
			}
		}
		catch (Exception $e)
		{
			// Joomla! 3.5 and later throw an exception when crap happens instead of suppressing it and returning false
		}
	}

	private function lockTables(array $tables): void
	{
		try
		{
			$db = $this->getDatabase();
		}
		catch (Throwable $e)
		{
			return;
		}

		$serverType = $db->getServerType();

		if (!in_array($serverType, ['mysql', 'postgresql']))
		{
			return;
		}

		/**
		 * MySQL.
		 *
		 * We use `SET autocommit = 0;` before locking the tables to start an implicit transaction. Note that START
		 * TRANSACTION would not work in this case.
		 *
		 * @see https://dev.mysql.com/doc/refman/8.0/en/lock-tables.html
		 */
		if ($serverType === 'mysql')
		{
			try
			{
				$db->setQuery('SET autocommit = 0')->execute();
			}
			catch (Exception $e)
			{
				return;
			}

			$sql = 'LOCK TABLES ' . implode(
					',',
					array_map(
						function ($table) use ($db) {
							if (substr($table, 0, 1) !== '`')
							{
								$table = $db->quoteName($table);
							}

							return $table . ' WRITE';
						},
						$tables
					)
				);

			try
			{
				$db->setQuery($sql)->execute();
			}
			catch (Exception $e)
			{
				return;
			}

			return;
		}

		/**
		 * PostgreSQL.
		 *
		 * We start a transaction (internally, it calls BEGIN WORK), then lock each table in ACCESS EXCLUSIVE MODE.
		 *
		 * @see https://www.postgresql.org/docs/current/sql-lock.html
		 */
		$db->transactionStart();

		foreach ($tables as $table)
		{
			/**
			 * Joomla tries to create a new transaction for each call to lockTable, causing a failure. Therefore, I
			 * have to run the LOCK TABLE command manually.
			 */
			$db->setQuery('LOCK TABLE ' . $db->quoteName($table) . ' IN ACCESS EXCLUSIVE MODE')->execute();
		}
	}

	private function unlockTables(): void
	{
		try
		{
			$db = $this->getDatabase();
		}
		catch (Throwable $e)
		{
			return;
		}

		$serverType = $db->getServerType();

		if (!in_array($serverType, ['mysql', 'postgresql']))
		{
			return;
		}

		/**
		 * MySQL.
		 *
		 * We have to manually commit the transaction, then unlock the tables, and finally re-enable AUTOCOMMIT.
		 *
		 * @see https://dev.mysql.com/doc/refman/8.0/en/lock-tables.html
		 */
		if ($serverType === 'mysql')
		{
			try
			{
				$db->setQuery('COMMIT')->execute();
			}
			catch (Exception $e)
			{
				// Ignore. We must reach the SET autocommit.
			}

			try
			{
				$db->unlockTables();
			}
			catch (Exception $e)
			{
				// Ignore. We must reach the SET autocommit.
			}

			try
			{
				$db->setQuery('SET autocommit = 1')->execute();
			}
			catch (Exception $e)
			{
				return;
			}

			return;
		}

		/**
		 * PostgreSQL.
		 *
		 * There is no UNLOCK TABLES command; we just commit the transaction.
		 *
		 * @see https://www.postgresql.org/docs/current/sql-lock.html
		 */
		$db->transactionCommit();
	}

	/**
	 * Notify by email if we're under a deluge of blocked requests.
	 *
	 * @return  void
	 * @since   7.6.3
	 */
	private function conditionalEmailOnBlockedRequestsDeluge(): void
	{
		$recipient             = trim($this->wafParams->getValue('delugeemail', '') ?? '');
		$num                   = (int) $this->wafParams->getValue('delugenum', 1000);
		$num                   = max(0, min($num, 1000000));
		$numFreq               = (int) $this->wafParams->getValue('delugenumfreq', 1);
		$numFreq               = max(1, min($numFreq, 40000));
		$frequency             = trim($this->wafParams->getValue('delugefrequency', 'hour') ?: 'hour');
		$frequency             = in_array($frequency, ['second', 'minute', 'hour', 'day']) ? $frequency : 'hour';
		$lastNotifiedTimestamp = (int) $this->wafParams->getValue('deluge_notified', 0);
		$lastNotifiedTimestamp = max(0, $lastNotifiedTimestamp);

		// This feature requires a recipient, and a number of requests between 1 and one million.
		if ($recipient == '' || $num < 1)
		{
			return;
		}

		switch ($frequency)
		{
			case 'second':
				$intervalExpression = 'PT' . $numFreq . 'S';
				$intervalInSeconds = $numFreq;
				break;

			case 'minute':
				$intervalExpression = 'PT' . $numFreq . 'M';
				$intervalInSeconds = $numFreq * 60;
				break;

			case 'hour':
				$intervalExpression = 'PT' . $numFreq . 'H';
				$intervalInSeconds = $numFreq * 3600;
				break;

			case 'day':
				$intervalExpression = 'P' . $numFreq . 'D';
				$intervalInSeconds = $numFreq * 86400;
				break;
		}

		// Note: the second expression is never evaluated; it's only here for static code analysis to work properly.
		if (!isset($intervalExpression) || !isset($intervalInSeconds))
		{
			return;
		}

		try
		{
			$interval = new \DateInterval($intervalExpression);
			$minDate  = (clone Factory::getDate())->sub($interval)->toSql();
		}
		catch (Throwable $e)
		{
			return;
		}

		$db = $this->getDatabase();

		/**
		 * Preliminary notification period check.
		 *
		 * If the last notified timestamp is within the frequency window we will exit immediately. This means that if
		 * the check frequency window is 6 hours, and the last time we sent an email was within the last 6 hours we will
		 * exit immediately, so as not to send an additional email.
		 *
		 * This is a _preliminary_ check, before we start performing any expensive database operations and date math. It
		 * will only trigger if it's been ‘a while’ since we last sent an email from this feature, and the database has
		 * settled enough for us to read its up-to-date contents. This is why we will check this *again* later on.
		 */
		if ($lastNotifiedTimestamp + $intervalInSeconds > time())
		{
			return;
		}

		// Lock tables. Prevents DB concurrency issues leading to multiple emails.
		$this->lockTables(['#__admintools_log', '#__admintools_storage']);

		// Let's count how many blocked requests we had in the previous time window.
		$sql = DbQuery::create($db)
			->select('COUNT(*)')
			->from($db->qn('#__admintools_log'))
			->where($db->qn('logdate') . ' >= :minDate')
			->bind(':minDate', $minDate, ParameterType::STRING);
		$db->setQuery($sql);

		try
		{
			$numBlocks = $db->loadResult();
		}
		catch (Throwable $e)
		{
			$numBlocks = 0;
		}

		// We're under the threshold? Let's stop here
		if ($numBlocks < $num)
		{
			$this->unlockTables();

			return;
		}

		/**
		 * Reload the WAF configuration.
		 *
		 * Our notification period check makes sense only if we reload the WAF configuration after locking tables.
		 *
		 * The idea is that in the time between Admin Tools initialising and reaching this point of execution another
		 * thread might have fully executed this feature and already sent an email. In this case, the WAF configuration
		 * we have read (which includes the last notification timestamp), is out of date.
		 *
		 * Now that we have locked the tables we are guaranteed that the information is up-to-date and immutable, so we
		 * need to reload it, and re-evaluate it.
		 */
		$this->wafParams->load();

		$lastNotifiedTimestamp = (int) $this->wafParams->getValue('deluge_notified', 0);
		$lastNotifiedTimestamp = max(0, $lastNotifiedTimestamp);

		if ($lastNotifiedTimestamp + $intervalInSeconds > time())
		{
			$this->unlockTables();

			return;
		}

		// If we are here, we are the thread which will send the email. Mark the time.
		$this->wafParams->setValue('deluge_notified', time(), true);

		// Finally, release the table lock. The critical path is over.
		$this->unlockTables();

		// Set up the data to include in the email
		$emailData = [
			'DATE' => $minDate,
			'NUM'  => $numBlocks,
		];

		// Send emails to all recipients
		try
		{
			$recipients = array_filter(array_map('trim', explode(',', $recipient)));

			foreach ($recipients as $recipient)
			{
				$this->sendEmail(
					'com_admintools.delugerequests',
					EmailLanguage::userForEmail($recipient),
					$emailData
				);
			}
		}
		catch (Throwable $e)
		{
			// Joomla! 3.5 and later throw an exception when crap happens instead of suppressing it and returning false
		}
	}
}
