<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model\UpgradeHandler;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Akeeba\Component\AdminTools\Administrator\Model\UpgradeModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Custom UpgradeModel handler removing the former default search engine domains from "Never block these domains".
 *
 * Until 7.9.3 the default list hard-coded nine search engine domains (Baidu, Google, Bing, Yahoo, Yandex). Search
 * engines and AI crawlers are now handled by the Crawlers tab of Configure WAF. Site owners did not choose these
 * entries, so we remove them, unless the administrator gave the entry a description (i.e. they deliberately maintain
 * it).
 *
 * This runs exactly once. The flag row in the storage table makes sure we never run again: an idempotent handler
 * would delete an entry which the administrator later re-added without a description, on every update.
 *
 * Nothing in here may make the installation or the update fail.
 *
 * @since 7.9.4
 */
class RetiredNeverBlockDomains
{
	/**
	 * The storage row recording that the migration has run (or was not needed).
	 *
	 * @since 7.9.4
	 */
	public const FLAG_ROW = 'migration.retired_neverblock_domains';

	/**
	 * The former default domains.
	 *
	 * @since 7.9.4
	 */
	private const RETIRED_DOMAINS = [
		'.crawl.baidu.com', '.crawl.baidu.jp', '.google.com', '.googlebot.com', '.search.msn.com',
		'.crawl.yahoo.net', '.yandex.ru', '.yandex.net', '.yandex.com',
	];

	public function __construct(UpgradeModel $upgradeModel, DatabaseDriver $dbo)
	{
	}

	/**
	 * Fresh installation: the defaults are empty already. Just set the flag.
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
	 * Update: remove the undescribed former default domains, once.
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
			$stored  = $storage->getValue('whitelist_domains', null);

			// Never saved: the site runs on the (now empty) defaults.
			if ($stored !== null)
			{
				$entries = $this->normalise($stored);
				$kept    = array_values(array_filter($entries, function (array $entry): bool {
					return $entry[1] !== '' || !in_array(strtolower($entry[0]), self::RETIRED_DOMAINS, true);
				}));

				if (count($kept) !== count($entries))
				{
					$storage->setValue('whitelist_domains', $kept);
					$storage->save();
				}
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
	 * Normalise the stored list to an array of [domain, description] pairs, in the original order.
	 *
	 * Handles the pair, string and object forms, and the legacy comma-separated and one-per-line strings
	 * (see ConfigurewafModel::getConfig()). Entries without a domain are dropped.
	 *
	 * @param   mixed  $value  The stored value.
	 *
	 * @return  array<int, array{0: string, 1: string}>
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

		$entries = [];

		foreach ($value as $entry)
		{
			if (is_object($entry))
			{
				$entry = (array) $entry;
			}

			if (is_array($entry))
			{
				$domain      = $entry[0] ?? $entry['item'] ?? '';
				$description = $entry[1] ?? $entry['description'] ?? '';
			}
			else
			{
				$domain      = $entry;
				$description = '';
			}

			$domain      = is_scalar($domain) ? trim((string) $domain) : '';
			$description = is_scalar($description) ? trim((string) $description) : '';

			if ($domain === '')
			{
				continue;
			}

			$entries[] = [$domain, $description];
		}

		return $entries;
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
