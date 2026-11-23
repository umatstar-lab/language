<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;

/** @var array{disable: bool, hide: bool} $buttons */

?>
<h4 class="fs-5">
	<?= Text::_('PLG_ADMINTOOLS_LBL_DLFNOTICE_HEAD') ?>
</h4>
<p>
	<?= Text::_('PLG_ADMINTOOLS_LBL_DLFNOTICE_MESSAGE') ?>
</p>
<div class="d-flex flex-row gap-3">
	<?php if (!empty($buttons['disable'])): ?>
	<div class="btn-group d-none" id="plgSystemAdmintoolsDLFOffGroup">
		<button type="button" class="btn btn-primary" id="plgSystemAdmintoolsDLFOff">
			<span class="fa fa-toggle-off me-1" aria-hidden="true"></span>
			<?= Text::_('PLG_ADMINTOOLS_LBL_DLFNOTICE_BTN_DISABLE') ?>
		</button>
		<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split"
		        data-bs-toggle="dropdown" aria-expanded="false">
			<span class="visually-hidden"><?= Text::_('JGLOBAL_TOGGLE_DROPDOWN') ?></span>
		</button>
		<ul class="dropdown-menu">
			<li>
				<button type="button" class="dropdown-item" id="plgSystemAdmintoolsDLFOffDelete">
					<span class="fa fa-trash me-1" aria-hidden="true"></span>
					<?= Text::_('PLG_ADMINTOOLS_LBL_DLFNOTICE_BTN_DISABLE_DELETE') ?>
				</button>
			</li>
		</ul>
	</div>
	<?php endif ?>
	<?php if (!empty($buttons['hide'])): ?>
	<button type="button" class="btn btn-danger d-none" id="plgSystemAdmintoolsDLFHide">
		<span class="fa fa-eye-slash me-1" aria-hidden="true"></span>
		<?= Text::_('PLG_ADMINTOOLS_LBL_DLFNOTICE_BTN_HIDE') ?>
	</button>
	<?php endif ?>
</div>
