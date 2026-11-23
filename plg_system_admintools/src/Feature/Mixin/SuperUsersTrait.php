<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\UserGroups;
use Akeeba\Component\AdminTools\Administrator\Helper\EmailLanguage;
use Joomla\CMS\User\User;

trait SuperUsersTrait
{
	/**
	 * Return the user IDs of all active (non-blocked) Super Users on the site.
	 *
	 * @return  array
	 *
	 * @since   5.3.0
	 */
	protected function getSuperUsers()
	{
		// Get the Super User groups
		$superUserGroups = UserGroups::superUserGroups();

		// Get all Super Users
		$superUsers = $this->getUsersByGroups($superUserGroups);
		$superUsers = array_unique($superUsers);

		// Return only active (non-blocked) Super User account IDs
		return array_filter(
			$superUsers,
			fn($userID) => self::getUserById($userID)->block == 0
		);
	}

	/**
	 * Returns all Joomla! user groups
	 *
	 * @return  array
	 *
	 * @since   5.3.0
	 */
	protected function getAllJoomlaUserGroups()
	{
		return UserGroups::getAllIds();
	}

	/**
	 * Returns all user IDs belonging to any of the group IDs specified.
	 *
	 * @param   array  $groups  List of all user group IDs we are interested in
	 *
	 * @return  array
	 *
	 * @since   5.3.0
	 */
	protected function getUsersByGroups(array $groups)
	{
		return UserGroups::getUserIds($groups);
	}

	/**
	 * Get an array of User objects given a WAF options key containing a list of emails.
	 *
	 * If a user with an email equal to the configured email exists the user object will be that exact user. Otherwise,
	 * it's a fake user object with an ID of 0 and the username, name, and email address set to the recipient email
	 * address entered into the WAF configuration option.
	 *
	 * @param   string  $wafParamsKey
	 *
	 * @return  array<User>
	 * @since   7.7.1
	 */
	private function userListFromConfiguredEmailList(string $wafParamsKey): array
	{
		$emailList = $this->wafParams->getValue($wafParamsKey, '') ?: '';

		if (empty($emailList))
		{
			return [];
		}

		$emailList = is_array($emailList) ? $emailList : explode(',', trim($emailList));
		$emailList = array_map('trim', $emailList);
		$emailList = array_filter($emailList);

		return array_map(
			fn($email) => $this->userObjectByEmail($email),
			$emailList
		);
	}

	/**
	 * Get a user object given an email address.
	 *
	 * If a user with an email equal to the configured email exists the user object will be that exact user. Otherwise,
	 * it's a fake user object with an ID of 0 and the username, name, and email address set to the recipient email
	 * address entered into the WAF configuration option.
	 *
	 * @param   string  $email
	 *
	 * @return  User
	 * @since   7.7.1
	 */
	private function userObjectByEmail(string $email): User
	{
		/**
		 * The lookup lives in EmailLanguage because the email language rules need exactly the same answer — whether
		 * a configured address belongs to a Joomla user account decides which of the two rules applies to it.
		 */
		return EmailLanguage::userForEmail($email);
	}

	/**
	 * Get an array of User objects with the site's Super User accounts.
	 *
	 * All non-Blocked Super User accounts are returned. This includes Super Users which are configured to not Receive
	 * Email in their user profile.
	 *
	 * @return  array<User>
	 * @since   7.7.1
	 */
	private function getSuperUserObjects(): array
	{
		return array_map(
			fn($id) => self::getUserById($id),
			$this->getSuperUsers()
		);
	}
}