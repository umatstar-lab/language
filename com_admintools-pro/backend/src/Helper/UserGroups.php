<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\UserGroupsHelper;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * User group and site-wide permission lookups, built on Joomla's UserGroupsHelper and Access APIs.
 *
 * It is a static helper, not a DI service, because the system plugin's WAF features use it as well, and they must not
 * depend on booting the component.
 *
 * Group permissions are always evaluated against the root asset, i.e. site-wide.
 *
 * @since  7.10.0
 */
final class UserGroups
{
	/**
	 * Group IDs allowed each action, by action. Per-request cache.
	 *
	 * @var   array
	 * @since 7.10.0
	 */
	private static $allowed = [];

	/**
	 * All user groups.
	 *
	 * @return  object[]  Group ID => object with id, parent_id and title
	 * @since   7.10.0
	 */
	public static function getAll(): array
	{
		$ret = [];

		foreach (UserGroupsHelper::getInstance()->getAll() as $group)
		{
			$ret[(int) $group->id] = $group;
		}

		return $ret;
	}

	/**
	 * IDs of all user groups.
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	public static function getAllIds(): array
	{
		return array_keys(self::getAll());
	}

	/**
	 * IDs of the groups allowed an action site-wide.
	 *
	 * @param   string  $action  e.g. core.admin, core.login.admin, core.login.site
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	public static function allowedTo(string $action): array
	{
		if (!isset(self::$allowed[$action]))
		{
			self::$allowed[$action] = array_values(
				array_filter(
					self::getAllIds(),
					function (int $groupId) use ($action) {
						return Access::checkGroup($groupId, $action, 1) === true;
					}
				)
			);
		}

		return self::$allowed[$action];
	}

	/**
	 * IDs of the Super User groups.
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	public static function superUserGroups(): array
	{
		return self::allowedTo('core.admin');
	}

	/**
	 * Does a group have backend access, either explicitly (Administrator Login) or as a Super User group?
	 *
	 * @param   int  $groupId
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isBackendGroup(int $groupId): bool
	{
		return Access::checkGroup($groupId, 'core.login.admin', 1) || Access::checkGroup($groupId, 'core.admin', 1);
	}

	/**
	 * IDs of the groups with backend access.
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	public static function backendGroups(): array
	{
		return array_values(array_unique(array_merge(self::superUserGroups(), self::allowedTo('core.login.admin'))));
	}

	/**
	 * IDs of the users who are directly members of any of the groups, whatever their account's state.
	 *
	 * @param   int[]  $groupIds
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	public static function getUserIds(array $groupIds): array
	{
		$userIds = [];

		foreach (array_unique(array_map('intval', $groupIds)) as $groupId)
		{
			$userIds = array_merge($userIds, Access::getUsersByGroup($groupId));
		}

		return array_values(array_unique(array_map('intval', $userIds)));
	}

	/**
	 * Active users who are directly members of any of the groups.
	 *
	 * Active means the account is neither blocked nor awaiting activation; in other words the user can log in. Joomla
	 * treats an activation value of '' and '0' alike, so both count as activated.
	 *
	 * @param   int[]  $groupIds
	 *
	 * @return  string[]  User ID => username, ordered by username
	 * @since   7.10.0
	 */
	public static function getActiveUsers(array $groupIds): array
	{
		$groupIds = array_values(array_unique(array_map('intval', $groupIds)));

		if (empty($groupIds))
		{
			return [];
		}

		$db    = self::getDatabase();
		$block = 0;
		$query = DbQuery::create($db)
			->select('DISTINCT ' . $db->quoteName('u.id') . ', ' . $db->quoteName('u.username'))
			->from($db->quoteName('#__users', 'u'))
			->innerJoin(
				$db->quoteName('#__user_usergroup_map', 'm'),
				$db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id')
			)
			->whereIn($db->quoteName('m.group_id'), $groupIds, ParameterType::INTEGER)
			->where($db->quoteName('u.block') . ' = :block')
			->whereIn($db->quoteName('u.activation'), ['', '0'], ParameterType::STRING)
			->bind(':block', $block, ParameterType::INTEGER)
			->order($db->quoteName('u.username') . ' ASC');

		$ret = [];

		foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row)
		{
			$ret[(int) $row->id] = (string) $row->username;
		}

		return $ret;
	}

	/**
	 * Forget the cached permission lookups. Only needed after changing group permissions in the same request.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public static function reset(): void
	{
		self::$allowed = [];
	}

	private static function getDatabase(): DatabaseDriver
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}
}
