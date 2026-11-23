<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Scanner;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Scanner\Collector;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

/**
 * Non-core files must not exist in core folders.
 *
 * The files are those the last complete PHP File Change Scanner run scanned and classified as non-core, with the
 * classification rules of the version which ran it. A file which was part of an earlier scan but not of the last one
 * (deleted, or excluded from scanning since) is therefore not reported, while one which simply did not change since an
 * earlier scan still is.
 *
 * Files the user marked as safe in the scan report are not among them: the scanner knows which those are while it scans
 * (an unchanged file whose current version is marked safe), and the Collector does not store them. They are managed in
 * the scan report, so they are not listed here, nor counted as ignored, and they take up none of the stored findings.
 *
 * @since  7.10.0
 */
class NonCoreInCoreFolders extends AbstractFindingsCheck
{
	protected $id = 'noncore_in_core_folders';

	protected $langKey = 'NONCOREINCOREFOLDERS';

	protected $severity = Severity::MEDIUM;

	protected $category = Collector::NONCORE;

	/** Reviewing, not deleting: a file in a core folder may still be needed. */
	protected $deletable = false;

	public function run(): CheckResult
	{
		if (ComponentHelper::getParams('com_admintools')->get('nonCoreInCoreFolders', 'flag') === 'ignore')
		{
			return CheckResult::fail($this->text('MSG_IGNORED'))->withLink(
				'index.php?option=com_config&view=component&component=com_admintools',
				Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_ADMINTOOLS_OPTIONS')
			);
		}

		$result = parent::run();

		return $result->isProblem()
			? $result->withLink('index.php?option=com_admintools&view=Scans', Text::_('COM_ADMINTOOLS_TITLE_SCANS'))
			: $result;
	}
}
