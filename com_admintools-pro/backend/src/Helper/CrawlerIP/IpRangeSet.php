<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

use Countable;

/**
 * A compact set of IP ranges with fast membership tests.
 *
 * Every address is held as a 16-byte binary string (IPv4 is mapped to ::ffff:a.b.c.d, the same normalisation as
 * Utility\Filter::ipToNormalisedIPv6() in the system plugin). Ranges are inclusive, sorted and merged, so contains()
 * is a binary search.
 *
 * @since   7.9.4
 */
final class IpRangeSet implements Countable
{
	/**
	 * Most addresses a range may cover inside an IPv4 block, minus one: the size of an IPv4 /8 (IPv6 prefix 104).
	 *
	 * @since   7.10.0
	 */
	private const MAX_IPV4_SPAN = 0xFFFFFF;

	/**
	 * The blocks which hold IPv4 addresses, as [first, last] 16-byte addresses: IPv4-compatible ::/96 and IPv4-mapped
	 * ::ffff:0:0/96.
	 *
	 * @since   7.10.0
	 */
	private const IPV4_BLOCKS = [
		["\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0", "\0\0\0\0\0\0\0\0\0\0\0\0\xFF\xFF\xFF\xFF"],
		["\0\0\0\0\0\0\0\0\0\0\xFF\xFF\0\0\0\0", "\0\0\0\0\0\0\0\0\0\0\xFF\xFF\xFF\xFF\xFF\xFF"],
	];

	/**
	 * Range start addresses (16-byte strings), sorted ascending.
	 *
	 * @var   string[]
	 * @since 7.9.4
	 */
	private $from = [];

	/**
	 * Range end addresses (16-byte strings), parallel to $from.
	 *
	 * @var   string[]
	 * @since 7.9.4
	 */
	private $to = [];

	/**
	 * Number of source entries this set was built from.
	 *
	 * @var   int
	 * @since 7.9.4
	 */
	private $entryCount = 0;

	/**
	 * Build a set from entries as produced by ListParser (addresses or CIDRs). Unparseable entries are ignored.
	 *
	 * @param   string[]  $entries  The entries.
	 *
	 * @return  self
	 * @since   7.9.4
	 */
	public static function fromEntries(array $entries): self
	{
		$pairs = [];

		foreach ($entries as $entry)
		{
			if (!is_string($entry))
			{
				continue;
			}

			$pair = self::entryToRange($entry);

			if ($pair !== null)
			{
				$pairs[] = $pair;
			}
		}

		return self::build($pairs, count($entries));
	}

	/**
	 * Rebuild a set from toArray() output. Malformed pairs are ignored.
	 *
	 * @param   array  $data  The serialised set.
	 *
	 * @return  self
	 * @since   7.9.4
	 */
	public static function fromArray(array $data): self
	{
		$pairs  = [];
		$sorted = true;
		$last   = null;

		$ranges = isset($data['ranges']) && is_array($data['ranges']) ? $data['ranges'] : [];

		foreach ($ranges as $pair)
		{
			if (!is_array($pair) || count($pair) !== 2 || !isset($pair[0], $pair[1])
				|| !is_string($pair[0]) || !is_string($pair[1])
				|| !preg_match('/^[0-9a-f]{32}$/', $pair[0]) || !preg_match('/^[0-9a-f]{32}$/', $pair[1]))
			{
				continue;
			}

			$from = hex2bin($pair[0]);
			$to   = hex2bin($pair[1]);

			if ($from === false || $to === false || strcmp($from, $to) > 0)
			{
				continue;
			}

			// The cache, or the last good copy, may hold a set built before gh-478; apply the same width rule.
			if (!self::isAcceptableRange($from, $to))
			{
				continue;
			}

			// Data we wrote ourselves is already sorted and merged; detect that to skip the sort on the hot path.
			if ($last !== null && strcmp($from, $last) <= 0)
			{
				$sorted = false;
			}

			$last    = $to;
			$pairs[] = [$from, $to];
		}

		$entries = isset($data['entries']) && is_int($data['entries']) && $data['entries'] >= 0
			? $data['entries'] : count($pairs);

		if ($sorted)
		{
			return self::fromSortedPairs($pairs, $entries);
		}

		return self::build($pairs, $entries);
	}

	/**
	 * Union of several sets.
	 *
	 * @param   self  ...$sets  The sets to merge.
	 *
	 * @return  self
	 * @since   7.9.4
	 */
	public static function merge(self ...$sets): self
	{
		$pairs   = [];
		$entries = 0;

		foreach ($sets as $set)
		{
			$entries += $set->entryCount;

			foreach ($set->from as $i => $from)
			{
				$pairs[] = [$from, $set->to[$i]];
			}
		}

		return self::build($pairs, $entries);
	}

	/**
	 * Is the IP address inside any of the ranges?
	 *
	 * @param   string  $ip  The IPv4 or IPv6 address.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function contains(string $ip): bool
	{
		$count = count($this->from);

		if ($count === 0)
		{
			return false;
		}

		$bin = self::normaliseAddress($ip);

		if ($bin === null)
		{
			return false;
		}

		// Find the last range whose start is <= $bin.
		$low  = 0;
		$high = $count - 1;
		$hit  = -1;

		while ($low <= $high)
		{
			$mid = ($low + $high) >> 1;

			if (strcmp($this->from[$mid], $bin) <= 0)
			{
				$hit = $mid;
				$low = $mid + 1;
			}
			else
			{
				$high = $mid - 1;
			}
		}

		return $hit >= 0 && strcmp($bin, $this->to[$hit]) <= 0;
	}

	/**
	 * Number of merged ranges.
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	public function count(): int
	{
		return count($this->from);
	}

	/**
	 * Number of source entries the set was built from (before merging).
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	public function getEntryCount(): int
	{
		return $this->entryCount;
	}

	/**
	 * A cache-safe representation: hex strings only, no binary data.
	 *
	 * @return  array  ['entries' => int, 'ranges' => [[fromHex, toHex], ...]]
	 * @since   7.9.4
	 */
	public function toArray(): array
	{
		$ranges = [];

		foreach ($this->from as $i => $from)
		{
			$ranges[] = [bin2hex($from), bin2hex($this->to[$i])];
		}

		return ['entries' => $this->entryCount, 'ranges' => $ranges];
	}

	/**
	 * Sort and merge overlapping or adjacent ranges.
	 *
	 * @param   array  $pairs       List of [from, to] 16-byte strings.
	 * @param   int    $entryCount  Number of source entries.
	 *
	 * @return  self
	 * @since   7.9.4
	 */
	private static function build(array $pairs, int $entryCount): self
	{
		usort($pairs, function (array $a, array $b) {
			return strcmp($a[0], $b[0]);
		});

		$merged = [];

		foreach ($pairs as $pair)
		{
			$lastIndex = count($merged) - 1;

			if ($lastIndex >= 0)
			{
				$successor = self::successor($merged[$lastIndex][1]);

				// $successor is NULL when the last range already ends at the very top of the address space.
				if ($successor === null || strcmp($pair[0], $successor) <= 0)
				{
					if (strcmp($pair[1], $merged[$lastIndex][1]) > 0)
					{
						$merged[$lastIndex][1] = $pair[1];
					}

					continue;
				}
			}

			$merged[] = $pair;
		}

		return self::fromSortedPairs($merged, $entryCount);
	}

	/**
	 * Create the set from pairs which are already sorted, non-overlapping and non-adjacent-merged.
	 *
	 * @param   array  $pairs       List of [from, to] 16-byte strings.
	 * @param   int    $entryCount  Number of source entries.
	 *
	 * @return  self
	 * @since   7.9.4
	 */
	private static function fromSortedPairs(array $pairs, int $entryCount): self
	{
		$set             = new self();
		$set->entryCount = $entryCount;

		foreach ($pairs as $pair)
		{
			$set->from[] = $pair[0];
			$set->to[]   = $pair[1];
		}

		return $set;
	}

	/**
	 * The address immediately after the given one; NULL if it is already the highest address.
	 *
	 * @param   string  $bin  16-byte address.
	 *
	 * @return  string|null
	 * @since   7.9.4
	 */
	private static function successor(string $bin): ?string
	{
		for ($i = 15; $i >= 0; $i--)
		{
			$byte = ord($bin[$i]);

			if ($byte < 0xFF)
			{
				$bin[$i] = chr($byte + 1);

				return $bin;
			}

			$bin[$i] = chr(0);
		}

		return null;
	}

	/**
	 * Convert an entry (address or CIDR) to an inclusive [from, to] pair of 16-byte strings.
	 *
	 * @param   string  $entry  The entry.
	 *
	 * @return  array|null
	 * @since   7.9.4
	 */
	private static function entryToRange(string $entry): ?array
	{
		$entry = trim($entry);
		$slash = strpos($entry, '/');
		$bits  = null;

		if ($slash !== false)
		{
			$prefix = substr($entry, $slash + 1);
			$entry  = substr($entry, 0, $slash);

			if (!preg_match('/^\d{1,3}$/', $prefix))
			{
				return null;
			}

			$bits = (int) $prefix;
		}

		$bin = self::normaliseAddress($entry);

		if ($bin === null)
		{
			return null;
		}

		if ($bits === null)
		{
			return [$bin, $bin];
		}

		// An IPv4 prefix length counts from the start of the IPv4 part, i.e. after the 96 bits of the mapping prefix.
		$isIPv4 = strpos($entry, ':') === false;

		if ($bits > ($isIPv4 ? 32 : 128))
		{
			return null;
		}

		$range = self::cidrBounds($bin, $isIPv4 ? $bits + 96 : $bits);

		return self::isAcceptableRange($range[0], $range[1]) ? $range : null;
	}

	/**
	 * Is a CIDR range narrow enough where it meets the IPv4 address space? See {@see self::isAcceptableRange()}.
	 *
	 * @param   string  $bin   A 16-byte address.
	 * @param   int     $bits  The prefix length, 0 to 128.
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isAcceptableCidr(string $bin, int $bits): bool
	{
		if (strlen($bin) !== 16 || $bits < 0 || $bits > 128)
		{
			return false;
		}

		$range = self::cidrBounds($bin, $bits);

		return self::isAcceptableRange($range[0], $range[1]);
	}

	/**
	 * The inclusive [from, to] pair of 16-byte strings of a CIDR range.
	 *
	 * @param   string  $bin   A 16-byte address.
	 * @param   int     $bits  The prefix length, 0 to 128.
	 *
	 * @return  array
	 * @since   7.10.0
	 */
	private static function cidrBounds(string $bin, int $bits): array
	{
		$from = '';
		$to   = '';

		for ($i = 0; $i < 16; $i++)
		{
			$bitsInByte = max(0, min(8, $bits - $i * 8));
			$mask       = (0xFF << (8 - $bitsInByte)) & 0xFF;
			$byte       = ord($bin[$i]);
			$from       .= chr($byte & $mask);
			$to         .= chr(($byte & $mask) | (~$mask & 0xFF));
		}

		return [$from, $to];
	}

	/**
	 * Is a range narrow enough where it meets the IPv4 address space?
	 *
	 * IPv4 addresses live in the IPv4-mapped block ::ffff:0:0/96; the IPv4-compatible block ::/96 is treated the same
	 * way. A range which overlaps either block must lie entirely inside it, and cover no more addresses than an IPv4 /8
	 * (an IPv6 prefix of 104). A range which overlaps neither block is not restricted here.
	 *
	 * @param   string  $from  16-byte start address.
	 * @param   string  $to    16-byte end address, inclusive.
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private static function isAcceptableRange(string $from, string $to): bool
	{
		foreach (self::IPV4_BLOCKS as [$blockFrom, $blockTo])
		{
			// No overlap with this block.
			if (strcmp($to, $blockFrom) < 0 || strcmp($from, $blockTo) > 0)
			{
				continue;
			}

			// Overlaps the block but does not lie inside it.
			if (strcmp($from, $blockFrom) < 0 || strcmp($to, $blockTo) > 0)
			{
				return false;
			}

			$span = unpack('N', substr($to, 12))[1] - unpack('N', substr($from, 12))[1];

			if ($span > self::MAX_IPV4_SPAN)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalise an IPv4 / IPv6 address into a 16-byte binary string; NULL if it is not a valid address.
	 *
	 * Mirrors Utility\Filter::ipToNormalisedIPv6() in the system plugin.
	 *
	 * @param   string  $ip  The address.
	 *
	 * @return  string|null
	 * @since   7.9.4
	 */
	private static function normaliseAddress(string $ip): ?string
	{
		$ip = trim($ip);

		if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP))
		{
			return null;
		}

		$bin = @inet_pton($ip);

		if ($bin === false || $bin === '')
		{
			return null;
		}

		if (strlen($bin) === 16)
		{
			// PHP evaluates ::1.2.3.4 as ::0102:0304 when it should be ::ffff:0102:0304. Catch and fix that.
			if (substr($ip, 0, 2) === '::' && strpos($ip, '.', 2) !== false
				&& substr($bin, 0, 12) === str_repeat(chr(0), 12))
			{
				return str_repeat(chr(0x00), 10) . str_repeat(chr(0xFF), 2) . substr($bin, -4);
			}

			return $bin;
		}

		return str_repeat(chr(0x00), 10) . str_repeat(chr(0xFF), 2) . $bin;
	}
}
