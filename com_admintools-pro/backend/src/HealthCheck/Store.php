<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Joomla\Database\DatabaseDriver;

/**
 * Persistent storage for the Site Health Check: JSON values in their own rows of #__admintools_storage.
 *
 * Not final, so tests can substitute an in-memory store.
 *
 * @since  7.10.0
 */
class Store
{
	/** IDs of the checks the user chose to ignore. */
	public const KEY_IGNORED = 'sitehealthcheck.ignored';

	/** Items (e.g. files) the user chose to ignore: check ID => item keys. */
	public const KEY_IGNORED_ITEMS = 'sitehealthcheck.ignored_items';

	/** Cached results of expensive checks. */
	public const KEY_CACHE = 'sitehealthcheck.cache';

	/** Information collected by the last complete PHP File Change Scanner run. */
	public const KEY_SCAN = 'sitehealthcheck.scan';

	/** @var DatabaseDriver|null */
	private $db;

	public function __construct(?DatabaseDriver $db = null)
	{
		$this->db = $db;
	}

	/**
	 * Get a value.
	 *
	 * @param   string  $key      The storage key
	 * @param   mixed   $default  Returned when the key does not exist or cannot be decoded
	 *
	 * @return  mixed
	 * @since   7.10.0
	 */
	public function get(string $key, $default = null)
	{
		return KeyValueStorage::getJson($key, $default, $this->db);
	}

	/**
	 * Set a value.
	 *
	 * @param   string  $key    The storage key
	 * @param   mixed   $value  Any JSON-serialisable value
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function set(string $key, $value): void
	{
		KeyValueStorage::setJson($key, $value, $this->db);
	}

	/**
	 * Remove a value.
	 *
	 * @param   string  $key  The storage key
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function remove(string $key): void
	{
		KeyValueStorage::remove($key, $this->db);
	}
}
