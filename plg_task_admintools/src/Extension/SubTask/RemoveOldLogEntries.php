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
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;

/**
 * Scheduled Task type to remove old Blocked Request Log entries
 *
 * @since 7.1.2
 */
trait RemoveOldLogEntries
{
	use TaskParametersTrait;

	/**
	 * How many old entries to delete every time this feature executes.
	 *
	 * @since 7.1.2
	 */
	private static $DELETE_BATCH_SIZE = 10000;

	/**
	 * Removes old Blocked Requests Log entries beyond a certain number to keep.
	 *
	 * @param   ExecuteTaskEvent  $event
	 *
	 * @return  int
	 * @since   7.1.2
	 */
	private function removeOldLogEntries(ExecuteTaskEvent $event): int
	{
		$this->loadAdminToolsLanguage();

		// Get some basic information about the task at hand.
		/** @var Task $task */
		$task          = $event->getArgument('subject');
		$params        = $event->getArgument('params');
		$entriesToKeep = $this->getTaskParameterInteger($params, 'maxlogentries', 0);

		/**
		 * Nothing to keep means nothing to do. So does a parameter we cannot make sense of: the
		 * alternative is coercing it into a number of entries to keep which no one has configured,
		 * and then deleting everything past it.
		 */
		if ($entriesToKeep <= 0)
		{
			return Status::OK;
		}

		/**
		 * Delete up to 10,000 old entries
		 *
		 * IMPORTANT! We need a subquery-inside-a-subquery to avoid a MySQL limitation which does not allow IN arguments
		 * to be subqueries with LIMITs.
		 *
		 * The SQL to run is:
		 * DELETE FROM `#__admintools_log` WHERE `id` IN (SELECT * FROM (SELECT `id` FROM `#__admintools_log` ORDER BY
		 * `id` desc LIMIT $entriesToKeep, self::DELETE_BATCH_SIZE) `foo`)
		 */
		$db = $this->getDatabase();

		$innerSubquery = DbQuery::create($db)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__admintools_log'))
			->order($db->quoteName('id') . ' DESC')
			->setLimit(self::$DELETE_BATCH_SIZE, $entriesToKeep);

		$outerSubquery = DbQuery::create($db)
			->select('*')
			->from('(' . $innerSubquery . ') ' . $db->quoteName('foo'));

		$query = DbQuery::create($db)
			->delete($db->quoteName('#__admintools_log'))
			->where($db->quoteName('id') . ' IN (' . $outerSubquery . ')');

		try
		{
			$db->setQuery($query)->execute();
			$affectedRows = $db->getAffectedRows();
		}
		catch (\Exception $exc)
		{
			// Do nothing on DB exception
			$affectedRows = 0;
		}

		return ($affectedRows === self::$DELETE_BATCH_SIZE) ? Status::WILL_RESUME : Status::OK;
	}
}