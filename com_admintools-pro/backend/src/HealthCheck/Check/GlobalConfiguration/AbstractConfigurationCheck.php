<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\Service\JoomlaConfiguration;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Base for checks on a single Global Configuration (configuration.php) variable, with a one-click fix setting it to
 * the recommended value.
 *
 * Changing the Global Configuration is a Super User privilege in Joomla, so the fix is only offered to Super Users.
 *
 * @since  7.10.0
 */
abstract class AbstractConfigurationCheck extends BuiltinCheck
{
	/** @var string The configuration.php variable */
	protected $configKey = '';

	/** @var mixed The recommended value, applied by the fix */
	protected $recommended = false;

	/** @var bool Does the fix need a confirmation (language key suffix FIX_CONFIRM)? */
	protected $confirmFix = false;

	protected $section = 'globalconfig';

	/**
	 * Is the current value a problem?
	 *
	 * @param   mixed  $value
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	abstract protected function isBad($value): bool;

	public function run(): CheckResult
	{
		if (!$this->isBad(Factory::getApplication()->get($this->configKey)))
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL'),
			[],
			['setvalue']
		)->withLink('index.php?option=com_config', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_GLOBALCONFIG'));
	}

	public function getFixes(): array
	{
		return [
			// A fix needing confirmation (Shared Sessions) logs the user out, so the page must be reloaded afterwards.
			new Fix(
				'setvalue', $this->text('FIX'), $this->confirmFix ? $this->text('FIX_CONFIRM') : '', 'primary', 'fa fa-check',
				$this->confirmFix
			),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		return $fixId === 'setvalue' && self::isSuperUser($user);
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		$this->getConfigurationService()->set($this->configKey, $this->recommended);

		return $this->text('FIX_DONE');
	}

	/**
	 * The service which writes configuration.php.
	 *
	 * @return  JoomlaConfiguration
	 * @since   7.10.0
	 */
	protected function getConfigurationService(): JoomlaConfiguration
	{
		return self::getComponent()->getJoomlaConfigurationService();
	}
}
