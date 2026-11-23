<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\AdminTools\Extension\SubTask;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\BotRegistry;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\CrawlerIPLists;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\StateStore;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Refresh the crawler IP lists
 *
 * Resumable. The scheduler gives us no state to carry between runs, so a run is identified by a start time kept in
 * a row of the Admin Tools storage table. A bot is "done" in this run when its last download attempt is not older
 * than the run's start; the next run (a resume) simply carries on with the bots which are not done yet.
 *
 * @since  7.9.4
 */
trait CrawlerIPs
{
	/**
	 * Storage row holding the Unix start time of an unfinished (to be resumed) run.
	 *
	 * @since 7.9.4
	 */
	private static $crawlerIPsRunKey = 'crawlerips.taskrun';

	/**
	 * A run which was not finished within this many seconds is abandoned rather than resumed.
	 *
	 * @since 7.9.4
	 */
	private static $crawlerIPsMaxRunAge = 21600;

	/**
	 * Refresh the crawler IP lists of every bot which is not set to Ignore.
	 *
	 * @param   ExecuteTaskEvent  $event  The scheduled task event we are handling
	 *
	 * @return  int
	 * @since   7.9.4
	 */
	private function crawlerIPs(ExecuteTaskEvent $event): int
	{
		$this->loadAdminToolsLanguage();

		if (!ComponentHelper::isEnabled('com_admintools'))
		{
			return Status::OK;
		}

		try
		{
			// Booting the component registers its autoloader.
			$component = $this->getApplication()->bootComponent('com_admintools');

			if (!($component instanceof MVCFactoryServiceInterface))
			{
				throw new \RuntimeException('The Admin Tools component is not installed or has been disabled.');
			}

			if (!class_exists(CrawlerIPLists::class))
			{
				$this->logTask('The crawler IP lists are only available in Admin Tools Professional.');

				return Status::OK;
			}

			$service = CrawlerIPLists::getInstance();

			if (!$service->isEnabled())
			{
				$this->logTask('The crawler IP lists feature is not enabled. Nothing to do.');

				return Status::OK;
			}

			$state     = new StateStore(Factory::getContainer()->get(DatabaseInterface::class));
			$now       = time();
			$runStart  = (int) $state->readValue(self::$crawlerIPsRunKey);
			$resuming  = $runStart > 0 && $runStart <= $now && ($now - $runStart) < self::$crawlerIPsMaxRunAge;

			if (!$resuming)
			{
				$runStart = $now;
				$state->writeValue(self::$crawlerIPsRunKey, (string) $runStart);
			}

			$this->logTask($resuming ? 'Resuming the refresh of the crawler IP lists.' : 'Refreshing the crawler IP lists.');

			// Same time budget logic as the temp directory cleaner, based on the scheduler's own timeout.
			$params       = ComponentHelper::getParams('com_scheduler');
			$timeout      = (int) ($params->get('timeout', 300) ?: 300);
			$phpTimeLimit = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;
			$timeout      = ($phpTimeLimit > 0) ? min($timeout, $phpTimeLimit) : $timeout;
			// Budget for the slowest format (the HTML pages get a longer limit than the JSON lists).
			$downloadTime = (int) ceil(
				CrawlerIPLists::maxTimeoutMs(
					max(500, min(60000, (int) ComponentHelper::getParams('com_admintools')->get('crawlerips_timeout_ms', 1000)))
				) / 1000
			);
			$startTime    = microtime(true);
			$pending      = false;

			foreach (BotRegistry::keys() as $bot)
			{
				if ($service->getBotState($bot) === 'ignore')
				{
					continue;
				}

				// Already refreshed (or at least attempted) by this run.
				if ($state->getLastAttempt($bot) >= $runStart)
				{
					continue;
				}

				if ($timeout - (microtime(true) - $startTime) < $downloadTime + 5)
				{
					$pending = true;

					break;
				}

				$result = $service->refresh($bot);

				if (empty($result))
				{
					// The feature was turned off while we were running.
					break;
				}

				if ($result['ok'])
				{
					$this->logTask(sprintf('%s: OK, %d entries.', $bot, $result['count']));
				}
				elseif ($result['error'] === 'busy')
				{
					// Another process is downloading. Skip; we do not retry within this run, or we'd spin.
					$this->logTask(sprintf('%s: skipped, another download is in progress.', $bot), 'warning');
				}
				else
				{
					$this->logTask(sprintf('%s: failed (%s).', $bot, $result['error']), 'warning');
				}
			}

			if ($pending)
			{
				$this->logTask('Ran out of time. Refreshing the crawler IP lists will resume on the next run.');

				return Status::WILL_RESUME;
			}

			// Finished: forget the run.
			$state->writeValue(self::$crawlerIPsRunKey, '0');
			$this->logTask('Refreshing the crawler IP lists is complete.');

			return Status::OK;
		}
		catch (Throwable $e)
		{
			$this->logTask('Unexpected error: ' . $e->getMessage(), 'error');

			return Status::KNOCKOUT;
		}
	}
}
