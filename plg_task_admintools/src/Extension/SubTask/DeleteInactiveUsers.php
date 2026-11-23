<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\AdminTools\Extension\SubTask;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Plugin\Task\AdminTools\Extension\Traits\TaskParametersTrait;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;

/**
 * Delete inactive Joomla user records
 *
 * @since  7.1.2
 */
trait DeleteInactiveUsers
{
	use TaskParametersTrait;

	/**
	 * Delete inactive Joomla user records
	 *
	 * @param   ExecuteTaskEvent  $event  The scheduled task event we are handling
	 *
	 * @return  int
	 * @since   7.1.2
	 */
	private function deleteInactiveUsers(ExecuteTaskEvent $event): int
	{
		$this->loadAdminToolsLanguage();

		// Get some basic information about the task at hand.
		/** @var Task $task */
		$task = $event->getArgument('subject');
		[$filtertype, $days, $groups] = $this->normaliseDeleteInactiveUsersParameters(
			$event->getArgument('params')
		);

		$schedulerParams = ComponentHelper::getParams('com_scheduler');
		$timeLimit       = intdiv(intval($schedulerParams->get('timeout', 300) ?: 300), 2);
		$phpTimeLimit    = function_exists('ini_get') ? ini_get('max_execution_time') : 0;

		$timeLimit = ($phpTimeLimit > 0) ? min($timeLimit, $phpTimeLimit) : $timeLimit;

		$hasMore         = true;
		$totalElapsed    = 0.0;
		$lastStepElapsed = 0.0;
		$maxStepElapsed  = 0.0;

		// Pre-compute the group filter subquery SQL string once (constant across all loop iterations)
		$groupFilterSql = '';

		if (!empty($groups))
		{
			$dbForGroups    = $this->getDatabase();
			$subQuery       = DbQuery::create($dbForGroups)
				->select($dbForGroups->qn('user_id'))
				->from($dbForGroups->qn('#__user_usergroup_map'))
				->where($dbForGroups->qn('group_id') . ' IN (' . implode(',', $groups) . ')');
			$groupFilterSql = (string) $subQuery;
		}

		while (true)
		{
			$totalElapsed   += $lastStepElapsed;
			$maxStepElapsed = max($maxStepElapsed, $lastStepElapsed);

			if ($totalElapsed >= ($timeLimit - $maxStepElapsed))
			{
				// We have hit or are about to hit a timeout
				break;
			}

			$stepStart = microtime(true);
			$db        = $this->getDatabase();
			$nullDate  = $db->getNullDate();
			$sql       = DbQuery::create($db)
			                ->select($db->qn('id'))
			                ->from($db->qn('#__users'))
			                ->where($db->qn('registerDate') . ' <= ' . "DATE_SUB(NOW(), INTERVAL $days DAY)")
			                ->extendWhere('AND', [
				                $db->quoteName('lastvisitDate') . ' = :nullDate',
				                $db->quoteName('lastvisitDate') . ' IS NULL',
			                ], 'OR')
			                ->bind(':nullDate', $nullDate);

			if ($groupFilterSql !== '')
			{
				// Apply this before extendWhere('AND NOT', ...) below. Joomla's query
				// builder otherwise carries that glue over and negates this filter too.
				$sql->where($db->qn('id') . ' IN (' . $groupFilterSql . ')');
			}

			switch ($filtertype)
			{
				case 1:
					// Only users not yet activated
					$sql->extendWhere('AND NOT', [
						$db->quoteName('activation') . ' = ' . $db->quote(''),
						$db->quoteName('activation') . ' IS NULL',
					], 'OR');
					break;

				case 2:
					// Only users already activated
					$sql
						->extendWhere('AND', [
							$db->quoteName('activation') . ' = ' . $db->quote(''),
							$db->quoteName('activation') . ' IS NULL',
						], 'OR')
						->extendWhere('AND', [
							$db->quoteName('lastResetTime') . ' IS NULL',
							$db->quoteName('lastResetTime') . ' = :nullDate20',
						], 'OR')
						->bind(':nullDate20', $nullDate);
					break;

				case 3:
					// All users who haven't logged in
					break;
			}


			$db->setQuery($sql, 0, 1);

			$id = $db->loadResult();

			if (empty($id))
			{
				// No more users to process.
				$hasMore = false;

				break;
			}

			$userToKill = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);
			$userToKill->delete();

			$lastStepElapsed = microtime(true) - $stepStart;
		}

		return $hasMore ? Status::WILL_RESUME : Status::OK;
	}

	/**
	 * Normalise the parameters Joomla passes to the scheduled task.
	 *
	 * Joomla JSON-decodes task parameters into a stdClass. Do not use Registry
	 * methods here, and do not let malformed stored JSON raise PHP warnings or
	 * broaden the deletion criteria unexpectedly.
	 *
	 * @param   mixed  $params  The task parameters supplied by Joomla
	 *
	 * @return  array{0: int, 1: int, 2: int[]}
	 * @since   7.9.2
	 */
	private function normaliseDeleteInactiveUsersParameters($params): array
	{
		$filtertype = $this->getTaskParameterInteger($params, 'deleteinactive', 1);
		$days       = $this->getTaskParameterInteger($params, 'deleteinactive_days', 0);
		$groups     = $this->getTaskParameterIntegerList($params, 'deleteinactive_groups');

		if (!in_array($filtertype, [1, 2, 3], true))
		{
			$filtertype = 1;
		}

		if ($days < 0)
		{
			$days = 0;
		}

		return [$filtertype, $days, $groups];
	}
}
