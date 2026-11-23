<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerCustomACLTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerEventsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerRegisterTasksTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerReusableModelsTrait;
use Akeeba\Component\AdminTools\Administrator\Model\ScansModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;

class ScansController extends AdminController
{
	use ControllerEventsTrait;
	use ControllerCustomACLTrait;
	use ControllerRegisterTasksTrait;
	use ControllerReusableModelsTrait;

	protected $text_prefix = 'COM_ADMINTOOLS_SCANS';

	public function getModel($name = 'Scan', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}

	public function startscan()
	{
		// Validate the anti-CSRF token. The token travels in the AJAX launcher URL.
		$this->checkToken('get');

		/** @var ScansModel $model */
		$model = $this->getModel('Scans', 'Administrator', ['ignore_request' => true]);

		$retArray = $model->startScan();

		@ob_clean();
		$this->sendNoCacheHeaders();
		echo json_encode($retArray);
		$this->app->close();
	}

	public function stepscan()
	{
		// Validate the anti-CSRF token. The token travels in the AJAX launcher URL.
		$this->checkToken('get');

		/** @var ScansModel $model */
		$model = $this->getModel('Scans', 'Administrator', ['ignore_request' => true]);

		$retArray = $model->stepScan();

		@ob_clean();
		$this->sendNoCacheHeaders();
		echo json_encode($retArray);
		$this->app->close();
	}

	/**
	 * Send HTTP headers which prevent the browser and any intermediary proxies from caching the response.
	 *
	 * The scan AJAX endpoints echo their JSON directly and call $this->app->close(), bypassing Joomla's
	 * response object. Therefore we have to emit the headers with PHP's header() function before any output
	 * instead of using $this->app->setHeader().
	 *
	 * @return  void
	 */
	private function sendNoCacheHeaders(): void
	{
		if (headers_sent())
		{
			return;
		}

		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, no-transform', true);
		header('Pragma: no-cache', true);
		header('Expires: Wed, 17 Aug 2005 00:00:00 GMT', true);
	}

	public function purge()
	{
		$this->checkToken('request');

		/** @var ScansModel $model */
		$model = $this->getModel('Scans', 'Administrator', ['ignore_request' => true]);
		$type  = 'success';

		if ($model->purgeFilesCache())
		{
			$msg = Text::_('COM_ADMINTOOLS_SCAN_LBL_MSG_PURGE_COMPLETED');
		}
		else
		{
			$msg  = Text::_('COM_ADMINTOOLS_SCAN_LBL_MSG_PURGE_ERROR');
			$type = 'error';
		}

		$this->setRedirect(Route::_('index.php?option=com_admintools&view=Scans', false), $msg, $type);
	}

	public function dismissCoreSumsWarning()
	{
		$this->checkToken('get');

		/** @var ScansModel $model */
		$model = $this->getModel('Scans', 'Administrator', ['ignore_request' => true]);
		$model->dismissCoreSumsWarning();

		$this->setRedirect(Route::_('index.php?option=com_admintools&view=Scans', false));
	}

	protected function onBeforeMain()
	{
		/** @var ScansModel $model */
		$model = $this->getModel('Scans', 'Administrator', ['ignore_request' => true]);

		$model->removeIncompleteScans();
	}
}