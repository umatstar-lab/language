<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die();

use Akeeba\Component\AdminTools\Administrator\Helper\Layouts;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\IgnorableItemsInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Runner;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Joomla\CMS\Language\Text;

/**
 * One Site Health Check item.
 *
 * Messages and descriptions are HTML written by the check, output as-is. Everything else is escaped.
 *
 * @var array                 $displayData  Incoming display data. These set the following variables.
 * @var array                 $entry        One entry of the Runner::run() output
 * @var \Joomla\CMS\User\User $user         The current user, to decide which fixes to offer
 * @var bool                  $secondary    Hidden unless the user asks to see all checks?
 */

extract(array_merge([
	'entry'     => [],
	'user'      => null,
	'secondary' => false,
], $displayData));

/** @var CheckInterface $check */
$check = $entry['check'];
/** @var CheckResult $result */
$result  = $entry['result'];
$ignored = (bool) $entry['ignored'];

$e = function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

// Fix descriptors this user may apply, by ID. A misbehaving third-party check must not break the page.
$fixes = [];

try
{
	foreach ($check->getFixes() as $fix)
	{
		if ($fix instanceof Fix && $user !== null && $check->canFix($fix->getId(), $user))
		{
			$fixes[$fix->getId()] = $fix;
		}
	}
}
catch (\Throwable $exc)
{
	$fixes = [];
}

// Ignoring items is up to the Runner, not the check, and needs no more than access to this page; like ignoring checks.
if ($check instanceof IgnorableItemsInterface && $user !== null)
{
	$fixes = array_merge($fixes, Runner::getIgnoreFixes());
}

$buttonLayout = Layouts::get('akeeba.admintools.sitehealthcheck.button');

$renderFix = function (string $fixId, ?string $itemKey = null, string $itemLabel = '') use ($fixes, $check, $buttonLayout): string {
	if ($fixId === '' || !isset($fixes[$fixId]))
	{
		return '';
	}

	/** @var Fix $fix */
	$fix = $fixes[$fixId];

	return $buttonLayout->render([
		'task'    => 'sitehealthcheck.fix',
		'checkId' => $check->getId(),
		'fixId'   => $fixId,
		'itemKey' => $itemKey,
		'label'   => $fix->getLabel(),
		// Per-item fixes name the item in their confirmation question.
		'confirm' => str_replace('%s', $itemLabel, $fix->getConfirm()),
		'style'   => $fix->getStyle(),
		'icon'    => $fix->getIcon(),
		'reload'  => $fix->isReloadPage(),
	]);
};

switch ($ignored ? 'ignored' : $result->getStatus())
{
	case 'ignored':
		$statusColour = 'light border';
		$statusKey    = 'COM_ADMINTOOLS_SITEHEALTHCHECK_STATUS_IGNORED';
		break;

	case CheckResult::STATUS_PASS:
		$statusColour = 'success';
		$statusKey    = 'COM_ADMINTOOLS_SITEHEALTHCHECK_STATUS_PASS';
		break;

	case CheckResult::STATUS_FAIL:
		$statusColour = Severity::bootstrapColour($check->getSeverity());
		$statusKey    = 'COM_ADMINTOOLS_SITEHEALTHCHECK_SEVERITY_' . strtoupper($check->getSeverity());
		break;

	case CheckResult::STATUS_NA:
		$statusColour = 'secondary';
		$statusKey    = 'COM_ADMINTOOLS_SITEHEALTHCHECK_STATUS_NA';
		break;

	case CheckResult::STATUS_INFO:
		$statusColour = 'info';
		$statusKey    = 'COM_ADMINTOOLS_SITEHEALTHCHECK_STATUS_INFO';
		break;

	default:
		$statusColour = 'danger';
		$statusKey    = 'COM_ADMINTOOLS_SITEHEALTHCHECK_STATUS_ERROR';
		break;
}

$items      = $result->getItems();
$moreItems  = $result->getTotalItems() - count($items);
$wholeFixes = array_filter(array_map($renderFix, $result->getFixes()));
$links      = array_map(
	function (array $link) {
		return Layouts::render('akeeba.admintools.sitehealthcheck.link', $link);
	},
	$result->getLinks()
);
$elementId  = 'shc-' . preg_replace('/[^A-Za-z0-9_\-]/', '-', $check->getId());

?>
<div class="border rounded p-3 mb-3 <?= $ignored ? 'opacity-75' : '' ?>"
	 id="<?= $e($elementId) ?>" data-shc-card
	 <?= $secondary ? 'data-shc-secondary' : '' ?>>
	<div class="d-flex flex-column flex-lg-row align-items-lg-start gap-2 mb-2">
		<h4 class="h5 d-flex flex-wrap align-items-center gap-2 mb-0">
			<span class="badge text-bg-<?= $e($statusColour) ?>"><?= Text::_($statusKey) ?></span>
			<span><?= $e($check->getTitle()) ?></span>
			<?php if ($result->getStatus() !== CheckResult::STATUS_FAIL && $check->getSeverity() !== Severity::INFO): ?>
				<small class="text-muted fw-normal">
					<?= Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_SEVERITY_' . strtoupper($check->getSeverity())) ?>
				</small>
			<?php endif ?>
		</h4>

		<div class="d-flex flex-wrap gap-2 ms-lg-auto flex-shrink-0">
			<?= implode('', $wholeFixes) ?>
			<?= $buttonLayout->render([
				'task'    => $ignored ? 'sitehealthcheck.unignore' : 'sitehealthcheck.ignore',
				'checkId' => $check->getId(),
				'label'   => Text::_($ignored ? 'COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_UNIGNORE' : 'COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_IGNORE'),
				'style'   => 'outline-secondary',
				'icon'    => $ignored ? 'fa fa-eye' : 'fa fa-eye-slash',
			]) ?>
		</div>
	</div>

	<?php if ($result->getMessage() !== ''): ?>
		<div class="mb-2"><?= $result->getMessage() ?></div>
	<?php endif ?>

	<?php if (!empty($items)): ?>
		<ul class="list-unstyled border-start ps-3 mb-2">
			<?php foreach ($items as $item): ?>
				<li class="mb-2">
					<div class="d-flex flex-wrap align-items-center gap-2">
						<span class="font-monospace text-break"><?= $e($item['label']) ?></span>
						<?php foreach ($item['fixes'] as $fixId): ?>
							<?= $renderFix($fixId, $item['key'], $item['label']) ?>
						<?php endforeach ?>
					</div>
					<?php if (($item['description'] ?? '') !== ''): ?>
						<div class="small text-muted"><?= $e($item['description']) ?></div>
					<?php endif ?>
				</li>
			<?php endforeach ?>
			<?php if ($moreItems > 0): ?>
				<li class="text-muted fst-italic">
					<?= Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_MORE_ITEMS', $moreItems) ?>
				</li>
			<?php endif ?>
		</ul>
	<?php endif ?>

	<?php if (!empty($links)): ?>
		<div class="d-flex flex-wrap gap-2"><?= implode('', $links) ?></div>
	<?php endif ?>

	<?php if ($check->getDescription() !== ''): ?>
		<?php // Shown and hidden by Joomla's Toggle Inline Help toolbar button. ?>
		<div id="<?= $e($elementId) ?>-desc" class="hide-aware-inline-help d-none small text-muted mt-2">
			<?= $check->getDescription() ?>
		</div>
	<?php endif ?>
</div>
