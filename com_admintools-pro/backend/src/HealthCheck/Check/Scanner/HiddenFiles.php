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
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Util\SiteFilesystem;

/**
 * Hidden files and folders.
 *
 * @since  7.10.0
 */
class HiddenFiles extends AbstractFindingsCheck
{
	protected $id = 'hidden_files';

	protected $langKey = 'HIDDENFILES';

	protected $severity = Severity::LOW;

	protected $category = Collector::HIDDEN;

	protected $deletable = true;

	public function isApplicable(): bool
	{
		// Hidden files are a Unix convention; on Windows a leading dot means nothing special.
		return DIRECTORY_SEPARATOR !== '\\';
	}

	/**
	 * Never report legitimate hidden files (e.g. .htpasswd), even if a scan run by an older version collected them.
	 * A folder by such a name is still reported.
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
					return !Collector::isLegitHiddenName(basename($rel), $this->isFolder($rel));
				}
			)
		);
	}

	/**
	 * Is a stored item a folder?
	 *
	 * @param   string  $rel  Path relative to the site's root
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected function isFolder(string $rel): bool
	{
		return SiteFilesystem::isDir($rel);
	}
}
