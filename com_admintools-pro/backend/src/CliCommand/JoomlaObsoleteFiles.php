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
use Akeeba\Component\AdminTools\Administrator\Model\ObsoletecoreModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * admintools:joomla:obsoletefiles
 *
 * Removes the Joomla! core files and directories a Joomla update should have deleted, but did not.
 *
 * @since 7.9.2
 */
class JoomlaObsoleteFiles extends AbstractCommand
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
	protected static $defaultName = 'admintools:joomla:obsoletefiles';

	/**
	 * Internal function to execute the command.
	 *
	 * @param   InputInterface   $input   The input to inject into the command.
	 * @param   OutputInterface  $output  The output to inject into the command.
	 *
	 * @return  integer  The command exit code
	 *
	 * @since   7.9.2
	 */
	protected function doExecute(InputInterface $input, OutputInterface $output): int
	{
		$this->configureEnv();
		$this->configureSymfonyIO($input, $output);

		$format = (string) ($this->cliInput->getOption('format') ?? 'table');
		$dryRun = (bool) $this->cliInput->getOption('dry-run');

		/** @var ObsoletecoreModel $model */
		$model = $this->getMVCFactory()->createModel('Obsoletecore', 'Administrator', ['ignore_request' => true]);

		/**
		 * The default one second batch exists so the browser pop-up stays responsive. There is no browser here, so we
		 * work in much larger slices and spend far less time going round the loop.
		 */
		$model->setTimeoutThreshold(10.0);

		try
		{
			$model->initialise();
		}
		catch (\Throwable $e)
		{
			$this->ioStyle->error($e->getMessage());

			return 2;
		}

		$items = $model->getQueue();

		if (empty($items))
		{
			if ($format === 'table')
			{
				$this->ioStyle->success(Text::_('COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_LBL_NOTHINGTODO'));
			}

			return $this->printFormattedAndReturn([], $format);
		}

		if ($dryRun)
		{
			return $this->printFormattedAndReturn($this->reportWithoutDeleting($model, $items), $format);
		}

		return $this->deleteAndReport($model, $items, $format);
	}

	/**
	 * Configure the command.
	 *
	 * @return  void
	 *
	 * @since   7.9.2
	 */
	protected function configure(): void
	{
		$this->addOption(
			'dry-run', null, InputOption::VALUE_NONE,
			Text::_('COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_OPT_DRYRUN')
		);
		$this->addOption(
			'format', null, InputOption::VALUE_OPTIONAL,
			Text::_('COM_ADMINTOOLS_CLI_COMMON_LIST_OPT_FORMAT'), 'table'
		);

		$this->setDescription(Text::_('COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_DESC'));
		$this->setHelp(Text::_('COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_HELP'));
	}

	/**
	 * Report on the obsolete items without touching them.
	 *
	 * We report whether PHP considers each path writeable. That is not the same question as "can this be deleted" —
	 * that depends on the permissions of the *containing* directory — but it is a close enough approximation to be
	 * useful, and it never risks changing anything.
	 *
	 * @param   ObsoletecoreModel  $model  The model, already initialised.
	 * @param   array              $items  The work queue.
	 *
	 * @return  array  Rows ready for printFormattedAndReturn().
	 * @since   7.9.2
	 */
	private function reportWithoutDeleting(ObsoletecoreModel $model, array $items): array
	{
		$rows = [];

		foreach ($items as $item)
		{
			$absolutePath = $model->getAbsolutePath($item['path']);
			$writeable    = $absolutePath !== null && @is_writable($absolutePath);

			$rows[] = [
				'type'   => $item['type'],
				'path'   => $item['path'],
				'status' => Text::_(
					$writeable
						? 'COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_LBL_WRITEABLE'
						: 'COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_LBL_NOTWRITEABLE'
				),
			];
		}

		return $rows;
	}

	/**
	 * Remove the obsolete items, then report on what happened to each of them.
	 *
	 * @param   ObsoletecoreModel  $model   The model, already initialised.
	 * @param   array              $items   The work queue, captured before processing.
	 * @param   string             $format  The output format.
	 *
	 * @return  int  The command exit code.
	 * @since   7.9.2
	 */
	private function deleteAndReport(ObsoletecoreModel $model, array $items, string $format): int
	{
		$done = $model->process();

		while (!$done)
		{
			$done = $model->process();
		}

		// Index the failures by path so we can report the outcome of every item we started with.
		$failed = [];

		foreach ($model->getFailures() as $failure)
		{
			$failed[$failure['path']] = true;
		}

		$rows = [];

		foreach ($items as $item)
		{
			$rows[] = [
				'type'   => $item['type'],
				'path'   => $item['path'],
				'status' => Text::_(
					isset($failed[$item['path']])
						? 'COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_LBL_FAILED'
						: 'COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_LBL_DELETED'
				),
			];
		}

		$result = $this->printFormattedAndReturn($rows, $format);

		if ($result !== 0)
		{
			return $result;
		}

		if (empty($failed))
		{
			return 0;
		}

		if ($format === 'table')
		{
			$this->ioStyle->warning(
				Text::sprintf('COM_ADMINTOOLS_CLI_JOOMLA_OBSOLETEFILES_ERR_SOMEFAILED', count($failed))
			);
		}

		return 1;
	}
}
