<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Provider;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Service\JoomlaConfiguration as JoomlaConfigurationService;
use Joomla\CMS\Factory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Registers the service which writes Joomla's Global Configuration (configuration.php).
 *
 * @since  7.10.0
 */
class JoomlaConfiguration implements ServiceProviderInterface
{
	public function register(Container $container)
	{
		$container->set(
			JoomlaConfigurationService::class,
			function (Container $container) {
				return new JoomlaConfigurationService(Factory::getApplication());
			}
		);
	}
}
