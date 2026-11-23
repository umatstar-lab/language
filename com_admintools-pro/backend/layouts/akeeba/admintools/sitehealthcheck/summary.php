<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die();

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The Site Health Check summary: checks performed, problems per severity, and the toggle for the other checks.
 *
 * @var array   $displayData  Incoming display data. These set the following variables.
 * @var array   $summary      The Runner::summarise() output
 * @var array[] $entries      The Runner::run() output, for the age of the cached results
 */

extract(array_merge([
	'summary' => [],
	'entries' => [],
], $displayData));

$e = function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$cachedTimes = array_filter(array_column($entries, 'cachedAt'));
$oldestCache = empty($cachedTimes) ? null : min($cachedTimes);
$hidden      = (int) ($summary['passed'] ?? 0) + (int) ($summary['ignored'] ?? 0);
$notChecked  = (int) ($summary['na'] ?? 0);

?>
<div class="card mb-3" data-shc-summary>
	<div class="card-body">
		<div class="d-flex flex-wrap align-items-center gap-2">
			<span class="fs-5 me-2">
				<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SUMMARY_TOTAL', (int) ($summary['total'] ?? 0)) ?>
			</span>

			<?php foreach ((array) ($summary['severity'] ?? []) as $severity => $count): ?>
				<span class="badge fs-6 text-bg-<?= $e(Severity::bootstrapColour($severity)) ?> <?= $count ? '' : 'opacity-50' ?>">
					<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SUMMARY_' . strtoupper($severity), (int) $count) ?>
				</span>
			<?php endforeach ?>

			<span class="badge fs-6 text-bg-success">
				<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SUMMARY_PASSED', (int) ($summary['passed'] ?? 0)) ?>
			</span>

			<?php if ($notChecked > 0): ?>
				<span class="badge fs-6 text-bg-secondary">
					<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SUMMARY_NA', $notChecked) ?>
				</span>
			<?php endif ?>

			<?php if (!empty($summary['ignored'])): ?>
				<span class="badge fs-6 text-bg-light border">
					<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SUMMARY_IGNORED', (int) $summary['ignored']) ?>
				</span>
			<?php endif ?>

			<?php if ($hidden > 0): ?>
				<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-shc-toggle
						aria-pressed="false">
					<span class="fa fa-eye" aria-hidden="true"></span>
					<span data-shc-toggle-show><?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_SHOW_ALL', $hidden) ?></span>
					<span data-shc-toggle-hide class="d-none"><?= Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_HIDE_ALL') ?></span>
				</button>
			<?php endif ?>
		</div>

		<?php if ($notChecked > 0): ?>
			<div class="alert alert-info mt-3 mb-0">
				<span class="fa fa-info-circle" aria-hidden="true"></span>
				<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NA_NOTE', $notChecked) ?>
			</div>
		<?php endif ?>

		<?php if (empty($summary['problems'])): ?>
			<div class="alert alert-success mt-3 mb-0">
				<span class="fa fa-check-circle" aria-hidden="true"></span>
				<?= Text::_($notChecked > 0 ? 'COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NO_PROBLEMS_SO_FAR' : 'COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NO_PROBLEMS') ?>
			</div>
		<?php endif ?>

		<?php if ($oldestCache !== null): ?>
			<p class="small text-muted mt-3 mb-0">
				<?= Text::sprintf(
					'COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_CACHED',
					$e(HTMLHelper::_('date', (int) $oldestCache, Text::_('DATE_FORMAT_LC2')))
				) ?>
			</p>
		<?php endif ?>
	</div>
</div>
