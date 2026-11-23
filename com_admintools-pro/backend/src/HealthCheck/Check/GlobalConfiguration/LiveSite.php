<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * $live_site should be empty in configuration.php.
 *
 * There is no one-click fix: emptying $live_site on a site behind a misconfigured proxy can make it unreachable.
 *
 * @since  7.10.0
 */
class LiveSite extends BuiltinCheck
{
	protected $id = 'live_site';

	protected $section = 'globalconfig';

	protected $langKey = 'LIVESITE';

	protected $severity = Severity::MEDIUM;

	public function getDescription(): string
	{
		$liveSite = trim((string) Factory::getApplication()->get('live_site', ''));
		$siteUrl  = rtrim($liveSite !== '' ? $liveSite : Uri::root(), '/');

		return $this->text('DESC', self::e($siteUrl));
	}

	public function run(): CheckResult
	{
		$liveSite = trim((string) Factory::getApplication()->get('live_site', ''));

		if ($liveSite === '')
		{
			return CheckResult::pass();
		}

		switch (self::compareHosts($liveSite, (string) ($_SERVER['HTTP_HOST'] ?? '')))
		{
			case 'match':
				return CheckResult::fail($this->text('MSG_MATCH'));

			case 'mismatch':
				return CheckResult::fail($this->text('MSG_MISMATCH'));

			default:
				return CheckResult::fail($this->text('MSG_NOHOST'));
		}
	}

	/**
	 * Compare the host name of $live_site with the HTTP Host header.
	 *
	 * @param   string  $liveSite    The $live_site value
	 * @param   string  $hostHeader  The HTTP Host header; empty if there is none
	 *
	 * @return  string  'match', 'mismatch' or 'nohost'
	 * @since   7.10.0
	 */
	public static function compareHosts(string $liveSite, string $hostHeader): string
	{
		$hostHeader = trim($hostHeader);

		if ($hostHeader === '')
		{
			return 'nohost';
		}

		$liveSite = trim($liveSite);

		if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $liveSite))
		{
			$liveSite = 'http://' . ltrim($liveSite, '/');
		}

		$liveHost    = strtolower((string) parse_url($liveSite, PHP_URL_HOST));
		$requestHost = strtolower((string) parse_url('http://' . $hostHeader, PHP_URL_HOST));

		return ($liveHost !== '' && $liveHost === $requestHost) ? 'match' : 'mismatch';
	}
}
