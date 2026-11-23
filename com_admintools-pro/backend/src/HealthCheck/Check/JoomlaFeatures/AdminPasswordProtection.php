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
use Akeeba\Component\AdminTools\Administrator\Helper\ServerTechnology;
use Akeeba\Component\AdminTools\Administrator\Model\AdminpasswordModel;
use Joomla\CMS\Factory;

/**
 * Password-protect the administrator directory.
 *
 * Only applies to servers which definitely support .htaccess files (Apache, LiteSpeed).
 *
 * @since  7.10.0
 */
class AdminPasswordProtection extends BuiltinCheck
{
	protected $id = 'admin_password_protection';

	protected $section = 'joomla';

	protected $langKey = 'ADMINPASSWORDPROTECTION';

	protected $severity = Severity::MEDIUM;

	public function isApplicable(): bool
	{
		return ServerTechnology::isHtaccessSupported() === 1;
	}

	public function run(): CheckResult
	{
		/** @var AdminpasswordModel $model */
		$model = Factory::getApplication()->bootComponent('com_admintools')->getMVCFactory()
			->createModel('Adminpassword', 'Administrator', ['ignore_request' => true]);

		if ($model->isLocked())
		{
			return CheckResult::pass();
		}

		return CheckResult::fail(
			$this->text('MSG_FAIL')
		)->withLink('index.php?option=com_admintools&view=Adminpassword', Text::_('COM_ADMINTOOLS_TITLE_ADMINPASSWORD'));
	}
}
