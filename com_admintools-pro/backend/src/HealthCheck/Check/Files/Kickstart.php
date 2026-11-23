<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Files;

defined('_JEXEC') or die;

use Joomla\Filesystem\Folder;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\IgnorableItemsInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Util\SiteFilesystem;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

/**
 * Akeeba Kickstart should not be left in the web root.
 *
 * Kickstart may have been renamed, so every .php file in the site's root other than index.php is examined for the
 * Kickstart header comment within its first 512 bytes.
 *
 * @since  7.10.0
 */
class Kickstart extends BuiltinCheck implements IgnorableItemsInterface
{
	protected $id = 'kickstart';

	protected $section = 'files';

	protected $langKey = 'KICKSTART';

	protected $severity = Severity::CRITICAL;

	public function isExpensive(): bool
	{
		return true;
	}

	public function run(): CheckResult
	{
		[$files, $ignored] = $this->partitionIgnored(self::findKickstartFiles(JPATH_ROOT));

		if (empty($files))
		{
			return self::withIgnoredItems(CheckResult::pass(), count($ignored));
		}

		return self::withIgnoredItems(
			CheckResult::fail($this->text('MSG_FAIL'), self::pathItems($files, $this->itemFixes(['delete'])), [], count($files)),
			count($ignored)
		);
	}

	public function getFixes(): array
	{
		return [self::deleteFix()];
	}

	public function canFix(string $fixId, User $user): bool
	{
		return $fixId === 'delete' && self::canDeleteFiles($user);
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		if ($fixId !== 'delete')
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_FIX_NOT_SUPPORTED'));
		}

		// The Runner has verified that $itemKey is one of the files found just now.
		SiteFilesystem::delete((string) $itemKey);

		return $this->text('FIX_DONE', $itemKey);
	}

	/**
	 * Find the Kickstart copies in a folder.
	 *
	 * @param   string  $root  The folder to look into
	 *
	 * @return  string[]  File names, sorted
	 * @since   7.10.0
	 */
	public static function findKickstartFiles(string $root): array
	{
		try
		{
			// No exclusions: a copy of Kickstart may well be renamed to a hidden file.
			$files = Folder::files($root, '.', false, false, [], []);
		}
		catch (\Exception $e)
		{
			return [];
		}

		$files = array_values(
			array_filter(
				$files,
				function (string $name) use ($root) {
					if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php' || strtolower($name) === 'index.php')
					{
						return false;
					}

					$header = @file_get_contents($root . '/' . $name, false, null, 0, 512);

					return is_string($header) && self::isKickstart($header);
				}
			)
		);

		sort($files);

		return $files;
	}

	/**
	 * Does the beginning of a file look like Akeeba Kickstart?
	 *
	 * @param   string  $header  The first bytes of the file
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isKickstart(string $header): bool
	{
		return (bool) preg_match('/\*\s*Akeeba Kickstart\b/i', $header);
	}
}
