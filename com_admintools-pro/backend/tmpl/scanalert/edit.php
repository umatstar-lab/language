<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

/** @var \Akeeba\Component\AdminTools\Administrator\View\Scanalert\HtmlView $this */

use Akeeba\Component\AdminTools\Administrator\Helper\CoreSums;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * HTMLHelper's `behavior.formvalidator` is deprecated in Joomla 6.
 *
 * See Joomla PR 45925.
 */
if (version_compare(JVERSION, '5.999.999', 'lt'))
{
	HTMLHelper::_('behavior.formvalidator');
}
else
{
	\Joomla\CMS\Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('form.validate');
}

?>
<form action="<?php echo Route::_('index.php?option=com_admintools&view=scanalert&layout=edit&id=' . $this->item->id); ?>"
      aria-label="<?= Text::_('COM_ADMINTOOLS_TITLE_BADWORD_EDIT', true) ?>"
      class="form-validate" id="tempsuperuser-form" method="post" name="adminForm">

	<div class="card card-body mb-2">
		<?php foreach ($this->form->getFieldset('details') as $field): ?>
			<?= $field->renderField(); ?>
		<?php endforeach; ?>
	</div>

	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

<div class="card card-body mb-2">
	<?= HTMLHelper::_('uitab.startTabSet', 'com_admintools_scanalert') ?>

	<?php if($this->generateDiff && ($this->fstatus == 'modified')): ?>
	<?= HTMLHelper::_('uitab.addTab', 'com_admintools_scanalert', 'diff', Text::_('COM_ADMINTOOLS_SCANALERTS_LBL_DIFF')) ?>
	<pre class="highlightCode <?= $this->item->suspicious ? 'php' : 'diff'; ?>"><?= htmlspecialchars($this->item->diff, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
	<?= HTMLHelper::_('uitab.endTab') ?>
	<?php endif; ?>

	<?= HTMLHelper::_('uitab.addTab', 'com_admintools_scanalert', 'source', Text::_('COM_ADMINTOOLS_SCANALERTS_LBL_SOURCE')) ?>

	<?php if(($this->filePath === null || !@file_exists(JPATH_SITE . '/' . $this->filePath)) && !$this->generateDiff): ?>
		<div class="alert alert-danger">
			<?=Text::sprintf('COM_ADMINTOOLS_SCANALERTS_LBL_FILENOTFOUND', htmlspecialchars($this->displayPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?>
		</div>
	<?php else: ?>
		<div class="alert alert-warning">
			<?=Text::_('COM_ADMINTOOLS_SCANALERTS_LBL_SOURCE_NOTE'); ?>
		</div>

		<?php
		// A core file with a mismatching checksum (flag = 'core') is shown against the canonical CoreSums value.
		$isCoreMismatch = (($this->item->flag ?? null) === 'core');
		$canonicalSum   = $isCoreMismatch ? CoreSums::getChecksum((string) $this->filePath) : null;
		$currentSum     = $isCoreMismatch
			? CoreSums::squashChecksum((string) @file_get_contents(JPATH_SITE . '/' . $this->filePath))
			: @hash_file('md5', JPATH_SITE . '/' . $this->filePath);
		?>
		<div class="row mb-3 alert <?= $isCoreMismatch ? 'alert-danger' : 'alert-info' ?>">
			<label class="col-md-3">
				<?=Text::_('COM_ADMINTOOLS_SCANALERTS_LBL_MD5'); ?>
			</label>

			<div class="col-md-9">
				<code><?= $this->escape((string) $currentSum) ?></code>
			</div>

			<?php if ($isCoreMismatch): ?>
				<label class="col-md-3 mt-2">
					<?=Text::_('COM_ADMINTOOLS_SCANALERTS_LBL_CORESUMS_CANONICAL'); ?>
				</label>

				<div class="col-md-9 mt-2">
					<code><?= $this->escape((string) ($canonicalSum ?? '—')) ?></code>
				</div>
			<?php endif; ?>
		</div>

		<pre class="highlightCode language-php"><?=$this->getFileSourceForDisplay(true); ?></pre>
	<?php endif; ?>


	<?= HTMLHelper::_('uitab.endTab') ?>

	<?= HTMLHelper::_('uitab.endTabSet') ?>
</div>