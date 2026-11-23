<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\View\Checktempandlogdirectories;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Mixin\ViewTaskBasedEventsTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;

class HtmlView extends BaseHtmlView
{
	use ViewTaskBasedEventsTrait;

	protected function onBeforeMain()
	{
		$this->getDocument()->getWebAssetManager()
			->useScript('com_admintools.check_tmp_log');

		Text::script('COM_ADMINTOOLS_CHECKTEMPANDLOGDIRECTORIES_LBL_CHECKCOMPLETED', true);
		Text::script('COM_ADMINTOOLS_CHECKTEMPANDLOGDIRECTORIES_LBL_CHECKFAILED', true);

		// Anti-CSRF token appended to the check AJAX endpoint (its controller task calls checkToken()).
		$token    = Factory::getApplication()->getFormToken();
		$urlCheck = Route::_('index.php?option=com_admintools&view=Checktempandlogdirectories&task=check&format=json&' . $token . '=1', false);

		$this->getDocument()->addScriptOptions('admintools.CheckTempAndLogDirectories.urlCheck', $urlCheck);
	}
}