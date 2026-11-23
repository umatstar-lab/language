<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\Acl;
use Akeeba\Component\AdminTools\Administrator\Model\ControlpanelModel;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Throwable;

/**
 * Remind back-end users who can generate the server configuration file (.htaccess, nginx.conf, web.config) that the
 * existing file, which Admin Tools generated, no longer matches what this version of Admin Tools would generate.
 *
 * This is the same check as the warning in the Control Panel page, which this feature extends to every back-end page.
 * Updating Admin Tools can add protections to the generated file, and they only take effect once it is regenerated, so
 * waiting until the user next visits Admin Tools' Control Panel is not good enough.
 *
 * It honours the Control Panel's "Ignore" button (the "Warn about manual edits on server configuration files" option),
 * does nothing for files Admin Tools did not generate, and is Pro only, like the Control Panel check.
 */
class ServerConfigReminder extends Base
{
	/**
	 * How long, in seconds, to trust a previous result for the same server configuration file. Working out the answer
	 * means generating a whole new server configuration file, which we must not do on every page load.
	 */
	private const CACHE_TTL = 600;

	private const SESSION_KEY = 'admintools.serverconfigreminder';

	public function onBeforeRender(): void
	{
		if (!$this->app->isClient('administrator'))
		{
			return;
		}

		$user = $this->app->getIdentity();

		if (!$user || $user->guest)
		{
			return;
		}

		// Only full HTML pages, not AJAX or modal ("tmpl=component") responses.
		if ($this->app->getDocument()->getType() !== 'html' || $this->app->getInput()->getCmd('tmpl', '') === 'component')
		{
			return;
		}

		if (strtoupper($this->input->getMethod()) !== 'GET')
		{
			return;
		}

		// Admin Tools' own pages: the Control Panel shows its own warning, and the Makers are where the fix is made.
		if ($this->app->getInput()->getCmd('option', '') === 'com_admintools')
		{
			return;
		}

		// The same privilege the three server configuration makers require.
		if (!Acl::isAuthorised(Acl::getRequiredPrivilege('Htaccessmaker'), $user))
		{
			return;
		}

		// The Control Panel's "Ignore" button turns the warning off. Check it here, outside the cache, to honour it at once.
		if (!ComponentHelper::getParams('com_admintools')->get('serverconfigwarn', 1))
		{
			return;
		}

		if (!$this->isServerConfigOutdated())
		{
			return;
		}

		$jlang = $this->app->getLanguage();
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, 'en-GB');
		$jlang->load('com_admintools', JPATH_ADMINISTRATOR, null, true);

		$this->app->enqueueMessage(
			Text::sprintf('COM_ADMINTOOLS_SERVERCONFIGREMINDER_MSG', 'index.php?option=com_admintools&view=Controlpanel'),
			'warning'
		);
	}

	/**
	 * Is the server configuration file outdated? The answer is cached in the user's session for as long as the file is
	 * unchanged, so regenerating it stops the reminder at once.
	 */
	private function isServerConfigOutdated(): bool
	{
		try
		{
			// The server configuration makers load their form relative to the component being dispatched, which is not ours.
			Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_admintools/forms');

			/** @var ControlpanelModel $model */
			$model = $this->app->bootComponent('com_admintools')->getMVCFactory()->createModel('Controlpanel', 'Administrator');
			$time  = $model->getServerConfigFileTime();

			if ($time === null)
			{
				return false;
			}

			$session = $this->app->getSession();
			$cached  = $session->get(self::SESSION_KEY);

			if (is_array($cached) && ($cached['time'] ?? null) === $time && ($cached['until'] ?? 0) > time())
			{
				return (bool) ($cached['outdated'] ?? false);
			}

			$outdated = $model->serverConfigEdited();

			$session->set(self::SESSION_KEY, [
				'time'     => $time,
				'until'    => time() + self::CACHE_TTL,
				'outdated' => $outdated,
			]);

			return $outdated;
		}
		catch (Throwable $e)
		{
			// A reminder must never break the back-end.
			return false;
		}
	}
}
