<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\GlobalConfiguration;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;

/**
 * Shared Sessions must be disabled.
 *
 * @since  7.10.0
 */
class SharedSessions extends AbstractConfigurationCheck
{
	protected $id = 'shared_sessions';

	protected $langKey = 'SHAREDSESSIONS';

	protected $severity = Severity::HIGH;

	/** Joomla's key is singular, unlike this check's ID (kept as is: it is stored in the users' ignore lists). */
	protected $configKey = 'shared_session';

	protected $recommended = false;

	/** Changing Shared Sessions changes the session cookie name, logging the user out. */
	protected $confirmFix = true;

	protected function isBad($value): bool
	{
		return (bool) $value;
	}

	/**
	 * Turn Shared Sessions off, like Joomla's Global Configuration does: also remove the shared session name.
	 *
	 * Joomla names the session cookie after session_name. Left in configuration.php, it would still give the frontend
	 * and the backend the same session cookie, i.e. shared sessions.
	 */
	public function fix(string $fixId, ?string $itemKey = null): string
	{
		$this->getConfigurationService()->setMany([$this->configKey => $this->recommended], ['session_name']);

		return $this->text('FIX_DONE');
	}
}
