<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\CliCommand;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureEnvTrait;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureIO;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\PrintFormattedArray;
use Akeeba\Component\AdminTools\Administrator\Model\EmergencyofflineModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Utilities\IpHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * admintools:offline
 *
 */
class Offline extends AbstractCommand
{
	use ConfigureIO;
	use PrintFormattedArray;
	use MVCFactoryAwareTrait;
	use ConfigureEnvTrait;

	/**
	 * The default command name
	 *
	 * @var    string
	 */
	protected static $defaultName = 'admintools:offline';

	/**
	 * Internal function to execute the command.
	 *
	 * @param   InputInterface   $input   The input to inject into the command.
	 * @param   OutputInterface  $output  The output to inject into the command.
	 *
	 * @return  integer  The command exit code
	 *
	 * @since   7.5.0
	 */
	protected function doExecute(InputInterface $input, OutputInterface $output): int
	{
		$this->configureEnv();
		$this->configureSymfonyIO($input, $output);

		$enable = $this->cliInput->getOption('enable') ?? false;
		$disable = $this->cliInput->getOption('disable') ?? false;

		if (!$enable && !$disable)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_OFFLINE_MISSING_MODE'));

			return 1;
		}

		/**
		 * Which IP address, if any, is exempted from the block?
		 *
		 * Under CLI there is no REMOTE_ADDR, therefore no IP address the model could exempt on its own. Emergency
		 * Off-Line Mode will lock out every visitor, including whoever is running this command, unless they name an
		 * IP address to let through.
		 */
		$exemptIp = IpExpressionValidator::normalise($this->cliInput->getOption('exempt-ip') ?: '');

		if ($enable && $exemptIp !== '' && filter_var($exemptIp, FILTER_VALIDATE_IP) === false)
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_OFFLINE_ERR_INVALIDIP', $exemptIp));

			return 1;
		}

		$exemptIp = $exemptIp ?: (IpHelper::getIp() ?: '');

		/** @var EmergencyofflineModel $model */
		$model        = $this->getMVCFactory()->createModel('Emergencyoffline', 'Administrator');

		if ($enable)
		{
			$success = $model->putOffline($exemptIp ?: null);
		}
		else
		{
			$success = $model->putOnline();
		}

		if ($success)
		{
			$this->ioStyle->success(Text::_('COM_ADMINTOOLS_CLI_OFFLINE_RESULT_SUCCESS'));

			if ($enable && $exemptIp === '')
			{
				$this->ioStyle->warning(Text::_('COM_ADMINTOOLS_CLI_OFFLINE_WARN_NOEXEMPTION'));
			}

			return 0;
		}


		$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_OFFLINE_RESULT_FAILURE'));

		return 1;
	}

	/**
	 * Configure the command.
	 *
	 * @return  void
	 *
	 * @since   7.5.0
	 */
	protected function configure(): void
	{
		$this->addOption('enable' , null, InputOption::VALUE_NONE, Text::_('COM_ADMINTOOLS_CLI_OFFLINE_OPT_ENABLE'));
		$this->addOption('disable', null, InputOption::VALUE_NONE, Text::_('COM_ADMINTOOLS_CLI_OFFLINE_OPT_DISABLE'));
		$this->addOption('exempt-ip', null, InputOption::VALUE_REQUIRED, Text::_('COM_ADMINTOOLS_CLI_OFFLINE_OPT_EXEMPTIP'));

		$this->setDescription(Text::_('COM_ADMINTOOLS_CLI_OFFLINE_DESC'));
		$this->setHelp(Text::_('COM_ADMINTOOLS_CLI_OFFLINE_HELP'));
	}
}
