<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Joomla\CMS\User\UserHelper;

class BlockForbiddenUsernameLogin extends Base
{
	public function isEnabled(): bool
	{
		if (!file_exists(__DIR__ . '/../../assets/forbidden_usernames.php'))
		{
			return false;
		}

		return ($this->wafParams->getValue('blockusernames', 0) == 1)
		       && ($this->wafParams->getValue('blockusernames_immeban', 0) == 1);
	}

	/**
	 * Detect login attempts using a forbidden username with no matching site user, and immediately ban the IP.
	 *
	 * @param   mixed  $response  The Joomla authentication response object
	 */
	public function onUserLoginFailure($response): void
	{
		if ($this->parentPlugin->runShortCircuitFeature('isIPBlocked', false, []))
		{
			return;
		}

		$username = $this->input->getString('username', null);

		if (empty($username))
		{
			return;
		}

		$blocked_usernames = include __DIR__ . '/../../assets/forbidden_usernames.php';

		if (!$blocked_usernames)
		{
			return;
		}

		$extra_block = $this->wafParams->getValue('blockusernames_forbid', []);

		if (is_string($extra_block))
		{
			$extra_block = array_map('trim', explode(',', $extra_block));
		}

		$extra_block = array_map(
			function ($x) {
				return is_array($x) ? $x[0] : $x;
			}, is_array($extra_block) ? $extra_block : []
		);

		$extra_allow = $this->wafParams->getValue('blockusernames_allow', []);

		if (is_string($extra_allow))
		{
			$extra_allow = array_map('trim', explode(',', $extra_allow));
		}

		$extra_allow = array_map(
			function ($x) {
				return is_array($x) ? $x[0] : $x;
			}, is_array($extra_allow) ? $extra_allow : []
		);

		$blocked_usernames = array_merge_recursive($blocked_usernames, $extra_block);
		$blocked_usernames = array_diff($blocked_usernames, $extra_allow);

		if (!in_array($username, $blocked_usernames))
		{
			return;
		}

		// Only trigger when no real user with this username exists; an existing user's failed login is not an attack signal.
		if (UserHelper::getUserId($username))
		{
			return;
		}

		$this->exceptionsHandler->logRequest('blockedusername', $username, 'Username: ' . $username);

		if ($this->wafParams->getValue('tsrenable', 0))
		{
			$this->exceptionsHandler->immediateIPAutoBan('blockedusername');
		}
	}
}
