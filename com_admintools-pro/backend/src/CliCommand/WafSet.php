<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\CliCommand;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\CliRouting;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureEnvTrait;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\ConfigureIO;
use Akeeba\Component\AdminTools\Administrator\CliCommand\MixIt\PrintFormattedArray;
use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\Model\ConfigurewafModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * admintools:waf:set
 *
 * Set the value of a WAF configuration option
 *
 */
class WafSet extends AbstractCommand
{
	use ConfigureIO;
	use PrintFormattedArray;
	use MVCFactoryAwareTrait;
	use CliRouting;
	use ConfigureEnvTrait;

	/**
	 * The default command name
	 *
	 * @var    string
	 */
	protected static $defaultName = 'admintools:waf:set';

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
		$this->initCliRouting();
		$this->configureEnv();
		$this->configureSymfonyIO($input, $output);
		$this->getApplication()->getLanguage()->load();
		$this->getApplication()->getLanguage()->load('com_admintools', JPATH_ADMINISTRATOR);
		$this->getApplication()->getLanguage()->load('com_admintools', JPATH_SITE);

		$key      = (string) $this->cliInput->getOption('key');
		$setValue = $this->cliInput->getOption('value');
		$noValue  = empty($setValue) && $setValue !== 0 && $setValue !== "0";

		// Special handling for array-ish values
		$addValue    = $this->cliInput->getOption('add') ?? null;
		$description = $this->cliInput->getOption('description') ?? null;
		$removeValue = $this->cliInput->getOption('remove') ?? null;
		$doEmpty     = $this->cliInput->getOption('empty') ?? false;

		/**
		 * An empty --add, --remove or --description counts as not given, like an empty --value (gh-467); 0 is a value.
		 * They were checked with empty(), so --add=0 and --remove=0 were refused as no action (gh-475).
		 */
		$doAdd          = $this->isGiven($addValue);
		$doRemove       = $this->isGiven($removeValue);
		$hasDescription = $this->isGiven($description);

		/**
		 * Sanity check: exactly one of --value, --add, --remove and --empty must be given. They used to be checked with
		 * `!(a xor b xor c xor d)`, which is true for any odd number of options: three of them together were accepted
		 * (gh-467). An empty --value counts as not given, as it always has.
		 */
		$actions = count(array_filter([!$noValue, $doAdd, $doRemove, (bool) $doEmpty]));

		if ($actions === 0)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_WAFSET_ERR_NO_ACTION'));

			return 1;
		}

		if ($actions > 1)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_WAFSET_ERR_MUTUALLY_EXCLUSIVE'));

			return 2;
		}

		// Sanity check: --description is only allowed with --add
		if (!$doAdd && $hasDescription)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_WAFSET_ERR_DESCRIPTION_NOTALLOWED'));

			return 40;
		}

		/** @var ConfigurewafModel $model */
		$model        = $this->getMVCFactory()->createModel('Configurewaf', 'Administrator');
		$isSubFormKey = in_array($key, $model->getSubformConfigKeys());

		// Got a special key, double check if we're allowed to do so
		if (($doAdd || $doRemove || $doEmpty) && !$isSubFormKey)
		{
			$this->ioStyle->error(Text::_('COM_ADMINTOOLS_CLI_WAFSET_ERR_LIST_NOTALLOWED'));

			return 10;
		}

		/**
		 * --add, --remove and --empty change a list; --value sets a key with a single value (gh-467). --value on a list
		 * was treated as --empty: the list was emptied, and the command said it was set. It is refused, rather than
		 * guessing what the user meant. Only the subform keys are lists here: --add, --remove and --empty are refused
		 * on the other keys holding several values, e.g. user groups, so --value stays allowed on those.
		 */
		if ($isSubFormKey && !$noValue)
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_WAFSET_ERR_VALUE_ON_LIST', $key));

			return 3;
		}

		// Get the corresponding Configure WAF form field
		$field = $model->getForm()->getField($key);

		// Fail if there is no such field (meaning there is no such configuration key)
		if (empty($key) || empty($field))
		{
			$this->ioStyle->error(Text::sprintf('COM_ADMINTOOLS_CLI_WAFSET_ERR_CANNOT_FIND', $key));

			return 20;
		}

		$valueForValidation = $setValue;
		$valueForSave       = $setValue;

		/**
		 * An Away Schedule time is trimmed, and saved as hh:mm (9:05 as 09:05); a value of spaces turns it off. Joomla's
		 * time rule accepts some values which are not a time, e.g. 12:5:, which saveConfig() saved as an empty string:
		 * the Away Schedule was silently turned off (gh-474). They are refused.
		 */
		if (in_array($key, ConfigurewafModel::AWAYSCHEDULE_KEYS, true))
		{
			$normalisedTime = ConfigurewafModel::normaliseAwayScheduleTime($setValue);

			if ($normalisedTime === null)
			{
				$this->ioStyle->error(
					Text::sprintf('COM_ADMINTOOLS_CONFIGUREWAF_ERR_AWAYSCHEDULE_TIME', $key, (string) $setValue)
				);

				return 30;
			}

			$valueForValidation = $normalisedTime;
			$valueForSave       = $normalisedTime;
		}

		// If we have to edit the list of values, we have to do some extra steps
		if ($isSubFormKey)
		{
			$configuration = $model->getConfig();
			$valueForSave  = $configuration[$key];

			/**
			 * Do we have to remove? Remove every row with this value (gh-471). A row is a [value, description] pair, as
			 * saved by --add and the WAF configuration page, or a bare value (defaults, lists saved by old versions).
			 * in_array() only matched a bare value, so a pair was never removed. The value must match exactly, letter
			 * case included, as the WAF compares some lists case-sensitively (usernames, file paths, 404 Shield
			 * patterns). The kept rows are renumbered: a gap would save the list as a JSON object, which the WAF
			 * reads as no list at all.
			 *
			 * An IP list which saveConfig() normalises, e.g. 001.002.003.004 to 1.2.3.4, is compared normalised, the
			 * value to remove and each stored value alike: --remove=001.002.003.004 matched nothing after
			 * --add=001.002.003.004 (gh-475), and a list saved before 7.10.0 may still hold leading zeros.
			 */
			if ($doRemove)
			{
				$isIpList  = in_array($key, ConfigurewafModel::IP_LIST_KEYS, true);
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
					$this->ioStyle->success(Text::sprintf('COM_ADMINTOOLS_CLI_WAFSET_LBL_UNCHANGED', $key));

					return 0;
				}

				$valueForSave = $keptRows;
			}
			// Do we have to add? That's easy, then
			elseif ($doAdd)
			{
				$valueForSave[] = [
					$addValue,
					$description ?? '',
				];
			}
			// Do we have to nuke it? Even simpler. Only --empty gets here: --value on a list is refused above.
			else
			{
				$valueForSave = [];
			}

			// Finally, massage the value so it will be compatible with Joomla Form
			$valueForValidation = $model->convertDatabaseDataToFormData($valueForSave);
		}

		// Validate the user-provided value
		$validationResult = $field->validate($valueForValidation);

		if ($validationResult instanceof \Throwable)
		{
			$this->ioStyle->error($validationResult->getMessage());

			return 30;
		}

		// Save the new value
		$configuration       = $model->getConfig();
		$configuration[$key] = $valueForSave;
		$model->saveConfig($configuration);

		$this->ioStyle->success(Text::sprintf('COM_ADMINTOOLS_CLI_WAFSET_LBL_SETTING', $key));

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
	 * The value of one row of a list key, without its description.
	 *
	 * @param   mixed  $row  A [value, description] pair, an ['item' => value, …] row, or a bare value.
	 *
	 * @return  string|null  The value; null if the row has none.
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
			$row = $row[0] ?? $row['item'] ?? null;
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
		$this->addOption('key', null, InputOption::VALUE_REQUIRED, Text::_('COM_ADMINTOOLS_CLI_WAFSET_OPT_KEY'));
		$this->addOption('value', null, InputOption::VALUE_REQUIRED, Text::_('COM_ADMINTOOLS_CLI_WAFSET_OPT_VALUE'));
		$this->addOption('add', null, InputOption::VALUE_OPTIONAL, Text::_('COM_ADMINTOOLS_CLI_WAFSET_OPT_ADD'));
		$this->addOption('description', null, InputOption::VALUE_OPTIONAL, Text::_('COM_ADMINTOOLS_CLI_WAFSET_OPT_DESCRIPTION'));
		$this->addOption('remove', null, InputOption::VALUE_OPTIONAL, Text::_('COM_ADMINTOOLS_CLI_WAFSET_OPT_REMOVE'));
		$this->addOption('empty', null, InputOption::VALUE_NONE, Text::_('COM_ADMINTOOLS_CLI_WAFSET_OPT_EMPTY'));

		$this->setDescription(Text::_('COM_ADMINTOOLS_CLI_WAFSET_DESC'));
		$this->setHelp(Text::_('COM_ADMINTOOLS_CLI_WAFSET_HELP'));
	}
}
