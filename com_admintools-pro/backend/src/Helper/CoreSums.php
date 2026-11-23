<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Version;
use Joomla\Http\HttpFactory;

/**
 * CoreSums helper
 *
 * Downloads, caches, and queries Akeeba CoreSums checksum data for the currently running Joomla
 * version.  The remote service (getpanopticon.com) publishes a gzip-compressed JSON file mapping
 * relative Joomla-root paths to their "squash" SHA-256 checksums for PHP, JS, and INI files.
 *
 * ## Caching
 *
 * Three rows are written to `#__admintools_storage`:
 *   - `coresums.version`  — the Joomla short version the cached data covers (e.g. "5.2.3")
 *   - `coresums.data`     — the decoded JSON re-serialised back to a canonical JSON string
 *   - `coresums.checksum` — SHA-256 of `coresums.data` used as an integrity guard
 *
 * On every call to getMap() / classify() the version and integrity guard are verified; a mismatch
 * (stale version, truncated row, or DB corruption) is treated as a cache miss and an empty map is
 * returned until the next successful refresh().
 *
 * ## Core-directory derivation (used by classify())
 *
 * A "core directory" is a first- or second-level Joomla installation directory that Joomla itself
 * owns and maintains.  The set of core directories is derived ONCE from the keys of the checksum
 * map, then memoized for the lifetime of the request.
 *
 * ### Group A — first-level subdirectory of a well-known base
 *
 * Base directories: `administrator/components`, `administrator/modules`,
 * `administrator/templates`, `api/components`, `components`, `layouts`, `libraries`, `modules`,
 * `templates`.
 *
 * For every map key whose path starts with `<base>/`, take the FIRST path segment that follows
 * `<base>/`.  The resulting core directory is `<base>/<segment>`.
 *
 * Examples:
 *   - `components/com_content/controller.php`   → core dir `components/com_content`
 *   - `libraries/vendor/joomla/string/...`      → core dir `libraries/vendor`
 *   - `administrator/components/com_users/...`  → core dir `administrator/components/com_users`
 *   - `api/components/com_banners/...`          → core dir `api/components/com_banners`
 *
 * Note that the base is `api/components`, not `api`.  Were it `api`, the derived core directory
 * would be `api/components` itself, making the API-application side of every third-party extension
 * non-core — the exact opposite of how the site and administrator applications are treated.  The
 * same goes for `api/language`, where Joomla installs the API language files of third-party
 * extensions.
 *
 * ### Group B — second-level subdirectory of `plugins/`
 *
 * For every map key of the form `plugins/<type>/<name>/...`, the core directory is
 * `plugins/<type>/<name>`.
 *
 * Example:
 *   - `plugins/system/admintools/feature.php`  → core dir `plugins/system/admintools`
 *
 * ### Group C — fixed well-known directories
 *
 * These are always considered core directories regardless of map contents:
 *   - `administrator/help`
 *   - `administrator/includes`
 *   - `api/includes`
 *   - `includes`
 *
 * ### Never non-core — `administrator/manifests`
 *
 * Joomla itself writes the manifest of every package, file, and library extension it installs
 * under `administrator/manifests`, third-party ones included.  A manifest sitting there is
 * therefore expected and must never be reported as a non-core file inside a core directory, so
 * the whole tree is excluded from core-directory derivation and short-circuited in classify().
 * Joomla's own manifests are still verified: they are keys in the checksum map, so they classify
 * as `'core'` and their checksums are compared as usual.
 *
 * ## classify() semantics
 *
 * Given a relative path `$rel` (forward slashes, no leading slash):
 *  - Returns `'core'`    if `$rel` is a key in the checksum map.
 *  - Returns `null`      if `$rel` is inside a never-non-core prefix (see above).
 *  - Returns `'noncore'` if `$rel` is not a map key but resides inside a core directory
 *                         (i.e. `$rel` equals a core-dir string OR starts with `<core-dir>/`).
 *  - Returns `null`      if the path is entirely outside all known core directories (out of scope).
 *
 * @since 7.8.0
 */
final class CoreSums
{
	/**
	 * Group A base directories used for core-directory derivation.
	 *
	 * Each entry is a path prefix (without trailing slash) for which the FIRST child segment
	 * after the base is taken as part of the core directory name.
	 *
	 * @since 7.8.0
	 */
	private const GROUP_A_BASES = [
		'administrator/components',
		'administrator/modules',
		'administrator/templates',
		'api/components',
		'components',
		'layouts',
		'libraries',
		'modules',
		'templates',
	];

	/**
	 * Group C fixed core directories (always considered core regardless of map contents).
	 *
	 * @since 7.8.0
	 */
	private const GROUP_C_DIRS = [
		'administrator/help',
		'administrator/includes',
		'api/includes',
		'includes',
	];

	/**
	 * Path prefixes which must never be reported as non-core.
	 *
	 * `administrator/manifests` holds the manifest XML files Joomla writes itself when it installs
	 * a package, file, or library extension — third-party extensions included. Their presence is
	 * normal, so anything there which is not a known core file is out of scope, not a non-core file
	 * inside a core directory.
	 *
	 * @since 7.9.0
	 */
	private const NEVER_NONCORE_PREFIXES = [
		'administrator/manifests',
	];

	/**
	 * Memoized checksum map: relative path → SHA-256 squash checksum.
	 *
	 * `null` means "not yet loaded from DB".  An empty array means "loaded but empty / invalid".
	 *
	 * @var  array<string,string>|null
	 * @since 7.8.0
	 */
	private static ?array $map = null;

	/**
	 * Memoized set of derived core directory strings.
	 *
	 * @var  string[]|null
	 * @since 7.8.0
	 */
	private static ?array $coreDirs = null;

	/**
	 * Private constructor — this class is a static utility and must not be instantiated.
	 *
	 * @return  void
	 * @since   7.8.0
	 */
	private function __construct() {}

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Downloads and caches the CoreSums checksum data for the specified Joomla version.
	 *
	 * If the DB cache already holds valid data for the requested version (and its SHA-256
	 * integrity guard passes), the download is skipped and `true` is returned immediately.
	 *
	 * This method is best-effort: it returns `false` — and never throws — on any network,
	 * decompression, parse, or database error, so that file scans can continue gracefully when
	 * the site is offline or the remote service is unavailable.
	 *
	 * @param   string|null  $version  Joomla short version to refresh (e.g. "5.2.3").
	 *                                  Defaults to the currently running Joomla version.
	 *
	 * @return  bool  `true` if the cache is now valid for the requested version, `false` otherwise.
	 * @since   7.8.0
	 */
	public static function refresh(?string $version = null): bool
	{
		try
		{
			$version = $version ?? (new Version())->getShortVersion();

			// --- Check existing cache ---
			$cachedVersion  = self::loadStorageKey('coresums.version');
			$cachedData     = self::loadStorageKey('coresums.data');
			$cachedChecksum = self::loadStorageKey('coresums.checksum');

			// If the cached version exists but belongs to a different Joomla release, wipe all
			// three storage rows immediately so stale data does not linger even if the download
			// that follows fails.
			if ($cachedVersion !== null && $cachedVersion !== $version)
			{
				self::deleteStorageKey('coresums.version');
				self::deleteStorageKey('coresums.data');
				self::deleteStorageKey('coresums.checksum');
				self::$map      = null;
				self::$coreDirs = null;
			}

			if (
				$cachedVersion === $version
				&& !empty($cachedData)
				&& !empty($cachedChecksum)
				&& hash('sha256', $cachedData) === $cachedChecksum
			)
			{
				// Valid cache hit — invalidate memoized map so getMap() reloads from DB.
				self::$map      = null;
				self::$coreDirs = null;

				return true;
			}

			// --- Download ---
			$params = ComponentHelper::getParams('com_admintools');
			$base   = rtrim((string) $params->get('coresums_url', 'https://getpanopticon.com/checksums'), '/');

			if (trim($base) === '')
			{
				$base = 'https://getpanopticon.com/checksums';
			}

			$url  = sprintf('%s/joomla/%s/sha256_squash.json.gz', $base, $version);
			$body = self::retrieveURL($url);

			if ($body === null || $body === '')
			{
				return false;
			}

			// Decompress (the remote endpoint serves gzip-encoded content).
			$json = @gzdecode($body);

			if ($json === false || $json === '')
			{
				return false;
			}

			// Decode to an associative array (relative path → checksum).
			$map = @json_decode($json, true);

			if (!is_array($map) || empty($map))
			{
				return false;
			}

			// Re-encode to a canonical JSON string for storage and integrity checking.
			$jsonString = json_encode($map);

			if ($jsonString === false || $jsonString === '')
			{
				return false;
			}

			$checksum = hash('sha256', $jsonString);

			// --- Persist ---
			self::saveStorageKey('coresums.version', $version);
			self::saveStorageKey('coresums.data', $jsonString);
			self::saveStorageKey('coresums.checksum', $checksum);

			// Invalidate memoized state so the next getMap() call reloads from DB.
			self::$map      = null;
			self::$coreDirs = null;

			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Returns the checksum map for the currently running Joomla version.
	 *
	 * The map is loaded once from the DB cache on the first call, then memoized in memory for
	 * the remainder of the request.  If the cache is absent, covers a different Joomla version,
	 * or fails the integrity check, an empty array is returned.
	 *
	 * @return  array<string,string>  Associative array of relative path → SHA-256 squash checksum.
	 * @since   7.8.0
	 */
	public static function getMap(): array
	{
		if (self::$map !== null)
		{
			return self::$map;
		}

		try
		{
			$version        = (new Version())->getShortVersion();
			$cachedVersion  = self::loadStorageKey('coresums.version');
			$cachedData     = self::loadStorageKey('coresums.data');
			$cachedChecksum = self::loadStorageKey('coresums.checksum');

			if (
				$cachedVersion !== $version
				|| empty($cachedData)
				|| empty($cachedChecksum)
				|| hash('sha256', $cachedData) !== $cachedChecksum
			)
			{
				return self::$map = [];
			}

			$map = @json_decode($cachedData, true);

			self::$map = (is_array($map) && !empty($map)) ? $map : [];
		}
		catch (\Throwable $e)
		{
			self::$map = [];
		}

		return self::$map;
	}

	/**
	 * Returns `true` if the given relative path is present in the CoreSums checksum map.
	 *
	 * @param   string  $rel  Relative path from the Joomla root (forward slashes, no leading slash).
	 *
	 * @return  bool
	 * @since   7.8.0
	 */
	public static function isCoreFile(string $rel): bool
	{
		return isset(self::getMap()[$rel]);
	}

	/**
	 * Returns the squash SHA-256 checksum for the given relative path, or `null` if not found.
	 *
	 * @param   string  $rel  Relative path from the Joomla root.
	 *
	 * @return  string|null  The SHA-256 hex checksum string, or `null` if not in the map.
	 * @since   7.8.0
	 */
	public static function getChecksum(string $rel): ?string
	{
		return self::getMap()[$rel] ?? null;
	}

	/**
	 * Computes the squash SHA-256 checksum for the given file contents.
	 *
	 * The "squash" algorithm collapses all whitespace sequences (spaces, tabs, newlines, carriage
	 * returns, vertical tabs) into a single ASCII space before hashing.  This makes the checksum
	 * insensitive to trivial whitespace-only differences while still detecting meaningful changes.
	 *
	 * @param   string  $contents  Raw file contents.
	 *
	 * @return  string  SHA-256 hex string.
	 * @since   7.8.0
	 */
	public static function squashChecksum(string $contents): string
	{
		return hash('sha256', preg_replace('#[\n\r\t\s\v]+#ms', ' ', $contents));
	}

	/**
	 * Classifies a relative path as a Joomla core file, a non-core file within a core directory,
	 * or a path that is entirely out of scope.
	 *
	 * See the class-level docblock for the full classification rules and the core-directory
	 * derivation algorithm (Groups A, B, C).
	 *
	 * @param   string  $rel  Relative path from the Joomla root (forward slashes, no leading slash).
	 *
	 * @return  string|null  `'core'` | `'noncore'` | `null`
	 * @since   7.8.0
	 */
	public static function classify(string $rel): ?string
	{
		// Fast path: the path is a key in the checksum map — definitively a core file.
		if (self::isCoreFile($rel))
		{
			return 'core';
		}

		// Paths Joomla maintains on behalf of every extension can never be non-core.
		if (self::isNeverNonCore($rel))
		{
			return null;
		}

		// Check whether the path resides inside a derived core directory.
		foreach (self::getCoreDirs() as $coreDir)
		{
			if ($rel === $coreDir || strpos($rel, $coreDir . '/') === 0)
			{
				return 'noncore';
			}
		}

		return null;
	}

	/**
	 * Is this path one Joomla maintains on behalf of every extension, which can therefore never be non-core?
	 *
	 * For example, `administrator/manifests` holds the manifests of packages, libraries and file extensions together
	 * with their installation scripts, which are PHP files.
	 *
	 * @param   string  $rel  Relative path from the Joomla root (forward slashes, no leading slash).
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isNeverNonCore(string $rel): bool
	{
		foreach (self::NEVER_NONCORE_PREFIXES as $prefix)
		{
			if ($rel === $prefix || strpos($rel, $prefix . '/') === 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Seeds the in-memory checksum map directly, bypassing the database. FOR TESTING ONLY.
	 *
	 * Calling this method with a synthetic map lets unit tests exercise classify() and
	 * isCoreFile() without any database connection.  Pass an empty array to reset the
	 * state back to "empty map loaded".
	 *
	 * @param   array<string,string>  $map  Relative path → checksum entries to inject.
	 *
	 * @return  void
	 * @internal
	 * @since   7.8.0
	 */
	public static function setMapForTesting(array $map): void
	{
		self::$map      = $map;
		self::$coreDirs = null; // force re-derivation from the new map on next classify() call
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns (and memoizes) the flat list of derived core directories.
	 *
	 * Built once from getMap() keys according to the Group A / B / C rules documented in the
	 * class-level docblock, then cached in self::$coreDirs for subsequent calls.
	 *
	 * @return  string[]
	 * @since   7.8.0
	 */
	private static function getCoreDirs(): array
	{
		if (self::$coreDirs !== null)
		{
			return self::$coreDirs;
		}

		$map  = self::getMap();
		$dirs = [];

		// Without checksum data we cannot tell a core file from a non-core one, therefore there are no core
		// directories to speak of. Returning the fixed Group C directories in this case would misclassify Joomla's own
		// files in includes/, administrator/includes/ and administrator/help/ as non-core.
		if (empty($map))
		{
			return self::$coreDirs = [];
		}

		foreach ($map as $rel => $checksum)
		{
			// --- Group A: first-level subdir of a well-known base ---
			foreach (self::GROUP_A_BASES as $base)
			{
				$prefix    = $base . '/';
				$prefixLen = strlen($prefix);

				if (strncmp($rel, $prefix, $prefixLen) !== 0)
				{
					continue;
				}

				$rest     = substr($rel, $prefixLen);
				$slashPos = strpos($rest, '/');
				$segment  = ($slashPos !== false) ? substr($rest, 0, $slashPos) : $rest;

				if ($segment !== '')
				{
					$dirs[$base . '/' . $segment] = true;
				}

				// Each map key can only belong to one Group A base; move on.
				break;
			}

			// --- Group B: second-level subdir of plugins/ ---
			if (strncmp($rel, 'plugins/', 8) === 0)
			{
				$rest  = substr($rel, 8); // strip leading 'plugins/'
				$parts = explode('/', $rest, 3);

				// Need at least <type> and <name> (two non-empty segments).
				if (isset($parts[1]) && $parts[1] !== '' && $parts[0] !== '')
				{
					$dirs['plugins/' . $parts[0] . '/' . $parts[1]] = true;
				}
			}
		}

		// --- Group C: fixed directories always treated as core ---
		foreach (self::GROUP_C_DIRS as $fixedDir)
		{
			$dirs[$fixedDir] = true;
		}

		return self::$coreDirs = array_keys($dirs);
	}

	/**
	 * Fetches the body of a URL via Joomla's HTTP factory.
	 *
	 * Uses a Joomla-branded user agent string and follows redirects.  Returns `null` on any
	 * non-2xx HTTP status or on transport-level errors, so the caller can treat the return value
	 * as a simple success/failure signal.
	 *
	 * @param   string  $url  The URL to fetch.
	 *
	 * @return  string|null  The raw response body on success, `null` on failure.
	 * @since   7.8.0
	 */
	private static function retrieveURL(string $url): ?string
	{
		try
		{
			$params  = ComponentHelper::getParams('com_admintools');
			$timeout = (int) $params->get('coresums_timeout', 5);

			if ($timeout < 1)
			{
				$timeout = 5;
			}

			$response = (new HttpFactory())
				->getHttp(
					[
						'follow_location' => true,
						'userAgent'       => (new Version())->getUserAgent('Joomla', true, false),
						'timeout'         => $timeout,
					]
				)
				->get($url);

			if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300)
			{
				return null;
			}

			return (string) $response->getBody() ?: null;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Deletes a single row from `#__admintools_storage` by key.
	 *
	 * Throws on DB error so that the calling context can catch it and return false.
	 *
	 * @param   string  $key  The storage key.
	 *
	 * @return  void
	 * @since   7.8.0
	 */
	private static function deleteStorageKey(string $key): void
	{
		KeyValueStorage::remove($key);
	}

	/**
	 * Loads a single value from `#__admintools_storage` by key.
	 *
	 * @param   string  $key  The storage key.
	 *
	 * @return  string|null  The stored value, or `null` if the key does not exist or DB fails.
	 * @since   7.8.0
	 */
	private static function loadStorageKey(string $key): ?string
	{
		try
		{
			return KeyValueStorage::get($key) ?: null;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Persists a single key/value pair to `#__admintools_storage`, replacing any existing row.
	 *
	 * Throws on DB error so that the calling context in refresh() can catch it and return false.
	 *
	 * @param   string  $key    The storage key.
	 * @param   string  $value  The value to store.
	 *
	 * @return  void
	 * @since   7.8.0
	 */
	private static function saveStorageKey(string $key, string $value): void
	{
		KeyValueStorage::set($key, $value);
	}
}
