<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Scanner;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Use the PHP File Change Scanner: it must have completed a scan within the last two weeks.
 *
 * @since  7.10.0
 */
class ScannerRecency extends BuiltinCheck
{
	/** A scan older than this many days is too old. */
	public const MAX_AGE_DAYS = 14;

	private const SCANS_URL = 'index.php?option=com_admintools&view=Scans';

	protected $id = 'scanner_recency';

	protected $section = 'scanner';

	protected $langKey = 'SCANNERRECENCY';

	protected $severity = Severity::MEDIUM;

	public function run(): CheckResult
	{
		$lastScan = $this->getLastCompleteScanDate();

		if ($lastScan === null)
		{
			return CheckResult::fail($this->text('MSG_NEVER'))
				->withLink(self::SCANS_URL, Text::_('COM_ADMINTOOLS_TITLE_SCANS'));
		}

		$date = HTMLHelper::_('date', $lastScan, Text::_('DATE_FORMAT_LC2'));

		if (Factory::getDate($lastScan)->toUnix() < Factory::getDate('-' . self::MAX_AGE_DAYS . ' days')->toUnix())
		{
			return CheckResult::fail($this->text('MSG_OLD', self::e($date), self::MAX_AGE_DAYS))
				->withLink(self::SCANS_URL, Text::_('COM_ADMINTOOLS_TITLE_SCANS'));
		}

		return CheckResult::pass($this->text('MSG_PASS', self::e($date)));
	}

	/**
	 * Start date of the most recent complete scan, in SQL format.
	 *
	 * @return  string|null
	 * @since   7.10.0
	 */
	private function getLastCompleteScanDate(): ?string
	{
		$db     = $this->getDatabase();
		$status = 'complete';
		$query  = DbQuery::create($db)
			->select('MAX(' . $db->quoteName('scanstart') . ')')
			->from($db->quoteName('#__admintools_scans'))
			->where($db->quoteName('status') . ' = :status')
			->bind(':status', $status);

		$date = $db->setQuery($query)->loadResult();

		return empty($date) || $date === $db->getNullDate() ? null : (string) $date;
	}
}
