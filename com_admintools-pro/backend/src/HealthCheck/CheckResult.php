<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

/**
 * The outcome of running a Site Health Check item.
 *
 * The message is HTML. It is output as-is, so a check MUST escape any value it did not write itself (file names,
 * usernames, configuration values, …) before putting it in the message. Item labels, on the other hand, are plain text
 * and are always escaped on output.
 *
 * Results of expensive checks are cached, so a result must survive a round trip through toArray() / fromArray().
 *
 * @since  7.10.0
 */
final class CheckResult
{
	/** The check found nothing wrong. */
	public const STATUS_PASS = 'pass';

	/** The check found a problem. */
	public const STATUS_FAIL = 'fail';

	/** An informational item; nothing was checked. */
	public const STATUS_INFO = 'info';

	/** The check could not be determined (e.g. it needs data which has not been collected yet). */
	public const STATUS_NA = 'na';

	/** The check itself failed to run. */
	public const STATUS_ERROR = 'error';

	/** @var string */
	private $status;

	/** @var string */
	private $message;

	/** @var array[] */
	private $items;

	/** @var string[] */
	private $fixes;

	/** @var int */
	private $totalItems;

	/** @var array[] Each one is ['url' => string, 'label' => string] */
	private $links = [];

	/**
	 * @param   string    $status      One of the STATUS_* constants.
	 * @param   string    $message     HTML message explaining this specific outcome.
	 * @param   array[]   $items       Details. Each one is ['key' => string, 'label' => string, 'fixes' => string[],
	 *                                 'description' => string]. The key identifies the item to the check's fix()
	 *                                 method; the label and the optional description are plain text.
	 * @param   string[]  $fixes       IDs of the fixes offered for the check as a whole.
	 * @param   int|null  $totalItems  Total number of items, when $items is a truncated list. NULL: count($items).
	 *
	 * @since   7.10.0
	 */
	public function __construct(
		string $status, string $message = '', array $items = [], array $fixes = [], ?int $totalItems = null
	)
	{
		$validStatuses = [
			self::STATUS_PASS, self::STATUS_FAIL, self::STATUS_INFO, self::STATUS_NA, self::STATUS_ERROR,
		];

		if (!in_array($status, $validStatuses, true))
		{
			throw new \InvalidArgumentException(sprintf('Invalid Site Health Check status “%s”', $status));
		}

		$this->status     = $status;
		$this->message    = $message;
		$this->items      = array_values(array_map([self::class, 'normaliseItem'], $items));
		$this->fixes      = array_values(array_unique(array_map('strval', $fixes)));
		$this->totalItems = max($totalItems ?? 0, count($this->items));
	}

	/**
	 * A copy of this result with a link to the backend page where the user can deal with it.
	 *
	 * @param   string  $url    Non-SEF backend URL, e.g. index.php?option=com_config. Anything else is ignored.
	 * @param   string  $label  Translated link text (plain text)
	 *
	 * @return  self
	 * @since   7.10.0
	 */
	public function withLink(string $url, string $label): self
	{
		$clone = clone $this;

		// Only links into the backend; never javascript:, data: or off-site URLs.
		if (strpos($url, 'index.php') === 0)
		{
			$clone->links[] = ['url' => $url, 'label' => $label];
		}

		return $clone;
	}

	/**
	 * Links to the backend pages where the user can deal with this result.
	 *
	 * @return  array[]  Each one is ['url' => string, 'label' => string]
	 * @since   7.10.0
	 */
	public function getLinks(): array
	{
		return $this->links;
	}

	public static function pass(string $message = ''): self
	{
		return new self(self::STATUS_PASS, $message);
	}

	public static function fail(string $message, array $items = [], array $fixes = [], ?int $totalItems = null): self
	{
		return new self(self::STATUS_FAIL, $message, $items, $fixes, $totalItems);
	}

	public static function info(string $message = '', array $fixes = []): self
	{
		return new self(self::STATUS_INFO, $message, [], $fixes);
	}

	public static function notAvailable(string $message): self
	{
		return new self(self::STATUS_NA, $message);
	}

	public static function error(string $message): self
	{
		return new self(self::STATUS_ERROR, $message);
	}

	/**
	 * Create an item array.
	 *
	 * @param   string    $key          Identifies the item to the check's fix() method.
	 * @param   string    $label        Plain text shown to the user, e.g. a file name.
	 * @param   string[]  $fixes        IDs of the per-item fixes offered for this item.
	 * @param   string    $description  Optional plain text explaining the item, shown under it.
	 *
	 * @return  array
	 * @since   7.10.0
	 */
	public static function item(string $key, ?string $label = null, array $fixes = [], string $description = ''): array
	{
		return [
			'key'         => $key,
			'label'       => $label ?? $key,
			'fixes'       => $fixes,
			'description' => $description,
		];
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function isProblem(): bool
	{
		return $this->status === self::STATUS_FAIL;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function getItems(): array
	{
		return $this->items;
	}

	public function getTotalItems(): int
	{
		return $this->totalItems;
	}

	public function getFixes(): array
	{
		return $this->fixes;
	}

	/**
	 * Does this result offer the given fix, either for the whole check or for the given item?
	 *
	 * @param   string       $fixId
	 * @param   string|null  $itemKey
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function offersFix(string $fixId, ?string $itemKey = null): bool
	{
		if ($itemKey === null || $itemKey === '')
		{
			return in_array($fixId, $this->fixes, true);
		}

		foreach ($this->items as $item)
		{
			if ($item['key'] === $itemKey)
			{
				return in_array($fixId, $item['fixes'], true);
			}
		}

		return false;
	}

	/**
	 * The opaque token which identifies an item in the page, and in the requests to fix or ignore it.
	 *
	 * Item keys are often names found on disk, which whoever can create files chooses. They can look like HTML, or not
	 * be valid UTF-8, so they do not survive a trip through an HTML attribute and Joomla's input filters unchanged. The
	 * token (lowercase hexadecimal) does, and findItemKey() maps it back to the exact key.
	 *
	 * @param   string  $key  The item key
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function itemToken(string $key): string
	{
		return hash('sha256', $key);
	}

	/**
	 * The key of the listed item an item token identifies.
	 *
	 * @param   string  $token  An item token, see itemToken()
	 *
	 * @return  string|null  NULL if no listed item has this token
	 * @since   7.10.0
	 */
	public function findItemKey(string $token): ?string
	{
		if ($token === '')
		{
			return null;
		}

		foreach ($this->items as $item)
		{
			if (hash_equals(self::itemToken($item['key']), $token))
			{
				return $item['key'];
			}
		}

		return null;
	}

	public function toArray(): array
	{
		return [
			'status'     => $this->status,
			'message'    => $this->message,
			'items'      => $this->items,
			'fixes'      => $this->fixes,
			'totalItems' => $this->totalItems,
			'links'      => $this->links,
		];
	}

	public static function fromArray(array $data): self
	{
		$result = new self(
			(string) ($data['status'] ?? self::STATUS_ERROR),
			(string) ($data['message'] ?? ''),
			is_array($data['items'] ?? null) ? $data['items'] : [],
			is_array($data['fixes'] ?? null) ? $data['fixes'] : [],
			isset($data['totalItems']) ? (int) $data['totalItems'] : null
		);

		foreach ((array) ($data['links'] ?? []) as $link)
		{
			$link   = (array) $link;
			$result = $result->withLink((string) ($link['url'] ?? ''), (string) ($link['label'] ?? ''));
		}

		return $result;
	}

	private static function normaliseItem($item): array
	{
		if (is_string($item))
		{
			return self::item($item);
		}

		$item = (array) $item;

		return [
			'key'         => (string) ($item['key'] ?? ''),
			'label'       => (string) ($item['label'] ?? ($item['key'] ?? '')),
			'fixes'       => array_values(array_map('strval', (array) ($item['fixes'] ?? []))),
			'description' => (string) ($item['description'] ?? ''),
		];
	}
}
