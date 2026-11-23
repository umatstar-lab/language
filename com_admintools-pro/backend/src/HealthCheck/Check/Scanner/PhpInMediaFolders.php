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
 * PHP files should not be in media, image, file and language folders.
 *
 * @since  7.10.0
 */
class PhpInMediaFolders extends AbstractFindingsCheck
{
	protected $id = 'php_in_media_folders';

	protected $langKey = 'PHPINMEDIAFOLDERS';

	protected $severity = Severity::CRITICAL;

	protected $category = Collector::PHP_IN_MEDIA;

	protected $deletable = true;
}
