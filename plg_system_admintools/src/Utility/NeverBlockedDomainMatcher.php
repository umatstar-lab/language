<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Utility;

defined('_JEXEC') or die;

/**
 * Does an IP address belong to a domain in the "Never block these domains" list?
 *
 * @since 7.9.4
 */
class NeverBlockedDomainMatcher
{
	/** @var string[] */
	private $domains;

	/** @var callable */
	private $reverseLookup;

	/** @var callable */
	private $forwardLookup;

	/**
	 * @param   string[]       $domains        The "Never block these domains" list
	 * @param   callable|null  $reverseLookup  fn(string $ip): string. Defaults to gethostbyaddr()
	 * @param   callable|null  $forwardLookup  fn(string $host): string[]. Defaults to an A/AAAA lookup
	 */
	public function __construct(array $domains, ?callable $reverseLookup = null, ?callable $forwardLookup = null)
	{
		$this->domains       = $domains;
		$this->reverseLookup = $reverseLookup ?? [self::class, 'defaultReverseLookup'];
		$this->forwardLookup = $forwardLookup ?? [self::class, 'defaultForwardLookup'];
	}

	/**
	 * Is the IP address's host name in the list, and does that host name resolve back to the same IP address?
	 *
	 * The PTR record is controlled by whoever holds the IP address, not by the owner of the domain, so the name only
	 * counts once the domain's own DNS zone confirms it (forward-confirmed reverse DNS, as documented by Google, Bing,
	 * Yandex and Baidu).
	 *
	 * @param   string  $ip  The visitor's IP address
	 *
	 * @return  bool
	 */
	public function matches(string $ip): bool
	{
		$domains = $this->getNormalisedDomains();

		if (empty($domains))
		{
			return false;
		}

		$host = strtolower(rtrim(trim((string) ($this->reverseLookup)($ip)), '.'));

		// No PTR record: gethostbyaddr() gives us the IP address back.
		if ($host === '' || $host === strtolower($ip))
		{
			return false;
		}

		$matched = false;

		foreach ($domains as $domain)
		{
			// Whole DNS labels only: "googlebot.com" or any "*.googlebot.com", but not "evilgooglebot.com".
			if ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain)
			{
				$matched = true;

				break;
			}
		}

		return $matched && $this->resolvesTo($host, $ip);
	}

	private function resolvesTo(string $host, string $ip): bool
	{
		$wanted = @inet_pton($ip);

		if ($wanted === false)
		{
			return false;
		}

		foreach (($this->forwardLookup)($host) as $candidate)
		{
			if (@inet_pton((string) $candidate) === $wanted)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return  string[]  Lowercase domains without leading dots; blank entries removed
	 */
	private function getNormalisedDomains(): array
	{
		$domains = array_map(function ($domain) {
			return strtolower(trim((string) $domain, " \t\n\r\0\x0B."));
		}, $this->domains);

		return array_values(array_filter($domains, function ($domain) {
			return $domain !== '';
		}));
	}

	private static function defaultReverseLookup(string $ip): string
	{
		return (string) @gethostbyaddr($ip);
	}

	private static function defaultForwardLookup(string $host): array
	{
		if (!function_exists('dns_get_record'))
		{
			return [];
		}

		/**
		 * IMPORTANT! WE MUST NOT CACHE THE RESULT OF dns_get_record USING PHP CODE.
		 *
		 * Not only it will not make DNS resolution faster, it will actually make it slower.
		 *
		 * This means that PHP-side caching of reverse DNS resolution is actually an _anti-feature_ which materially
		 * degrades performance instead of helping with it. Thus, the only correct solution is implementing a caching
		 * DNS resolver, on the server itself. This is explained in detail in the waf-configure-exceptions documentation
		 * page, under “A few words about DNS resolution”.
		 *
		 * If you submit a bug report / security issue asking me to implement PHP-side caching of DNS resolution, or,
		 * worse yet, magically implement a timeout for DNS resolution, you agree to making a minimum donation of
		 * 300 Euro / $350 USD to a charity of your choice which supports one of the following causes:
		 * - Children in need (impoverished, abused, neglected, or war-stricken)
		 * - LGBTQ+ rights
		 * - Wildlife preservation
		 * If you decline, you agree that your full legal name, email address, your bogus report, and this comment will
		 * be displayed publicly in any medium of my choice in perpetuity. Learn to read or suffer the consequences.
		 */
		$records = @dns_get_record($host . '.', DNS_A | DNS_AAAA);

		if (!is_array($records))
		{
			return [];
		}

		$addresses = [];

		foreach ($records as $record)
		{
			$addresses[] = $record['ip'] ?? $record['ipv6'] ?? '';
		}

		return array_filter($addresses);
	}
}
