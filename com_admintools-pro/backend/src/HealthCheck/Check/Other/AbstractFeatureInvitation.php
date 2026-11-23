<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Other;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\Helper\Acl;
use Joomla\CMS\Language\Text;

/**
 * Base for the informational items inviting the user to look at an Admin Tools feature. Nothing is checked.
 *
 * The link is only shown to users who may use the feature.
 *
 * @since  7.10.0
 */
abstract class AbstractFeatureInvitation extends BuiltinCheck
{
	protected $section = 'other';

	protected $severity = Severity::INFO;

	/** @var string The view the feature lives in; its ACL privilege decides whether the link is shown */
	protected $view = '';

	/** @var string The link URL */
	protected $url = '';

	/** @var string Language key of the link text */
	protected $linkLabel = '';

	public function run(): CheckResult
	{
		$result = CheckResult::info();

		return ($this->url !== '' && Acl::canAccessView($this->view))
			? $result->withLink($this->url, Text::_($this->linkLabel))
			: $result;
	}
}
