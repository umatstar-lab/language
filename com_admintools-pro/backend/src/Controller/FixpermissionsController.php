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
use Akeeba\Component\AdminTools\Administrator\Model\FixpermissionsModel;
use Joomla\CMS\MVC\Controller\BaseController;

class FixpermissionsController extends BaseController
{
	use ControllerEventsTrait;
	use ControllerCustomACLTrait;
	use ControllerRegisterTasksTrait;
	use ControllerReusableModelsTrait;

	public function main()
	{
		// Validate the anti-CSRF token. Accept it via GET (initial launcher link) or POST.
		$this->checkToken('request');

		/** @var FixpermissionsModel $model */
		$model = $this->getModel();
		$state = $model->startScanning();
		$model->setState('scanstate', $state);

		$this->display(false);
	}

	public function run()
	{
		$this->checkToken('request');

		/** @var FixpermissionsModel $model */
		$model = $this->getModel();
		$state = $model->run();
		$model->setState('scanstate', $state);

		$this->display(false);
	}

}