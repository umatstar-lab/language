<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Sanitiser for the WAF “Allowed Domains” configuration.
 *
 * Historically a bug in the Quick Setup Wizard could persist an obviously invalid allowed domain. The wizard
 * loaded the domains in the subform database format ([[domain, description], …]) and imploded them straight into
 * a text field; PHP casts each row (an array) to the literal string “Array”, which the wizard then saved back as
 * the allowed domain. Once persisted, the WAF lower-cases it to “array” and auto-generates the “www.array”
 * variant, so the real domain is lost and the site is locked out behind an HTTP 421 error.
 *
 * These helpers let every consumer (the WAF feature, and the Quick Setup Wizard / Configure WAF form loaders)
 * share a single definition of “obviously corrupt” so affected sites self-heal.
 *
 * @since 7.0.0
 */
abstract class AllowedDomainsFilter
{
	/**
	 * The obviously corrupt domain values produced by the historical Quick Setup Wizard bug.
	 *
	 * These are the lower-cased results of PHP casting the subform data structure to a string, plus the
	 * “www.” variant the WAF auto-generates from it. Neither can ever be a legitimate Host header.
	 *
	 * @var   string[]
	 * @since 7.0.0
	 */
	private const CORRUPT_VALUES = ['array', 'www.array'];

	/**
	 * Sanitise a list of allowed domains, dropping empty and obviously corrupt entries.
	 *
	 * Accepts both the flat storage format (['example.com', …]) and the subform database format
	 * ([['example.com', 'description'], …]), returning a de-duplicated, zero-indexed list of bare domain
	 * strings.
	 *
	 * @param   mixed  $domains  The stored allowed-domains value.
	 *
	 * @return  string[]
	 * @since   7.0.0
	 */
	public static function sanitize($domains): array
	{
		if (is_object($domains))
		{
			$domains = (array) $domains;
		}

		if (!is_array($domains))
		{
			return [];
		}

		$clean = [];

		foreach ($domains as $domain)
		{
			// Flatten the subform database format ([domain, description]) to the bare domain string.
			if (is_array($domain))
			{
				$domain = $domain[0] ?? null;
			}

			if (!is_string($domain))
			{
				continue;
			}

			$domain = trim($domain);

			if (!self::isValid($domain))
			{
				continue;
			}

			$clean[] = $domain;
		}

		return array_values(array_unique($clean));
	}

	/**
	 * Is this a plausible allowed-domain value, i.e. not empty and not an obviously corrupt artifact?
	 *
	 * @param   mixed  $domain  The domain value to check.
	 *
	 * @return  bool
	 * @since   7.0.0
	 */
	public static function isValid($domain): bool
	{
		if (!is_string($domain))
		{
			return false;
		}

		$domain = trim($domain);

		if ($domain === '')
		{
			return false;
		}

		// Reject the “array to string” artifact left behind by the historical Quick Setup Wizard bug.
		return !in_array(strtolower($domain), self::CORRUPT_VALUES, true);
	}
}
