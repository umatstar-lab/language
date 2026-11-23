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
use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\Model\ServerconfigmakerModel;
use Joomla\CMS\Form\Field\SubformField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ServerConfigurationSet extends AbstractCommand
{
	use ConfigureIO;
	use PrintFormattedArray;
	use MVCFactoryAwareTrait;
	use ConfigureEnvTrait;

	/** @var string Which engine are we working on? */
	protected $server_engine = '';

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

		$key      = (string) $this->cliInput->getOption('key');
		$setValue = $this->cliInput->getOption('value', null);

		// Special handling for array-ish values
		$addValue    = $this->cliInput->getOption('add') ?? null;
		$removeValue = $this->cliInput->getOption('remove') ?? null;
		$doEmpty     = $this->cliInput->getOption('empty') ?? false;

		/**
		 * An empty --add or --remove counts as not given; 0 is a value. They were checked with empty(), so --add=0 and
		 * --remove=0 were refused as no action (gh-475).
		 *
		 * An empty --value counts as not given too, as for admintools:waf:set (gh-467). Any --value which was not
		 * missing counted, so --value= saved an empty string (gh-477).
		 */
		$doValue  = $this->isGiven($setValue);
		$doAdd    = $this->isGiven($addValue);
		$doRemove = $this->isGiven($removeValue);

		/**
		 * Sanity check: exactly one of --value, --add, --remove and --empty must be given. They used to be checked with
		 * `!(a xor b xor c xor d)`, which is true for any odd number of options: three of them together were accepted
		 * (gh-462).
		 */
		$actions = count(array_filter([$doValue, $doAdd, $doRemove, (bool) $doEmpty]));

		if ($actions === 0)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_ERR_NO_ACTION'));

			return 1;
		}

		if ($actions > 1)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_ERR_MUTUALLY_EXCLUSIVE'));

			return 2;
		}

		/** @var ServerconfigmakerModel $model */
		$model = $this->getMVCFactory()->createModel(ucfirst($this->server_engine), 'Administrator');
		$isSubFormKey = in_array($key, $model->getSubformConfigKeys());
		$isArrayKey   = in_array($key, $model->getArrayConfigKeys());

		// Get the corresponding Configure WAF form field
		$field = $model->getForm()->getField($key);

		// Fail if there is no such field (meaning there is no such configuration key)
		if (empty($key) || empty($field))
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_SERVERCONFIG_ERR_CANNOT_FIND', $key));

			return 20;
		}

		/**
		 * --value sets a key with a single value; --add, --remove and --empty change a list (gh-462). --value on a list
		 * was treated as --empty: the list was emptied, and the command said it was set. --add, --remove and --empty on
		 * a single value saved no value at all. Both are refused, rather than guessing what the user meant.
		 */
		if (($isSubFormKey || $isArrayKey) && $doValue)
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_ERR_VALUE_ON_LIST', $key));

			return 3;
		}

		if (!$isSubFormKey && !$isArrayKey && !$doValue)
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_ERR_LIST_NOTALLOWED', $key));

			return 4;
		}

		$valueForValidation = $setValue;
		$valueForSave       = $setValue;

		// If we have to edit the list of values, we have to do some extra steps
		if ($isSubFormKey || $isArrayKey)
		{
			$configuration = $model->loadConfiguration();
			$valueForSave  = $configuration->{$key};

			/**
			 * A subform which is not in the subform configuration keys, e.g. restrictip_custom, is stored the way the
			 * form sends it: one ['item' => …] row per value. A bare value would fail the subform's validation. The
			 * value to remove is found in a row or as a bare value alike.
			 */
			$isRowKey = !$isSubFormKey && $field instanceof SubformField;

			/**
			 * Do we have to remove? Remove every entry with this value (gh-475), like admintools:waf:set (gh-471). The
			 * command stopped at the first match, so a value listed twice stayed in the list, and compared with ==, so
			 * e.g. --remove=1e3 removed the entry 1000. The value must match exactly, letter case included. The kept
			 * entries are renumbered, so the list is stored as a list, as the back-end saves it.
			 *
			 * An IP list which saveConfiguration() normalises, e.g. 001.002.003.004 to 1.2.3.4, is compared normalised,
			 * the value to remove and each stored value alike: --remove=001.002.003.004 matched nothing after
			 * --add=001.002.003.004 (gh-475), and a list saved before 7.10.0 may still hold leading zeros.
			 */
			if ($doRemove)
			{
				$isIpList  = in_array($key, ServerconfigmakerModel::IP_LIST_KEYS, true);
				$normalise = function (?string $value) use ($isIpList): ?string {
					return ($isIpList && $value !== null) ? IpExpressionValidator::normalise($value) : $value;
				};
				$match     = $normalise((string) $removeValue);
				$keptRows  = array_values(
					array_filter(
						(array) $valueForSave,
						function ($row) use ($match, $normalise) {
							return $normalise($this->getRowValue($row)) !== $match;
						}
					)
				);

				if (count($keptRows) === count((array) $valueForSave))
				{
					$this->ioStyle->success(Text::sprintf('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_LBL_UNCHANGED', $key));

					return 0;
				}

				$valueForSave = $keptRows;
			}
			// Do we have to add? That's easy, then
			elseif ($doAdd)
			{
				$valueForSave[] = $isRowKey ? ['item' => $addValue] : $addValue;
			}
			// Do we have to nuke it? Even simpler. Only --empty gets here: --value on a list is refused above.
			else
			{
				$valueForSave = [];
			}

			$valueForValidation = $valueForSave;

			// If the value is inside a subform, massage it so it will be compatible with Joomla Form
			if ($isSubFormKey)
			{
				$valueForValidation = $model->convertDatabaseDataToFormData($valueForSave);
			}
		}

		// Validate the user-provided value
		$validationResult = $field->validate($valueForValidation);

		if ($validationResult instanceof \Throwable)
		{
			$this->ioStyle->error($validationResult->getMessage());

			return 30;
		}

		// Save the new value
		$configuration       = $model->loadConfiguration();
		$configuration->{$key} = $valueForSave;
		$model->saveConfiguration($configuration);

		$this->ioStyle->success(Text::sprintf('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_LBL_SETTING', $key));

		return 0;
	}

	/**
	 * Was an option given a value? An option which is not given, or given without a value (--add or --add=), has none;
	 * 0 is a value.
	 *
	 * @param   mixed  $value  The option's value
	 *
	 * @return  bool
	 *
	 * @since   7.10.0
	 */
	private function isGiven($value): bool
	{
		return $value !== null && $value !== '' && $value !== false;
	}

	/**
	 * The value of one entry of a list key.
	 *
	 * @param   mixed  $row  An ['item' => value] row, as the restrictip_custom subform stores it, or a bare value.
	 *
	 * @return  string|null  The value; null if the entry has none.
	 *
	 * @since   7.10.0
	 */
	private function getRowValue($row): ?string
	{
		if (is_object($row))
		{
			$row = (array) $row;
		}

		if (is_array($row))
		{
			$row = $row['item'] ?? null;
		}

		return is_scalar($row) ? (string) $row : null;
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
		$this->addOption('key', null, InputOption::VALUE_REQUIRED, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_OPT_KEY'));
		$this->addOption('value', null, InputOption::VALUE_REQUIRED, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_OPT_VALUE'));
		$this->addOption('add', null, InputOption::VALUE_OPTIONAL, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_OPT_ADD'));
		$this->addOption('remove', null, InputOption::VALUE_OPTIONAL, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_OPT_REMOVE'));
		$this->addOption('empty', null, InputOption::VALUE_NONE, Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_OPT_EMPTY'));

		$this->setDescription(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_DESC'));
		$this->setHelp(Text::_('COM_ADMINTOOLS_CLI_SERVERCONFIGSET_HELP'));
	}
}