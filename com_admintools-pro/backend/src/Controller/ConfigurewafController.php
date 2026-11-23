<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Field\CrawlerIPStatusField;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\BotRegistry;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\CrawlerIPLists;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerCustomACLTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerEventsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerRegisterTasksTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerReusableModelsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\SendTroubleshootingEmailTrait;
use Akeeba\Component\AdminTools\Administrator\Model\ConfigurewafModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

class ConfigurewafController extends BaseController
{
	use ControllerEventsTrait;
	use ControllerCustomACLTrait;
	use SendTroubleshootingEmailTrait;
	use ControllerReusableModelsTrait;
	use ControllerRegisterTasksTrait;

	public function main()
	{
		$view = $this->getView();
		$cPanelModel = $this->getModel('Controlpanel', 'Administrator', ['ignore_request' => true]);
		$view->setModel($cPanelModel, false);

		$this->display(false);
	}

	public function apply()
	{
		$redirectURL = Route::_('index.php?option=com_admintools&view=Configurewaf', false);
		$msg         = Text::_('COM_ADMINTOOLS_CONFIGUREWAF_LBL_CONFIGSAVED');
		$msgType     = 'success';

		if (!$this->saveOrApply())
		{
			$redirectURL = Route::_('index.php?option=com_admintools&view=Configurewaf', false);
			$msg         = Text::_('COM_ADMINTOOLS_CONFIGUREWAF_LBL_CONFIGNOTSAVED');
			$msgType     = 'error';
		}

		$this->setRedirect($redirectURL, $msg, $msgType);
	}

	public function save()
	{
		$redirectURL = Route::_('index.php?option=com_admintools&view=Webapplicationfirewall', false);
		$msg         = Text::_('COM_ADMINTOOLS_CONFIGUREWAF_LBL_CONFIGSAVED');
		$msgType     = 'success';

		if (!$this->saveOrApply())
		{
			$redirectURL = Route::_('index.php?option=com_admintools&view=Configurewaf', false);
			$msg         = Text::_('COM_ADMINTOOLS_CONFIGUREWAF_LBL_CONFIGNOTSAVED');
			$msgType     = 'error';
		}

		$this->setRedirect($redirectURL, $msg, $msgType);
	}

	/**
	 * AJAX: refresh the crawler IP lists.
	 *
	 * Without a `bot` parameter it answers with the keys of the bots which need refreshing (every bot which is not
	 * set to Ignore in the saved configuration). With a `bot` parameter it refreshes that one bot. The browser drives
	 * the loop, one bot per request, so no single request can run into a server time limit.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function crawlerRefresh()
	{
		if (!$this->checkAjaxToken())
		{
			return;
		}

		try
		{
			$lists = CrawlerIPLists::getInstance();

			if (!$lists->isEnabled())
			{
				$this->sendJson([
					'result' => false,
					'msg'    => Text::_('COM_ADMINTOOLS_CRAWLERIPS_ERR_SWITCHEDOFF'),
					'html'   => CrawlerIPStatusField::renderStatus(),
				]);

				return;
			}

			$bot = $this->input->getCmd('bot', '');

			if ($bot === '')
			{
				$this->sendJson([
					'result' => true,
					'bots'   => array_values(array_filter(
						BotRegistry::keys(),
						function (string $key) use ($lists): bool {
							return $lists->getBotState($key) !== 'ignore';
						}
					)),
					'html'   => CrawlerIPStatusField::renderStatus(),
				]);

				return;
			}

			if (!BotRegistry::has($bot) || $lists->getBotState($bot) === 'ignore')
			{
				$this->sendJson([
					'result' => false,
					'msg'    => Text::_('COM_ADMINTOOLS_CRAWLERIPS_ERR_UNKNOWNBOT'),
					'html'   => CrawlerIPStatusField::renderStatus(),
				]);

				return;
			}

			$result = $lists->refresh($bot);
			$ok     = (bool) ($result['ok'] ?? false);
			$error  = (string) ($result['error'] ?? '');

			if ($error === 'busy')
			{
				$error = Text::_('COM_ADMINTOOLS_CRAWLERIPS_ERR_BUSY');
			}

			$this->sendJson([
				'result' => $ok,
				'msg'    => $ok ? '' : $error,
				'html'   => CrawlerIPStatusField::renderStatus(),
			]);
		}
		catch (\Throwable $e)
		{
			$this->sendJson(['result' => false, 'msg' => $e->getMessage()]);
		}
	}

	/**
	 * AJAX: the "Clear cache lock information" button. Performs the reset, exactly like saving Configure WAF with the
	 * crawler IP lists switched on.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	public function crawlerReset()
	{
		if (!$this->checkAjaxToken())
		{
			return;
		}

		try
		{
			CrawlerIPLists::getInstance()->reset();

			$this->sendJson([
				'result' => true,
				'msg'    => Text::_('COM_ADMINTOOLS_CRAWLERIPS_MSG_RESET_DONE'),
				'html'   => CrawlerIPStatusField::renderStatus(),
			]);
		}
		catch (\Throwable $e)
		{
			$this->sendJson(['result' => false, 'msg' => $e->getMessage()]);
		}
	}

	/**
	 * Checks the anti-CSRF token of an AJAX request. A bad token gets a JSON answer with HTTP 403, not a redirect.
	 *
	 * @return  bool  True if the token is valid. Otherwise the response was already sent.
	 * @since   7.9.4
	 */
	private function checkAjaxToken(): bool
	{
		if ($this->checkToken('request', false))
		{
			return true;
		}

		$this->sendJson(['result' => false, 'msg' => Text::_('JINVALID_TOKEN_NOTICE')], 403);

		return false;
	}

	/**
	 * Sends a JSON response and closes the application.
	 *
	 * @param   array  $data    The data to send.
	 * @param   int    $status  The HTTP status code.
	 *
	 * @return  void
	 * @since   7.9.4
	 */
	private function sendJson(array $data, int $status = 200): void
	{
		$this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$this->app->setHeader('status', (string) $status, true);
		$this->app->sendHeaders();

		echo json_encode($data);

		$this->app->close();
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	private function saveOrApply(): bool
	{
		$this->checkToken();

		/** @var ConfigurewafModel $model */
		$model = $this->getModel();

		$data = $this->input->getArray();

		// Save data in the session
		$this->app->setUserState('com_admintools.' . $this->getName() . '.data', $model->convertFormDataToDatabaseData($data));

		$form = $model->getForm($data, true);
		$data = $model->validate($form, $data);

		if (is_null($data))
		{
			foreach ($form->getErrors() as $error)
			{
				$this->app->enqueueMessage($error->getMessage(), 'warning');
			}

			return false;
		}

		$data = $model->convertFormDataToDatabaseData($data);

		/**
		 * Joomla's time rule accepts some values which are not a time, e.g. 12:5: or 1::00. saveConfig() used to save
		 * them as an empty string, which silently turned the Away Schedule off (gh-474). Refuse them like any other
		 * invalid value: nothing is saved, and the form shows what was posted.
		 */
		$invalidTime = false;

		foreach (ConfigurewafModel::AWAYSCHEDULE_KEYS as $key)
		{
			$value = $data[$key] ?? '';

			if (ConfigurewafModel::normaliseAwayScheduleTime($value) !== null)
			{
				continue;
			}

			$invalidTime = true;
			$field       = $form->getField($key);
			$label       = $field ? Text::_((string) $field->getAttribute('label')) : $key;
			$this->app->enqueueMessage(
				Text::sprintf(
					'COM_ADMINTOOLS_CONFIGUREWAF_ERR_AWAYSCHEDULE_TIME',
					htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
					htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8')
				),
				'warning'
			);
		}

		if ($invalidTime)
		{
			return false;
		}

		$this->sendTroubelshootingEmail($this->getName());

		$model->saveConfig($data);

		return true;
	}
}