<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Exception;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Akeeba\Component\AdminTools\Administrator\Helper\EmailLanguage;
use Akeeba\Plugin\System\AdminTools\Utility\RescueUrl;

class EmailOnFailedAdminLogin extends Base
{
	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		if ($this->wafParams->getValue('trackfailedlogins', 0) == 1)
		{
			// When track failed logins is enabled we don't send emails through this feature
			return false;
		}

		if (!$this->app->isClient('administrator'))
		{
			return false;
		}

		$emailonfailedadmin = $this->wafParams->getValue('emailonfailedadminlogin', '');

		if (empty($emailonfailedadmin))
		{
			return false;
		}

		return true;
	}

	/**
	 * Sends an email upon a failed administrator login
	 *
	 * @param   AuthenticationResponse  $response
	 *
	 * @return  bool
	 */
	public function onUserLoginFailure($response): void
	{
		// Do not email about failed logins as a result of an empty username
		if (!isset($response['username']) || empty($response['username']))
		{
			return;
		}

		// Make sure we don't fire unless someone is still in the login page
		$user = $this->app->getIdentity();

		if (!$user->guest)
		{
			return;
		}

		$option = $this->input->getCmd('option');
		$task   = $this->input->getCmd('task');

		if (($option != 'com_login') && ($task != 'login'))
		{
			return;
		}

		// Exit if the IP is blacklisted; logins originating from blacklisted IPs will be blocked anyway
		if ($this->parentPlugin->runShortCircuitFeature('isIPBlocked', false, []))
		{
			return;
		}

		// If we are STILL in the login task WITHOUT a valid user, we had a login failure.

		// Let's get the most suitable email template
		try
		{
			$recipients = $this->wafParams->getValue('emailonfailedadminlogin', '');
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
					'com_admintools.adminloginfail',
					$recipientUser,
					/**
					 * {REASON} and {RESCUEINFO} are translated strings. Building them here — inside the recipient's
					 * language scope — is what keeps them coherent with the subject and body they end up inside.
					 */
					fn($language) => array_merge(
						RescueUrl::getRescueInformation($recipient),
						[
							'REASON' => $language->_('COM_ADMINTOOLS_WAFEMAILTEMPLATE_REASON_ADMINLOGINFAIL'),
							'USER'   => $response['username'],
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
