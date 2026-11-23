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
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/**
 * Only enable user registration if needed.
 *
 * @since  7.10.0
 */
class UserRegistration extends BuiltinCheck
{
	protected $id = 'user_registration';

	protected $section = 'joomla';

	protected $langKey = 'USERREGISTRATION';

	protected $severity = Severity::LOW;

	public function run(): CheckResult
	{
		if (!ComponentHelper::getParams('com_users')->get('allowUserRegistration', 0))
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL'),
			[],
			['disable']
		)->withLink('index.php?option=com_config&view=component&component=com_users', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_USERS_OPTIONS'));
	}

	public function getFixes(): array
	{
		return [
			new Fix('disable', $this->text('FIX'), '', 'primary', 'fa fa-toggle-off'),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		// Changing a component's Options requires its Configure ACL & Options permission.
		return $fixId === 'disable' && $user->authorise('core.admin', 'com_users');
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		$params = clone ComponentHelper::getParams('com_users');
		$params->set('allowUserRegistration', 0);

		self::getComponent()->getComponentParametersService()->save($params, 'com_users');

		return $this->text('FIX_DONE');
	}
}
