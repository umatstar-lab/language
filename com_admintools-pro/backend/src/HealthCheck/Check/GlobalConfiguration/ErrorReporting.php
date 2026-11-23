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
 * Error Reporting should be set to None on a live site.
 *
 * @since  7.10.0
 */
class ErrorReporting extends AbstractConfigurationCheck
{
	protected $id = 'error_reporting';

	protected $langKey = 'ERRORREPORTING';

	protected $severity = Severity::HIGH;

	protected $configKey = 'error_reporting';

	protected $recommended = 'none';

	protected function isBad($value): bool
	{
		return strtolower(trim((string) $value)) !== 'none';
	}
}
