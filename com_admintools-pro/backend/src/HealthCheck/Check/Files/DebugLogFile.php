<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Files;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Model\ControlpanelModel;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Admin Tools' debug log file should be turned off, and its log files deleted.
 *
 * The fixes reuse the Control Panel's debug log file reminder logic (ControlpanelModel::deleteOldLogs()).
 *
 * @since  7.10.0
 */
class DebugLogFile extends BuiltinCheck
{
	/** The debug log files, relative to Joomla's log folder. */
	public const LOG_FILES = [
		'admintools_blocked.php',
		'admintools_blocked.1.php',
		'admintools_blocked.log',
		'admintools_blocked.log.1',
	];

	protected $id = 'debug_log_file';

	protected $section = 'files';

	protected $langKey = 'DEBUGLOGFILE';

	protected $severity = Severity::MEDIUM;

	public function run(): CheckResult
	{
		$enabled = (bool) Storage::getInstance()->getValue('logfile', 0);
		$files   = $this->getLogFiles();

		if ($enabled)
		{
			return CheckResult::fail(
				$this->text('MSG_ENABLED'),
				self::pathItems($files),
				['disable', 'disable_delete'],
				count($files)
			)->withLink('index.php?option=com_admintools&view=Configurewaf', Text::_('COM_ADMINTOOLS_TITLE_CONFIGUREWAF'));
		}

		if (!empty($files))
		{
			return CheckResult::fail($this->text('MSG_FILES'), self::pathItems($files), ['delete'], count($files));
		}

		return CheckResult::pass();
	}

	public function getFixes(): array
	{
		return [
			new Fix('disable', $this->text('FIX_DISABLE'), '', 'primary', 'fa fa-toggle-off'),
			new Fix('disable_delete', $this->text('FIX_DISABLE_DELETE'), '', 'danger', 'fa fa-trash'),
			new Fix('delete', $this->text('FIX_DELETE'), '', 'danger', 'fa fa-trash'),
		];
	}

	public function canFix(string $fixId, User $user): bool
	{
		// Same privilege as the Control Panel's debug log file reminder actions.
		return in_array($fixId, ['disable', 'disable_delete', 'delete'], true)
		       && $user->authorise('admintools.security', 'com_admintools');
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		if ($fixId !== 'delete')
		{
			$storage = Storage::getInstance();
			$storage->setValue('logfile', 0);
			$storage->save();
		}

		if ($fixId === 'disable')
		{
			return $this->text('FIX_DISABLE_DONE');
		}

		/** @var ControlpanelModel $model */
		$model = Factory::getApplication()->bootComponent('com_admintools')->getMVCFactory()
			->createModel('Controlpanel', 'Administrator', ['ignore_request' => true]);
		$model->deleteOldLogs();

		return $this->text('FIX_DELETE_DONE');
	}

	/**
	 * The non-empty debug log files which exist.
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	private function getLogFiles(): array
	{
		$logPath = rtrim((string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs'), '/\\');

		return array_values(
			array_filter(
				self::LOG_FILES,
				function (string $file) use ($logPath) {
					return @is_file($logPath . '/' . $file) && @filesize($logPath . '/' . $file) > 0;
				}
			)
		);
	}
}
