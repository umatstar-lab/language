<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Files;

defined('_JEXEC') or die;

use Joomla\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\IgnorableItemsInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Util\SiteFilesystem;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Leftover extension installation folders (install_*) must be deleted from the temporary directory.
 *
 * The temporary directory may well be outside the site's root, so paths are relative to it, not to the site's root.
 *
 * It may also be shared with other accounts on the server, e.g. /tmp. Delete is only offered for folders the site
 * itself could have created: real folders, not symbolic links, owned by the user PHP runs as. Another account can
 * change what is inside a folder it owns while it is being deleted, e.g. turn a sub-folder into a link to the site's
 * root just before the recursive delete reaches it.
 *
 * @since  7.10.0
 */
class TempInstallFolders extends BuiltinCheck implements IgnorableItemsInterface
{
	protected $id = 'temp_install_folders';

	protected $section = 'files';

	protected $langKey = 'TEMPINSTALLFOLDERS';

	protected $severity = Severity::MEDIUM;

	public function isExpensive(): bool
	{
		return true;
	}

	public function run(): CheckResult
	{
		[$folders, $ignored] = $this->partitionIgnored(self::findInstallFolders($this->getTempPath()));

		if (empty($folders))
		{
			return self::withIgnoredItems(CheckResult::pass(), count($ignored));
		}

		return self::withIgnoredItems(
			CheckResult::fail(
				$this->text('MSG_FAIL', self::e($this->getTempPath())),
				$this->folderItems($this->getTempPath(), $folders),
				[],
				count($folders)
			)->withLink('index.php?option=com_scheduler&view=select', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NEW_TASK')),
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

		// The Runner has verified that $itemKey is one of the folders found just now.
		SiteFilesystem::delete((string) $itemKey, $this->getTempPath());

		return $this->text('FIX_DONE', $itemKey);
	}

	/**
	 * Find the install_* folders in a folder.
	 *
	 * @param   string  $tempPath
	 *
	 * @return  string[]  Folder names, sorted
	 * @since   7.10.0
	 */
	public static function findInstallFolders(string $tempPath): array
	{
		if ($tempPath === '' || !@is_dir($tempPath))
		{
			return [];
		}

		try
		{
			$folders = Folder::folders($tempPath, '^install_', false, false, [], []);
		}
		catch (\Exception $e)
		{
			return [];
		}

		sort($folders);

		return $folders;
	}

	/**
	 * The result items for the install_* folders found.
	 *
	 * @param   string    $tempPath  The temporary folder
	 * @param   string[]  $folders   Folder names in it
	 *
	 * @return  array[]
	 * @since   7.10.0
	 */
	protected function folderItems(string $tempPath, array $folders): array
	{
		$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

		return array_map(
			function (string $folder) use ($tempPath, $uid) {
				$safe = self::isSafeToDelete($tempPath . '/' . $folder, $uid);

				return CheckResult::item(
					$folder, $folder, $this->itemFixes($safe ? ['delete'] : []),
					$safe ? '' : Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NO_DELETE')
				);
			},
			array_slice(array_values($folders), 0, static::MAX_ITEMS)
		);
	}

	/**
	 * May an install_* entry be deleted: is it a real folder, owned by the given user?
	 *
	 * @param   string    $path  Absolute path
	 * @param   int|bool  $uid   The user PHP runs as; FALSE when unknown, which makes nothing safe to delete.
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected static function isSafeToDelete(string $path, $uid): bool
	{
		clearstatcache(true, $path);

		$stat = @lstat($path);

		return $uid !== false && is_array($stat) && !is_link($path) && is_dir($path) && $stat['uid'] === $uid;
	}

	private function getTempPath(): string
	{
		return rtrim((string) Factory::getApplication()->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\');
	}
}
