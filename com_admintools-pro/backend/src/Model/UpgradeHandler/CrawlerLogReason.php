<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model\UpgradeHandler;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Akeeba\Component\AdminTools\Administrator\Model\UpgradeModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Custom UpgradeModel handler adding the `crawler` block reason to the "Never log" and "Never send email" lists.
 *
 * Crawler IP list blocks (gh-376) can be very frequent, so by default they are neither logged nor emailed. New
 * installations and sites which never saved Configure WAF get that from the defaults. Sites which did save it have
 * explicit lists which do not contain `crawler`, so we add it, exactly once. The flag row in the storage table makes
 * sure we never run again, i.e. we never re-add `crawler` after an administrator deliberately removed it.
 *
 * Nothing in here may make the installation or the update fail.
 *
 * @since 7.9.4
 */
class CrawlerLogReason
{
	/**
	 * The storage row recording that the migration has run (or was not needed).
	 *
	 * @since 7.9.4
	 */
	public const FLAG_ROW = 'migration.crawler_log_reason';

	/**
	 * The WAF settings which get the new reason.
	 *
	 * @since 7.9.4
	 */
	private const SETTINGS = ['reasons_nolog', 'reasons_noemail'];

	public function __construct(UpgradeModel $upgradeModel, DatabaseDriver $dbo)
	{
	}

	/**
	 * Fresh installation: the defaults already include `crawler`. Just set the flag.
	 *
	 * @param   string               $type    The installation type.
	 * @param   PackageAdapter|null  $parent  The package adapter.
	 *
	 * @return  void
	 * @since   7.9.4
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
	 * Update: add `crawler` to both lists, once.
	 *
	 * @param   string               $type    The installation type.
	 * @param   PackageAdapter|null  $parent  The package adapter.
	 *
	 * @return  void
	 * @since   7.9.4
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

			$storage = $this->makeStorage();
			$changed = false;

			foreach (self::SETTINGS as $key)
			{
				$stored = $storage->getValue($key, null);

				// Never saved: the site runs on the defaults, which include `crawler` already.
				if ($stored === null)
				{
					continue;
				}

				$list = $this->normalise($stored);

				if (in_array('crawler', $list, true))
				{
					continue;
				}

				$list[] = 'crawler';

				$storage->setValue($key, $list);
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
	 * Handles the array and object forms, and the legacy comma-separated and one-per-line strings
	 * (see ConfigurewafModel::getConfig()).
	 *
	 * @param   mixed  $value  The stored value.
	 *
	 * @return  string[]
	 * @since   7.9.4
	 */
	private function normalise($value): array
	{
		if (is_object($value))
		{
			$value = (array) $value;
		}

		if (!is_array($value))
		{
			$value = str_replace(["\r\n", "\r"], "\n", trim((string) $value));
			$value = explode(strpos($value, "\n") !== false ? "\n" : ',', $value);
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
	 * @since   7.9.4
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
	 * This deliberately does not use the crawler list state store helper: that code is Pro only, whereas upgrade
	 * handlers run in Core too.
	 *
	 * @return  DatabaseInterface
	 * @since   7.9.4
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
	 * @since   7.9.4
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
	 * @since   7.9.4
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
	 * Creates the object used to read and write the WAF configuration. Overridable for tests.
	 *
	 * @return  Storage
	 * @since   7.9.4
	 */
	protected function makeStorage(): Storage
	{
		return new Storage();
	}
}
