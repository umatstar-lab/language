<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Admin Tools' per-view ACL: which privilege each view (and task) requires, and whether a user holds it.
 *
 * This is the single source of truth for controllers (ControllerCustomACLTrait), for hiding the UI entry points of
 * features a user cannot use (Control Panel buttons), and for anything else which links to a feature.
 *
 * @since  7.10.0
 */
final class Acl
{
	/**
	 * Resolves the ACL privilege required to access a given view (optionally for a specific task).
	 *
	 * Views not listed in the map are limited by the default `admintools.utils` privilege.
	 *
	 * The view and task names are matched case-insensitively. Joomla's `BaseController::getName()`
	 * returns a lowercased controller name, so the map keys are normalised to lowercase with
	 * `array_change_key_case()` to guarantee a match — otherwise every lookup would miss and silently
	 * fall back to `admintools.utils`, defeating the stricter `admintools.security` /
	 * `admintools.maintenance` gating.
	 *
	 * The view name is normalised with {@see self::normaliseName()} first, so that it resolves to the privilege of the
	 * class Joomla will actually load for it.
	 *
	 * @param   string|null  $view  The view name (defaults to "controlpanel" when null).
	 * @param   string|null  $task  The task name (defaults to "main" when null).
	 *
	 * @return  string  The required ACL privilege.
	 */
	public static function getRequiredPrivilege(?string $view, ?string $task = null): string
	{
		$viewACLMap = [
			'Emergencyoffline'           => 'admintools.security',
			// The Site Health Check reveals the site's security posture; its one-click fixes check their own permissions.
			'Sitehealthcheck'            => 'admintools.security',
			'Mainpassword'               => 'admintools.security',
			'Adminpassword'              => 'admintools.security',
			// Alias of Htaccessmaker for authorisation purposes; must track its privilege.
			'ConfigMaker'                => 'admintools.security',
			/**
			 * The base controller of the three server configuration makers below. It is reachable on its own
			 * (controller=serverconfigmaker), and then loads the model and view of the request's `view`, so it must not
			 * fall back to the laxer `admintools.utils` default.
			 */
			'Serverconfigmaker'          => 'admintools.security',
			'Htaccessmaker'              => 'admintools.security',
			'Webconfigmaker'             => 'admintools.security',
			'Nginxconfmaker'             => 'admintools.security',
			'Webapplicationfirewall'     => 'admintools.security',
			'Configurewaf'               => 'admintools.security',
			'Wafexceptions'              => 'admintools.security',
			'Wafdenylists'               => 'admintools.security',
			'Adminallowlists'            => 'admintools.security',
			'Disallowlists'              => 'admintools.security',
			'Badwords'                   => 'admintools.security',
			'Blockedrequestslog'         => 'admintools.security',
			'Autobannedaddresses'        => 'admintools.security',
			'Ipautobanhistories'         => 'admintools.security',
			'Unblockip'                  => 'admintools.security',
			'Scanalerts'                 => 'admintools.security',
			'Scanner'                    => 'admintools.security',
			'Scans'                      => 'admintools.security',
			'Configurepermissions'       => 'admintools.maintenance',
			'Fixpermissions'             => 'admintools.maintenance',
			'Tempsuperusers'             => 'admintools.security',
			'Seoandlinktools'            => 'admintools.utils',
			'Cleantempdirectory'         => 'admintools.maintenance',
			'Checktempandlogdirectories' => 'admintools.maintenance',
			/**
			 * Removing obsolete Joomla core files deletes files and directories under the site's root. That is the
			 * same kind of destructive maintenance as the features around it, so it gets the same privilege — not the
			 * laxer `admintools.utils` default.
			 */
			'Obsoletecore'               => 'admintools.maintenance',
			'Databasetools'              => 'admintools.maintenance',
			'Urlredirections'            => 'admintools.maintenance',
			'Exportimport'               => 'admintools.security',
			'Quickstart'                 => 'admintools.security',
			'Schedulinginformation'      => 'admintools.security',

			// Site IP Allow List (WAF bypass IPs). List + item are security-sensitive.
			'Allowlists'                 => 'admintools.security',
			'Allowlist'                  => 'admintools.security',

			/**
			 * Singular item (FormController) controllers MUST stay in sync with their plural list
			 * counterparts above. BaseController::getName() returns the singular name for these,
			 * so without an explicit entry they would silently fall back to the laxer
			 * `admintools.utils`, letting a `utils`-only user create/edit/delete records (e.g. WAF
			 * exceptions, deny lists, even temporary Super Users) that listing them requires
			 * `admintools.security` to see.
			 */
			'Wafexception'               => 'admintools.security',
			'Wafdenylist'                => 'admintools.security',
			'Disallowlist'               => 'admintools.security',
			'Adminallowlist'             => 'admintools.security',
			'Badword'                    => 'admintools.security',
			'Scanalert'                  => 'admintools.security',
			'Scan'                       => 'admintools.security',
			'Tempsuperuser'              => 'admintools.security',
			'Urlredirection'             => 'admintools.maintenance',

			// Email templates for security alerts are a general utility.
			'Emailtemplates'             => 'admintools.utils',

			// Resetting/triggering the Joomla! update is reserved for global Super Users.
			'Jupdate'                    => 'core.admin',

			// The Control Panel and its quick-actions are available to anyone who can access the
			// component, i.e. everyone holding `core.manage` on com_admintools (the backend-access
			// permission the Dispatcher already enforces).
			'Controlpanel'               => 'core.manage',

			// Turning off the WAF debug log file (optionally deleting its files) changes WAF configuration.
			'Controlpanel.disableDebugLogFile'          => 'admintools.security',
			'Controlpanel.disableDebugLogFileAndDelete' => 'admintools.security',
			// Hiding the debug log file reminder needs admintools.security OR core.options. The map holds a single
			// privilege, so the baseline is core.manage and the task itself enforces the OR.
			'Controlpanel.disableDebugLogFileNotice'    => 'core.manage',
		];

		// Normalise the map keys to lowercase so they match the lowercased view/task lookup keys.
		$viewACLMap = array_change_key_case($viewACLMap, CASE_LOWER);

		$view = self::normaliseName($view ?? 'controlpanel');
		$task = strtolower($task ?? 'main');

		// Default
		$privilege = 'admintools.utils';

		// Just the view was found
		if (array_key_exists($view, $viewACLMap))
		{
			$privilege = $viewACLMap[$view];
		}

		// The view AND task was found
		if (array_key_exists($view . '.' . $task, $viewACLMap))
		{
			$privilege = $viewACLMap[$view . '.' . $task];
		}

		return $privilege;
	}


	/**
	 * Normalises a controller or view name the way Joomla does before it loads the class.
	 *
	 * Joomla's MVCFactory strips everything but [A-Za-z0-9_] from controller, view and model names, and the request
	 * filter (`cmd`) lets `-` and `.` through. Without the same normalisation `view=temp-superusers` loads the
	 * Tempsuperusers controller and view, but finds no entry in the privilege map and falls back to the
	 * `admintools.utils` default. A `.` would also be confused with the view.task separator of the map.
	 *
	 * @param   string|null  $name  The controller or view name, as found in the request
	 *
	 * @return  string  Lowercase; empty if nothing is left
	 * @since   7.10.0
	 */
	public static function normaliseName(?string $name): string
	{
		return strtolower(preg_replace('/[^A-Z0-9_]/i', '', $name ?? ''));
	}

	/**
	 * Does the user hold an Admin Tools privilege?
	 *
	 * `core.admin` is a GLOBAL Super User check, not the component-level admin permission. Authorising it against the
	 * `com_admintools` asset would instead test the (configurable) "Configure ACL & Options" permission on the
	 * component, so it is checked globally. An empty privilege means no check at all.
	 *
	 * @param   string     $privilege  e.g. admintools.security, core.manage, core.admin
	 * @param   User|null  $user       NULL for the current user
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isAuthorised(string $privilege, ?User $user = null): bool
	{
		if ($privilege === '')
		{
			return true;
		}

		$user = $user ?? Factory::getApplication()->getIdentity();

		if (!$user)
		{
			return false;
		}

		return ($privilege === 'core.admin')
			? (bool) $user->authorise('core.admin')
			: (bool) $user->authorise($privilege, 'com_admintools');
	}

	/**
	 * May the user access a view (optionally, a specific task of it)?
	 *
	 * @param   string       $view  The canonical controller name, e.g. Configurepermissions
	 * @param   string|null  $task  The task, if it matters
	 * @param   User|null    $user  NULL for the current user
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function canAccessView(string $view, ?string $task = null, ?User $user = null): bool
	{
		return self::isAuthorised(self::getRequiredPrivilege($view, $task), $user);
	}
}
