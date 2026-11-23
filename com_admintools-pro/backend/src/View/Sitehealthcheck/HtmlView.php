<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\View\Sitehealthcheck;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Mixin\ViewTaskBasedEventsTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Site Health Check page.
 *
 * The page itself is an empty shell; the results are fetched asynchronously, the same way the Control Panel's badge
 * fetches its summary.
 *
 * @since  7.10.0
 */
class HtmlView extends BaseHtmlView
{
	use ViewTaskBasedEventsTrait;

	public function onBeforeMain()
	{
		self::addResultsScript(false);

		// Joomla.renderMessages(), for the outcome of actions applied without reloading the page.
		$this->getDocument()->getWebAssetManager()->useScript('messages');

		Text::script('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_LOAD_FAILED', true);

		$this->setLayout('default');

		ToolbarHelper::title(Text::_('COM_ADMINTOOLS_TITLE_SITEHEALTHCHECK'), 'admintools');
		ToolbarHelper::custom('sitehealthcheck.refresh', 'refresh', '', 'COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_REFRESH', false);
		ToolbarHelper::custom('sitehealthcheck.resetIgnored', 'eye', '', 'COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_RESET_IGNORED', false);
		ToolbarHelper::inlinehelp();
		ToolbarHelper::back('COM_ADMINTOOLS_TITLE_CONTROLPANEL', 'index.php?option=com_admintools');
		ToolbarHelper::help(null, false, 'https://www.akeeba.com/documentation/admin-tools-joomla/site-health-check.html');
	}

	/**
	 * Load the Site Health Check script and tell it where to fetch the results from.
	 *
	 * Shared with the Control Panel, which only shows a summary badge.
	 *
	 * @param   bool  $summaryOnly  Only fetch the summary (Control Panel badge)?
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public static function addResultsScript(bool $summaryOnly): void
	{
		$app      = Factory::getApplication();
		$document = $app->getDocument();
		// The script POSTs the anti-CSRF token (Joomla's csrf.token script option); never put it in the URL.
		$url      = 'index.php?option=com_admintools&view=Sitehealthcheck&task=results&format=raw'
		            . ($summaryOnly ? '&summary=1' : '');

		$document->getWebAssetManager()->useScript('com_admintools.site_health_check');
		$document->addScriptOptions(
			'admintools.SiteHealthCheck',
			[
				'resultsUrl'  => Route::_($url, false),
				'summaryOnly' => $summaryOnly,
			]
		);
	}
}
