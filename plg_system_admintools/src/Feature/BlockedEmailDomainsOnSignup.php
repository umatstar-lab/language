<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Exception;
use Joomla\CMS\Language\Text;

class BlockedEmailDomainsOnSignup extends Base
{
	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		if (!$this->app->isClient('site'))
		{
			return false;
		}

		$domains = $this->wafParams->getValue('blockedemaildomains', []);

		if (empty($domains))
		{
			return false;
		}

		return true;
	}

	public function onUserBeforeSave($olduser, $isnew, $user): bool
	{
		$block   = ($this->wafParams->getValue('filteremailregistration', 'block') == 'block');
		$domains = self::normalizeDomains($this->wafParams->getValue('blockedemaildomains', []));
		$email   = is_array($user) ? ($user['email'] ?? '') : '';

		$matchedDomain = self::findMatchingDomain($email, $domains);

		// Block-list mode: block the registration when the email's domain matches a listed domain.
		if ($block && $matchedDomain !== null)
		{
			$this->loadComponentLanguage();

			throw new Exception(Text::sprintf('PLG_ADMINTOOLS_ERR_BLOCKEDEMAILDOMAINS', $matchedDomain));
		}

		// Allow-list mode: block the registration when the email's domain matches none of the listed domains.
		if (!$block && $matchedDomain === null)
		{
			$this->loadComponentLanguage();

			throw new Exception(Text::sprintf('PLG_ADMINTOOLS_ERR_BLOCKEDEMAILDOMAINS', $email));
		}

		return true;
	}

	/**
	 * Normalise the configured domains into a flat list of trimmed, non-empty domain strings.
	 *
	 * The value may arrive as a comma-separated string, a flat array, or a subform-style array of
	 * single-element arrays.
	 *
	 * @param   mixed  $domains  The raw value from the WAF parameters.
	 *
	 * @return  string[]
	 */
	protected static function normalizeDomains($domains): array
	{
		if (is_string($domains))
		{
			$domains = explode(',', $domains);
		}

		if (!is_array($domains))
		{
			return [];
		}

		$domains = array_map(
			function ($x) {
				return is_array($x) ? ($x[0] ?? '') : $x;
			},
			$domains
		);

		$domains = array_map('trim', $domains);

		return array_values(
			array_filter(
				$domains,
				function ($x) {
					return $x !== '';
				}
			)
		);
	}

	/**
	 * Return the first configured domain the email address belongs to, or null if none match.
	 *
	 * @param   string    $email    The email address to test.
	 * @param   string[]  $domains  The list of configured domains.
	 *
	 * @return  string|null
	 */
	protected static function findMatchingDomain(string $email, array $domains): ?string
	{
		foreach ($domains as $domain)
		{
			if (self::emailMatchesDomain($email, $domain))
			{
				return $domain;
			}
		}

		return null;
	}

	/**
	 * Does the email address belong to the given domain?
	 *
	 * The comparison is anchored to the host part of the address (everything after the last "@") and
	 * matches either the exact domain or any of its subdomains, case-insensitively. This prevents the
	 * local part of the address from being used to bypass an allow-list or trip a block-list.
	 *
	 * @param   string  $email   The email address to test.
	 * @param   string  $domain  The domain to test against.
	 *
	 * @return  bool
	 */
	protected static function emailMatchesDomain(string $email, string $domain): bool
	{
		$domain = strtolower(trim($domain));

		if ($domain === '')
		{
			return false;
		}

		$host = self::extractDomain($email);

		if ($host === '')
		{
			return false;
		}

		if ($host === $domain)
		{
			return true;
		}

		// Subdomain match: "mail.example.com" matches the configured "example.com".
		$suffix = '.' . $domain;

		return substr($host, -strlen($suffix)) === $suffix;
	}

	/**
	 * Extract the lowercase host part (after the last "@") of an email address.
	 *
	 * @param   string  $email  The email address.
	 *
	 * @return  string  The host part, or an empty string if the address has no "@".
	 */
	protected static function extractDomain(string $email): string
	{
		$email = trim($email);
		$atPos = strrpos($email, '@');

		if ($atPos === false)
		{
			return '';
		}

		return strtolower(substr($email, $atPos + 1));
	}

	/**
	 * Load the component's administrator translation files so the error message can be shown.
	 *
	 * @return  void
	 */
	private function loadComponentLanguage(): void
	{
		$jlang = $this->app->getLanguage();
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, 'en-GB', true);
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, $jlang->getDefault(), true);
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, null, true);
	}
}
