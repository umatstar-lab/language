<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Scanner;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Configuration;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Filesystem;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;

/**
 * Implements directory and file exclusion filters
 */
class Filter
{
	/**
	 * Scanner configuration
	 *
	 * @var   Configuration
	 */
	private Configuration $configuration;

	/**
	 * Excluded directories (relative to site's root)
	 *
	 * @var   array
	 */
	private array $directoryFilters = [];

	/**
	 * The scanner's built-in directory exclusions (temporary, log and cache folders), a subset of $directoryFilters.
	 *
	 * @var   array
	 * @since 7.10.0
	 */
	private array $defaultDirectoryFilters = [];

	/**
	 * Excluded files (relative to site's root)
	 *
	 * @var   array
	 */
	private array $fileFilters = [];

	/**
	 * File extensions to scan
	 *
	 * @var   array
	 */
	private array $scanExtensions = [];

	private bool $scanDoubleExtensions = true;

	private bool $caseInsensitiveExtensions = false;

	/**
	 * Filter constructor.
	 *
	 * @param   Configuration  $configuration
	 *
	 * @return  void
	 */
	public function __construct(Configuration $configuration)
	{
		$this->configuration             = $configuration;
		$this->scanDoubleExtensions = in_array($configuration->get('doubleExtensions'), [true, 1, '1'], true);
		$this->caseInsensitiveExtensions = in_array(
			$configuration->get('caseInsensitiveExtensions'), [true, 1, '1'], true
		);
		$this->loadFilters();
	}

	/**
	 * Is this folder explicitly excluded?
	 *
	 * @param   string  $folder
	 *
	 * @return  bool
	 */
	public function isExcludedFolder($folder)
	{
		return in_array(Filesystem::relativePath($folder), $this->directoryFilters);
	}

	/**
	 * Is this folder one of the scanner's built-in exclusions (temporary, log and cache folders)?
	 *
	 * @param   string  $folder
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function isDefaultExcludedFolder($folder): bool
	{
		return in_array(Filesystem::relativePath($folder), $this->defaultDirectoryFilters);
	}

	/**
	 * Is this file explicitly excluded?
	 *
	 * @param   string  $file
	 *
	 * @return  bool
	 */
	public function isExcludedFile($file)
	{
		return in_array(Filesystem::relativePath($file), $this->fileFilters);
	}

	/**
	 * is the file excluded because of its extension?
	 *
	 * @param   string  $file
	 *
	 * @return  bool
	 */
	public function isExcludedByExtension($file)
	{
		return !self::fileHasScannedExtension(
			$file,
			$this->scanExtensions,
			$this->scanDoubleExtensions,
			$this->caseInsensitiveExtensions
		);
	}

	/**
	 * Does the file match one of the scanned extensions?
	 *
	 * Only the basename is considered. When double-extension scanning is disabled only the final
	 * extension is checked. When it is enabled, EVERY dot-separated extension segment of the
	 * basename (i.e. everything after the first dot) is checked — so "shell.php.jpg" and
	 * "evil.jpg.php" match on "php", while "php_elephants.jpg" (whose name merely contains "php")
	 * and directory names such as "includes/" do NOT.
	 *
	 * @param   string    $file                       File name or path.
	 * @param   string[]  $scanExtensions             Normalised list of scanned extensions (no leading dot).
	 * @param   bool      $scanDoubleExtensions       Whether to scan double-extension files.
	 * @param   bool      $caseInsensitiveExtensions  Whether extension matching is case-insensitive.
	 *
	 * @return  bool
	 */
	public static function fileHasScannedExtension(
		string $file, array $scanExtensions, bool $scanDoubleExtensions, bool $caseInsensitiveExtensions
	): bool
	{
		$basename = pathinfo($file, PATHINFO_BASENAME);

		// Without double-extension scanning we only consider the final extension.
		if (!$scanDoubleExtensions)
		{
			$extension = pathinfo($basename, PATHINFO_EXTENSION);

			if ($caseInsensitiveExtensions)
			{
				$extension = strtolower($extension);
			}

			return in_array($extension, $scanExtensions, true);
		}

		// With double-extension scanning, check every dot-separated extension segment of the basename.
		$segments = explode('.', $basename);
		array_shift($segments); // Drop the file name; keep only the extension segments.

		foreach ($segments as $segment)
		{
			if ($caseInsensitiveExtensions)
			{
				$segment = strtolower($segment);
			}

			if (in_array($segment, $scanExtensions, true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Load the filters from the scanner configuration
	 *
	 * @return  void
	 */
	private function loadFilters()
	{
		$dirFilters             = $this->configuration->get('directoryFilters');
		$this->directoryFilters = array_unique(
			array_filter(
				array_map(
					[Filesystem::class, 'relativePath'],
					is_array($dirFilters) ? $dirFilters : $this->stringToArray($dirFilters)
				)
			)
		);
		asort($this->directoryFilters);

		$this->addDefaultDirectoryFilters();

		$fileFilters       = $this->configuration->get('fileFilters');
		$this->fileFilters = array_unique(
			array_filter(
				array_map(
					[Filesystem::class, 'relativePath'],
					is_array($fileFilters) ? $fileFilters : $this->stringToArray($fileFilters)
				)
			)
		);
		asort($this->fileFilters);

		$scanExtensions       = $this->configuration->get('scanExtensions');
		$this->scanExtensions = array_unique(
			array_filter(
				array_map(
					fn($x) => ltrim($this->caseInsensitiveExtensions ? strtolower($x) : $x, '.'),
					is_array($scanExtensions) ? $scanExtensions : $this->stringToArray($scanExtensions)
				)
			)
		);
		asort($this->scanExtensions);
	}

	/**
	 * Returns an array of unique, non-empty elements from a newline- or comma-separated list
	 *
	 * @param   string  $string  The string to convert
	 *
	 * @return  array
	 */
	private function stringToArray($string)
	{
		// Explode the string by newlines and then by commas
		$entries = array_map(function ($x) {
			return explode(",", $x);
		}, explode("\n", $string));

		// The array is now in the form of [ ['a', 'b'], ['c'] ] -- Convert to flattened form ['a', 'b', 'c']
		$entries = array_reduce($entries, function ($carry, $x) {
			if (empty($x))
			{
				return $carry;
			}

			return array_merge($carry, array_map(function ($item) {
				return trim($item);
			}, $x));
		}, []);

		// Filter out empty elements and return the unique values
		return array_unique(
			array_filter($entries, function ($x) {
				return !empty($x);
			})
		);
	}

	/**
	 * Normalize the path of an excluded file or folder.
	 *
	 * Runs Filesystem::normalizePath and removes the site's root if it's used as the path prefix.
	 *
	 * @param   string  $path
	 *
	 * @return  string
	 */
	private function normalizePath($path)
	{
		return Filesystem::relativePath($path);
	}

	/**
	 * Add default directory filters.
	 *
	 * Prevents our scanner from scanning Joomla's temporary, log and cache folders.
	 *
	 * @return  void
	 */
	private function addDefaultDirectoryFilters()
	{
		/** @var CMSApplication $app */
		$app            = Factory::getApplication();
		$defaultFilters = [
			// Configured temporary and log path
			Filesystem::relativePath($app->get('tmp_path')),
			Filesystem::relativePath($app->get('log_path')),
			// Configured cache path
			Filesystem::relativePath(JPATH_CACHE),
			// Default cache paths for frontend and backend
			Filesystem::relativePath(JPATH_SITE . '/cache'),
			Filesystem::relativePath(JPATH_ADMINISTRATOR . '/cache'),
			// Default and common mistake temporary folders
			Filesystem::relativePath(JPATH_ADMINISTRATOR . '/tmp'),
			Filesystem::relativePath(JPATH_SITE . '/tmp'),
			// New default, old default and common mistake log paths
			Filesystem::relativePath(JPATH_ADMINISTRATOR . '/logs'),
			Filesystem::relativePath(JPATH_SITE . '/logs'),
			Filesystem::relativePath(JPATH_ADMINISTRATOR . '/log'),
			Filesystem::relativePath(JPATH_SITE . '/log'),
			// This should not be possible (but I've seen some weird things in my time...)
			'installation',
		];

		$this->defaultDirectoryFilters = array_values(array_unique($defaultFilters));
		$this->directoryFilters        = array_unique(
			array_merge(
				$this->directoryFilters,
				$defaultFilters
			)
		);
	}

}
