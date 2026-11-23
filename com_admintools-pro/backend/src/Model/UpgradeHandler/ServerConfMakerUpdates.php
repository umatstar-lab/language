<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model\UpgradeHandler;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Akeeba\Component\AdminTools\Administrator\Model\UpgradeModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Custom UpgradeModel handler for the lists in the saved configurations of the server configuration makers.
 *
 * From 7.0.5 to 7.9.x this handler merged fixed lists of back-end and front-end file types, the `.well-known` folder
 * and the Joomla Update / Akeeba Backup restore scripts into the saved configurations of the .htaccess Maker, NginX
 * Conf Maker and web.config Maker on EVERY update. That undid entries a site owner had deliberately removed. On lists
 * stored as strings, or missing, it also threw a TypeError which UpgradeModel swallowed, so it stopped partway.
 *
 * Those lists last changed in May 2022, so every site updated since already has every entry. The handler now only
 * writes its flag row in the storage table, on install and on update, and never touches the saved configurations
 * again. Any future change to the Makers' saved lists needs its own run-once handler; see FileTypesJsonBpe.
 *
 * Do not delete this file: Joomla never removes files dropped from the package, and UpgradeModel loads every handler
 * before removeObsoleteFiles runs, so the old, merging copy would stay behind and keep running on every update.
 *
 * Nothing in here may make the installation or the update fail.
 *
 * @since 7.0.5
 * @see   https://github.com/akeeba/admintools/issues/438
 */
class ServerConfMakerUpdates
{
	/**
	 * The storage row recording that this handler must never change the saved Maker configurations.
	 *
	 * @since 7.10.0
	 */
	public const FLAG_ROW = 'migration.server_conf_maker_updates';

	/**
	 * Constructor.
	 *
	 * @param   UpgradeModel    $upgradeModel  The UpgradeModel instance we belong to
	 * @param   DatabaseDriver  $dbo           The database driver
	 *
	 * @since   9.0.0
	 */
	public function __construct(UpgradeModel $upgradeModel, DatabaseDriver $dbo)
	{
	}

	/**
	 * Fresh installation: the Makers' defaults are current. Set the flag.
	 *
	 * @param   string               $type    The installation type.
	 * @param   PackageAdapter|null  $parent  The package adapter.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function onInstall(string $type, ?PackageAdapter $parent = null)
	{
		$this->setFlag();
	}

	/**
	 * Update: set the flag, without changing the saved Maker configurations.
	 *
	 * @param   string               $type    The installation type.
	 * @param   PackageAdapter|null  $parent  The package adapter.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function onUpdate(string $type, ?PackageAdapter $parent = null)
	{
		$this->setFlag();
	}

	/**
	 * Writes the flag row, unless it already exists.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function setFlag(): void
	{
		try
		{
			$this->loadClasses();

			$db = $this->makeDatabase();

			if (KeyValueStorage::get(self::FLAG_ROW, null, $db) !== null)
			{
				return;
			}

			KeyValueStorage::set(self::FLAG_ROW, '1', $db);
		}
		catch (Throwable $e)
		{
			// Never fail an installation or update because of this.
		}
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
}
