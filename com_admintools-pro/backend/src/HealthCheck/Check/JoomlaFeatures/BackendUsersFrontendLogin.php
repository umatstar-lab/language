<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\JoomlaFeatures;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\UserGroups;
use Joomla\CMS\Access\Access;

/**
 * Backend users, other than Super Users, must not be able to log into the frontend.
 *
 * Super Users are covered by the Forbid frontend Super Administrator login check, so they are left out here.
 *
 * @since  7.10.0
 */
class BackendUsersFrontendLogin extends BuiltinCheck
{
	protected $id = 'backend_users_frontend_login';

	protected $section = 'joomla';

	protected $langKey = 'BACKENDUSERSFRONTENDLOGIN';

	protected $severity = Severity::MEDIUM;

	public function isExpensive(): bool
	{
		return true;
	}

	public function run(): CheckResult
	{
		$allGroups   = UserGroups::getAll();
		$superGroups = UserGroups::superUserGroups();
		$groups      = array_filter(
			UserGroups::allowedTo('core.login.admin'),
			function (int $groupId) use ($superGroups) {
				return !in_array($groupId, $superGroups, true) && Access::checkGroup($groupId, 'core.login.site', 1) === true;
			}
		);

		// Super Users are covered by the Forbid frontend Super Administrator login check.
		$users = array_diff_key(UserGroups::getActiveUsers($groups), UserGroups::getActiveUsers($superGroups));

		if (empty($users))
		{
			return CheckResult::pass();
		}

		$titles = array_map(
			function (int $groupId) use ($allGroups) {
				return isset($allGroups[$groupId]) ? (string) $allGroups[$groupId]->title : (string) $groupId;
			},
			array_values($groups)
		);
		sort($titles);

		return CheckResult::fail(
			$this->text('MSG_FAIL', count($users)),
			self::pathItems($titles),
			[],
			count($titles)
		)->withLink('index.php?option=com_config#page-permissions', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_PERMISSIONS'));
	}
}
