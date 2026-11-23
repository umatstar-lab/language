<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Service\JoomlaConfiguration;

/**
 * configuration.php must not set a session name while Shared Sessions are disabled.
 *
 * Joomla names the session cookie after session_name. In configuration.php it gives the frontend and the backend the
 * same session cookie, i.e. shared sessions, even though Shared Sessions read as disabled. Joomla only writes one while
 * Shared Sessions are enabled; earlier Admin Tools versions wrote `administrator` by mistake, together with other
 * runtime-only values (see JoomlaConfiguration::STRAY_RUNTIME_KEYS).
 *
 * This reads configuration.php itself, not the application's configuration: Joomla always sets session_name there for
 * the current request (CMSApplication's constructor uses the application's name when configuration.php has none).
 *
 * @since  7.10.0
 */
class StraySessionName extends AbstractConfigurationCheck
{
	protected $id = 'stray_session_name';

	protected $langKey = 'STRAYSESSIONNAME';

	protected $severity = Severity::HIGH;

	protected $configKey = 'session_name';

	/** Removing session_name changes the frontend's session cookie name, logging out everyone logged into it. */
	protected $confirmFix = true;

	public function run(): CheckResult
	{
		if (!$this->isBad($this->getConfigurationService()->getOnDisk()))
		{
			return CheckResult::pass();
		}

		return CheckResult::fail($this->text('MSG_FAIL'), [], ['setvalue']);
	}

	/**
	 * @param   array  $value  The configuration on disk
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected function isBad($value): bool
	{
		return is_array($value) && JoomlaConfiguration::hasStraySessionName($value);
	}

	/**
	 * Remove session_name, and the other runtime-only values written along with it.
	 */
	public function fix(string $fixId, ?string $itemKey = null): string
	{
		$this->getConfigurationService()->removeStrayRuntimeKeys();

		return $this->text('FIX_DONE');
	}
}
