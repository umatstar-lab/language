<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Field;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\Layouts;
use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\CrawlerIPLists;
use Joomla\CMS\Form\FormField;

/**
 * A read-only status table for the crawler IP lists, with the "Refresh now" and "Clear cache lock information"
 * buttons.
 *
 * This is not a setting: the XML element carries `noconfig="true"`, and nothing is ever stored for it.
 *
 * @since   7.9.4
 */
class CrawlerIPStatusField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  7.9.4
	 */
	protected $type = 'CrawlerIPStatus';

	/**
	 * The status table has no label of its own.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	protected function getLabel()
	{
		return '';
	}

	/**
	 * Render the status layout.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	protected function getInput()
	{
		return '<div id="admintools-crawlerips-status">' . self::renderStatus() . '</div>';
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

	/**
	 * Render the contents of the status container. Used by the field and by the AJAX endpoints, so both produce
	 * identical markup. The container itself (`#admintools-crawlerips-status`) is added by the field only; the AJAX
	 * endpoints return the contents, which the JavaScript puts inside it.
	 *
	 * @return  string
	 * @since   7.9.4
	 */
	public static function renderStatus(): string
	{
		return Layouts::render('crawlerips.status', ['status' => CrawlerIPLists::getInstance()->getStatus()]);
	}
}
