<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Other;

defined('_JEXEC') or die;

/**
 * Invitation to run Check Temp and Log Directories (a Control Panel tool shown in a modal, hence the link to the Control Panel).
 *
 * @since  7.10.0
 */
class TempAndCacheFolders extends AbstractFeatureInvitation
{
	protected $id = 'temp_and_cache_folders';

	protected $langKey = 'TEMPANDCACHEFOLDERS';

	protected $view = 'Checktempandlogdirectories';

	protected $url = 'index.php?option=com_admintools&view=Controlpanel';

	protected $linkLabel = 'COM_ADMINTOOLS_TITLE_CONTROLPANEL';
}
