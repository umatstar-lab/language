<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;

/** @var $this \Akeeba\Component\AdminTools\Administrator\View\Obsoletecore\HtmlView */

$hasFailures = !$this->more && !empty($this->failures);

?>
<div class="card">
	<h3 class="card-header text-white <?= $this->more ? 'bg-primary' : ($hasFailures ? 'bg-warning text-dark' : 'bg-success') ?>">
		<?php if ($this->more): ?>
			<?= Text::_('COM_ADMINTOOLS_OBSOLETECORE_LBL_INPROGRESS') ?>
		<?php elseif ($hasFailures): ?>
			<?= Text::_('COM_ADMINTOOLS_OBSOLETECORE_LBL_INCOMPLETE') ?>
		<?php else: ?>
			<?= Text::_('COM_ADMINTOOLS_OBSOLETECORE_LBL_DONE') ?>
		<?php endif ?>
	</h3>
	<div class="card-body">
		<?php if ($this->more): ?>
			<div id="admintools-obsoletecore-more">
				<div class="progress mb-3">
					<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
						 aria-valuemax="100" aria-valuemin="0" aria-valuenow="100" style="width: 100%"></div>
				</div>
				<p class="mb-0">
					<?= Text::_('COM_ADMINTOOLS_OBSOLETECORE_LBL_INPROGRESS_WAIT') ?>
				</p>
			</div>
		<?php elseif ($hasFailures): ?>
			<p>
				<?= Text::plural('COM_ADMINTOOLS_OBSOLETECORE_LBL_FAILED_INTRO_N', count($this->failures)) ?>
			</p>
			<ul class="list-group mb-3">
				<?php foreach ($this->failures as $failure): ?>
					<li class="list-group-item">
						<span class="badge <?= $failure['type'] === 'folder' ? 'bg-secondary' : 'bg-info' ?> me-2">
							<?= Text::_(
								$failure['type'] === 'folder'
									? 'COM_ADMINTOOLS_OBSOLETECORE_LBL_TYPE_FOLDER'
									: 'COM_ADMINTOOLS_OBSOLETECORE_LBL_TYPE_FILE'
							) ?>
						</span>
						<span class="font-monospace"><?= $this->escape($failure['path']) ?></span>
					</li>
				<?php endforeach ?>
			</ul>
			<div class="alert alert-warning mb-0">
				<p class="mb-0"><?= Text::_('COM_ADMINTOOLS_OBSOLETECORE_LBL_FAILED_ADVICE') ?></p>
			</div>
		<?php else: ?>
			<p>
				<?= Text::_('COM_ADMINTOOLS_OBSOLETECORE_LBL_DONE_INFO') ?>
			</p>
			<div class="alert alert-info mb-0" id="admintools-obsoletecore-autoclose">
				<p class="mb-0">
					<span class="icon-info-circle" aria-hidden="true"></span>
					<span class="visually-hidden"><?= Text::_('INFO') ?></span>
					<?= Text::_('COM_ADMINTOOLS_COMMON_LBL_AUTOCLOSEIN3S') ?>
				</p>
			</div>
		<?php endif ?>
	</div>
</div>
