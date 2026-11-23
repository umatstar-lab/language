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
use Joomla\CMS\Component\ComponentHelper;

/**
 * Locate and review large files.
 *
 * @since  7.10.0
 */
class LargeFiles extends AbstractFindingsCheck
{
	protected $id = 'large_files';

	protected $langKey = 'LARGEFILES';

	protected $severity = Severity::LOW;

	protected $category = Collector::LARGE;

	protected $deletable = false;

	public function isApplicable(): bool
	{
		return (bool) ComponentHelper::getParams('com_admintools')->get('healthcheckLargeFiles', 1);
	}

	/**
	 * Never report Joomla core files, even if a scan run by an older version collected them.
	 *
	 * @param   string[]  $items
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function filterItems(array $items): array
	{
		return array_values(
			array_filter(
				$items,
				function (string $rel) {
					return !Collector::isCoreFile($rel);
				}
			)
		);
	}
}
