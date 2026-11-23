<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Scanner;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Scanner\Collector;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;

/**
 * .htaccess and .user.ini files should not let files run as PHP or CGI scripts, or change PHP's settings, except in the
 * site's root and administrator folders. See Collector::isUnsafeServerConfig().
 *
 * @since  7.10.0
 */
class ServerConfigFiles extends AbstractFindingsCheck
{
	protected $id = 'server_config_files';

	protected $langKey = 'SERVERCONFIGFILES';

	protected $severity = Severity::CRITICAL;

	protected $category = Collector::SERVER_CONFIG;

	protected $deletable = true;
}
