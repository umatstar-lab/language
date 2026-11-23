<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\CrawlerIP\BotRegistry;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * @var   array  $displayData  ['status' => array]  The result of CrawlerIPLists::getStatus()
 */
$status = $displayData['status'];
$health = $status['health'];
$cause  = $health['cause'] ?? null;
$escape = function ($x): string {
	return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
};
$fmtDate = function (int $ts): string {
	return HTMLHelper::_('date', $ts, Text::_('DATE_FORMAT_LC6'));
};
?>
<?php if (!empty($cause)): ?>
		<div class="alert alert-warning">
			<?= $escape(Text::sprintf(
				'COM_ADMINTOOLS_CRAWLERIPS_STATUS_AUTODISABLED',
				$health['disabledAt'] ? $fmtDate((int) $health['disabledAt']) : '',
				Text::_('COM_ADMINTOOLS_CRAWLERIPS_CAUSE_' . strtoupper($cause))
			)) ?>
		</div>
	<?php elseif (!$status['masterSwitch']): ?>
		<div class="alert alert-info">
			<?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_STATUS_SWITCHEDOFF')) ?>
		</div>
	<?php endif; ?>

	<div class="table-responsive">
		<table class="table table-striped align-middle">
			<thead>
			<tr>
				<th scope="col"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_COL_PROVIDER')) ?></th>
				<th scope="col"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_COL_BOT')) ?></th>
				<th scope="col"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_COL_DOWNLOADED')) ?></th>
				<th scope="col"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_COL_RANGES')) ?></th>
				<th scope="col"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_COL_STATUS')) ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($status['bots'] as $bot):
				$isIgnored = $bot['state'] === 'ignore';
				$hasData = $bot['fetchedAt'] !== null;

				if (!empty($bot['lastError']) && !$isIgnored)
				{
					$error = ($bot['lastError'] === 'busy')
						? Text::_('COM_ADMINTOOLS_CRAWLERIPS_ERR_BUSY')
						: (string) $bot['lastError'];
					$statusText = $error;
					$statusClass = 'text-danger';
				}
				elseif ($isIgnored)
				{
					$statusText = Text::_($hasData ? 'COM_ADMINTOOLS_CRAWLERIPS_STATUS_UNUSED' : 'COM_ADMINTOOLS_CRAWLERIPS_STATUS_NOTDOWNLOADED');
					$statusClass = 'text-muted';
				}
				elseif ($bot['stale'])
				{
					$statusText = Text::_('COM_ADMINTOOLS_CRAWLERIPS_STATUS_STALE');
					$statusClass = 'text-warning';
				}
				elseif ($bot['usable'])
				{
					$statusText = Text::_('COM_ADMINTOOLS_CRAWLERIPS_STATUS_OK');
					$statusClass = 'text-success';
				}
				else
				{
					$statusText = Text::_('COM_ADMINTOOLS_CRAWLERIPS_STATUS_NOTDOWNLOADED');
					$statusClass = 'text-muted';
				}
				?>
				<tr>
					<td><?= $escape(Text::_(BotRegistry::providerLabelKey($bot['provider']))) ?></td>
					<td><?= $escape(Text::_(BotRegistry::labelKey($bot['key']))) ?></td>
					<td>
						<?= $hasData
							? $escape($fmtDate((int) $bot['fetchedAt']))
							: $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_NEVER')) ?>
					</td>
					<td><?= $hasData ? $escape((string) (int) $bot['entries']) : '&mdash;' ?></td>
					<td class="<?= $statusClass ?>"><?= $escape($statusText) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="d-flex flex-column flex-md-row gap-4 mb-3">
		<div>
			<button type="button" class="btn btn-primary" data-crawlerips-action="refresh">
				<span class="icon-refresh" aria-hidden="true"></span>
				<?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_BTN_REFRESH')) ?>
			</button>
			<div class="form-text"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_BTN_REFRESH_HELP')) ?></div>
		</div>
		<div>
			<button type="button" class="btn btn-outline-warning" data-crawlerips-action="reset">
				<span class="icon-undo" aria-hidden="true"></span>
				<?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_BTN_RESET')) ?>
			</button>
			<div class="form-text"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_BTN_RESET_HELP')) ?></div>
		</div>
	</div>

	<div class="alert alert-info d-none" data-crawlerips-progress role="status"></div>
