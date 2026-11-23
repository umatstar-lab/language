<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\JoomlaFeatures;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

/**
 * User "admin" must not exist, or must be disabled.
 *
 * @since  7.10.0
 */
class AdminUser extends BuiltinCheck
{
	protected $id = 'admin_user';

	protected $section = 'joomla';

	protected $langKey = 'ADMINUSER';

	protected $severity = Severity::HIGH;

	public function run(): CheckResult
	{
		$userId = $this->getAdminUserId();

		if ($userId === null)
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL'),
			[],
			['block']
		)->withLink('index.php?option=com_users&task=user.edit&id=' . $userId, Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_EDIT_USER'));
	}

	public function getFixes(): array
	{
		return [
			new Fix('block', $this->text('FIX'), $this->text('FIX_CONFIRM'), 'danger', 'fa fa-user-lock'),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		// Same as blocking the user in the Users manager: access to that manager, and the Edit State permission.
		return $fixId === 'block'
		       && $user->authorise('core.manage', 'com_users')
		       && $user->authorise('core.edit.state', 'com_users');
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		$userId = $this->getAdminUserId();
		$me     = Factory::getApplication()->getIdentity();

		if ($userId === null)
		{
			return $this->text('FIX_DONE');
		}

		if ($me === null || (int) $me->id === $userId)
		{
			throw new \RuntimeException($this->text('ERR_SELF'));
		}

		$target = new User($userId);

		// Only a Super User may block a Super User, just like in Joomla's User Manager.
		if ($target->authorise('core.admin') && !$me->authorise('core.admin'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$target->block = 1;

		if (!$target->save(true))
		{
			$error = method_exists($target, 'getError') ? $target->getError() : '';

			throw new \RuntimeException($error ?: $this->text('ERR_SAVE'));
		}

		return $this->text('FIX_DONE');
	}

	/**
	 * ID of the enabled user whose username is "admin" (in any letter case), if any.
	 *
	 * @return  int|null
	 * @since   7.10.0
	 */
	private function getAdminUserId(): ?int
	{
		$db       = $this->getDatabase();
		$username = 'admin';
		$block    = 0;
		$query    = DbQuery::create($db)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__users'))
			->where('LOWER(' . $db->quoteName('username') . ') = :username')
			->where($db->quoteName('block') . ' = :block')
			->bind(':username', $username)
			->bind(':block', $block, ParameterType::INTEGER);

		$id = $db->setQuery($query, 0, 1)->loadResult();

		return $id ? (int) $id : null;
	}
}
