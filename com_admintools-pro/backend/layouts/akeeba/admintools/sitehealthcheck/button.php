<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die();

use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;

/**
 * A Site Health Check action button: a one-click fix, or ignoring a check.
 *
 * The page's JavaScript submits the adminForm with these values when it is clicked, after asking for confirmation if
 * there is a confirmation question.
 *
 * @var array       $displayData  Incoming display data. These set the following variables.
 * @var string      $task         Controller task, e.g. sitehealthcheck.fix
 * @var string      $checkId      The check ID
 * @var string      $fixId        The fix ID; empty for other tasks
 * @var string|null $itemKey      The item key, for per-item fixes. The button carries the item's token instead
 *                                (CheckResult::itemToken()): a key is often a name found on disk, which would not
 *                                survive the attribute and the request's input filtering unchanged.
 * @var string      $label        Button label (already translated, plain text)
 * @var string      $confirm      Confirmation question (already translated, plain text); empty for none
 * @var string      $style        Bootstrap button style, e.g. primary, danger, outline-secondary
 * @var string      $icon         FontAwesome icon class
 * @var bool        $reload       Submit the page (full reload) instead of acting in place?
 */

extract(array_merge([
	'task'    => '',
	'checkId' => '',
	'fixId'   => '',
	'itemKey' => null,
	'label'   => '',
	'confirm' => '',
	'style'   => 'primary',
	'icon'    => 'fa fa-wrench',
	'reload'  => false,
], $displayData));

$e = function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>
<button type="button" class="btn btn-sm btn-<?= $e($style) ?>"
		data-shc-task="<?= $e($task) ?>"
		data-shc-check="<?= $e($checkId) ?>"
		data-shc-fix="<?= $e($fixId) ?>"
		data-shc-item="<?= $itemKey === null ? '' : $e(CheckResult::itemToken($itemKey)) ?>"
		data-shc-confirm="<?= $e($confirm) ?>"
		<?= $reload ? 'data-shc-reload' : '' ?>>
	<span class="<?= $e($icon) ?>" aria-hidden="true"></span>
	<?= $e($label) ?>
</button>
