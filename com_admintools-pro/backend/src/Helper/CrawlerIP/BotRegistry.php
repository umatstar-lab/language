<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

/**
 * The registry of crawlers ("bots") whose published IP lists Admin Tools can use.
 *
 * One entry is one published list. Adding a bot is a local change: add an entry here, and the language strings for
 * its label (and its provider's label, if the provider is new).
 *
 * @since   7.9.4
 */
final class BotRegistry
{
	/**
	 * The bots, in display order.
	 *
	 * 'provider' is the provider slug (grouping and language keys); 'url' is where the list is published; 'format' is
	 * 'json' (a JSON document) or 'amazon_html' (a JSON document inside the code block of an HTML page).
	 *
	 * @since   7.9.4
	 */
	private const BOTS = [
		'amazon_amazonbot'           => [
			'provider' => 'amazon',
			'url'      => 'https://developer.amazon.com/amazonbot/ip-addresses/',
			'format'   => 'amazon_html',
		],
		'amazon_searchbot'           => [
			'provider' => 'amazon',
			'url'      => 'https://developer.amazon.com/amazonbot/searchbot-ip-addresses/',
			'format'   => 'amazon_html',
		],
		'amazon_user'                => [
			'provider' => 'amazon',
			'url'      => 'https://developer.amazon.com/amazonbot/live-ip-addresses/',
			'format'   => 'amazon_html',
		],
		'apple_applebot'             => [
			'provider' => 'apple',
			'url'      => 'https://search.developer.apple.com/applebot.json',
			'format'   => 'json',
		],
		'bing_bingbot'               => [
			'provider' => 'bing',
			'url'      => 'https://www.bing.com/toolbox/bingbot.json',
			'format'   => 'json',
		],
		'duckduckgo_duckduckbot'     => [
			'provider' => 'duckduckgo',
			'url'      => 'https://duckduckgo.com/duckduckbot.json',
			'format'   => 'json',
		],
		'duckduckgo_duckassistbot'   => [
			'provider' => 'duckduckgo',
			'url'      => 'https://duckduckgo.com/duckassistbot.json',
			'format'   => 'json',
		],
		'google_common'              => [
			'provider' => 'google',
			'url'      => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
			'format'   => 'json',
		],
		'google_special'             => [
			'provider' => 'google',
			'url'      => 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
			'format'   => 'json',
		],
		'google_userfetchers'        => [
			'provider' => 'google',
			'url'      => 'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json',
			'format'   => 'json',
		],
		'google_userfetchers_google' => [
			'provider' => 'google',
			'url'      => 'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
			'format'   => 'json',
		],
		'mistral_index'              => [
			'provider' => 'mistral',
			'url'      => 'https://mistral.ai/mistralai-index-ips.json',
			'format'   => 'json',
		],
		'mistral_user'               => [
			'provider' => 'mistral',
			'url'      => 'https://mistral.ai/mistralai-user-ips.json',
			'format'   => 'json',
		],
		'openai_gptbot'              => [
			'provider' => 'openai',
			'url'      => 'https://openai.com/gptbot.json',
			'format'   => 'json',
		],
		'openai_searchbot'           => [
			'provider' => 'openai',
			'url'      => 'https://openai.com/searchbot.json',
			'format'   => 'json',
		],
		'openai_chatgptuser'         => [
			'provider' => 'openai',
			'url'      => 'https://openai.com/chatgpt-user.json',
			'format'   => 'json',
		],
		'perplexity_perplexitybot'   => [
			'provider' => 'perplexity',
			'url'      => 'https://www.perplexity.ai/perplexitybot.json',
			'format'   => 'json',
		],
		'perplexity_user'            => [
			'provider' => 'perplexity',
			'url'      => 'https://www.perplexity.ai/perplexity-user.json',
			'format'   => 'json',
		],
	];

	/**
	 * All bot keys, in display order.
	 *
	 * @return  string[]
	 * @since   7.9.4
	 */
	public static function keys(): array
	{
		return array_keys(self::BOTS);
	}

	/**
	 * Is this a known bot key?
	 *
	 * @param   string  $key  The bot key.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public static function has(string $key): bool
	{
		return isset(self::BOTS[$key]);
	}

	/**
	 * Get the registry entry of a bot, plus its 'key'.
	 *
	 * @param   string  $key  The bot key.
	 *
	 * @return  array|null  NULL if the bot is unknown.
	 * @since   7.9.4
	 */
	public static function get(string $key): ?array
	{
		if (!self::has($key))
		{
			return null;
		}

		return array_merge(self::BOTS[$key], ['key' => $key]);
	}

	/**
	 * Bot keys grouped by provider slug; providers in first-seen order.
	 *
	 * @return  array<string, string[]>
	 * @since   7.9.4
	 */
	public static function providers(): array
	{
		$ret = [];

		foreach (self::BOTS as $key => $bot)
		{
			$ret[$bot['provider']][] = $key;
		}

		return $ret;
	}

	/**
	 * The language key of a bot's label.
	 *
	 * @param   string  $key  The bot key.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public static function labelKey(string $key): string
	{
		return 'COM_ADMINTOOLS_CRAWLERIPS_BOT_' . strtoupper($key);
	}

	/**
	 * The language key of a bot's description: a short explanation of what the operator uses the crawler for.
	 *
	 * @param   string  $key  The bot key.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public static function descKey(string $key): string
	{
		return 'COM_ADMINTOOLS_CRAWLERIPS_BOT_' . strtoupper($key) . '_DESC';
	}

	/**
	 * The language key of a provider's label.
	 *
	 * @param   string  $provider  The provider slug.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public static function providerLabelKey(string $provider): string
	{
		return 'COM_ADMINTOOLS_CRAWLERIPS_PROVIDER_' . strtoupper($provider);
	}

	/**
	 * The WAF configuration key which holds a bot's state (ignore / block / allow).
	 *
	 * @param   string  $key  The bot key.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public static function settingKey(string $key): string
	{
		return 'crawlerips_' . $key;
	}
}
