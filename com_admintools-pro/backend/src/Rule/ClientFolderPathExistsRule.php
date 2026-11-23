<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Rule;

defined('_JEXEC') or die;

use Joomla\Filesystem\Folder;
use Joomla\Filesystem\Path;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\Rule\FolderPathExistsRule;
use Joomla\Registry\Registry;

class ClientFolderPathExistsRule extends FolderPathExistsRule
{
	/**
	 * Method to test if the folder path is valid and points to an existing folder below the configured Joomla client root
	 *
	 * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the `<field>` tag for the form field object.
	 * @param   mixed              $value    The form field value to validate.
	 * @param   string             $group    The field name group control value. This acts as an array container for the field.
	 *                                       For example if the field has name="foo" and the group value is set to "bar" then the
	 *                                       full field name would end up being "bar[foo]".
	 * @param   Registry           $input    An optional Registry object with the entire data set to validate against the entire form.
	 * @param   Form               $form     The form object for which the field is being tested.
	 *
	 * @return  boolean  True if the value is valid and points to an existing folder below the Joomla root, false otherwise.
	 *
	 * @since   7.0.0
	 */
	public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
	{
		if (!parent::test($element, $value, $group, $input, $form))
		{
			return false;
		}

		// If the field is empty and not required so the previous test hasn't failed, the field is valid.
		if ($value === '' || $value === null)
		{
			return true;
		}

		// Spaces only would result in Joomla root which is not allowed
		if (!trim($value))
		{
			return false;
		}

		$client = $this->getClientAttribute($element);
		$root = ($client === 'site') ? JPATH_ROOT : JPATH_ADMINISTRATOR;

		$pathCleaned = rtrim(Path::clean($root . '/' . $value), \DIRECTORY_SEPARATOR);
		$rootCleaned = rtrim(Path::clean($root), \DIRECTORY_SEPARATOR);

		// JPATH_ROOT is not allowed
		if ($pathCleaned === $rootCleaned)
		{
			return false;
		}

		try
		{
			return Folder::exists($pathCleaned);
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	/**
	 * Read the `client` attribute of a `<field>` element, defaulting to `site` when the attribute is absent.
	 *
	 * The previous inline expression `(string) $element->attributes()->client ?? 'site'` was a no-op: casting a
	 * missing SimpleXMLElement attribute to string yields '' (never null), so the `??` never fired and a field
	 * with no client attribute silently fell through to the JPATH_ADMINISTRATOR branch instead of the documented
	 * default of `site`. isset() on the array-access form returns false for missing attributes, so the default
	 * now applies.
	 *
	 * @param   \SimpleXMLElement  $element  The `<field>` SimpleXMLElement.
	 *
	 * @return  string  The value of the `client` attribute, or `'site'` if it is absent.
	 *
	 * @since   7.9.2
	 */
	protected function getClientAttribute(\SimpleXMLElement $element): string
	{
		return isset($element['client']) ? (string) $element['client'] : 'site';
	}

}
