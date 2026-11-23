<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Util;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Deletes files and folders for the Site Health Check's one-click fixes.
 *
 * It has a stricter containment check than Joomla's Path::check(), which only compares string prefixes (a symlinked
 * parent folder could point anywhere) and rejects any `..` substring (so a file literally named `foo..bak` could never
 * be removed).
 *
 * It does not use Joomla's File::delete() or Folder::delete(). File::delete() makes every file world-writable (chmod
 * 0777) before unlinking it, and chmod() follows symbolic links: deleting a link, or a folder containing links, would
 * make the files and folders they point to world-writable, wherever they are.
 *
 * @since  7.10.0
 */
final class SiteFilesystem
{
	/**
	 * Resolve a path relative to a root folder to an absolute path strictly inside that root.
	 *
	 * This is what makes a path safe to delete: no folder above it, symbolic links included, may lead out of the root.
	 *
	 * @param   string       $relativePath  Path relative to the root, using forward slashes. A backslash is a folder
	 *                                      separator only on Windows; elsewhere it is part of the name.
	 * @param   string|null  $root          The root; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  string  The absolute path. The entry itself is not resolved, so a symlink stays a symlink.
	 * @throws  \RuntimeException  If the path is empty, has . or .. segments, or its real parent lies outside the root.
	 * @since   7.10.0
	 */
	public static function resolve(string $relativePath, ?string $root = null): string
	{
		$root         = self::rootPath($root);
		$relativePath = self::validate($relativePath);

		// Not dirname() and basename(): they are locale-aware, and the name may not even be valid UTF-8.
		$lastSlash = strrpos($relativePath, '/');
		$parent    = ($lastSlash === false) ? '' : substr($relativePath, 0, $lastSlash);
		$name      = ($lastSlash === false) ? $relativePath : substr($relativePath, $lastSlash + 1);

		$realRoot   = realpath($root);
		$realParent = realpath($parent === '' ? $root : ($root . '/' . $parent));

		if ($realRoot === false || $realParent === false)
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_INVALID_PATH'));
		}

		$realRoot   = rtrim(self::separators($realRoot), '/');
		$realParent = rtrim(self::separators($realParent), '/');

		if ($realParent !== $realRoot && strpos($realParent . '/', $realRoot . '/') !== 0)
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_INVALID_PATH'));
		}

		return $realParent . '/' . $name;
	}

	/**
	 * Can a path relative to a root be deleted safely, i.e. does it resolve strictly inside the root?
	 *
	 * @param   string       $relativePath  Path relative to the root.
	 * @param   string|null  $root          The root; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function canDelete(string $relativePath, ?string $root = null): bool
	{
		try
		{
			self::resolve($relativePath, $root);
		}
		catch (\RuntimeException $e)
		{
			return false;
		}

		return true;
	}

	/**
	 * Does a path relative to a root exist, as a file, a folder or a symbolic link?
	 *
	 * Unlike resolve(), this follows symbolic links in the folders above it: a file in a linked folder exists, even if
	 * the link leads out of the root. Whether it can be deleted safely is canDelete()'s business.
	 *
	 * @param   string       $relativePath  Path relative to the root.
	 * @param   string|null  $root          The root; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  bool  False also when the path is invalid.
	 * @since   7.10.0
	 */
	public static function exists(string $relativePath, ?string $root = null): bool
	{
		$path = self::absolutePath($relativePath, $root);

		return $path !== null && (@is_link($path) || @file_exists($path));
	}

	/**
	 * Is a path a folder? A symbolic link to a folder is not: deleting it only deletes the link.
	 *
	 * @param   string       $relativePath
	 * @param   string|null  $root          The root; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isDir(string $relativePath, ?string $root = null): bool
	{
		$path = self::absolutePath($relativePath, $root);

		return $path !== null && !@is_link($path) && @is_dir($path);
	}

	/**
	 * Delete a file, a symbolic link or, recursively, a folder.
	 *
	 * A symbolic link is always removed as a link, never followed: neither the link itself nor any link inside a folder
	 * is descended into, or has its target's permissions changed.
	 *
	 * @param   string       $relativePath  Path relative to the root.
	 * @param   string|null  $root          The root; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  void
	 * @throws  \RuntimeException  On failure.
	 * @since   7.10.0
	 */
	public static function delete(string $relativePath, ?string $root = null): void
	{
		$path = self::resolve($relativePath, $root);

		if (!is_link($path) && !file_exists($path))
		{
			return;
		}

		if (!self::remove($path))
		{
			throw new \RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_CANNOT_DELETE', $relativePath)
			);
		}
	}

	/**
	 * Remove a directory entry; a folder recursively. Whether it is a link is checked before anything else, every time.
	 *
	 * @param   string  $path  Absolute path
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private static function remove(string $path): bool
	{
		clearstatcache(true, $path);

		// A link to a file, to a folder or to nothing: remove the link. Windows removes a link to a folder with rmdir().
		if (is_link($path))
		{
			return @unlink($path) || (DIRECTORY_SEPARATOR === '\\' && @rmdir($path));
		}

		if (!is_dir($path))
		{
			return self::removeFile($path);
		}

		$entries = @scandir($path);

		if ($entries === false)
		{
			return false;
		}

		foreach ($entries as $entry)
		{
			if ($entry !== '.' && $entry !== '..' && !self::remove($path . '/' . $entry))
			{
				return false;
			}
		}

		return @rmdir($path);
	}

	/**
	 * Remove a file which is not a symbolic link.
	 *
	 * @param   string  $path  Absolute path
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private static function removeFile(string $path): bool
	{
		// Do not leave a deleted PHP file in OPcache.
		if (function_exists('opcache_invalidate'))
		{
			@opcache_invalidate($path, true);
		}

		if (@unlink($path))
		{
			return true;
		}

		// Windows will not delete a read-only file. There is no link here, so this only ever changes this very file.
		if (DIRECTORY_SEPARATOR === '\\' && !is_link($path) && @chmod($path, 0666))
		{
			return @unlink($path);
		}

		return false;
	}

	/**
	 * The absolute path of a path relative to a root, without resolving any symbolic links.
	 *
	 * @param   string       $relativePath
	 * @param   string|null  $root  The root; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  string|null  NULL when the path is invalid.
	 * @since   7.10.0
	 */
	private static function absolutePath(string $relativePath, ?string $root): ?string
	{
		try
		{
			return self::rootPath($root) . '/' . self::validate($relativePath);
		}
		catch (\RuntimeException $e)
		{
			return null;
		}
	}

	/**
	 * Normalise and validate a relative path.
	 *
	 * @param   string  $relativePath
	 *
	 * @return  string  The path without leading or trailing slashes.
	 * @throws  \RuntimeException  If the path is empty, or has empty, . or .. segments, or a NUL byte.
	 * @since   7.10.0
	 */
	private static function validate(string $relativePath): string
	{
		$relativePath = trim(self::separators($relativePath), '/');
		$segments     = explode('/', $relativePath);

		if ($relativePath === '' || strpos($relativePath, "\0") !== false
		    || array_intersect($segments, ['', '.', '..']))
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_INVALID_PATH'));
		}

		return $relativePath;
	}

	/**
	 * The root folder, normalised.
	 *
	 * @param   string|null  $root  NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private static function rootPath(?string $root): string
	{
		return rtrim(self::separators($root ?? JPATH_ROOT), '/');
	}

	/**
	 * Use forward slashes as folder separators. Only on Windows is a backslash a folder separator; elsewhere it is a
	 * character like any other, which may well be part of a file name.
	 *
	 * @param   string  $path
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private static function separators(string $path): string
	{
		return DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $path) : $path;
	}
}
