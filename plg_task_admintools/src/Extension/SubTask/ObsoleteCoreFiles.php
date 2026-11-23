<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\AdminTools\Extension\SubTask;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Model\ObsoletecoreModel;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;

/**
 * Remove the obsolete Joomla! core files a Joomla update should have deleted, but did not.
 *
 * @since  7.9.2
 */
trait ObsoleteCoreFiles
{
	/**
	 * Remove obsolete Joomla! core files and directories
	 *
	 * @param   ExecuteTaskEvent  $event  The scheduled task event we are handling
	 *
	 * @return  int
	 * @since   7.9.2
	 */
	private function obsoleteCoreFiles(ExecuteTaskEvent $event): int
	{
		$this->loadAdminToolsLanguage();

		// Get some basic information about the task at hand.
		/** @var Task $task */
		$task = $event->getArgument('subject');

		if (!ComponentHelper::isEnabled('com_admintools'))
		{
			return Status::OK;
		}

		// Make sure Admin Tools is installed and enabled.
		$component = $this->getApplication()->bootComponent('com_admintools');

		if (!($component instanceof MVCFactoryServiceInterface))
		{
			throw new \RuntimeException('The Admin Tools component is not installed or has been disabled.');
		}

		$factory = $component->getMVCFactory();

		/** @var ObsoletecoreModel $model */
		$model = $factory->createModel('Obsoletecore', 'Administrator', ['ignore_request' => true]);

		$schedulerParams = ComponentHelper::getParams('com_scheduler');
		$timeLimit       = intdiv(intval($schedulerParams->get('timeout', 300) ?: 300), 2);
		$phpTimeLimit    = function_exists('ini_get') ? ini_get('max_execution_time') : 0;
		$timeLimit       = ($phpTimeLimit > 0) ? min($timeLimit, $phpTimeLimit) : $timeLimit;
		$timeLimit       = max(1, $timeLimit);
		$startTime       = microtime(true);
		$status          = $model->process();

		while (!$status && microtime(true) - $startTime < $timeLimit)
		{
			$status = $model->process();
		}

		/**
		 * The model holds no state between runs. When we run out of time we simply report that we will resume; the
		 * next run rebuilds the list of what is still there and carries on where this one left off.
		 */
		if (!$status)
		{
			$this->logTask('Ran out of time. Removing obsolete Joomla! core files will resume on the next run.');

			return Status::WILL_RESUME;
		}

		$failures = $model->getFailures();

		if (empty($failures))
		{
			$this->logTask('There are no obsolete Joomla! core files or folders left on this site.');

			return Status::OK;
		}

		$this->logTask(
			sprintf(
				'%d obsolete Joomla! core files and folders could not be removed. Please delete them by hand over '
				. 'FTP, SFTP, or with the file manager your host provides.',
				count($failures)
			),
			'warning'
		);

		foreach ($failures as $failure)
		{
			$this->logTask(sprintf('Could not remove the %s %s', $failure['type'], $failure['path']), 'warning');
		}

		return Status::KNOCKOUT;
	}
}
