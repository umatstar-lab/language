<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die();

use Akeeba\Component\AdminTools\Administrator\Helper\Layouts;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Runner;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Section;

/**
 * The Site Health Check results, sent to the page by the results JSON task.
 *
 * Passed and ignored checks are marked data-shc-secondary (see Runner::isSecondary()); the page's JavaScript shows them
 * on request. A section whose checks are all secondary is marked the same way.
 *
 * @var array                   $displayData  Incoming display data. These set the following variables.
 * @var Section[]               $sections     The sections, in display order
 * @var array[]                 $entries      The Runner::run() output
 * @var array                   $summary      The Runner::summarise() output
 * @var \Joomla\CMS\User\User   $user         The current user, to decide which fixes to offer
 */

extract(array_merge([
	'sections' => [],
	'entries'  => [],
	'summary'  => [],
	'user'     => null,
], $displayData));

$e = function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$isSecondary = [Runner::class, 'isSecondary'];

$checkLayout = Layouts::get('akeeba.admintools.sitehealthcheck.check');

echo Layouts::render('akeeba.admintools.sitehealthcheck.summary', ['summary' => $summary, 'entries' => $entries]);

?>
<?php foreach ($sections as $section): ?>
	<?php
	$sectionEntries = array_filter($entries, function (array $entry) use ($section) {
		return $entry['check']->getSection() === $section->getId();
	});

	if (empty($sectionEntries))
	{
		continue;
	}

	$allSecondary = count(array_filter($sectionEntries, $isSecondary)) === count($sectionEntries);
	?>
	<div class="card mb-3" data-shc-section="<?= $e($section->getId()) ?>" <?= $allSecondary ? 'data-shc-secondary' : '' ?>>
		<h3 class="card-header">
			<?= $e($section->getTitle()) ?>
		</h3>
		<div class="card-body">
			<?php if ($section->getDescription() !== ''): ?>
				<p class="text-muted"><?= $section->getDescription() ?></p>
			<?php endif ?>

			<?php foreach ($sectionEntries as $entry): ?>
				<?= $checkLayout->render([
					'entry'     => $entry,
					'user'      => $user,
					'secondary' => $isSecondary($entry),
				]) ?>
			<?php endforeach ?>
		</div>
	</div>
<?php endforeach ?>
