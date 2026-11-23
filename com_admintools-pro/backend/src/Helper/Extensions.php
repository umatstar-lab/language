<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Lookups and changes on Joomla's #__extensions table.
 *
 * This is the one place which reads extension records directly from the database. Joomla's own PluginHelper and
 * ComponentHelper only know about enabled extensions (on Joomla 5 PluginHelper::getPlugin() does not even return a
 * disabled plugin), so code which must also see disabled extensions uses this helper.
 *
 * It is a static helper, not a DI service, on purpose: the system plugin uses it on every request, including the
 * rescue and anti-deactivation code paths, which must keep working without booting the component.
 *
 * Extensions are identified by name:
 * - `pkg_something`       package
 * - `com_something`       component
 * - `plg_folder_element`  plugin
 * - `mod_something`       site module
 * - `amod_something`      administrator module (an Akeeba convention, not Joomla's)
 * - `file_something`      file extension (the element is the full name)
 * - `lib_something`       library
 *
 * @since  7.10.0
 */
final class Extensions
{
	/**
	 * Extension IDs already looked up, by extension name. Only successful lookups are kept.
	 *
	 * @var   int[]
	 * @since 7.10.0
	 */
	private static $ids = [];

	/**
	 * Convert an extension name to #__extensions query criteria.
	 *
	 * @param   string  $extensionName  e.g. plg_system_admintools
	 *
	 * @return  array  Column => value; empty for an unsupported name
	 * @since   7.10.0
	 */
	public static function nameToCriteria(string $extensionName): array
	{
		$parts = explode('_', $extensionName, 3);

		switch ($parts[0])
		{
			case 'pkg':
				return [
					'type'    => 'package',
					'element' => $extensionName,
				];

			case 'com':
				return [
					'type'    => 'component',
					'element' => $extensionName,
				];

			case 'plg':
				if (count($parts) < 3)
				{
					return [];
				}

				return [
					'type'    => 'plugin',
					'folder'  => $parts[1],
					'element' => $parts[2],
				];

			case 'mod':
				return [
					'type'      => 'module',
					'element'   => $extensionName,
					'client_id' => 0,
				];

			// That's how we note admin modules
			case 'amod':
				return [
					'type'      => 'module',
					'element'   => substr($extensionName, 1),
					'client_id' => 1,
				];

			case 'file':
				return [
					'type'    => 'file',
					'element' => $extensionName,
				];

			case 'lib':
				if (count($parts) < 2)
				{
					return [];
				}

				return [
					'type'    => 'library',
					'element' => $parts[1],
				];
		}

		return [];
	}

	/**
	 * Get an extension's ID, whether it is enabled or not.
	 *
	 * @param   string  $extensionName  e.g. plg_system_admintools
	 *
	 * @return  int|null  NULL if there is no such extension
	 * @since   7.10.0
	 */
	public static function getId(string $extensionName): ?int
	{
		if (isset(self::$ids[$extensionName]))
		{
			return self::$ids[$extensionName];
		}

		$record = self::getRecordByCriteria(self::nameToCriteria($extensionName), ['extension_id']);
		$id     = empty($record) ? 0 : (int) $record->extension_id;

		if ($id <= 0)
		{
			return null;
		}

		return self::$ids[$extensionName] = $id;
	}

	/**
	 * Get an extension's #__extensions record, whether it is enabled or not.
	 *
	 * @param   string  $extensionName  e.g. plg_system_admintools
	 *
	 * @return  object|null  All columns; params is the raw JSON string
	 * @since   7.10.0
	 */
	public static function getRecord(string $extensionName): ?object
	{
		return self::getRecordByCriteria(self::nameToCriteria($extensionName));
	}

	/**
	 * Get an extension's #__extensions record by its ID.
	 *
	 * @param   int  $extensionId
	 *
	 * @return  object|null  All columns; params is the raw JSON string
	 * @since   7.10.0
	 */
	public static function getRecordById(int $extensionId): ?object
	{
		return $extensionId > 0 ? self::getRecordByCriteria(['extension_id' => $extensionId]) : null;
	}

	/**
	 * Get the first #__extensions record matching the criteria.
	 *
	 * @param   array     $criteria  Column => value. Empty criteria never match anything.
	 * @param   string[]  $columns   Columns to return; empty for all.
	 *
	 * @return  object|null
	 * @since   7.10.0
	 */
	public static function getRecordByCriteria(array $criteria, array $columns = []): ?object
	{
		if (empty($criteria))
		{
			return null;
		}

		$db       = self::getDatabase();
		$query    = DbQuery::create($db)
			->select(empty($columns) ? '*' : $db->quoteName($columns))
			->from($db->quoteName('#__extensions'));
		$bindVars = [];

		foreach ($criteria as $column => $value)
		{
			// bind() keeps a reference, so each value needs its own variable which outlives this loop.
			$bindVars[$column] = $value;

			$query->where($db->quoteName($column) . ' = :' . $column)
				->bind(
					':' . $column,
					$bindVars[$column],
					is_int($value) ? ParameterType::INTEGER : ParameterType::STRING
				);
		}

		try
		{
			return $db->setQuery($query, 0, 1)->loadObject() ?: null;
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	/**
	 * Get an extension's parameters, whether it is enabled or not.
	 *
	 * @param   string  $extensionName  e.g. plg_system_admintools
	 *
	 * @return  Registry  Empty if there is no such extension
	 * @since   7.10.0
	 */
	public static function getParams(string $extensionName): Registry
	{
		$record = self::getRecordByCriteria(self::nameToCriteria($extensionName), ['params']);

		try
		{
			return new Registry($record->params ?? '{}');
		}
		catch (\Exception $e)
		{
			return new Registry();
		}
	}

	/**
	 * Is the extension enabled? Unlike PluginHelper::isEnabled() this does not depend on the current user's access.
	 *
	 * @param   string  $extensionName  e.g. plg_system_debug
	 *
	 * @return  bool  False if there is no such extension
	 * @since   7.10.0
	 */
	public static function isEnabled(string $extensionName): bool
	{
		$record = self::getRecordByCriteria(self::nameToCriteria($extensionName), ['enabled']);

		return !empty($record) && (int) $record->enabled === 1;
	}

	/**
	 * Enable or disable extensions by ID.
	 *
	 * Cleaning Joomla's plugin cache afterwards is the caller's business; use the component's CacheCleaner service.
	 *
	 * @param   int[]  $extensionIds
	 * @param   bool   $enabled
	 *
	 * @return  void
	 * @throws  \RuntimeException  On database error
	 * @since   7.10.0
	 */
	public static function setEnabledById(array $extensionIds, bool $enabled): void
	{
		$extensionIds = array_values(array_filter(array_map('intval', $extensionIds)));

		if (empty($extensionIds))
		{
			return;
		}

		$db    = self::getDatabase();
		$state = $enabled ? 1 : 0;
		$query = DbQuery::create($db)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = :enabled')
			->whereIn($db->quoteName('extension_id'), $extensionIds, ParameterType::INTEGER)
			->bind(':enabled', $state, ParameterType::INTEGER);

		$db->setQuery($query)->execute();
	}

	/**
	 * Enable or disable an extension by name.
	 *
	 * @param   string  $extensionName  e.g. plg_system_debug
	 * @param   bool    $enabled
	 *
	 * @return  void
	 * @throws  \RuntimeException  On database error
	 * @since   7.10.0
	 */
	public static function setEnabled(string $extensionName, bool $enabled): void
	{
		$id = self::getId($extensionName);

		if ($id !== null)
		{
			self::setEnabledById([$id], $enabled);
		}
	}

	private static function getDatabase(): DatabaseDriver
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}
}
