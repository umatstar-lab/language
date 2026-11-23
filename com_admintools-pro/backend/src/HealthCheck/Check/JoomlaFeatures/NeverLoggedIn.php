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
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

/**
 * Remove "never logged in" accounts.
 *
 * Accounts registered in the last GRACE_DAYS days are not counted; they may simply not have logged in yet. The check
 * passes when an enabled "Admin Tools - Delete inactive users" scheduled task exists, since it takes care of them.
 *
 * @since  7.10.0
 */
class NeverLoggedIn extends BuiltinCheck
{
	/** Accounts younger than this many days are not reported. */
	public const GRACE_DAYS = 30;

	/** The Delete Inactive Users scheduled task routine ID. */
	public const TASK_ROUTINE = 'admintools.deleteInactiveUsers';

	protected $id = 'never_logged_in';

	protected $section = 'joomla';

	protected $langKey = 'NEVERLOGGEDIN';

	protected $severity = Severity::LOW;

	public function run(): CheckResult
	{
		if ($this->hasEnabledTask())
		{
			return CheckResult::pass($this->text('MSG_TASK'));
		}

		$count = $this->countNeverLoggedIn();

		if ($count === 0)
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL', $count, self::GRACE_DAYS)
		)->withLink('index.php?option=com_scheduler&view=select', Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NEW_TASK'));
	}

	private function hasEnabledTask(): bool
	{
		$db    = $this->getDatabase();
		$type  = self::TASK_ROUTINE;
		$state = 1;
		$query = DbQuery::create($db)
			->select('COUNT(*)')
			->from($db->quoteName('#__scheduler_tasks'))
			->where($db->quoteName('type') . ' = :type')
			->where($db->quoteName('state') . ' = :state')
			->bind(':type', $type)
			->bind(':state', $state, ParameterType::INTEGER);

		try
		{
			return (int) $db->setQuery($query)->loadResult() > 0;
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	private function countNeverLoggedIn(): int
	{
		$db       = $this->getDatabase();
		$nullDate = $db->getNullDate();
		$cutoff   = (clone Factory::getDate('-' . self::GRACE_DAYS . ' days'))->toSql();
		$query    = DbQuery::create($db)
			->select('COUNT(*)')
			->from($db->quoteName('#__users'))
			->where(
				'(' . $db->quoteName('lastvisitDate') . ' IS NULL OR '
				. $db->quoteName('lastvisitDate') . ' = :nullDate)'
			)
			->where($db->quoteName('registerDate') . ' <= :cutoff')
			->bind(':nullDate', $nullDate)
			->bind(':cutoff', $cutoff);

		return (int) $db->setQuery($query)->loadResult();
	}
}
