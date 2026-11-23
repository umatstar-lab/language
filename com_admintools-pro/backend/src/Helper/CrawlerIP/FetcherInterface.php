<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP;

defined('_JEXEC') || die;

/**
 * Downloads one URL with a time limit.
 *
 * @since   7.9.4
 */
interface FetcherInterface
{
	/**
	 * Perform one HTTP GET request.
	 *
	 * @param   string  $url        The URL to download.
	 * @param   int     $timeoutMs  The time limit, in milliseconds.
	 *
	 * @return  array{body: ?string, error: ?string}  Exactly one of the two is non-NULL.
	 * @since   7.9.4
	 */
	public function fetch(string $url, int $timeoutMs): array;
}
