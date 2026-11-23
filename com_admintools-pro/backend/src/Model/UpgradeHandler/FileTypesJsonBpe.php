<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model\UpgradeHandler;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Model\UpgradeModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Custom UpgradeModel handler adding json and bpe to the back-end and front-end file types of the web.config and NginX
 * Conf Makers.
 *
 * The default "Back-end / Front-end file types allowed in selected directories" of these two Makers lacked json and
 * bpe, which the .htaccess Maker has always had. With Server Protection on, files such as
 * media/system/joomla.asset.json were refused on IIS and NginX. The defaults now include both. Sites which saved either
 * Maker's configuration have explicit lists, so we add the two types to them, exactly once. The flag row in the storage
 * table makes sure we never run again, i.e. we never re-add a type after an administrator deliberately removed it.
 *
 * The .htaccess Maker's configuration is left alone. Its default has always had both types.
 *
 * It does NOT regenerate the server configuration files. The control panel already tells the user when a file made by
 * Admin Tools is out of date.
 *
 * Nothing in here may make the installation or the update fail.
 *
 * @since 7.10.0
 */
class FileTypesJsonBpe
{
	/**
	 * The storage row recording that the migration has run (or was not needed).
	 *
	 * @since 7.10.0
	 */
	public const FLAG_ROW = 'migration.extypes_json_bpe';

	/**
	 * The component settings holding the saved web.config and NginX Conf Maker configurations.
	 *
	 * @since 7.10.0
	 */
	private const STORAGE_KEYS = ['wcconfig', 'nginxconfig'];

	/**
	 * The file type lists of each Maker configuration: back-end and front-end.
	 *
	 * @since 7.10.0
	 */
	private const LIST_KEYS = ['bepextypes', 'fepextypes'];

	/**
	 * The file types to add.
	 *
	 * @since 7.10.0
	 */
	private const NEW_TYPES = ['json', 'bpe'];

	public function __construct(UpgradeModel $upgradeModel, DatabaseDriver $dbo)
	{
	}

	/**
	 * Fresh installation: the defaults already include json and bpe. Just set the flag.
	 *
	 * @param   string               $type    The installation type.
	 * @param   PackageAdapter|null  $parent  The package adapter.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function onInstall(string $type, ?PackageAdapter $parent = null)
	{
		try
		{
			$this->loadClasses();

			$this->writeFlag(self::FLAG_ROW, '1');
		}
		catch (Throwable $e)
		{
			// Never fail an installation because of this.
		}
	}

	/**
	 * Update: add json and bpe to the saved back-end and front-end file types, once.
	 *
	 * @param   string               $type    The installation type.
	 * @param   PackageAdapter|null  $parent  The package adapter.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function onUpdate(string $type, ?PackageAdapter $parent = null)
	{
		try
		{
			$this->loadClasses();

			if ($this->readFlag(self::FLAG_ROW) !== null)
			{
				return;
			}

			// Created here, not earlier: other handlers save the whole component configuration as well.
			$storage = $this->makeStorage();
			$changed = false;

			foreach (self::STORAGE_KEYS as $key)
			{
				$encoded = $storage->getValue($key, '');

				// Never saved: the Maker runs on the defaults, which include json and bpe already.
				if (!is_string($encoded) || trim($encoded) === '')
				{
					continue;
				}

				$config = @json_decode(@base64_decode($encoded) ?: '', true);

				// Corrupt; leave it alone.
				if (!is_array($config) || empty($config))
				{
					continue;
				}

				$configChanged = false;

				foreach (self::LIST_KEYS as $listKey)
				{
					/**
					 * No saved list: the default list applies. Do not create the key, it would replace the whole
					 * default list with just our two types.
					 */
					if (!array_key_exists($listKey, $config))
					{
						continue;
					}

					$types   = $this->normalise($config[$listKey]);
					$lower   = array_map('strtolower', $types);
					$missing = array_values(array_diff(self::NEW_TYPES, $lower));

					if (empty($missing))
					{
						continue;
					}

					$config[$listKey] = array_values(array_merge($types, $missing));
					$configChanged    = true;
				}

				if (!$configChanged)
				{
					continue;
				}

				$storage->setValue($key, base64_encode(json_encode($config)));
				$changed = true;
			}

			if ($changed)
			{
				$storage->save();
			}

			// Also when nothing changed: it must never run again.
			$this->writeFlag(self::FLAG_ROW, '1');
		}
		catch (Throwable $e)
		{
			// Never fail an update because of this.
		}
	}

	/**
	 * Normalise a stored list to a plain array of trimmed, non-empty strings, in the original order.
	 *
	 * Handles the array and object (gappy keys) forms, and the legacy comma-separated string (see
	 * ServerconfigmakerModel::handlePotentialArrayKeys()).
	 *
	 * @param   mixed  $value  The stored value.
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	private function normalise($value): array
	{
		if (is_object($value))
		{
			$value = (array) $value;
		}

		if (!is_array($value))
		{
			$value = explode(',', (string) $value);
		}

		$value = array_map(function ($x) {
			return trim((string) $x);
		}, $value);

		return array_values(array_unique(array_filter($value, function ($x) {
			return $x !== '';
		})));
	}

	/**
	 * The installer script runs before the component's namespace is reliably autoloadable. Load what we use.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function loadClasses(): void
	{
		$base    = 'Akeeba\Component\AdminTools\Administrator\Helper\\';
		$classes = [
			$base . 'DbQuery'         => 'DbQuery.php',
			$base . 'KeyValueStorage' => 'KeyValueStorage.php',
			$base . 'Storage'         => 'Storage.php',
		];

		foreach ($classes as $class => $file)
		{
			if (!class_exists($class))
			{
				@include_once __DIR__ . '/../../Helper/' . $file;
			}
		}
	}

	/**
	 * Returns the database driver. Overridable for tests.
	 *
	 * @return  DatabaseInterface
	 * @since   7.10.0
	 */
	protected function makeDatabase(): DatabaseInterface
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}

	/**
	 * Reads a row of the storage table.
	 *
	 * @param   string  $key  The row key.
	 *
	 * @return  string|null  NULL if the row does not exist or the query failed.
	 * @since   7.10.0
	 */
	private function readFlag(string $key): ?string
	{
		try
		{
			return KeyValueStorage::get($key, null, $this->makeDatabase());
		}
		catch (Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Creates or updates a row of the storage table (never DELETE and INSERT).
	 *
	 * @param   string  $key    The row key.
	 * @param   string  $value  The value.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function writeFlag(string $key, string $value): void
	{
		try
		{
			KeyValueStorage::set($key, $value, $this->makeDatabase());
		}
		catch (Throwable $e)
		{
			// Never fail an installation or update because of this.
		}
	}

	/**
	 * Creates the object used to read and write the component configuration. Overridable for tests.
	 *
	 * @return  Storage
	 * @since   7.10.0
	 */
	protected function makeStorage(): Storage
	{
		return new Storage();
	}
}
