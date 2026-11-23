<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Scanner;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Scanner\Collector;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;

/**
 * Do not rename files and folders to keep old versions of them.
 *
 * @since  7.10.0
 */
class VersionedNames extends AbstractFindingsCheck
{
	protected $id = 'versioned_names';

	protected $langKey = 'VERSIONEDNAMES';

	protected $severity = Severity::LOW;

	protected $category = Collector::VERSIONED;

	protected $deletable = true;
}
