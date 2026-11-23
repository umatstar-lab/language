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
use Akeeba\Component\AdminTools\Administrator\Model\ObsoletecoreModel;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Removes the Joomla! core files and directories a Joomla update should have deleted, but did not.
 *
 * @since 7.9.2
 */
class ObsoletecoreController extends BaseController
{
	use ControllerCustomACLTrait;
	use ControllerEventsTrait;
	use ControllerRegisterTasksTrait;
	use ControllerReusableModelsTrait;

	/**
	 * Remove a batch of obsolete core files, then render the pop-up.
	 *
	 * The model holds no state between requests: each batch rebuilds the list of obsolete items still present on the
	 * site and works through as many as it can in the time it is given. The browser reloads this same URL until the
	 * model reports it has nothing left to do, exactly like the Clean Temp-directory feature does. Since every
	 * deletion is permanent, successive batches see less and less work, and the run converges.
	 *
	 * @return  void
	 * @since   7.9.2
	 */
	public function main()
	{
		// Validate the anti-CSRF token. The token travels in the launcher URL and is preserved by the JS reload loop.
		$this->checkToken('request');

		/** @var ObsoletecoreModel $model */
		$model = $this->getModel();

		$model->setState('scanstate', $model->process());

		$this->display(false);
	}
}
