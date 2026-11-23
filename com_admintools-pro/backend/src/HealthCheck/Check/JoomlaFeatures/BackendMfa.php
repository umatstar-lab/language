<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\JoomlaFeatures;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\UserGroups;

/**
 * Use Multi-factor Authentication on all accounts with backend access.
 *
 * Backup codes are created alongside the first real MFA method; on their own they do not count as MFA.
 *
 * @since  7.10.0
 */
class BackendMfa extends BuiltinCheck
{
	protected $id = 'backend_mfa';

	protected $section = 'joomla';

	protected $langKey = 'BACKENDMFA';

	protected $severity = Severity::HIGH;

	public function isExpensive(): bool
	{
		return true;
	}

	public function run(): CheckResult
	{
		$users = UserGroups::getActiveUsers(UserGroups::backendGroups());

		if (empty($users))
		{
			return CheckResult::pass();
		}

		$withMfa    = $this->getUsersWithMfa();
		$withoutMfa = array_diff_key($users, array_flip($withMfa));

		if (empty($withoutMfa))
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL', count($withoutMfa)),
			self::pathItems(array_values($withoutMfa)),
			[],
			count($withoutMfa)
		)->withLink('index.php?option=com_config&view=component&component=com_users', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_USERS_OPTIONS'));
	}

	/**
	 * IDs of the users with at least one MFA method other than backup codes.
	 *
	 * @return  int[]
	 * @since   7.10.0
	 */
	private function getUsersWithMfa(): array
	{
		$db     = $this->getDatabase();
		$method = 'backupcodes';
		$query  = DbQuery::create($db)
			->select('DISTINCT ' . $db->quoteName('user_id'))
			->from($db->quoteName('#__user_mfa'))
			->where($db->quoteName('method') . ' != :method')
			->bind(':method', $method);

		try
		{
			return array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
		}
		catch (\Exception $e)
		{
			return [];
		}
	}
}
