<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;

/**
 * Debug Language should be disabled.
 *
 * @since  7.10.0
 */
class DebugLanguage extends AbstractConfigurationCheck
{
	protected $id = 'debug_language';

	protected $langKey = 'DEBUGLANGUAGE';

	protected $severity = Severity::MEDIUM;

	protected $configKey = 'debug_lang';

	protected $recommended = false;

	protected function isBad($value): bool
	{
		return (bool) $value;
	}
}
