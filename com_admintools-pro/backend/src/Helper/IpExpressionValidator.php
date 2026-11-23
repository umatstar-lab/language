<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Akeeba\Plugin\System\AdminTools\Utility\Filter;

/**
 * Validates an Admin Tools IP expression string.
 *
 * Mirrors the format whitelist in plugins/system/admintools/src/Utility/Filter::expressionToRange():
 *  - `@example.com` resolves the domain to an IPv4 for exact IP matching
 *  - `#example.com` resolves the domain to an IPv6 for exact IP matching
 *  - `1.2.3.4` a single IPv4
 *  - `::1` a single IPv6
 *  - `1.2.3.4/24` IPv4 with CIDR
 *  - `1.2.3.4/255.255.255.0` IPv4 with netmask
 *  - `1.2.3.` a dangling-dot partial IPv4 (existing octets become 255, missing become 0)
 *  - `1.2.3.4-2.3.4.5` IPv4 range
 *  - `2001::cafe:1-2001::fefe:ffff` IPv6 range
 *  - `2001::cafe:1/64` IPv6 with CIDR
 *
 * The runtime consumer (Filter::IPinList / Filter::expressionToRange) silently drops unparseable
 * expressions; without this validator a non-empty nonsense string such as `not-an-ip` is stored to
 * the IP allow/deny list and never matches any request, giving the operator a false sense that a
 * block is in place.
 *
 * @since 7.9.2
 */
final class IpExpressionValidator
{
	/**
	 * Canonical IPv4 netmasks, in dotted-decimal form. The runtime whitelist from
	 * plugins/system/admintools/src/Utility/Filter.php.
	 *
	 * @var string[]
	 */
	private const VALID_NETMASKS = [
		'255.255.255.255',
		'255.255.255.254',
		'255.255.255.252',
		'255.255.255.248',
		'255.255.255.240',
		'255.255.255.224',
		'255.255.255.192',
		'255.255.255.128',
		'255.255.255.0',
		'255.255.254.0',
		'255.255.252.0',
		'255.255.248.0',
		'255.255.240.0',
		'255.255.224.0',
		'255.255.192.0',
		'255.255.128.0',
		'255.255.0.0',
		'255.254.0.0',
		'255.252.0.0',
		'255.248.0.0',
		'255.240.0.0',
		'255.224.0.0',
		'255.192.0.0',
		'255.128.0.0',
		'255.0.0.0',
		'254.0.0.0',
		'252.0.0.0',
		'248.0.0.0',
		'240.0.0.0',
		'224.0.0.0',
		'192.0.0.0',
		'128.0.0.0',
	];

	/**
	 * Normalise an IP expression for saving: strip the leading zeros from IPv4 octets (`001.02.3.004` becomes
	 * `1.2.3.4`; zeros are never octal) in plain IPs, ranges, CIDR / netmask forms, partial IPs and IPv4-in-IPv6.
	 *
	 * The implementation is the one the WAF uses at runtime, Filter::normaliseExpression() in the system plugin. If the
	 * plugin is not installed we only trim, rather than fail to save the list.
	 *
	 * @param   string|null  $expression  The IP expression to normalise
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function normalise(?string $expression): string
	{
		// The plugin may be missing, or older than the component after a failed update.
		if (!class_exists(Filter::class) || !method_exists(Filter::class, 'normaliseExpression'))
		{
			return trim((string) $expression);
		}

		return Filter::normaliseExpression($expression);
	}

	/**
	 * Normalise a list of IP expressions as stored in the WAF / server configuration: plain strings, or
	 * `[ip, description]` arrays, or objects / arrays with an `item` key. Other entries are left alone.
	 *
	 * @param   mixed  $list  The list to normalise
	 *
	 * @return  mixed  The list, with every IP expression normalised
	 * @since   7.10.0
	 */
	public static function normaliseList($list)
	{
		if (!is_array($list))
		{
			return $list;
		}

		foreach ($list as $key => $entry)
		{
			if (is_string($entry))
			{
				$list[$key] = self::normalise($entry);
			}
			elseif (is_array($entry) && is_string($entry['item'] ?? null))
			{
				$list[$key]['item'] = self::normalise($entry['item']);
			}
			elseif (is_array($entry) && is_string($entry[0] ?? null))
			{
				$list[$key][0] = self::normalise($entry[0]);
			}
			elseif (is_object($entry) && is_string($entry->item ?? null))
			{
				$entry->item = self::normalise($entry->item);
			}
		}

		return $list;
	}

	/**
	 * Test whether $expression is one of the IP expression formats Admin Tools accepts.
	 *
	 * @param   string|null  $expression  The expression to validate.
	 *
	 * @return  bool  True when the expression is well-formed; false otherwise.
	 *
	 * @since   7.9.2
	 */
	public static function isValid(?string $expression): bool
	{
		$expression = self::normalise($expression);

		if ($expression === '')
		{
			return false;
		}

		$first = $expression[0];

		// IPv4 / IPv6 domain-lookup prefixes
		if ($first === '@' || $first === '#')
		{
			return self::isValidDomain(substr($expression, 1));
		}

		// IP range (tried before the slash branch because neither side of the range contains a slash)
		if (strpos($expression, '-') !== false)
		{
			return self::isValidRange($expression);
		}

		// CIDR / netmask
		if (strpos($expression, '/') !== false)
		{
			return self::isValidCidr($expression);
		}

		// Dangling-dot partial IPv4: 1.2.3.
		if (substr($expression, -1) === '.')
		{
			return self::isValidDanglingDot($expression);
		}

		// Plain IP address
		return filter_var($expression, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Validate the domain portion of a `@example.com` / `#example.com` expression.
	 *
	 * @param   string  $domain
	 *
	 * @return  bool
	 *
	 * @since   7.9.2
	 */
	private static function isValidDomain(string $domain): bool
	{
		$domain = rtrim($domain, '.');

		if ($domain === '')
		{
			return false;
		}

		// Reasonable domain syntax: labels separated by dots, each label 1-63 chars, leading/trailing
		// alphanumerics, internal hyphens allowed. Matches what gethostbyname() / DNS_AAAA would accept.
		return (bool) preg_match(
			'/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/',
			$domain
		);
	}

	/**
	 * Validate an IPv4 or IPv6 range expression such as `1.2.3.4-2.3.4.5` or `2001::1-2001::ffff`.
	 *
	 * @param   string  $expression
	 *
	 * @return  bool
	 *
	 * @since   7.9.2
	 */
	private static function isValidRange(string $expression): bool
	{
		$parts = explode('-', $expression, 2);

		if (count($parts) !== 2)
		{
			return false;
		}

		$from = trim($parts[0]);
		$to   = trim($parts[1]);

		if ($from === '' || $to === '')
		{
			return false;
		}

		return filter_var($from, FILTER_VALIDATE_IP) !== false
			&& filter_var($to, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Validate a CIDR or netmask expression such as `1.2.3.4/24` or `1.2.3.4/255.255.255.0`.
	 *
	 * @param   string  $expression
	 *
	 * @return  bool
	 *
	 * @since   7.9.2
	 */
	private static function isValidCidr(string $expression): bool
	{
		$parts = explode('/', $expression, 2);

		if (count($parts) !== 2)
		{
			return false;
		}

		$ip   = trim($parts[0]);
		$mask = trim($parts[1]);

		if ($ip === '' || $mask === '')
		{
			return false;
		}

		$packed = @inet_pton($ip);

		if ($packed === false)
		{
			return false;
		}

		$isIPv4 = strlen($packed) === 4;

		// Numeric mask: a CIDR width in bits.
		if (is_numeric($mask))
		{
			$bits = (int) $mask;

			if ($bits < 0)
			{
				return false;
			}

			if ($isIPv4)
			{
				return $bits <= 32;
			}

			return $bits <= 128;
		}

		// Non-numeric mask: an IPv4 netmask in dotted-decimal form (IPv4 only).
		if (!$isIPv4)
		{
			return false;
		}

		return in_array($mask, self::VALID_NETMASKS, true);
	}

	/**
	 * Validate a dangling-dot partial IPv4 such as `1.2.3.`. Each octet must be 0-255; up to three
	 * octets may precede the trailing dot.
	 *
	 * @param   string  $expression
	 *
	 * @return  bool
	 *
	 * @since   7.9.2
	 */
	private static function isValidDanglingDot(string $expression): bool
	{
		// Dangling-dot is IPv4-only; the IPv6 separator is a colon.
		if (strpos($expression, ':') !== false)
		{
			return false;
		}

		$parts = explode('.', $expression);

		// The trailing dot yields a trailing empty element, so 1. -> [1, ''] (length 2),
		// 1.2.3. -> [1, 2, 3, ''] (length 4). Anything outside that range is malformed.
		if (count($parts) < 2 || count($parts) > 4)
		{
			return false;
		}

		// Drop the trailing empty entry introduced by the trailing dot.
		array_pop($parts);

		foreach ($parts as $octet)
		{
			if ($octet === '' || !ctype_digit($octet))
			{
				return false;
			}

			$value = (int) $octet;

			if ($value < 0 || $value > 255)
			{
				return false;
			}
		}

		return true;
	}
}
