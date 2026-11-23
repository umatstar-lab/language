<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Layout\FileLayout;

/**
 * Renders the component's own Joomla layouts (administrator/components/com_admintools/layouts).
 *
 * Templates can still override them, as with any Joomla layout.
 *
 * @since  7.10.0
 */
final class Layouts
{
	/**
	 * The component's layouts folder.
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function getBasePath(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_admintools/layouts';
	}

	/**
	 * Get a layout object, e.g. to render the same layout many times.
	 *
	 * @param   string  $layoutId  Dot-separated layout name, e.g. akeeba.admintools.common.user
	 *
	 * @return  FileLayout
	 * @since   7.10.0
	 */
	public static function get(string $layoutId): FileLayout
	{
		return new FileLayout($layoutId, self::getBasePath());
	}

	/**
	 * Render a layout.
	 *
	 * @param   string  $layoutId     Dot-separated layout name, e.g. akeeba.admintools.common.user
	 * @param   array   $displayData  Data passed to the layout as $displayData
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function render(string $layoutId, array $displayData = []): string
	{
		return self::get($layoutId)->render($displayData);
	}
}
