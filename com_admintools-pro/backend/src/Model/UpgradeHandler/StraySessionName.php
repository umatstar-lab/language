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
use Akeeba\Component\AdminTools\Administrator\Service\JoomlaConfiguration;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Custom UpgradeModel handler removing the session_name earlier versions wrote into configuration.php.
 *
 * Released versions up to 7.9.4 (Check Temp and Log Directories) and 7.10.0 development versions (Site Health Check)
 * wrote the application's runtime configuration into configuration.php, including `session_name = 'administrator'`.
 * With Shared Sessions disabled, that gives the frontend and the backend the same session cookie, i.e. their sessions
 * are silently shared. We remove it, with the other runtime-only values written along with it, exactly once. The flag
 * row in the storage table makes sure we never run again.
 *
 * The Site Health Check (Pro) reports the same problem afterwards, e.g. when configuration.php could not be written.
 *
 * configuration.php is written through the component's JoomlaConfiguration service, which ships in Core too.
 *
 * Nothing in here may make the installation or the update fail.
 *
 * @since 7.10.0
 */
class StraySessionName
{
	/**
	 * The storage row recording that the migration has run (or was not needed).
	 *
	 * @since 7.10.0
	 */
	public const FLAG_ROW = 'migration.stray_session_name';

	public function __construct(UpgradeModel $upgradeModel, DatabaseDriver $dbo)
	{
	}

	/**
	 * Fresh installation: this version never writes a stray session_name. Just set the flag.
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
	 * Update: remove a session_name set while Shared Sessions are disabled, once.
	 *
	 * This logs out everyone logged into the frontend, once.
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

			$service = $this->makeConfigurationService();

			if (JoomlaConfiguration::hasStraySessionName($service->getOnDisk()))
			{
				// Throws when configuration.php cannot be written: no flag then, so we try again on the next update.
				$service->removeStrayRuntimeKeys();
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
	 * The installer script runs before the component's namespace is reliably autoloadable. Load what we use.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function loadClasses(): void
	{
		$classes = [
			'Akeeba\Component\AdminTools\Administrator\Helper\DbQuery'              => '/../../Helper/DbQuery.php',
			'Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage'      => '/../../Helper/KeyValueStorage.php',
			'Akeeba\Component\AdminTools\Administrator\Service\JoomlaConfiguration' => '/../../Service/JoomlaConfiguration.php',
		];

		foreach ($classes as $class => $file)
		{
			if (!class_exists($class))
			{
				@include_once __DIR__ . $file;
			}
		}
	}

	/**
	 * The service which writes configuration.php. Overridable for tests.
	 *
	 * Normally the booted component's service. A component booted earlier in this request, i.e. before the update
	 * replaced its files, is still the old version's, which may not have the service yet; then we create the service
	 * the way the component's service provider does.
	 *
	 * @return  JoomlaConfiguration
	 * @since   7.10.0
	 */
	protected function makeConfigurationService(): JoomlaConfiguration
	{
		$app = Factory::getApplication();

		try
		{
			$component = $app->bootComponent('com_admintools');

			if (method_exists($component, 'getJoomlaConfigurationService'))
			{
				$service = $component->getJoomlaConfigurationService();

				if ($service instanceof JoomlaConfiguration)
				{
					return $service;
				}
			}
		}
		catch (Throwable $e)
		{
			// Fall through.
		}

		return new JoomlaConfiguration($app);
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
}
