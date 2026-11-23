<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die();

use Joomla\CMS\Router\Route;

/**
 * A Site Health Check link to the backend page where the user can deal with a problem.
 *
 * @var array  $displayData  Incoming display data. These set the following variables.
 * @var string $url          Non-SEF backend URL, e.g. index.php?option=com_config
 * @var string $label        Link text (already translated, plain text)
 * @var string $icon         FontAwesome icon class
 */

extract(array_merge([
	'url'   => 'index.php',
	'label' => '',
	'icon'  => 'fa fa-external-link-alt',
], $displayData));

$e = function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>
<a href="<?= $e(Route::_($url, false)) ?>" class="btn btn-sm btn-outline-primary">
	<span class="<?= $e($icon) ?>" aria-hidden="true"></span>
	<?= $e($label) ?>
</a>
