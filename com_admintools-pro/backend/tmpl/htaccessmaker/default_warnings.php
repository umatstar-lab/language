<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;

/**
 * Misconfigurations of the saved settings, shared by the .htaccess, NginX Conf and web.config Makers.
 *
 * @var $this \Akeeba\Component\AdminTools\Administrator\View\Htaccessmaker\HtmlView
 */
$warnings = $this->configWarnings;
?>
<?php if (!empty($warnings['restrictipEmpty'])): ?>
<div class="alert alert-danger mb-3" id="admintools-servertech-restrictip-empty">
	<h3 class="alert-heading">
		<?= Text::_('COM_ADMINTOOLS_HTACCESSMAKER_ERR_RESTRICTIP_EMPTY_HEAD') ?>
	</h3>
	<p>
		<?= Text::_('COM_ADMINTOOLS_HTACCESSMAKER_ERR_RESTRICTIP_EMPTY') ?>
	</p>
</div>
<?php endif; ?>
<?php // NginX Conf Maker only, see NginxconfmakerModel::getConfigurationWarnings() ?>
<?php if (!empty($warnings['restrictipRealIp'])): ?>
<div class="alert alert-warning text-dark mb-3" id="admintools-servertech-restrictip-realip">
	<h3 class="alert-heading">
		<?= Text::_('COM_ADMINTOOLS_NGINXCONFMAKER_WARN_RESTRICTIP_REALIP_HEAD') ?>
	</h3>
	<p>
		<?= Text::_('COM_ADMINTOOLS_NGINXCONFMAKER_WARN_RESTRICTIP_REALIP') ?>
	</p>
</div>
<?php endif; ?>
<?php if (!empty($warnings['skippedUserAgents'])): ?>
<div class="alert alert-warning text-dark mb-3" id="admintools-servertech-skipped-useragents">
	<h3 class="alert-heading">
		<?= Text::_('COM_ADMINTOOLS_HTACCESSMAKER_WARN_SKIPPED_USERAGENTS_HEAD') ?>
	</h3>
	<p>
		<?= Text::_('COM_ADMINTOOLS_HTACCESSMAKER_WARN_SKIPPED_USERAGENTS') ?>
	</p>
	<ul class="mb-0">
		<?php foreach ($warnings['skippedUserAgents'] as $agent): ?>
		<?php // Control characters, which are invisible, as \xNN ?>
		<li><code><?= $this->escape(preg_replace_callback('/[\x00-\x1F\x7F]/', function (array $m): string {
			return sprintf('\\x%02X', ord($m[0]));
		}, $agent)) ?></code></li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>
