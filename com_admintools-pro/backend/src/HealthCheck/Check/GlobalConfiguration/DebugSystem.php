<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Factory;

/**
 * Debug System should be disabled.
 *
 * Joomla defines JDEBUG from the Global Configuration's Debug System option, unless something defined it first (usually
 * a defines.php file). That second case cannot be fixed from the Global Configuration.
 *
 * @since  7.10.0
 */
class DebugSystem extends AbstractConfigurationCheck
{
	protected $id = 'debug_system';

	protected $langKey = 'DEBUGSYSTEM';

	protected $severity = Severity::HIGH;

	protected $configKey = 'debug';

	protected $recommended = false;

	public function run(): CheckResult
	{
		if ($this->isBad(Factory::getApplication()->get($this->configKey)))
		{
			return parent::run();
		}

		if (defined('JDEBUG') && JDEBUG)
		{
			return CheckResult::fail($this->text('MSG_CONSTANT'));
		}

		return CheckResult::pass();
	}

	protected function isBad($value): bool
	{
		return (bool) $value;
	}
}
