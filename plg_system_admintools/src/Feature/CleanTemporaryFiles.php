<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Model\CleantempdirectoryModel;
use Exception;
use Joomla\CMS\Factory;

/**
 * @deprecated 8.0  Use the Joomla Scheduled Tasks instead
 */
class CleanTemporaryFiles extends Base
{
	/**
	 * Which fraction of PHP's max_execution_time may a cleanup run consume?
	 *
	 * This runs inside an ordinary page request, made by whoever happened to be the first visitor after the interval
	 * elapsed. Whatever we do not spend here is what is left for the page they actually asked for.
	 */
	private const TIME_BUDGET_DIVISOR = 4;

	/**
	 * Time budget for a cleanup run, in seconds, used when PHP's max_execution_time is unlimited.
	 *
	 * `max_execution_time` is 0 — no limit at all — on plenty of CLI-ish and misconfigured set-ups. Unlimited is
	 * precisely what we are trying not to be, so we fall back to a fixed, modest budget instead.
	 */
	private const FALLBACK_TIME_BUDGET = 10;

	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		return ($this->params->get('cleantemp', 0) == 1);
	}

	public function onAfterInitialise(): void
	{
		$minutes = (int) $this->params->get('cleantemp_freq', 0);

		if ($minutes <= 0)
		{
			return;
		}

		$lastJob = $this->getTimestamp('clean_temp');
		$nextJob = $lastJob + $minutes * 60;

		$now = clone Factory::getDate();

		if ($now->toUnix() >= $nextJob)
		{
			$this->setTimestamp('clean_temp');
			$this->tempDirectoryCleanup();
		}
	}

	/**
	 * Cleans up the temporary directory, for as long as we can afford to inside a page request.
	 *
	 * Whatever is left over is picked up by the next run, at the next interval. Cleaning up converges over several
	 * runs instead of holding a single visitor hostage for the entire job — and, since the timestamp is recorded
	 * before we get here, a run cut short does not repeat itself on the very next request.
	 */
	private function tempDirectoryCleanup()
	{
		try
		{
			$model = $this->getCleanupModel();

			$timeBudget = $this->getTimeBudget();
			$startTime  = microtime(true);

			/**
			 * process() returns false when it hits its internal timeout, true once the temp directory is clean.
			 *
			 * Note that a run can overshoot its budget by as much as that internal timeout — five seconds — since we
			 * only get to look at the clock between passes. Any budget shorter than that is therefore approximate.
			 */
			$done = $model->process();

			while (!$done && (microtime(true) - $startTime) < $timeBudget)
			{
				$done = $model->process();
			}
		}
		catch (Exception $e)
		{
			// Avoid any blank page on error
		}
	}

	/**
	 * How many seconds may this cleanup run take?
	 *
	 * There is no com_scheduler timeout to consult, the way the equivalent scheduled task does, so the budget comes
	 * from PHP's own execution time limit — and only a fraction of it, since the visitor's page still has to be built
	 * afterwards. Letting PHP's `max_execution_time` kill us mid-run would be a fatal error, not an exception, i.e.
	 * something the catch block around the loop cannot turn into anything but a blank page.
	 *
	 * @return  float  The time budget, in seconds.
	 */
	protected function getTimeBudget(): float
	{
		$phpTimeLimit = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;

		if ($phpTimeLimit <= 0)
		{
			return (float) self::FALLBACK_TIME_BUDGET;
		}

		return (float) intdiv($phpTimeLimit, self::TIME_BUDGET_DIVISOR);
	}

	/**
	 * Returns the model which does the actual cleaning up.
	 *
	 * @return  CleantempdirectoryModel
	 */
	protected function getCleanupModel()
	{
		return new CleantempdirectoryModel();
	}
}
