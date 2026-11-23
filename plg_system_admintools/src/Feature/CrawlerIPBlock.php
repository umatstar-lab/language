<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\CrawlerIPLists;
use Akeeba\Plugin\System\AdminTools\Utility\Filter;
use Throwable;

/**
 * Blocks visitors whose IP is in the published ranges of a crawler which the site owner set to "Block" (gh-376).
 *
 * This is independent of the IP deny list (`ipbl`): choosing Block for a crawler is the opt-in.
 *
 * The block goes through the standard blocked request path with the `crawler` reason. That path returns early for
 * exempt IPs (administrator exclusive allow list, site IP allow list, Never block these IPs, crawlers set to
 * Do not block, Never block these domains), so the exemptions always win.
 *
 * Any failure in the crawler list service fails open: the request is not blocked.
 *
 * @since 7.9.4
 */
class CrawlerIPBlock extends Base
{
	/**
	 * Is this feature enabled?
	 *
	 * Pro only; the service must be available and switched on, and at least one crawler must be set to Block.
	 *
	 * @return  bool
	 * @since   7.9.4
	 */
	public function isEnabled()
	{
		if (!defined('ADMINTOOLS_PRO') || !ADMINTOOLS_PRO || !class_exists(CrawlerIPLists::class))
		{
			return false;
		}

		try
		{
			$service = CrawlerIPLists::getInstance();

			return $service->isEnabled() && count($service->getBotsInState('block')) > 0;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Blocks the request if the visitor's IP is in the ranges of a crawler set to Block.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function onAfterInitialise(): void
	{
		try
		{
			$ip = Filter::getIp();

			// IPv4-mapped IPv6 address, e.g. ::ffff:192.0.2.1
			if (is_string($ip) && (strpos($ip, '::') === 0) && (strstr($ip, '.') !== false))
			{
				$ip = substr($ip, strrpos($ip, ':') + 1);
			}

			if (empty($ip) || $ip === '0.0.0.0')
			{
				return;
			}

			$blocked = CrawlerIPLists::getInstance()->isIpInBlockLists((string) $ip);
		}
		catch (Throwable $e)
		{
			// Fail open.
			return;
		}

		/**
		 * Crawler Block yields to BOTH exemption tiers (operator's precedence decision for gh-376): the allow lists,
		 * Never block these IPs, crawlers set to "Do not block" and Never block these domains. blockRequest() alone
		 * would still block the Never-block tier (gh-382 semantics, correct for malicious requests), so check here.
		 */
		if ($blocked && !$this->exceptionsHandler->isExemptIP())
		{
			$this->exceptionsHandler->blockRequest('crawler');
		}
	}
}
