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
use Akeeba\Component\AdminTools\Administrator\Helper\UserGroups;

/**
 * Limit backend access to a small number of trusted operators.
 *
 * @since  7.10.0
 */
class BackendUserCount extends BuiltinCheck
{
	/** More enabled backend users than this is a problem. */
	public const THRESHOLD = 30;

	protected $id = 'backend_user_count';

	protected $section = 'joomla';

	protected $langKey = 'BACKENDUSERCOUNT';

	protected $severity = Severity::MEDIUM;

	public function isExpensive(): bool
	{
		return true;
	}

	public function run(): CheckResult
	{
		$count = count(UserGroups::getActiveUsers(UserGroups::backendGroups()));

		if ($count <= self::THRESHOLD)
		{
			return CheckResult::pass($this->text('MSG_PASS', $count));
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL', $count, self::THRESHOLD)
		)->withLink('index.php?option=com_users&view=users', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_USERS'));
	}
}
