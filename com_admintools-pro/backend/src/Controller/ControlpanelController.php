<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\Helper\ServerTechnology;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerCustomACLTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerEventsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerReusableModelsTrait;
use Akeeba\Component\AdminTools\Administrator\Model\ControlpanelModel;
use Akeeba\Component\AdminTools\Administrator\Model\MainpasswordModel;
use Akeeba\Component\AdminTools\Administrator\Model\ServerconfigmakerModel;
use Akeeba\Component\AdminTools\Administrator\Model\UnblockipModel;
use Akeeba\Component\AdminTools\Administrator\Model\UpdatesModel;
use Akeeba\Component\AdminTools\Administrator\Model\UpgradeModel;
use Akeeba\Component\AdminTools\Administrator\Service\ComponentParameters;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use RuntimeException;

class ControlpanelController extends BaseController
{
	use ControllerEventsTrait;
	use ControllerCustomACLTrait;
	use ControllerReusableModelsTrait;

	/**
	 * Dummy method to test the custom error page.
	 *
	 * @throws RuntimeException Always thrown :)
	 */
	public function helloerror()
	{
		throw new RuntimeException('This is an error');
	}

	protected function onBeforeMain()
	{
		/** @var ControlpanelModel $model */
		$model = $this->getModel();

		// Update the magic parameters
		$model->updateMagicParameters();

		// Delete the old log files if logging is disabled
		$model->deleteOldLogs();

		// Update the frontend directories where file extensions are allowed (for Joomla! 5.3.0 and later).
		$model->serverConfigUpdateFrontendDirectories();

		// Refresh the update site definitions if required.
		/** @var UpdatesModel $updateModel */
		$updateModel = $this->getModel('Updates', 'Administrator');
		$updateModel->refreshUpdateSite();

		// Make sure all of my extensions are assigned to my package.
		/** @var UpgradeModel $upgradeModel */
		$upgradeModel = $this->getModel('Upgrade', 'Administrator');
		$upgradeModel->init();
		$upgradeModel->adoptMyExtensions();

		// Reorder the Admin Tools plugin if necessary
		if (ComponentHelper::getParams('com_admintools')->get('reorderplugin', 1))
		{
			$model->reorderPlugin();
		}

		// Pass models to the view
		$view = $this->getView();
		$view->setModel($this->getModel('Adminpassword', 'Administrator'));
		$view->setModel($this->getModel('Mainpassword', 'Administrator'));
		$view->setModel($this->getModel('UsageStatistics', 'Administrator'));

		if (defined('ADMINTOOLS_PRO') && ADMINTOOLS_PRO)
		{
			$view->setModel($this->getModel('Blockedrequestslogs', 'Administrator', ['ignore_request' => true]));
		}

		$view->setModel($this->getModel('Updates', 'Administrator'));

		// Special toggle to display all panels
		$view->debugAllPanels = $this->input->getBool('_tp', false);
	}

	public function login()
	{
		$this->checkToken();

		/** @var MainpasswordModel $model */
		$model    = $this->getModel('Mainpassword', 'Administrator');
		$password = $this->input->get('userpw', '', 'raw');
		$model->setUserPassword($password);

		$url = Route::_('index.php?option=com_admintools', false);
		$this->setRedirect($url);
	}

	public function selfblocked()
	{
		$externalIP = $this->input->getString('ip', '');

		/** @var ControlpanelModel $model */
		$model  = $this->getModel();
		$result = $model->isMyIPBlocked($externalIP);

		echo json_encode(
			[
				'blocked' => $result,
			]
		);

		$this->app->close();
	}

	public function unblockme()
	{
		/** @var ControlpanelModel $model */
		$model     = $this->getModel();
		$unblockIP = [
			$this->input->getString('ip', ''),
			$model->getVisitorIP(),
		];

		/** @var UnblockipModel $unblockModel */
		$unblockModel = $this->getModel('Unblockip', 'Administrator');
		$unblockModel->unblockIP($unblockIP);

		$redirectUrl = Route::_('index.php?option=com_admintools', false);
		$this->setRedirect($redirectUrl, Text::_('COM_ADMINTOOLS_CONTROLPANEL_LBL_IP_UNBLOCKED'), 'success');
	}

	public function endRescue()
	{
		$session = $this->app->getSession();

		$session->set('com_admintools.rescue_timestamp', null);
		$session->set('com_admintools.rescue_username', null);

		$returnUrl = Route::_('index.php?option=com_admintools', false);
		$this->setRedirect($returnUrl);
	}

	public function changelog()
	{
		$view = $this->getView();
		$view->setLayout('changelog');

		$this->display(true);
	}

	public function renameMainPhp()
	{
		$this->checkToken('get');

		/** @var ControlpanelModel $model */
		$model = $this->getModel();
		$model->reenableMainPhp();

		$default = Route::_('index.php?option=com_admintools&view=Controlpanel', false);

		$this->setRedirect($this->resolveInternalReturnUrl('returnurl', $default));
	}

	/**
	 * Decode a base64-encoded, request-supplied return URL and use it only if it points back into this site.
	 *
	 * The return URL travels in the querystring of links the operator clicks from the backend, so an attacker
	 * who can craft such a link could otherwise redirect the operator's browser to an external, attacker-chosen
	 * destination (open redirect / phishing).
	 *
	 * @param   string  $key      The request variable holding the base64-encoded URL.
	 * @param   string  $default  The URL to use when none was supplied, or it doesn't resolve to an internal one.
	 *
	 * @return  string
	 */
	private function resolveInternalReturnUrl(string $key, string $default): string
	{
		$encoded = $this->input->getBase64($key, '');

		if (empty($encoded))
		{
			return $default;
		}

		$decoded = base64_decode($encoded);

		return ($decoded !== '' && Uri::isInternal($decoded)) ? $decoded : $default;
	}

	/**
	 * Dismiss the CoreSums failure warning by resetting the coresums_failed flag on all scan rows.
	 *
	 * @return  void
	 * @since   7.8.0
	 */
	public function dismissCoreSumsWarning(): void
	{
		$this->checkToken('get');

		try
		{
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = DbQuery::create($db)
				->update($db->quoteName('#__admintools_scans'))
				->set($db->quoteName('coresums_failed') . ' = 0')
				->where($db->quoteName('coresums_failed') . ' = 1');
			$db->setQuery($query)->execute();
		}
		catch (\Throwable $e)
		{
			// Swallow — column may not exist on very old DBs mid-upgrade.
		}

		$returnUrl = Route::_('index.php?option=com_admintools&view=Controlpanel', false);
		$this->setRedirect($returnUrl);
	}

	/**
	 * Put a flag inside component configuration so user won't be warned again if he manually edits any server
	 * configuration file. He can enable it again by changing its value inside the component options
	 */
	public function ignoreServerConfigWarn()
	{
		$this->checkToken('get');

		$cParams = ComponentHelper::getParams('com_admintools');
		$cParams->set('serverconfigwarn', 0);

		$this->app->bootComponent('com_admintools')->getComponentParametersService()->save($cParams);

		$returnUrl = Route::_('index.php?option=com_admintools&view=Controlpanel', false);

		$this->setRedirect($returnUrl);
	}

	public function regenerateServerConfig()
	{
		$this->checkToken('get');

		$classModel = '';
		$returnUrl  = Route::_('index.php?option=com_admintools&view=Controlpanel', false);

		if (ServerTechnology::isHtaccessSupported())
		{
			$classModel = 'Htaccessmaker';
		}
		elseif (ServerTechnology::isNginxSupported())
		{
			$classModel = 'Nginxconfmaker';
		}
		elseif (ServerTechnology::isWebConfigSupported())
		{
			$classModel = 'Webconfigmaker';
		}

		if (!$classModel)
		{

			$this->setRedirect(
				$returnUrl, Text::_('COM_ADMINTOOLS_CONTROLPANEL_ERR_SERVERCONFIGWARN_REGENERATE'), 'error'
			);

			return;
		}

		/** @var ServerconfigmakerModel $model */
		$model = $this->getModel($classModel, 'Administrator');

		$model->writeConfigFile();

		$this->setRedirect(
			$returnUrl, Text::_('COM_ADMINTOOLS_CONTROLPANEL_LBL_SERVERCONFIGWARN_REGENERATED'), 'success'
		);
	}

	/**
	 * Reset the Secret Word for remote PHP file change scanning
	 *
	 * @return  void
	 * @throws  Exception
	 */
	public function resetSecretWord()
	{
		$this->checkToken('get');

		// The Secret Word is Super User-equivalent trust (see documentation), so require the same
		// component-level "Configure ACL & Options" permission Joomla itself uses to gate the Options page.
		if (!$this->app->getIdentity()->authorise('core.admin', 'com_admintools'))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$newSecret = $this->app->getSession()->get('admintools.cpanel.newSecretWord', null);

		if (empty($newSecret))
		{
			$newSecret = UserHelper::genRandomPassword(32);
			$newSecret = $this->app->getSession()->set('admintools.cpanel.newSecretWord', $newSecret);
		}

		$cParams = ComponentHelper::getParams('com_admintools');
		$cParams->set('frontend_secret_word', $newSecret);
		$this->app->bootComponent('com_admintools')->getComponentParametersService()->save($cParams);

		$msg     = Text::sprintf('COM_ADMINTOOLS_CONTROLPANEL_LBL_FESECRETWORD_RESET', $newSecret);
		$msgType = null;

		$returnUrl = Route::_('index.php?option=com_admintools&view=Controlpanel', false);
		$this->setRedirect($returnUrl, $msg, $msgType);
	}

	public function serverConfigMaker()
	{
		if (ServerTechnology::isWebConfigSupported())
		{
			$view = 'webconfigmaker';
		}
		elseif (ServerTechnology::isNginxSupported())
		{
			$view = 'nginxconfmaker';
		}
		else
		{
			$view = 'htaccessmaker';
		}

		$this->setRedirect(sprintf('index.php?option=com_admintools&view=%s', $view));
	}

	/**
	 * Disable the Email on Blocked Request feature by removing the email address.
	 *
	 * @return  void
	 * @since   7.7.0
	 */
	public function disableBlockedRequestEmail(): void
	{
		$this->checkToken('get');

		$params = Storage::getInstance();
		$params->setValue('emailbreaches', '');
		$params->save();

		$this->setRedirect($this->resolveInternalReturnUrl('return', 'index.php'));
	}

	/**
	 * Disable the notice about a non-empty Email on Blocked Request.
	 *
	 * @return  void
	 * @since   7.7.0
	 */
	public function disableBlockedRequestEmailNotifications(): void
	{
		$this->checkToken('get');

		try
		{
			/** @var ComponentParameters $paramsService */
			$paramsService = Factory::getApplication()
				->bootComponent('com_admintools')
				->getComponentParametersService();
			$plugin = PluginHelper::getPlugin('system', 'admintools');
			$params = new Registry($plugin->params);
			$params->set('blockedrequestemailnotice', 0);
			$paramsService->save($params, 'plg_system_admintools');
		}
		catch (\Throwable $e)
		{
			// No-op
		}

		$this->setRedirect($this->resolveInternalReturnUrl('return', 'index.php'));
	}

	/**
	 * Turn off the WAF's debug log file, keeping any existing debug log files.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function disableDebugLogFile(): void
	{
		$this->checkToken('get');

		$this->turnOffDebugLogFile(false);
	}

	/**
	 * Turn off the WAF's debug log file and delete any existing debug log files.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function disableDebugLogFileAndDelete(): void
	{
		$this->checkToken('get');

		$this->turnOffDebugLogFile(true);
	}

	/**
	 * Stop showing the reminder about the WAF's debug log file being enabled.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function disableDebugLogFileNotice(): void
	{
		$this->checkToken('get');

		$user = $this->app->getIdentity();

		if (
			!$user->authorise('admintools.security', 'com_admintools')
			&& !$user->authorise('core.options', 'com_admintools')
		)
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$cParams = ComponentHelper::getParams('com_admintools');
		$cParams->set('debuglogfilenotice', 0);
		$this->app->bootComponent('com_admintools')->getComponentParametersService()->save($cParams);

		$this->setRedirect($this->resolveInternalReturnUrl('return', 'index.php'));
	}

	/**
	 * Turn off the WAF's debug log file, optionally deleting the existing debug log files, and redirect back.
	 *
	 * @param   bool  $deleteLogs  Also delete the existing debug log files?
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	private function turnOffDebugLogFile(bool $deleteLogs): void
	{
		$params = Storage::getInstance();
		$params->setValue('logfile', 0);
		$params->save();

		if ($deleteLogs)
		{
			/** @var ControlpanelModel $model */
			$model = $this->getModel();
			$model->deleteOldLogs();
		}

		$this->setRedirect(
			$this->resolveInternalReturnUrl('return', 'index.php'),
			Text::_(
				$deleteLogs
					? 'COM_ADMINTOOLS_CONTROLPANEL_MSG_DEBUGLOGFILE_DISABLED_DELETED'
					: 'COM_ADMINTOOLS_CONTROLPANEL_MSG_DEBUGLOGFILE_DISABLED'
			)
		);
	}
}
