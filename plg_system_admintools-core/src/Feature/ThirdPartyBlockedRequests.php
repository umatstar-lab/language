<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

class ThirdPartyBlockedRequests extends Base
{
	/**
	 * Log a security exception coming from a third party application. It's
	 * supposed to be used by 3PD to log security exceptions in Admin Tools'
	 * log.
	 *
	 * @param   string   $reason     The blocking reason to show to the administrator. MANDATORY.
	 * @param   string   $message    The message to show to the user being blocked. MANDATORY.
	 * @param   array    $extraInfo  Any extra information to record to the log file (hash array).
	 * @param   boolean  $autoban    OBSOLETE. No longer used.
	 *
	 * @return  void
	 */
	public function onAdminToolsThirdpartyException($reason, $message, $extraInfo = [], $autoban = false)
	{
		if (empty($message))
		{
			return;
		}

		// $extraInfo is documented as a hash array but BlockedRequestHandler::blockRequest() declares its
		// $extraLogInformation parameter as ?string. Forwarding an array verbatim raises an uncaught
		// TypeError, so we serialise it for the text log file. A plain string is passed through unchanged
		// for callers that already do their own encoding.
		$extraLogInformation = is_array($extraInfo)
			? json_encode($extraInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			: (string) $extraInfo;

		// Block the request
		$this->exceptionsHandler->blockRequest('external', $message, $extraLogInformation, $reason);
	}
}
