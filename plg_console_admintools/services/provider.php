<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Akeeba\Component\AdminTools\Administrator\Helper\VersionLimits;
use Akeeba\Plugin\Console\AdminTools\Command\CommandFactoryInterface;
use Akeeba\Plugin\Console\AdminTools\Command\CommandFactoryProvider;
use Akeeba\Plugin\Console\AdminTools\Extension\AdminTools;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   7.0.0
	 */
	public function register(Container $container)
	{
		// Only register the plugin in compatible environments
		if (!class_exists(VersionLimits::class) || !VersionLimits::isCompatible())
		{
			return;
		}

		$container->registerServiceProvider(new MVCFactory('Akeeba\\Component\\AdminTools'));
		$container->registerServiceProvider(new CommandFactoryProvider());

		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$config     = (array) PluginHelper::getPlugin('console', 'admintools');
				$subject    = $container->get(DispatcherInterface::class);

				$factory = $container->get(CommandFactoryInterface::class);

				$plugin = new AdminTools($subject, $factory, $config);

				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
