<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\AdminTools\Extension\SubTask;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Model\ExportimportModel;
use Akeeba\Plugin\Task\AdminTools\Extension\Traits\TaskParametersTrait;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;
use Joomla\Http\HttpFactory;

/**
 * Automatically import Admin Tools configuration settings from a URL
 *
 * @since      7.1.2
 */
trait AutoImport
{
	use TaskParametersTrait;

	/**
	 * Automatically import Admin Tools configuration settings from a URL
	 *
	 * @param   ExecuteTaskEvent  $event  The scheduled task event we are handling
	 *
	 * @return  int
	 * @since   7.1.2
	 */
	private function autoImport(ExecuteTaskEvent $event): int
	{
		$this->loadAdminToolsLanguage();

		// Get some basic information about the task at hand.
		/** @var Task $task */
		$task   = $event->getArgument('subject');
		$params = $event->getArgument('params');
		$rawUrl = $this->normaliseTaskParameters($params)->autoimport_url ?? null;
		$url    = $this->getTaskParameterUrl($params, 'autoimport_url');

		/**
		 * No URL, nothing to import. This is a legitimate configuration — the task can be set up
		 * before the URL it will import from exists — so it is not an error.
		 *
		 * Note that empty() is false for any object and for any non-empty array, so a URL check
		 * cannot be an emptiness check: an unusable value would sail past it, into an HTTP client
		 * which can only throw over it, on every single scheduled run. Anything which is not an
		 * HTTP(S) URL is therefore treated as no URL at all, and logged so it does not fail silently.
		 */
		if ($url === '')
		{
			$wasConfigured = is_string($rawUrl)
				? trim($rawUrl) !== ''
				: !in_array($rawUrl, [null, []], true);

			if ($wasConfigured)
			{
				$this->logTask(
					sprintf(
						'Ignoring the invalid Auto Import URL configured in task %d; it is not an HTTP(S) URL.',
						$task->get('id')
					),
					'warning'
				);
			}

			return Status::OK;
		}

		$http = (new HttpFactory())->getHttp();

		$response = $http->get($url);
		$settings = (string) $response->getBody();

		if ($response->getStatusCode() > 299)
		{
			throw new \RuntimeException(sprintf("Cannot download Admin Tools settings from %s (HTTP status %u)", $url, $response->getStatusCode()));
		}

		if (empty($settings))
		{
			throw new \RuntimeException(sprintf("Cannot download Admin Tools settings from %s (no content in the remote server response)", $url));
		}

		/** @var MVCFactoryInterface $factory */
		$factory = $this->getApplication()->bootComponent('com_admintools')->getMVCFactory();
		/** @var ExportimportModel $importModel */
		$importModel = $factory->createModel('Exportimport', 'Administrator', ['ignore_request' => true]);

		$importModel->importData($settings);

		return Status::OK;
	}
}