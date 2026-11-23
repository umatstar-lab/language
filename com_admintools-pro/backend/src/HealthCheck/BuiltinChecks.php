<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Files;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\JoomlaFeatures;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Other;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Scanner;
use Joomla\CMS\Language\Text;

/**
 * Admin Tools' own Site Health Check sections and checks.
 *
 * @since  7.10.0
 */
final class BuiltinChecks
{
	/**
	 * The built-in sections.
	 *
	 * @return  Section[]
	 * @since   7.10.0
	 */
	public static function getSections(): array
	{
		$sections = [
			'globalconfig' => 100,
			'joomla'       => 200,
			'files'        => 300,
			'scanner'      => 400,
			'other'        => 9000,
		];

		$ret = [];

		foreach ($sections as $id => $ordering)
		{
			$key   = 'COM_ADMINTOOLS_SITEHEALTHCHECK_SECTION_' . strtoupper($id);
			$ret[] = new Section($id, Text::_($key . '_TITLE'), Text::_($key . '_DESC'), $ordering);
		}

		return $ret;
	}

	/**
	 * The built-in checks, in display order within each section and severity.
	 *
	 * @return  CheckInterface[]
	 * @since   7.10.0
	 */
	public static function getChecks(): array
	{
		return [
			new GlobalConfiguration\DebugSystem(),
			new GlobalConfiguration\DebugLanguage(),
			new GlobalConfiguration\LiveSite(),
			new GlobalConfiguration\ErrorReporting(),
			new GlobalConfiguration\SharedSessions(),
			new GlobalConfiguration\StraySessionName(),

			new JoomlaFeatures\DebugPlugin(),
			new JoomlaFeatures\AdminUser(),
			new JoomlaFeatures\UserRegistration(),
			new JoomlaFeatures\BackendUserCount(),
			new JoomlaFeatures\BackendMfa(),
			new JoomlaFeatures\AdminPasswordProtection(),
			new JoomlaFeatures\SecretUrlParameter(),
			new JoomlaFeatures\NeverLoggedIn(),
			new JoomlaFeatures\SuperUserFrontendLogin(),
			new JoomlaFeatures\BackendUsersFrontendLogin(),

			new Files\Kickstart(),
			new Files\InstallationDirectory(),
			new Files\TempInstallFolders(),
			new Files\UnneededCoreFiles(),
			new Files\DebugLogFile(),

			new Scanner\ScannerRecency(),
			new Scanner\NonCoreInCoreFolders(),
			new Scanner\PhpInMediaFolders(),
			new Scanner\ServerConfigFiles(),
			new Scanner\VersionedNames(),
			new Scanner\LargeFiles(),
			new Scanner\HiddenFiles(),

			new Other\TemporarySuperUsers(),
			new Other\FilePermissions(),
			new Other\TempAndCacheFolders(),
		];
	}
}
