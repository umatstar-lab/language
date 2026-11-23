<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\JoomlaFeatures;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\Helper\Extensions;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\UserGroups;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use Joomla\Database\ParameterType;

/**
 * The System - Debug plugin must be disabled, or limited to Super Users.
 *
 * Who sees the plugin's output is the intersection of two settings. The plugin's Access level decides whether Joomla
 * loads the plugin for a user at all; a user has an access level when any of their groups, or the groups' ancestors,
 * is in the level's rules. The plugin's Allowed Groups, when not empty, then requires the user to be directly in one of
 * those groups (the plugin compares against User::$groups, which holds direct memberships only).
 *
 * @since  7.10.0
 */
class DebugPlugin extends BuiltinCheck
{
	/** The System - Debug plugin */
	private const PLUGIN = 'plg_system_debug';

	protected $id = 'debug_plugin';

	protected $section = 'joomla';

	protected $langKey = 'DEBUGPLUGIN';

	protected $severity = Severity::CRITICAL;

	public function isApplicable(): bool
	{
		return Extensions::getId(self::PLUGIN) !== null;
	}

	public function run(): CheckResult
	{
		$plugin = Extensions::getRecord(self::PLUGIN);

		if ($plugin === null || !(int) $plugin->enabled)
		{
			return CheckResult::pass();
		}

		$params  = new Registry($plugin->params ?: '{}');
		$groups  = UserGroups::getAll();
		$exposed = self::exposedGroups(
			array_map(
				function (object $group) {
					return (int) $group->parent_id;
				},
				$groups
			),
			array_map('intval', (array) $params->get('filter_groups', [])),
			$this->getViewLevelRules((int) $plugin->access),
			UserGroups::superUserGroups()
		);

		if (empty($exposed))
		{
			return CheckResult::pass();
		}

		$titles = array_map(
			function (int $groupId) use ($groups) {
				return isset($groups[$groupId]) ? (string) $groups[$groupId]->title : (string) $groupId;
			},
			$exposed
		);
		sort($titles);

		return CheckResult::fail(
			$this->text('MSG_FAIL'),
			self::pathItems($titles),
			['disable'],
			count($titles)
		)->withLink('index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $plugin->extension_id, Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_EDIT_PLUGIN'));
	}

	public function getFixes(): array
	{
		return [
			new Fix('disable', $this->text('FIX'), '', 'primary', 'fa fa-toggle-off'),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		// Same as unpublishing it in the Plugins manager: access to that manager, and the Edit State permission.
		return $fixId === 'disable'
		       && $user->authorise('core.manage', 'com_plugins')
		       && $user->authorise('core.edit.state', 'com_plugins');
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		Extensions::setEnabled(self::PLUGIN, false);
		self::getComponent()->getCacheCleanerService()->clearGroups(['com_plugins']);

		return $this->text('FIX_DONE');
	}

	/**
	 * Which non-Super User groups can see the Debug plugin's output?
	 *
	 * @param   int[]  $parents          Group ID => parent group ID, for all groups
	 * @param   int[]  $filterGroups     The plugin's Allowed Groups; empty means everyone
	 * @param   int[]  $levelRules       Group IDs in the rules of the plugin's access level
	 * @param   int[]  $superUserGroups  IDs of the Super User groups
	 *
	 * @return  int[]  IDs of the groups whose members, other than Super Users, can see the output
	 * @since   7.10.0
	 */
	public static function exposedGroups(
		array $parents, array $filterGroups, array $levelRules, array $superUserGroups
	): array
	{
		$exposed = [];

		foreach (array_keys($parents) as $groupId)
		{
			$groupId = (int) $groupId;

			if (in_array($groupId, $superUserGroups, true))
			{
				continue;
			}

			if (!empty($filterGroups) && !in_array($groupId, $filterGroups, true))
			{
				continue;
			}

			// Walk up the group tree: the group, or any of its ancestors, must be in the access level's rules.
			$current = $groupId;
			$visited = [];

			while ($current > 0 && !isset($visited[$current]))
			{
				if (in_array($current, $levelRules, true))
				{
					$exposed[] = $groupId;

					break;
				}

				$visited[$current] = true;
				$current           = (int) ($parents[$current] ?? 0);
			}
		}

		return $exposed;
	}

	/**
	 * The group IDs in an access level's rules.
	 *
	 * @param   int  $accessLevel
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	private function getViewLevelRules(int $accessLevel): array
	{
		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->select($db->quoteName('rules'))
			->from($db->quoteName('#__viewlevels'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $accessLevel, ParameterType::INTEGER);

		$rules = json_decode((string) $db->setQuery($query)->loadResult(), true);

		return is_array($rules) ? array_map('intval', $rules) : [];
	}
}
