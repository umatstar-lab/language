<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;

/**
 * What the Ignore, Block and Do not block options of the crawler settings mean. Shown once, right after the
 * "Use crawler IP lists" switch.
 */
$escape = function ($x): string {
	return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
};
$options = [
	'COM_ADMINTOOLS_CONFIGUREWAF_CRAWLERIPS_OPT_IGNORE' => 'COM_ADMINTOOLS_CRAWLERIPS_HELP_IGNORE',
	'COM_ADMINTOOLS_CONFIGUREWAF_CRAWLERIPS_OPT_BLOCK'  => 'COM_ADMINTOOLS_CRAWLERIPS_HELP_BLOCK',
	'COM_ADMINTOOLS_CONFIGUREWAF_CRAWLERIPS_OPT_ALLOW'  => 'COM_ADMINTOOLS_CRAWLERIPS_HELP_ALLOW',
];
?>
<div class="alert alert-info">
	<details>
		<summary class="fw-bold">
			<?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_HELP_SUMMARY')) ?>
		</summary>
		<dl class="mt-3 mb-2">
			<?php foreach ($options as $term => $definition): ?>
				<dt><?= $escape(Text::_($term)) ?></dt>
				<dd><?= $escape(Text::_($definition)) ?></dd>
			<?php endforeach; ?>
		</dl>
		<p class="mb-0"><?= $escape(Text::_('COM_ADMINTOOLS_CRAWLERIPS_HELP_DOWNLOADS')) ?></p>
	</details>
</div>
