<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Other;

defined('_JEXEC') or die;

/**
 * Invitation to use Permissions Configuration and Apply Permissions.
 *
 * @since  7.10.0
 */
class FilePermissions extends AbstractFeatureInvitation
{
	protected $id = 'file_permissions';

	protected $langKey = 'FILEPERMISSIONS';

	protected $view = 'Configurepermissions';

	protected $url = 'index.php?option=com_admintools&view=Configurepermissions';

	protected $linkLabel = 'COM_ADMINTOOLS_TITLE_CONFIGUREPERMISSIONS';
}
