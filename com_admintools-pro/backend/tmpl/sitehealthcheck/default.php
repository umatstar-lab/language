<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var  \Akeeba\Component\AdminTools\Administrator\View\Sitehealthcheck\HtmlView $this */

?>
<form action="<?= Route::_('index.php?option=com_admintools&view=Sitehealthcheck') ?>"
	  method="post" name="adminForm" id="adminForm">

	<?php // Joomla hides the Toggle Inline Help button when no help text exists on page load; ours loads later. ?>
	<div id="admintoolsSiteHealthCheckHelpState" class="hide-aware-inline-help d-none"></div>

	<div id="admintoolsSiteHealthCheck" aria-live="polite">
		<div class="card mb-3" id="admintoolsSiteHealthCheckLoading">
			<div class="card-body text-center p-5">
				<span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
				<p class="mt-3 mb-0">
					<?= Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_LOADING') ?>
				</p>
			</div>
		</div>
	</div>

	<input type="hidden" name="task" value="">
	<input type="hidden" name="check_id" value="">
	<input type="hidden" name="fix_id" value="">
	<input type="hidden" name="item_key" value="">
	<?= HTMLHelper::_('form.token') ?>
</form>
