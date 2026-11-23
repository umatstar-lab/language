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

/**
 * Key/value rows of the #__admintools_storage table.
 *
 * This is the one place which reads and writes that table's rows. Values are stored as given: raw strings with get()
 * and set(), or JSON with getJson() and setJson(). The WAF configuration is one such row (`cparams`), managed by the
 * Storage helper on top of this one.
 *
 * It is a static helper, not a DI service, because the system plugin's WAF features use it as well, and they must not
 * depend on booting the component.
 *
 * Every method takes an optional database driver, for callers which already hold a specific one.
 *
 * @since  7.10.0
 */
final class KeyValueStorage
{
	/**
	 * Get a raw value.
	 *
	 * @param   string               $key      The storage key
	 * @param   string|null          $default  Returned when there is no such key, or on database error
	 * @param   DatabaseDriver|null  $db       The database driver; NULL for the site's
	 *
	 * @return  string|null
	 * @since   7.10.0
	 */
	public static function get(string $key, ?string $default = null, ?DatabaseInterface $db = null): ?string
	{
		$db    = $db ?? self::getDatabase();
		$query = DbQuery::create($db)
			->select($db->quoteName('value'))
			->from($db->quoteName('#__admintools_storage'))
			->where($db->quoteName('key') . ' = :key')
			->bind(':key', $key);

		try
		{
			$value = $db->setQuery($query)->loadResult();
		}
		catch (\Exception $e)
		{
			return $default;
		}

		return $value === null ? $default : (string) $value;
	}

	/**
	 * Set a raw value, creating the row if necessary.
	 *
	 * Updates first and only inserts when there is no row, so two requests writing the same key at the same time
	 * cannot make one of them fail on the primary key, nor leave the key momentarily missing (as delete-then-insert
	 * would).
	 *
	 * @param   string               $key    The storage key
	 * @param   string               $value  The value
	 * @param   DatabaseDriver|null  $db     The database driver; NULL for the site's
	 *
	 * @return  void
	 * @throws  \RuntimeException  On database error
	 * @since   7.10.0
	 */
	public static function set(string $key, string $value, ?DatabaseInterface $db = null): void
	{
		$db    = $db ?? self::getDatabase();
		$query = DbQuery::create($db)
			->update($db->quoteName('#__admintools_storage'))
			->set($db->quoteName('value') . ' = :value')
			->where($db->quoteName('key') . ' = :key')
			->bind(':value', $value)
			->bind(':key', $key);

		$db->setQuery($query)->execute();

		// MySQL reports zero affected rows when the UPDATE writes the value already stored; hence exists().
		if ($db->getAffectedRows() > 0 || self::exists($key, $db))
		{
			return;
		}

		$object = (object) [
			'key'   => $key,
			'value' => $value,
		];

		try
		{
			$db->insertObject('#__admintools_storage', $object);
		}
		catch (\RuntimeException $e)
		{
			// Another request inserted the row in the meantime; its value is just as fresh.
			if (!self::exists($key, $db))
			{
				throw $e;
			}
		}
	}

	/**
	 * Get a JSON-encoded value, decoded to arrays.
	 *
	 * @param   string               $key      The storage key
	 * @param   mixed                $default  Returned when there is no such key, or it cannot be decoded
	 * @param   DatabaseDriver|null  $db       The database driver; NULL for the site's
	 *
	 * @return  mixed
	 * @since   7.10.0
	 */
	public static function getJson(string $key, $default = null, ?DatabaseInterface $db = null)
	{
		$json = self::get($key, null, $db);

		if ($json === null || $json === '')
		{
			return $default;
		}

		$value = json_decode($json, true);

		return $value ?? $default;
	}

	/**
	 * Set a value, JSON-encoded. Invalid UTF-8 (e.g. in file names) is substituted rather than failing the encoding.
	 *
	 * @param   string               $key    The storage key
	 * @param   mixed                $value  Any JSON-serialisable value
	 * @param   DatabaseDriver|null  $db     The database driver; NULL for the site's
	 *
	 * @return  void
	 * @throws  \RuntimeException  On database error
	 * @since   7.10.0
	 */
	public static function setJson(string $key, $value, ?DatabaseInterface $db = null): void
	{
		self::set($key, (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), $db);
	}

	/**
	 * Is there a row for the key?
	 *
	 * @param   string               $key  The storage key
	 * @param   DatabaseDriver|null  $db   The database driver; NULL for the site's
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function exists(string $key, ?DatabaseInterface $db = null): bool
	{
		$db    = $db ?? self::getDatabase();
		$query = DbQuery::create($db)
			->select('COUNT(*)')
			->from($db->quoteName('#__admintools_storage'))
			->where($db->quoteName('key') . ' = :key')
			->bind(':key', $key);

		try
		{
			return (int) $db->setQuery($query)->loadResult() > 0;
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	/**
	 * Remove a key.
	 *
	 * @param   string               $key  The storage key
	 * @param   DatabaseDriver|null  $db   The database driver; NULL for the site's
	 *
	 * @return  void
	 * @throws  \RuntimeException  On database error
	 * @since   7.10.0
	 */
	public static function remove(string $key, ?DatabaseInterface $db = null): void
	{
		$db    = $db ?? self::getDatabase();
		$query = DbQuery::create($db)
			->delete($db->quoteName('#__admintools_storage'))
			->where($db->quoteName('key') . ' = :key')
			->bind(':key', $key);

		$db->setQuery($query)->execute();
	}

	private static function getDatabase(): DatabaseDriver
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}
}
