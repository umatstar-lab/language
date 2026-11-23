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
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Joomla\CMS\User\User;

/**
 * Super Users must not be able to log into the frontend.
 *
 * @since  7.10.0
 */
class SuperUserFrontendLogin extends BuiltinCheck
{
	protected $id = 'superuser_frontend_login';

	protected $section = 'joomla';

	protected $langKey = 'SUPERUSERFRONTENDLOGIN';

	protected $severity = Severity::MEDIUM;

	public function run(): CheckResult
	{
		if ((int) Storage::getInstance()->getValue('nofesalogin', 0) === 1)
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL'),
			[],
			['enable']
		)->withLink('index.php?option=com_admintools&view=Configurewaf', Text::_('COM_ADMINTOOLS_TITLE_CONFIGUREWAF'));
	}

	public function getFixes(): array
	{
		return [
			new Fix('enable', $this->text('FIX'), '', 'primary', 'fa fa-toggle-on'),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		// Same privilege as the Configure WAF page.
		return $fixId === 'enable' && $user->authorise('admintools.security', 'com_admintools');
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		$storage = Storage::getInstance();
		$storage->setValue('nofesalogin', 1);
		$storage->save();

		return $this->text('FIX_DONE');
	}
}
