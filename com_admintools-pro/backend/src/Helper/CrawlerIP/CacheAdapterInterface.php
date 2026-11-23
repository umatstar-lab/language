<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

/**
 * A thin abstraction over Joomla's callback cache controller, for the current copy of a list only.
 *
 * @since   7.9.4
 */
interface CacheAdapterInterface
{
	/**
	 * Return the cached payload, or call $callback on a miss and store (and return) what it returns.
	 *
	 * @param   string    $id        The cache ID.
	 * @param   callable  $callback  Called on a miss; must return an array.
	 *
	 * @return  array|null
	 * @since   7.9.4
	 */
	public function get(string $id, callable $callback): ?array;

	/**
	 * Direct read. Never calls back.
	 *
	 * @param   string  $id  The cache ID.
	 *
	 * @return  array|null  NULL on a miss.
	 * @since   7.9.4
	 */
	public function peek(string $id): ?array;

	/**
	 * Store a payload, replacing whatever is cached under that ID.
	 *
	 * @param   string  $id       The cache ID.
	 * @param   array   $payload  The payload.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function store(string $id, array $payload): void;

	/**
	 * Remove an entry.
	 *
	 * @param   string  $id  The cache ID.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function remove(string $id): void;
}
