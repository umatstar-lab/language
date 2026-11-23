<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Database-backed bookkeeping for the crawler IP lists.
 *
 * The site-wide download lock, the per-bot last-attempt timestamps, the health counters and the last good copy of every
 * list live in their own rows of the #__admintools_storage table. They are deliberately NOT in `cparams` (writing that
 * row from a visitor request could overwrite settings an administrator just saved) and NOT in the Joomla cache (this
 * state has to work exactly when the cache does not).
 *
 * Nothing here ever lets a database exception escape: this code runs on the WAF's hot path. On error the readers
 * return defaults, the writers do nothing and tryLock() returns false.
 *
 * All SQL lives in the four protected row primitives (readRow, writeRow, insertRow, swapRow) so a test can subclass
 * this class and keep the rows in memory; every other method is plain logic on top of them.
 *
 * @since   7.9.4
 */
class StateStore
{
	/**
	 * Storage row: the site-wide download lock. The value is the Unix timestamp at which the lock expires; 0 is free.
	 *
	 * @since   7.9.4
	 */
	public const ROW_LOCK = 'crawlerips.lock';

	/**
	 * Storage row: JSON object {botkey: unixTimestamp} of the last download attempt per bot.
	 *
	 * @since   7.9.4
	 */
	public const ROW_ATTEMPTS = 'crawlerips.attempts';

	/**
	 * Storage row: JSON health counters and auto-disable bookkeeping.
	 *
	 * @since   7.9.4
	 */
	public const ROW_HEALTH = 'crawlerips.health';

	/**
	 * Prefix of the storage rows holding the last good copy of a list; the bot key is appended.
	 *
	 * @since   7.9.4
	 */
	public const ROW_LASTGOOD_PREFIX = 'crawlerips.lastgood.';

	/**
	 * The default health record.
	 *
	 * @since   7.9.4
	 */
	private const HEALTH_DEFAULTS = [
		'toosoon'    => 0,
		'failures'   => 0,
		'cause'      => null,
		'disabledAt' => null,
		'emailSent'  => false,
	];

	/**
	 * The database driver. NULL for in-memory subclasses.
	 *
	 * @var   DatabaseInterface|null
	 * @since 7.9.4
	 */
	protected $db;

	/**
	 * The value we wrote into the lock row when we last acquired the lock; NULL if we do not hold it.
	 *
	 * @var   string|null
	 * @since 7.9.4
	 */
	private $myLockValue = null;

	/**
	 * The prefix of the storage row keys (the default is `crawlerips`, see the ROW_* constants).
	 *
	 * @var   string
	 * @since 7.9.4
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param   DatabaseInterface|null  $db      The database driver.
	 * @param   string                  $prefix  Prefix of the storage row keys. Another feature which needs the same
	 *                                           kind of bookkeeping uses its own prefix, so the rows never collide.
	 *
	 * @since   7.9.4
	 */
	public function __construct(?DatabaseInterface $db = null, string $prefix = 'crawlerips')
	{
		$this->db     = $db;
		$this->prefix = $prefix;
	}

	/**
	 * Try to take the site-wide download lock.
	 *
	 * This is a compare-and-swap, atomic across processes: a missing row is created with an INSERT (a concurrent
	 * INSERT hits the primary key and loses), an expired row is taken over with an UPDATE which only matches if the
	 * row still holds the exact value we read.
	 *
	 * @param   int  $now  The current Unix timestamp.
	 * @param   int  $ttl  Seconds until the lock expires on its own (so a request which dies cannot jam it).
	 *
	 * @return  bool  True if we now hold the lock.
	 * @since   7.9.4
	 */
	public function tryLock(int $now, int $ttl): bool
	{
		$new = (string) ($now + $ttl);
		$old = $this->readRow($this->prefix . '.lock');

		if ($old === null)
		{
			if (!$this->insertRow($this->prefix . '.lock', $new))
			{
				return false;
			}

			$this->myLockValue = $new;

			return true;
		}

		if ((int) $old > $now)
		{
			return false;
		}

		if (!$this->swapRow($this->prefix . '.lock', $old, $new))
		{
			return false;
		}

		$this->myLockValue = $new;

		return true;
	}

	/**
	 * Release the lock, but only if it is still ours.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function unlock(): void
	{
		if ($this->myLockValue === null)
		{
			return;
		}

		$mine              = $this->myLockValue;
		$this->myLockValue = null;

		$this->swapRow($this->prefix . '.lock', $mine, '0');
	}

	/**
	 * Is the download lock held (and not expired) by anyone?
	 *
	 * @param   int  $now  The current Unix timestamp.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function isLocked(int $now): bool
	{
		$value = $this->readRow($this->prefix . '.lock');

		return $value !== null && (int) $value > $now;
	}

	/**
	 * Unix timestamp of the last download attempt for a bot; 0 if there was none.
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	public function getLastAttempt(string $bot): int
	{
		return (int) ($this->getAttempts()[$bot] ?? 0);
	}

	/**
	 * Last download attempt timestamps of all bots which have one.
	 *
	 * @return  array<string, int>
	 * @since   7.9.4
	 */
	public function getAttempts(): array
	{
		return array_map('intval', $this->readJson($this->prefix . '.attempts'));
	}

	/**
	 * Record the time of a download attempt for a bot.
	 *
	 * @param   string  $bot  The bot key.
	 * @param   int     $ts   The Unix timestamp.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function setLastAttempt(string $bot, int $ts): void
	{
		$attempts       = $this->readJson($this->prefix . '.attempts');
		$attempts[$bot] = $ts;

		$this->writeJson($this->prefix . '.attempts', $attempts);
	}

	/**
	 * The health record, merged over the defaults.
	 *
	 * @return  array  Keys: toosoon, failures, cause, disabledAt, emailSent.
	 * @since   7.9.4
	 */
	public function getHealth(): array
	{
		$health = array_merge(self::HEALTH_DEFAULTS, $this->readJson($this->prefix . '.health'));

		$health['toosoon']   = (int) $health['toosoon'];
		$health['failures']  = (int) $health['failures'];
		$health['emailSent'] = (bool) $health['emailSent'];

		return $health;
	}

	/**
	 * Save the health record.
	 *
	 * @param   array  $health  The health record.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function saveHealth(array $health): void
	{
		$this->writeJson($this->prefix . '.health', array_merge(self::HEALTH_DEFAULTS, $health));
	}

	/**
	 * Count one "too soon" event.
	 *
	 * @return  int  The new value of the counter.
	 * @since   7.9.4
	 */
	public function incrementTooSoon(): int
	{
		$health = $this->getHealth();
		$health['toosoon']++;
		$this->saveHealth($health);

		return $health['toosoon'];
	}

	/**
	 * Count one failed lazy download.
	 *
	 * @return  int  The new value of the counter.
	 * @since   7.9.4
	 */
	public function incrementFailures(): int
	{
		$health = $this->getHealth();
		$health['failures']++;
		$this->saveHealth($health);

		return $health['failures'];
	}

	/**
	 * Zero the "too soon" counter. Costs one read and no write when it is already zero.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function resetTooSoon(): void
	{
		$health = $this->getHealth();

		if ($health['toosoon'] === 0)
		{
			return;
		}

		$health['toosoon'] = 0;
		$this->saveHealth($health);
	}

	/**
	 * Zero the failed-download counter. Costs one read and no write when it is already zero.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function resetFailures(): void
	{
		$health = $this->getHealth();

		if ($health['failures'] === 0)
		{
			return;
		}

		$health['failures'] = 0;
		$this->saveHealth($health);
	}

	/**
	 * The payload of the last successful download of a bot, if any.
	 *
	 * @param   string  $bot  The bot key.
	 *
	 * @return  array|null
	 * @since   7.9.4
	 */
	public function getLastGood(string $bot): ?array
	{
		$value = $this->readRow($this->prefix . '.lastgood.' . $bot);

		if ($value === null)
		{
			return null;
		}

		$data = json_decode($value, true);

		return is_array($data) && !empty($data) ? $data : null;
	}

	/**
	 * Store the payload of a successful download. Never call this for a failure.
	 *
	 * @param   string  $bot      The bot key.
	 * @param   array   $payload  The payload.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function setLastGood(string $bot, array $payload): void
	{
		$json = json_encode($payload);

		if (!is_string($json))
		{
			return;
		}

		$this->writeRow($this->prefix . '.lastgood.' . $bot, $json);
	}

	/**
	 * Read an arbitrary row of the storage table.
	 *
	 * Used by code which needs a flag row of its own (e.g. the run-once upgrade migrations) and not the crawler state.
	 *
	 * @param   string  $key  The row key.
	 *
	 * @return  string|null  NULL if the row is missing or the query failed.
	 * @since   7.9.4
	 */
	public function readValue(string $key): ?string
	{
		return $this->readRow($key);
	}

	/**
	 * Create or update an arbitrary row of the storage table (SELECT, then UPDATE or INSERT; never DELETE+INSERT).
	 *
	 * @param   string  $key    The row key.
	 * @param   string  $value  The value.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function writeValue(string $key, string $value): void
	{
		$this->writeRow($key, $value);
	}

	/**
	 * The reset: free the lock, forget the attempts, zero the counters, clear the auto-disable record.
	 *
	 * The last good copies are kept. The cache and the master switch are not touched.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function reset(): void
	{
		$this->myLockValue = null;

		$this->writeRow($this->prefix . '.lock', '0');
		$this->writeJson($this->prefix . '.attempts', []);
		$this->writeJson($this->prefix . '.health', self::HEALTH_DEFAULTS);
	}

	/**
	 * Read a row and decode it as a JSON object.
	 *
	 * @param   string  $key  The row key.
	 *
	 * @return  array
	 * @since   7.9.4
	 */
	protected function readJson(string $key): array
	{
		$value = $this->readRow($key);

		if ($value === null)
		{
			return [];
		}

		$data = json_decode($value, true);

		return is_array($data) ? $data : [];
	}

	/**
	 * Encode an array as JSON and write it to a row.
	 *
	 * @param   string  $key   The row key.
	 * @param   array   $data  The data.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	protected function writeJson(string $key, array $data): void
	{
		// An empty array must be stored as an object, so a reader sees the same thing whatever the content.
		$json = json_encode(empty($data) ? new \stdClass() : $data);

		if (!is_string($json))
		{
			return;
		}

		$this->writeRow($key, $json);
	}

	/**
	 * Read the value of a row.
	 *
	 * @param   string  $key  The row key.
	 *
	 * @return  string|null  NULL if the row is missing or the query failed.
	 * @since   7.9.4
	 */
	protected function readRow(string $key): ?string
	{
		if ($this->db === null)
		{
			return null;
		}

		try
		{
			return KeyValueStorage::get($key, null, $this->db);
		}
		catch (Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Create or replace a row. Never DELETE+INSERT: that opens a window in which another process sees no row.
	 *
	 * @param   string  $key    The row key.
	 * @param   string  $value  The value.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	protected function writeRow(string $key, string $value): void
	{
		if ($this->readRow($key) === null && $this->insertRow($key, $value))
		{
			return;
		}

		// The row exists, or somebody else created it between our SELECT and our INSERT.
		$this->updateRow($key, $value);
	}

	/**
	 * Insert a new row.
	 *
	 * @param   string  $key    The row key.
	 * @param   string  $value  The value.
	 *
	 * @return  bool  False if the row already exists (duplicate key) or the query failed.
	 * @since   7.9.4
	 */
	protected function insertRow(string $key, string $value): bool
	{
		if ($this->db === null)
		{
			return false;
		}

		try
		{
			$object = (object) ['key' => $key, 'value' => $value];

			$this->db->insertObject('#__admintools_storage', $object);

			return true;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Unconditionally update an existing row.
	 *
	 * @param   string  $key    The row key.
	 * @param   string  $value  The value.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	protected function updateRow(string $key, string $value): void
	{
		if ($this->db === null)
		{
			return;
		}

		try
		{
			$db    = $this->db;
			$query = DbQuery::create($db)
				->update($db->quoteName('#__admintools_storage'))
				->set($db->quoteName('value') . ' = :value')
				->where($db->quoteName('key') . ' = :key')
				->bind(':value', $value, ParameterType::STRING)
				->bind(':key', $key, ParameterType::STRING);

			$db->setQuery($query)->execute();
		}
		catch (Throwable $e)
		{
			// Swallowed on purpose: see the class docblock.
		}
	}

	/**
	 * Compare-and-swap: change the value of a row only if it still holds an exact value.
	 *
	 * @param   string  $key  The row key.
	 * @param   string  $old  The value the row must hold.
	 * @param   string  $new  The new value.
	 *
	 * @return  bool  True if exactly one row was changed.
	 * @since   7.9.4
	 */
	protected function swapRow(string $key, string $old, string $new): bool
	{
		if ($this->db === null)
		{
			return false;
		}

		try
		{
			$db    = $this->db;
			$query = DbQuery::create($db)
				->update($db->quoteName('#__admintools_storage'))
				->set($db->quoteName('value') . ' = :value')
				->where($db->quoteName('key') . ' = :key')
				->where($db->quoteName('value') . ' = :old')
				->bind(':value', $new, ParameterType::STRING)
				->bind(':key', $key, ParameterType::STRING)
				->bind(':old', $old, ParameterType::STRING);

			$db->setQuery($query)->execute();

			return $db->getAffectedRows() === 1;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}
}
