<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Other;

defined('_JEXEC') or die;

/**
 * Invitation to use Temporary Super Users.
 *
 * @since  7.10.0
 */
class TemporarySuperUsers extends AbstractFeatureInvitation
{
	protected $id = 'temporary_super_users';

	protected $langKey = 'TEMPORARYSUPERUSERS';

	protected $view = 'Tempsuperusers';

	protected $url = 'index.php?option=com_admintools&view=Tempsuperusers';

	protected $linkLabel = 'COM_ADMINTOOLS_TITLE_TEMPSUPERUSERS';
}
