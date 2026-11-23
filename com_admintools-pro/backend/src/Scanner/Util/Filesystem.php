<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Scanner\Util;

defined('_JEXEC') || die;

abstract class Filesystem
{
	/**
	 * Prefix of a stored path which is not valid UTF-8, see encodePath(). A relative path never starts with a slash.
	 *
	 * @since  7.10.0
	 */
	public const ENCODED_PREFIX = '/b64:';

	static $normalizedRoot;

	/**
	 * Normalizes a path.
	 *
	 * Windows paths are normalized by having their backslashes converted to forward slashes. This normalization is
	 * aware of UNC paths.
	 *
	 * Furthermore, multiple forward slashes will be squashed into a single forward slash, e.g. convert
	 * /var//www////html to /var/www/html
	 *
	 * @param   string  $path  The path to transform
	 *
	 * @return  string
	 */
	public static function normalizePath(string $path): string
	{
		$isUNC = false;

		if (IS_WIN)
		{
			// Is this a UNC path?
			$prefix = substr($path, 0, 2);
			$isUNC  = in_array($prefix, ['//', '\\\\']);

			// Change potential windows directory separator
			$path = strtr($path, '\\', '/');
		}

		// Remove multiple slashes
		while (strpos($path, '//') !== false)
		{
			$path = str_replace('//', '/', $path);
		}

		// Remove trailing slashes
		$path = rtrim($path, '/');

		// Fix UNC paths
		if ($isUNC)
		{
			$path = '//' . ltrim($path, '/');
		}

		return $path;
	}

	/**
	 * Returns the normalized file path relative to the site's root
	 *
	 * @param   string  $path
	 *
	 * @return  string
	 */
	public static function relativePath(string $path): string
	{
		if (empty(static::$normalizedRoot))
		{
			static::$normalizedRoot = static::normalizePath(JPATH_ROOT);
		}

		$path = static::normalizePath($path);

		if (strpos($path, static::$normalizedRoot) === 0)
		{
			$path = substr($path, strlen(static::$normalizedRoot));
			/**
			 * normalizePath() has already turned Windows' backslashes into forward slashes, so only forward slashes
			 * separate the root from the rest. Elsewhere a backslash is an ordinary file name character: a file named
			 * \x.php in the site's root must not be recorded as x.php.
			 */
			$path = ltrim($path, '/');
		}

		return $path;
	}

	/**
	 * The form of a relative path which is safe to store in the database, the session, JSON, or a request.
	 *
	 * File names are bytes, often chosen by whoever created the file. A name which is not valid UTF-8 cannot be stored
	 * in a utf8mb4 column: MySQL in strict mode, as Joomla uses it, refuses the whole statement, and otherwise cuts the
	 * name short at the first invalid byte. Such paths, and any path starting with the prefix, are stored as the prefix
	 * followed by the path in Base64. Every other path is stored as it is, so existing records stay valid.
	 *
	 * @param   string  $path  Path relative to the site's root
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function encodePath(string $path): string
	{
		if (strpos($path, self::ENCODED_PREFIX) !== 0 && preg_match('//u', $path) === 1)
		{
			return $path;
		}

		return self::ENCODED_PREFIX . base64_encode($path);
	}

	/**
	 * The relative path a stored path stands for; the reverse of encodePath().
	 *
	 * @param   string  $stored  The stored path
	 *
	 * @return  string|null  NULL when it is not a valid encoded path
	 * @since   7.10.0
	 */
	public static function decodePath(string $stored): ?string
	{
		if (strpos($stored, self::ENCODED_PREFIX) !== 0)
		{
			return $stored;
		}

		$path = base64_decode(substr($stored, strlen(self::ENCODED_PREFIX)), true);

		return ($path === false || $path === '') ? null : $path;
	}

	/**
	 * A path which can be shown to the user: bytes which are not valid UTF-8 become U+FFFD (�).
	 *
	 * Display only. Never use the result to find or act on a file: it may name another file, or none.
	 *
	 * @param   string  $path  A relative path, NOT a stored one
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function displayPath(string $path): string
	{
		if (preg_match('//u', $path) === 1)
		{
			return $path;
		}

		return (string) json_decode((string) json_encode($path, JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/**
	 * A stored path, as it can be shown to the user. See encodePath() and displayPath().
	 *
	 * @param   string  $stored  The stored path
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function displayStoredPath(string $stored): string
	{
		return self::displayPath(self::decodePath($stored) ?? $stored);
	}
}
