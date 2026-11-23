<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\Layouts;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Runner;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerCustomACLTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerEventsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerRegisterTasksTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerReusableModelsTrait;
use Akeeba\Component\AdminTools\Administrator\Model\SitehealthcheckModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Site Health Check.
 *
 * Every task requires the admintools.security privilege (see ControllerCustomACLTrait). One-click fixes additionally
 * require whatever permission the check itself demands.
 *
 * @since  7.10.0
 */
class SitehealthcheckController extends BaseController
{
	use ControllerEventsTrait;
	use ControllerCustomACLTrait;
	use ControllerRegisterTasksTrait;
	use ControllerReusableModelsTrait;

	public function main()
	{
		// Always this controller's own view, whatever the request's view says: the ACL check is this controller's.
		$this->input->set('view', 'Sitehealthcheck');

		return $this->display(false);
	}

	/**
	 * Run the checks and return the results as JSON.
	 *
	 * Used by the Site Health Check page itself and by the Control Panel's badge (summary=1, without the HTML).
	 *
	 * The anti-CSRF token must be POSTed: in the query string it would end up in access and proxy logs.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function results(): void
	{
		$this->checkToken();

		$summaryOnly = $this->input->getBool('summary', false);

		try
		{
			$runner  = $this->getRunner();
			$entries = $runner->run();
			$json    = [
				'success' => true,
				'summary' => Runner::summarise($entries),
			];

			if (!$summaryOnly)
			{
				$json['html'] = Layouts::render(
					'akeeba.admintools.sitehealthcheck.results',
					[
						'sections' => $runner->getSections(),
						'entries'  => $entries,
						'summary'  => $json['summary'],
						'user'     => $this->app->getIdentity(),
					]
				);
			}
		}
		catch (\Throwable $e)
		{
			$json = [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}

		$this->sendJson($json);
	}

	/**
	 * Forget the cached results of the expensive checks, so they run again.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function refresh(): void
	{
		$this->checkToken();

		$this->getRunner()->clearCache();

		$this->redirectBack();
	}

	/**
	 * Ignore a check.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function ignore(): void
	{
		$this->checkToken();

		$checkId = $this->input->getString('check_id', '');

		try
		{
			$this->getRunner()->ignore($checkId);
		}
		catch (\Throwable $e)
		{
			$this->finish($checkId, $e->getMessage(), 'error');

			return;
		}

		$this->finish($checkId);
	}

	/**
	 * Stop ignoring a check.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function unignore(): void
	{
		$this->checkToken();

		$checkId = $this->input->getString('check_id', '');

		$this->getRunner()->unignore($checkId);

		$this->finish($checkId);
	}

	/**
	 * Stop ignoring all checks.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function resetIgnored(): void
	{
		$this->checkToken();

		$this->getRunner()->resetIgnored();

		$this->redirectBack(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_MSG_IGNORED_RESET'));
	}

	/**
	 * Apply a one-click fix.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function fix(): void
	{
		$this->checkToken();

		$checkId = $this->input->getString('check_id', '');
		/**
		 * The page posts the item's token (CheckResult::itemToken(), hexadecimal), never the item key itself: keys are
		 * names found on disk, which the STRING filter would change, e.g. ".<b>git" into ".git", another item.
		 */
		$itemToken = $this->input->getAlnum('item_key', '');

		try
		{
			$message = $this->getRunner()->applyFix(
				$checkId,
				$this->input->getCmd('fix_id', ''),
				$itemToken === '' ? null : $itemToken,
				$this->app->getIdentity()
			);
		}
		catch (\Throwable $e)
		{
			$this->finish($checkId, $e->getMessage(), 'error');

			return;
		}

		$this->finish($checkId, $message, 'success');
	}

	/**
	 * Finish an action on a check: redirect back to the page, or, for the page's asynchronous requests (format=raw),
	 * return the check re-rendered and the updated summary so the page can update itself in place.
	 *
	 * @param   string       $checkId  The check acted upon
	 * @param   string|null  $message  Plain text message
	 * @param   string       $type     Message type: message, success, error
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function finish(string $checkId, ?string $message = null, string $type = 'message'): void
	{
		if ($this->input->getCmd('format', 'html') !== 'raw')
		{
			$this->redirectBack($message, $type);

			return;
		}

		$json = [
			'success' => $type !== 'error',
			'message' => $message === null ? '' : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			'type'    => $type,
		];

		try
		{
			// Expensive checks come from the cache; the acted-on check's cache entry was cleared, so it runs afresh.
			$runner  = $this->getRunner();
			$entries = $runner->run();
			$summary = Runner::summarise($entries);
			$entry   = $entries[$checkId] ?? null;

			$json['summary']     = $summary;
			$json['summaryHtml'] = Layouts::render(
				'akeeba.admintools.sitehealthcheck.summary', ['summary' => $summary, 'entries' => $entries]
			);
			$json['checkHtml']   = $entry === null ? '' : Layouts::render(
				'akeeba.admintools.sitehealthcheck.check',
				[
					'entry'     => $entry,
					'user'      => $this->app->getIdentity(),
					'secondary' => Runner::isSecondary($entry),
				]
			);
		}
		catch (\Throwable $e)
		{
			// The action itself is done; the page can still reload to show the outcome.
			$json['reload'] = true;
		}

		$this->sendJson($json);
	}

	/**
	 * Output a JSON response.
	 *
	 * Asynchronous requests use format=raw, and the request then ends the normal way, so Joomla sends its usual headers
	 * for an uncached response (Expires, Cache-Control: no-store, Pragma: no-cache). The responses list usernames,
	 * file paths and the site's security posture, so they must not be kept by the browser's cache or a proxy.
	 *
	 * @param   array  $json  The data to output
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function sendJson(array $json): void
	{
		// Anything output so far (e.g. a PHP notice) would make the response invalid JSON.
		if (ob_get_level() > 0)
		{
			@ob_clean();
		}

		$this->app->getDocument()->setMimeEncoding('application/json');
		$this->app->setHeader('X-Content-Type-Options', 'nosniff', true);

		echo json_encode($json, JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/**
	 * Redirect to the Site Health Check page.
	 *
	 * Messages are plain text, and may contain file names, so they are escaped: Joomla outputs messages as HTML.
	 *
	 * @param   string|null  $message
	 * @param   string       $type
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function redirectBack(?string $message = null, string $type = 'message'): void
	{
		$this->setRedirect(
			Route::_('index.php?option=com_admintools&view=Sitehealthcheck', false),
			$message === null ? null : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			$type
		);
	}

	private function getRunner(): Runner
	{
		/**
		 * Always this controller's own model. Without a name the model would be the request's view, e.g.
		 * controller=sitehealthcheck&view=controlpanel would load the Control Panel model.
		 *
		 * @var SitehealthcheckModel $model
		 */
		$model = $this->getModel('Sitehealthcheck', 'Administrator', ['ignore_request' => true]);

		return $model->getRunner();
	}
}
