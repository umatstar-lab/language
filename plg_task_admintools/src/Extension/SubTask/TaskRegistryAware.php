<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\AdminTools\Extension\SubTask;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Registry\Registry;

trait TaskRegistryAware
{
	/**
	 * A registry object to keep track of parameters between subsequent calls of the resumable task.
	 *
	 * @var   Registry
	 * @since 7.1.2
	 */
	private $taskInfoRegistry;

	/**
	 * Get an #__akeebabackup_storage key for the task being executed by the event.
	 *
	 * This is used to store the temporary information which survives consecutive calls to the resumable task.
	 *
	 * @param   ExecuteTaskEvent  $event
	 *
	 * @return  string
	 * @since   7.1.2
	 */
	private function getTaskKey(ExecuteTaskEvent $event): string
	{
		return 'task.' . $event->getRoutineId() . '.' . $event->getTaskId();
	}

	/**
	 * Load the temporary information for the resumable task being executed by the event.
	 *
	 * @param   ExecuteTaskEvent  $event
	 *
	 * @return  void
	 * @since   7.1.2
	 */
	private function loadTaskRegistry(ExecuteTaskEvent $event): void
	{
		$key = $this->getTaskKey($event);

		try
		{
			$json = KeyValueStorage::get($key, null, $this->getDatabase()) ?: null;
		}
		catch (\Exception $e)
		{
			$json = null;
		}

		$this->taskInfoRegistry = new Registry($json);

		$this->removeTaskRegistry($event);
	}

	/**
	 * Remove the temporary information for the resumable task being executed by the event.
	 *
	 * @param   ExecuteTaskEvent  $event
	 *
	 * @return  void
	 * @since   7.1.2
	 */
	private function removeTaskRegistry(ExecuteTaskEvent $event): void
	{
		$key = $this->getTaskKey($event);

		try
		{
			KeyValueStorage::remove($key, $this->getDatabase());
		}
		catch (\Exception $e)
		{
			// No worries.
		}
	}

	/**
	 * Save the temporary information for the resumable task being executed by the event.
	 *
	 * @param   ExecuteTaskEvent  $event
	 *
	 * @return  void
	 * @since   7.1.2
	 */
	private function saveTaskRegistry(ExecuteTaskEvent $event): void
	{
		$this->removeTaskRegistry($event);

		$key = $this->getTaskKey($event);

		try
		{
			KeyValueStorage::set($key, $this->taskInfoRegistry->toString(), $this->getDatabase());
		}
		catch (\Exception $e)
		{
			// No worries.
		}
	}
}