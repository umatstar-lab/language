<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

use Joomla\CMS\Http\HttpFactory;
use Throwable;

/**
 * Downloads a URL using Joomla's HTTP client.
 *
 * We use the CMS HttpFactory (not the framework one) because its transports honour the proxy settings in Joomla's
 * Global Configuration.
 *
 * Joomla's transports cast the timeout to whole seconds, so 500 ms would become 0, i.e. no limit, on cURL. On cURL we
 * therefore pass the millisecond options directly; on every other transport the limit is rounded up to whole seconds
 * (minimum 1). The limit is approximate outside cURL. The caller's site-wide lock and one-download-per-request limit
 * are what bound the damage in that case.
 *
 * @since   7.9.4
 */
class HttpFetcher implements FetcherInterface
{
	/**
	 * @inheritDoc
	 * @since 7.9.4
	 */
	public function fetch(string $url, int $timeoutMs): array
	{
		try
		{
			$options = self::buildOptions($timeoutMs);

			$response = HttpFactory::getHttp($options)->get($url);

			// PSR-7 accessors: the magic ->code / ->body properties are gone in Joomla 6.
			$code = (int) $response->getStatusCode();

			if ($code < 200 || $code >= 300)
			{
				return ['body' => null, 'error' => 'HTTP ' . $code];
			}

			return ['body' => (string) $response->getBody(), 'error' => null];
		}
		catch (Throwable $e)
		{
			return ['body' => null, 'error' => $e->getMessage() ?: get_class($e)];
		}
	}

	/**
	 * The options for Joomla's HTTP client.
	 *
	 * @param   int  $timeoutMs  The time limit, in milliseconds.
	 *
	 * @return  array
	 * @since   7.9.4
	 */
	public static function buildOptions(int $timeoutMs): array
	{
		$timeoutMs = max(1, $timeoutMs);
		$options   = [
			'follow_location' => true,
			'userAgent'       => 'Mozilla/5.0 (compatible; AdminTools/'
			                     . (defined('ADMINTOOLS_VERSION') ? ADMINTOOLS_VERSION : 'dev')
			                     . '; +https://www.akeeba.com)',
			// Joomla's transports take whole seconds. Round up: 500 ms must not become 0, which means "no limit".
			'timeout'         => max(1, (int) ceil($timeoutMs / 1000)),
		];

		if (defined('CURLOPT_TIMEOUT_MS') && defined('CURLOPT_CONNECTTIMEOUT_MS') && defined('CURLOPT_NOSIGNAL'))
		{
			// The transport applies these after its own whole-second CURLOPT_TIMEOUT, so they win.
			$options['transport.curl'] = [
				CURLOPT_TIMEOUT_MS        => $timeoutMs,
				CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
				CURLOPT_NOSIGNAL          => 1,
			];
		}

		return $options;
	}
}
