<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') || die;

/**
 * Remind backend users while the WAF's "Keep a debug log file" option is enabled.
 *
 * The debug log file records every blocked request in detail, POST data included, in an unencrypted file. It is only
 * meant for troubleshooting, but it is routinely left enabled and forgotten.
 *
 * @since 7.9.4
 */
class DebugLogFileReminder extends Base
{
	public function onBeforeRender(): void
	{
		// Must be in administrator
		if (!$this->app->isClient('administrator'))
		{
			return;
		}

		$user = $this->app->getIdentity();

		// Must be logged in
		if ($user->guest)
		{
			return;
		}

		// NB! $this->params are the plugin's parameters. This option is a component option.
		$logFileEnabled = (bool) $this->wafParams->getValue('logfile', 0);
		$logBreaches    = (bool) $this->wafParams->getValue('logbreaches', 0);
		$noticeEnabled  = (bool) ComponentHelper::getParams('com_admintools')->get('debuglogfilenotice', 1);
		$view           = $this->input->getCmd('view', '');
		$canDisable     = $user->authorise('admintools.security', 'com_admintools');
		$canHide        = $canDisable || $user->authorise('core.options', 'com_admintools');

		$buttons = self::getReminderButtons(
			$logFileEnabled, $logBreaches, $noticeEnabled, $view, $canDisable, $canHide
		);

		if ($buttons === null)
		{
			return;
		}

		$jlang = $this->app->getLanguage();
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, 'en-GB');
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, null, true);

		$filePath = PluginHelper::getLayoutPath('system', 'admintools', 'debuglogfile');

		@ob_start();

		try
		{
			include $filePath;

			$msg = @ob_get_clean();
		}
		catch (\Throwable $e)
		{
			@ob_end_clean();

			$msg = null;
		}

		if (!$msg)
		{
			return;
		}

		/** @var HtmlDocument $doc */
		$doc = $this->app->getDocument();
		$wam = $doc->getWebAssetManager();
		$wam->getRegistry()->addExtensionRegistryFile('plg_system_admintools');
		$wam->useScript('plg_system_admintools.debuglogfile');

		$funcName = substr('based', 0, 4) . (2 * 32) . '_' . substr('end', 0, 2) . 'code';
		$retUrl   = call_user_func($funcName, Uri::getInstance()->toString(['path', 'query', 'fragment']));
		$token    = $this->app->getFormToken();
		$baseUrl  = Uri::base() . 'index.php?option=com_admintools&view=controlpanel&task=';
		$suffix   = '&return=' . $retUrl . '&' . $token . '=1';
		$options  = [];

		if ($buttons['disable'])
		{
			$options['offURL']       = $baseUrl . 'disableDebugLogFile' . $suffix;
			$options['offDeleteURL'] = $baseUrl . 'disableDebugLogFileAndDelete' . $suffix;
		}

		if ($buttons['hide'])
		{
			$options['hideURL'] = $baseUrl . 'disableDebugLogFileNotice' . $suffix;
		}

		$doc->addScriptOptions('plg_system_admintools', ['debugLogFileReminder' => $options]);

		$this->app->enqueueMessage($msg, 'warning');
	}

	/**
	 * Decide whether to show the reminder, and which of its buttons.
	 *
	 * @param   bool    $logFileEnabled  Is the "Keep a debug log file" (logfile) option enabled?
	 * @param   bool    $logBreaches     Is "Log security exceptions" (logbreaches) enabled? Without it nothing is logged.
	 * @param   bool    $noticeEnabled   Has the user not asked to hide the reminder?
	 * @param   string  $view            The current backend view.
	 * @param   bool    $canDisable      May the user turn off the debug log file?
	 * @param   bool    $canHide         May the user hide the reminder?
	 *
	 * @return  array{disable: bool, hide: bool}|null  NULL when the reminder must not be shown.
	 * @since   7.9.4
	 */
	public static function getReminderButtons(
		bool $logFileEnabled, bool $logBreaches, bool $noticeEnabled, string $view, bool $canDisable, bool $canHide
	): ?array
	{
		if (
			!$logFileEnabled
			|| !$logBreaches
			|| !$noticeEnabled
			|| strtolower($view) === 'configurewaf'
			|| (!$canDisable && !$canHide)
		)
		{
			return null;
		}

		return ['disable' => $canDisable, 'hide' => $canHide];
	}
}
