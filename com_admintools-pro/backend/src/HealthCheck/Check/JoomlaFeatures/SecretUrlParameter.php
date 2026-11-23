<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\JoomlaFeatures;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;

/**
 * Apply a secret URL parameter to the administrator.
 *
 * There is no one-click fix: the secret word is something only the user can choose.
 *
 * @since  7.10.0
 */
class SecretUrlParameter extends BuiltinCheck
{
	protected $id = 'secret_url_parameter';

	protected $section = 'joomla';

	protected $langKey = 'SECRETURLPARAMETER';

	protected $severity = Severity::MEDIUM;

	public function run(): CheckResult
	{
		if (trim((string) Storage::getInstance()->getValue('adminpw', '')) !== '')
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL')
		)->withLink('index.php?option=com_admintools&view=Configurewaf', Text::_('COM_ADMINTOOLS_TITLE_CONFIGUREWAF'));
	}
}
