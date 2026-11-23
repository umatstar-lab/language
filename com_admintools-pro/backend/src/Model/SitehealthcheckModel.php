<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Runner;
use Joomla\CMS\MVC\Model\BaseModel;

/**
 * Site Health Check model. The work is done by the HealthCheck\Runner.
 *
 * @since  7.10.0
 */
class SitehealthcheckModel extends BaseModel
{
	/**
	 * @var   Runner|null
	 * @since 7.10.0
	 */
	private $runner;

	/**
	 * The Site Health Check runner.
	 *
	 * @return  Runner
	 * @since   7.10.0
	 */
	public function getRunner(): Runner
	{
		return $this->runner ?? ($this->runner = new Runner());
	}
}
