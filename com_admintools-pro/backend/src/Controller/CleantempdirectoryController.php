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
use Akeeba\Component\AdminTools\Administrator\Model\CleantempdirectoryModel;
use Joomla\CMS\MVC\Controller\BaseController;

class CleantempdirectoryController extends BaseController
{
	use ControllerCustomACLTrait;
	use ControllerEventsTrait;
	use ControllerRegisterTasksTrait;
	use ControllerReusableModelsTrait;

	public function main()
	{
		// Validate the anti-CSRF token. The token travels in the launcher URL and is preserved by the JS reload loop.
		$this->checkToken('request');

		/** @var CleantempdirectoryModel $model */
		$model = $this->getModel();
		$model->setState('scanstate', $model->process());

		$this->display(false);
	}
}