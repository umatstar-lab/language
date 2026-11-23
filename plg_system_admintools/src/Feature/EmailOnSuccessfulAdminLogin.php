<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Exception;
use Akeeba\Component\AdminTools\Administrator\Helper\EmailLanguage;
use Akeeba\Plugin\System\AdminTools\Utility\RescueUrl;

class EmailOnSuccessfulAdminLogin extends Base
{
	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		if (!$this->app->isClient('administrator'))
		{
			return false;
		}

		if ($this->isAdminAccessAttempt())
		{
			return false;
		}

		$user = $this->app->getIdentity();

		if ($user->guest)
		{
			return false;
		}

		$email = $this->wafParams->getValue('emailonadminlogin', '');

		return !empty($email);
	}

	/**
	 * Sends an email upon accessing an administrator page other than the login screen
	 */
	public function onAfterInitialise(): void
	{
		// Check if the session flag is set (avoid sending thousands of emails!)
		$flag = $this->app->getSession()->get('plg_admintools.waf.loggedin', 0);

		if ($flag == 1)
		{
			return;
		}

		// Set the flag to prevent sending more emails
		$this->app->getSession()->set('plg_admintools.waf.loggedin', 1);

		try
		{
			$recipients = $this->wafParams->getValue('emailonadminlogin', '');
			$recipients = is_array($recipients) ? $recipients : explode(',', $recipients);
			$recipients = array_map('trim', $recipients);

			foreach ($recipients as $recipient)
			{
				if (empty($recipient))
				{
					continue;
				}

				// Resolves to the real account when the configured address belongs to one, so that account's
				// language preference decides the language of the email.
				$recipientUser = EmailLanguage::userForEmail($recipient);

				$this->exceptionsHandler->sendEmail(
					'com_admintools.adminloginsuccess',
					$recipientUser,
					/**
					 * {REASON} and {RESCUEINFO} are translated strings. Building them here — inside the recipient's
					 * language scope — is what keeps them coherent with the subject and body they end up inside.
					 */
					fn($language) => array_merge(
						RescueUrl::getRescueInformation($recipient),
						[
							'REASON' => $language->_('COM_ADMINTOOLS_LOG_LBL_REASON_ADMINLOGINSUCCESS'),
						]
					)
				);
			}
		}
		catch (Exception $e)
		{
			// Joomla! 3.5 and later throw an exception when crap happens instead of suppressing it and returning false
		}
	}
}
