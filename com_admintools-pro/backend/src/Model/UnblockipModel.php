<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;

#[\AllowDynamicProperties]
class UnblockipModel extends BaseDatabaseModel
{
	/**
	 * Removes the list of IPs provided from all the "block" lists
	 *
	 * @param   string|array  $ipAddresses  IP addresses to check and delete
	 *
	 * @return  bool            Did I had data to delete? If not, we will have to warn the user
	 */
	public function unblockIP($ipAddresses)
	{
		$ipAddresses = is_array($ipAddresses) ? $ipAddresses : [$ipAddresses];
		$ipAddresses = array_map('trim', array_map('strval', $ipAddresses));
		// Rows saved before IPs were normalised on save may keep leading zeros (e.g. 010.1.1.1): match both forms.
		$ipAddresses = array_values(
			array_unique(
				array_merge($ipAddresses, array_map([IpExpressionValidator::class, 'normalise'], $ipAddresses))
			)
		);

		$db    = $this->getDatabase();
		$found = false;

		/**
		 * Delete all entries through the database. It's substantially faster than going through the models and tables.
		 * We use a transaction so we can roll back the security state if any of the delete operations fails (i.e. we
		 * are failing to a safe state).
		 */
		$db->transactionStart();

		try
		{
			// Automatic IP blocking records
			$query = DbQuery::create($db)
				->delete($db->quoteName('#__admintools_ipautoban'))
				->whereIn($db->quoteName('ip'), $ipAddresses, ParameterType::STRING);
			$db->setQuery($query)->execute();
			$found = $found || ($db->getAffectedRows() > 0);

			// History of automatic IP blocking
			$query = DbQuery::create($db)
				->delete($db->quoteName('#__admintools_ipautobanhistory'))
				->whereIn($db->quoteName('ip'), $ipAddresses, ParameterType::STRING);
			$db->setQuery($query)->execute();
			$found = $found || ($db->getAffectedRows() > 0);

			// Permanent IP blocking (deny list)
			$query = DbQuery::create($db)
				->delete($db->quoteName('#__admintools_ipblock'))
				->whereIn($db->quoteName('ip'), $ipAddresses, ParameterType::STRING);
			$db->setQuery($query)->execute();
			$found = $found || ($db->getAffectedRows() > 0);

			// Log entries. Otherwise automatic IP blocking might kick in and temporarily block the IP again.
			$query = DbQuery::create($db)
				->delete($db->quoteName('#__admintools_log'))
				->whereIn($db->quoteName('ip'), $ipAddresses, ParameterType::STRING);
			$db->setQuery($query)->execute();
			$found = $found || ($db->getAffectedRows() > 0);

			// Finally, commit the transaction
			$db->transactionCommit();
		}
		catch (\Exception $e)
		{
			$db->transactionRollback();

			return false;
		}

		return $found;
	}
}