<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

/**
 * Parses and validates the crawler IP lists published by the providers.
 *
 * Everything returned is syntactically valid. Anything which is not usable results in NULL ("no usable data"), which
 * callers treat as a failed download. The HTTP content type is never consulted; callers pass the format.
 *
 * @since   7.9.4
 */
final class ListParser
{
	/**
	 * Maximum size of a response body we will even look at.
	 *
	 * @since   7.9.4
	 */
	public const MAX_BODY_BYTES = 1048576;

	/**
	 * Maximum number of entries a list may have.
	 *
	 * @since   7.9.4
	 */
	public const MAX_ENTRIES = 10000;

	/**
	 * Narrowest acceptable prefix length (widest network) for IPv4.
	 *
	 * @since   7.9.4
	 */
	private const MIN_PREFIX_IPV4 = 8;

	/**
	 * Narrowest acceptable prefix length (widest network) for IPv6.
	 *
	 * @since   7.9.4
	 */
	private const MIN_PREFIX_IPV6 = 16;

	/**
	 * Parse a response body.
	 *
	 * @param   string  $body    The raw response body.
	 * @param   string  $format  'json' or 'amazon_html' (see BotRegistry).
	 *
	 * @return  string[]|null  Validated, normalised, de-duplicated entries; NULL if there is no usable data.
	 * @since   7.9.4
	 */
	public static function parse(string $body, string $format): ?array
	{
		if (trim($body) === '' || strlen($body) > self::MAX_BODY_BYTES)
		{
			return null;
		}

		switch ($format)
		{
			case 'json':
				return self::parseJson($body);

			case 'amazon_html':
				$json = self::extractAmazonJson($body);

				return $json === null ? null : self::parseJson($json);
		}

		return null;
	}

	/**
	 * Extract the JSON document from the single `<pre><code>` block of an Amazon page.
	 *
	 * @param   string  $html  The HTML page.
	 *
	 * @return  string|null  The entity-decoded block contents; NULL if none, or if it is ambiguous.
	 * @since   7.9.4
	 */
	public static function extractAmazonJson(string $html): ?string
	{
		$candidates = [];
		$offset     = 0;
		$length     = strlen($html);

		while ($offset < $length)
		{
			$preStart = stripos($html, '<pre', $offset);

			if ($preStart === false)
			{
				break;
			}

			$offset = $preStart + 4;

			// Make sure it's <pre> or <pre ...>, not e.g. <prefix>
			$next = substr($html, $offset, 1);

			if ($next !== '>' && !ctype_space($next))
			{
				continue;
			}

			$preEnd = strpos($html, '>', $offset);

			if ($preEnd === false)
			{
				break;
			}

			// The <pre> must be followed (after optional whitespace) by <code ...>
			$codeStart = $preEnd + 1;
			$codeStart += strspn($html, " \t\r\n", $codeStart);

			if (strtolower(substr($html, $codeStart, 5)) !== '<code')
			{
				continue;
			}

			$afterCode = substr($html, $codeStart + 5, 1);

			if ($afterCode !== '>' && !ctype_space($afterCode))
			{
				continue;
			}

			$codeTagEnd = strpos($html, '>', $codeStart);

			if ($codeTagEnd === false)
			{
				break;
			}

			$contentStart = $codeTagEnd + 1;
			$contentEnd   = stripos($html, '</code>', $contentStart);

			if ($contentEnd === false)
			{
				break;
			}

			$offset  = $contentEnd + 7;
			$content = html_entity_decode(
				substr($html, $contentStart, $contentEnd - $contentStart), ENT_QUOTES | ENT_HTML5, 'UTF-8'
			);

			if (strpos($content, '"prefixes"') !== false)
			{
				$candidates[] = $content;
			}
		}

		return count($candidates) === 1 ? $candidates[0] : null;
	}

	/**
	 * Parse a `{"prefixes": [...]}` document.
	 *
	 * @param   string  $json  The JSON document.
	 *
	 * @return  string[]|null  Validated, normalised, de-duplicated entries; NULL if there is no usable data.
	 * @since   7.9.4
	 */
	public static function parseJson(string $json): ?array
	{
		if (strlen($json) > self::MAX_BODY_BYTES)
		{
			return null;
		}

		$data = json_decode($json, true);

		if (!is_array($data) || !isset($data['prefixes']) || !is_array($data['prefixes']))
		{
			return null;
		}

		if (count($data['prefixes']) > self::MAX_ENTRIES)
		{
			return null;
		}

		$ret = [];

		foreach ($data['prefixes'] as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			foreach (['ipv4Prefix', 'ipv6Prefix', 'ip_prefix'] as $key)
			{
				if (!isset($item[$key]) || !is_string($item[$key]))
				{
					continue;
				}

				$entry = self::normaliseEntry($item[$key]);

				if ($entry !== null)
				{
					$ret[$entry] = true;
				}
			}
		}

		return empty($ret) ? null : array_map('strval', array_keys($ret));
	}

	/**
	 * Validate and normalise a single entry: an IPv4/IPv6 address, or an address with a CIDR prefix length.
	 *
	 * @param   string  $entry  The raw entry.
	 *
	 * @return  string|null  The canonical form; NULL if invalid or too wide to be sane.
	 * @since   7.9.4
	 */
	public static function normaliseEntry(string $entry): ?string
	{
		$entry = trim($entry);

		if ($entry === '')
		{
			return null;
		}

		$prefix = null;
		$slash  = strpos($entry, '/');

		if ($slash !== false)
		{
			$prefix = substr($entry, $slash + 1);
			$entry  = substr($entry, 0, $slash);

			if (!preg_match('/^\d{1,3}$/', $prefix))
			{
				return null;
			}

			$prefix = (int) $prefix;
		}

		if (!filter_var($entry, FILTER_VALIDATE_IP))
		{
			return null;
		}

		$binary = @inet_pton($entry);

		if ($binary === false)
		{
			return null;
		}

		$isIPv6    = strlen($binary) === 16;
		$canonical = inet_ntop($binary);

		if ($canonical === false)
		{
			return null;
		}

		if ($prefix === null)
		{
			return $canonical;
		}

		if ($prefix > ($isIPv6 ? 128 : 32) || $prefix < ($isIPv6 ? self::MIN_PREFIX_IPV6 : self::MIN_PREFIX_IPV4))
		{
			return null;
		}

		/**
		 * An IPv6 range which reaches into the IPv4-mapped (::ffff:0:0/96) or IPv4-compatible (::/96) block must lie
		 * inside it and be no wider than an IPv4 /8 (gh-478). IPv4 entries already meet that through MIN_PREFIX_IPV4.
		 */
		if ($isIPv6 && !IpRangeSet::isAcceptableCidr($binary, $prefix))
		{
			return null;
		}

		return $canonical . '/' . $prefix;
	}
}
