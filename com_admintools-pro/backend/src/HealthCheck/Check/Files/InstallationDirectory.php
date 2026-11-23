<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Files;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Util\SiteFilesystem;
use Joomla\CMS\User\User;

/**
 * The installation folder must be deleted from the site's root.
 *
 * @since  7.10.0
 */
class InstallationDirectory extends BuiltinCheck
{
	protected $id = 'installation_directory';

	protected $section = 'files';

	protected $langKey = 'INSTALLATIONDIRECTORY';

	protected $severity = Severity::CRITICAL;

	public function run(): CheckResult
	{
		$path = JPATH_ROOT . '/installation';

		if (!is_dir($path) && !is_link($path))
		{
			return CheckResult::pass();
		}

		return CheckResult::fail($this->text('MSG_FAIL'), [], ['delete']);
	}

	public function getFixes(): array
	{
		return [
			new Fix('delete', $this->text('FIX'), $this->text('FIX_CONFIRM'), 'danger', 'fa fa-trash'),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		return $fixId === 'delete' && self::canDeleteFiles($user);
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		SiteFilesystem::delete('installation');

		return $this->text('FIX_DONE');
	}
}
