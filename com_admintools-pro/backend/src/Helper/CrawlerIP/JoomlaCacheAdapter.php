<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Factory;
use Throwable;

/**
 * Joomla cache adapter.
 *
 * We deliberately do not set `cachebase` or `storage`: Joomla decides where and how data is cached (file, Redis,
 * Memcached, a custom path, or nowhere at all if there is no writeable cache). `caching` is forced on so this works
 * whether or not caching is enabled in Global Configuration.
 *
 * Joomla's CallbackController::get() stores `serialize(['result' => $result, 'output' => $output])` through
 * `$controller->cache->store($data, $id)`. peek() and store() reproduce that format so a value stored here is read
 * back by get(), and vice versa.
 *
 * A broken cache must never break a request: every Throwable is caught.
 *
 * @since   7.9.4
 */
class JoomlaCacheAdapter implements CacheAdapterInterface
{
	/**
	 * The cache group.
	 *
	 * @since 7.9.4
	 */
	private const GROUP = 'com_admintools';

	/**
	 * The cache lifetime, in minutes (Joomla's unit).
	 *
	 * @var   int
	 * @since 7.9.4
	 */
	private $lifetime;

	/**
	 * The cache controller, created on first use.
	 *
	 * @var   CallbackController|null
	 * @since 7.9.4
	 */
	private $controller = null;

	/**
	 * Constructor.
	 *
	 * @param   int  $lifetime  Cache lifetime in minutes.
	 *
	 * @since   7.9.4
	 */
	public function __construct(int $lifetime)
	{
		$this->lifetime = $lifetime;
	}

	/**
	 * @inheritDoc
	 * @since 7.9.4
	 */
	public function get(string $id, callable $callback): ?array
	{
		$called   = false;
		$captured = null;
		$wrapped  = function () use ($callback, &$called, &$captured) {
			$called   = true;
			$captured = $callback();

			return $captured;
		};

		try
		{
			$result = $this->getController()->get($wrapped, [], $id);

			if (is_array($result))
			{
				return $result;
			}

			// Garbage in the cache. Throw it away and recompute.
			$this->remove($id);
		}
		catch (Throwable $e)
		{
			// The cache broke. If the callback already ran, never run it (i.e. download) a second time.
			if ($called)
			{
				return is_array($captured) ? $captured : null;
			}
		}

		if ($called)
		{
			return is_array($captured) ? $captured : null;
		}

		$result = $callback();

		return is_array($result) ? $result : null;
	}

	/**
	 * @inheritDoc
	 * @since 7.9.4
	 */
	public function peek(string $id): ?array
	{
		try
		{
			$raw = $this->getController()->cache->get($id);

			if ($raw === false || !is_string($raw))
			{
				return null;
			}

			// Only our own arrays are acceptable.
			$data = @unserialize(trim($raw), ['allowed_classes' => false]);

			return (is_array($data) && isset($data['result']) && is_array($data['result'])) ? $data['result'] : null;
		}
		catch (Throwable $e)
		{
			return null;
		}
	}

	/**
	 * @inheritDoc
	 * @since 7.9.4
	 */
	public function store(string $id, array $payload): void
	{
		try
		{
			$this->getController()->cache->store(serialize(['result' => $payload, 'output' => '']), $id);
		}
		catch (Throwable $e)
		{
			// Ignore.
		}
	}

	/**
	 * @inheritDoc
	 * @since 7.9.4
	 */
	public function remove(string $id): void
	{
		try
		{
			$this->getController()->cache->remove($id);
		}
		catch (Throwable $e)
		{
			// Ignore.
		}
	}

	/**
	 * Get the Joomla cache controller.
	 *
	 * @return  CallbackController
	 * @since   7.9.4
	 */
	private function getController(): CallbackController
	{
		if ($this->controller === null)
		{
			$this->controller = Factory::getContainer()
				->get(CacheControllerFactoryInterface::class)
				->createCacheController(
					'callback',
					[
						'defaultgroup' => self::GROUP,
						'lifetime'     => $this->lifetime,
						// Fixed, so sites with several languages share the entries.
						'language'     => 'en-GB',
						'locking'      => true,
						'locktime'     => 15,
						'checkTime'    => true,
						'caching'      => true,
					]
				);
		}

		return $this->controller;
	}
}
