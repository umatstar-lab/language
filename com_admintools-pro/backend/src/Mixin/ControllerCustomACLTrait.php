<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\Acl;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use RuntimeException;

trait ControllerCustomACLTrait
{
	protected function onBeforeExecute(&$task)
	{
		$this->akeebaRequestACLCheck();
	}

	/**
	 * Checks the privileges the current request needs: the controller's and, when it is a different one, the view's.
	 *
	 * The Dispatcher keeps `controller` and `view` apart when the request names both (also as `task=controller.task`),
	 * and Joomla's BaseController::display(), getModel() and getView() all default to the request's `view`. Checking
	 * only the controller let e.g. `controller=controlpanel&view=tempsuperusers` render a Security-tier view with the
	 * Control Panel's `core.manage`. Views do not check privileges themselves.
	 *
	 * A controller which needs its own onBeforeExecute() must call this method from it, because a class method replaces
	 * the trait method of the same name.
	 *
	 * @return  void
	 * @throws  RuntimeException  403 when the user lacks either privilege
	 * @since   7.10.0
	 */
	protected function akeebaRequestACLCheck(): void
	{
		$controller = Acl::normaliseName($this->getName());

		$this->akeebaBackupACLCheck($controller, $this->task);

		$view = Acl::normaliseName($this->input->getCmd('view', ''));

		if ($view !== '' && $view !== $controller)
		{
			$this->akeebaBackupACLCheck($view, $this->task);
		}
	}

	/**
	 * Checks if the currently logged in user has the required ACL privileges to access the current view. If not, a
	 * RuntimeException is thrown.
	 *
	 * @return  void
	 */
	protected function akeebaBackupACLCheck($view, $task)
	{
		$privilege = self::getRequiredPrivilege($view, $task);

		// If an empty privilege is defined do not perform any ACL checks
		if (empty($privilege))
		{
			return;
		}

		$user = Factory::getApplication()->getIdentity() ?? (new User());

		if (!Acl::isAuthorised($privilege, $user))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}

	/**
	 * Resolves the ACL privilege required to access a given view (optionally for a specific task).
	 *
	 * @param   string|null  $view  The view name (defaults to "controlpanel" when null).
	 * @param   string|null  $task  The task name (defaults to "main" when null).
	 *
	 * @return  string  The required ACL privilege.
	 * @see     Acl::getRequiredPrivilege()  The privilege map lives there.
	 */
	protected static function getRequiredPrivilege(?string $view, ?string $task = null): string
	{
		return Acl::getRequiredPrivilege($view, $task);
	}
}