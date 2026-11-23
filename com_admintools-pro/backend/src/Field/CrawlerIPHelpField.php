<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Field;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\Layouts;
use Joomla\CMS\Form\FormField;

/**
 * A collapsible information box explaining the Ignore, Block and Do not block options of the crawler settings, so
 * that this explanation is shown once instead of being repeated in every crawler's help text.
 *
 * This is not a setting: the XML element carries `noconfig="true"`, and nothing is ever stored for it.
 *
 * @since   7.9.4
 */
class CrawlerIPHelpField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  7.9.4
	 */
	protected $type = 'CrawlerIPHelp';

	/**
	 * The help box has no label of its own.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	protected function getLabel()
	{
		return '';
	}

	/**
	 * Render the help layout.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	protected function getInput()
	{
		return Layouts::render('crawlerips.help');
	}

	/**
	 * Use the full width of the tab; no label column.
	 *
	 * @param   array  $options  Not used.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public function renderField($options = [])
	{
		return '<div class="control-group w-100">' . $this->getInput() . '</div>';
	}
}
