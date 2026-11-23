<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureEnvTrait;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureIO;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\PrintFormattedArray;
use Akeeba\Component\AdminTools\Administrator\Model\ServerconfigmakerModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ServerConfigurationMake extends AbstractCommand
{
	use ConfigureIO;
	use PrintFormattedArray;
	use MVCFactoryAwareTrait;
	use ConfigureEnvTrait;

	/** @var string Which engine are we working on? */
	protected $server_engine = '';

	/** @var string Server version that we should use by default */
	protected $default_server_version = '';

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

		$preview = $this->cliInput->getOption('preview') ?? false;
		$version = $this->cliInput->getOption('server_version') ?? $this->default_server_version;

		/** @var ServerconfigmakerModel $model */
		$model = $this->getMVCFactory()->createModel(ucfirst($this->server_engine), 'Administrator');
		$model->setServerVersion($version);

		$this->printConfigurationWarnings($model);

		if ($preview)
		{
			$output = $model->makeConfigFile();

			$this->ioStyle->text($output);

			return 0;
		}

		if (!$model->writeConfigFile())
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGMAKE_OPT_WRITEKO'));

			return 1;
		}

		$this->ioStyle->success(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGMAKE_OPT_WRITEOK'));

		return 0;
	}

	/**
	 * Print the misconfigurations of the saved settings, which the maker's page warns about (gh-470).
	 *
	 * See ServerconfigmakerModel::getConfigurationWarnings() and tmpl/htaccessmaker/default_warnings.php, whose language
	 * strings this uses. The warnings go to the standard error, so that the standard output of --preview is only the
	 * file. Console formatting tags in the language strings and the user agents are printed, not interpreted; control
	 * characters in the user agents, which are invisible, are printed as \xNN, like on the page.
	 *
	 * @param   ServerconfigmakerModel  $model  The maker's model
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function printConfigurationWarnings(ServerconfigmakerModel $model): void
	{
		$warnings = $model->getConfigurationWarnings();
		$io       = $this->ioStyle->getErrorStyle();

		if (!empty($warnings['restrictipEmpty']))
		{
			$io->warning([
				OutputFormatter::escape(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_ERR_RESTRICTIP_EMPTY_HEAD')),
				OutputFormatter::escape(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_ERR_RESTRICTIP_EMPTY')),
			]);
		}

		// NginX Conf Maker only (gh-483)
		if (!empty($warnings['restrictipRealIp']))
		{
			$io->warning([
				OutputFormatter::escape(Text::_('COM_ADMINTOOLS_NGINXCONFMAKER_WARN_RESTRICTIP_REALIP_HEAD')),
				OutputFormatter::escape(Text::_('COM_ADMINTOOLS_NGINXCONFMAKER_WARN_RESTRICTIP_REALIP')),
			]);
		}

		if (!empty($warnings['skippedUserAgents']))
		{
			$agents = array_map(function (string $agent): string {
				$agent = preg_replace_callback('/[\x00-\x1F\x7F]/', function (array $m): string {
					return sprintf('\\x%02X', ord($m[0]));
				}, $agent);

				return '- ' . OutputFormatter::escape($agent);
			}, $warnings['skippedUserAgents']);

			$io->warning([
				OutputFormatter::escape(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_WARN_SKIPPED_USERAGENTS_HEAD')),
				OutputFormatter::escape(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_WARN_SKIPPED_USERAGENTS')),
				implode(PHP_EOL, $agents),
			]);
		}
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
		$this->addOption('preview', null, InputOption::VALUE_NONE, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGMAKE_OPT_PREVIEW'));
		$this->addOption('server_version', null, InputOption::VALUE_OPTIONAL, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGMAKE_OPT_VERSION'));

		$this->setDescription(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGMAKE_DESC'));
		$this->setHelp(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGMAKE_HELP'));
	}
}