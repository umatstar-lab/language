<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\CliCommand;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureEnvTrait;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureIO;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\PrintFormattedArray;
use Akeeba\Component\AdminTools\Administrator\Model\ExportimportModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * admintools:import
 *
 */
class SettingsImport extends AbstractCommand
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
	protected static $defaultName = 'admintools:import';

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

		$file = (string) ($this->cliInput->getOption('file') ?? '');

		if (!$file || !file_exists($file))
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_IMPORT_FILE_MISSING',$file));

			return 1;
		}

		$data = file_get_contents($file);

		/** @var ExportimportModel $model */
		$model = $this->getMVCFactory()->createModel('Exportimport', 'Administrator');

		try
		{
			/**
			 * Note: we DO load the default configuration, exactly like the GUI does.
			 *
			 * This used to pass $withDefaults = false, on the grounds that JForm cannot operate in CLI. That was a
			 * Joomla 3-era limitation which no longer applies; the other CLI commands, e.g. admintools:htmaker:set,
			 * read the forms from this very same context without a problem. Passing false also meant that the CLI
			 * import behaved differently to the GUI import for no good reason.
			 */
			$model->importData($data);
		}
		catch (\Throwable $e)
		{
			// Note: this must catch Throwable, not Exception. A broken import file can trip a TypeError.
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_IMPORT_ERRORS', $e->getMessage()));

			// Importing is not atomic. Tell the operator when their settings may have been left half-applied.
			if ($model->isPartialImport())
			{
				$this->ioStyle->warning(Text::_('COM_ADMINTOOLS_CLI_IMPORT_PARTIAL'));
			}

			return 1;
		}

		$this->ioStyle->success(Text::sprintf('COM_ADMINTOOLS_CLI_IMPORT_SUCCESS', $file));

		return 0;
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
		$this->addOption('file' , null, InputOption::VALUE_REQUIRED, Text::_('COM_ADMINTOOLS_CLI_IMPORT_OPT_FILE'));

		$this->setDescription(Text::_('COM_ADMINTOOLS_CLI_IMPORT_DESC'));
		$this->setHelp(Text::_('COM_ADMINTOOLS_CLI_IMPORT_HELP'));
	}
}
