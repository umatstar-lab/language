<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var  \Akeeba\Component\AdminTools\Administrator\View\Controlpanel\HtmlView $this */

$showGraphs = $this->isPro && $this->showstats;
?>

<div class="card mb-3">
	<h3 class="card-header bg-primary text-white">
		<?=Text::_('COM_ADMINTOOLS_CONTROLPANEL_LBL_SECURITY'); ?>
	</h3>

	<div class="akeeba-cpanel-container card-body d-flex flex-row flex-wrap align-items-stretch">

		<?php if (ADMINTOOLS_PRO && !$showGraphs): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-success border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Controlpanel&task=unblockme'); ?>"
			   id="selfBlocked"
			>
				<div class="bg-success text-white d-block text-center p-3 h2">
					<span class="fa fa-unlock-alt" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_CONTROLPANEL_LBL_UNBLOCK_ME'); ?>
				</span>
			</a>
		<?php endif ?>

		<?php if(ADMINTOOLS_PRO && $this->needsQuickSetup && $this->canAccessView('Quickstart')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-warning text-dark border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Quickstart') ?>">
				<div class="bg-warning d-block text-center p-3 h2">
					<span class="fa fa-bolt" aria-hidden="true"></span>
				</div>
				<span>
				<?= Text::_('COM_ADMINTOOLS_TITLE_QUICKSTART') ?>
			</span>
			</a>
		<?php endif; ?>

		<?php if ($this->htMakerSupported && $this->canAccessView('Emergencyoffline')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-danger border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Emergencyoffline') ?>">
				<div class="bg-danger text-white d-block text-center p-3 h2">
					<span class="fa fa-power-off" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_TITLE_EMERGENCYOFFLINE') ?>
				</span>
			</a>
		<?php endif; ?>

		<?php if ($this->isPro && $this->canAccessView('Sitehealthcheck')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-primary border-0 position-relative" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Sitehealthcheck') ?>">
				<div class="bg-primary text-white d-block text-center p-3 h2">
					<span class="fa fa-heartbeat" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_TITLE_SITEHEALTHCHECK') ?>
				</span>
				<span id="admintoolsSiteHealthCheckBadge"
					  class="position-absolute top-0 end-0 badge rounded-pill d-none"
					  title="<?= $this->escape(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_BADGE')) ?>"></span>
			</a>
		<?php endif; ?>

		<?php if ($this->canAccessView('Mainpassword')): ?>
		<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-warning text-dark border-0" style="width: 10em"
		   href="<?= Route::_('index.php?option=com_admintools&view=Mainpassword') ?>">
			<div class="bg-warning d-block text-center p-3 h2">
				<span class="fa fa-lock" aria-hidden="true"></span>
			</div>
			<span>
				<?= Text::_('COM_ADMINTOOLS_TITLE_MAINPASSWORD') ?>
			</span>
		</a>
		<?php endif; ?>

		<?php if ($this->htMakerSupported && $this->canAccessView('Adminpassword')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-warning text-dark border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Adminpassword') ?>">
				<div class="bg-warning d-block text-center p-3 h2">
					<span class="fa fa-<?= $this->adminLocked ? 'lock' : 'lock-open' ?>" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_TITLE_ADMINPASSWORD') ?>
				</span>
			</a>
		<?php endif; ?>

		<?php if($this->isPro): ?>
			<?php if($this->htMakerSupported && $this->canAccessView('Htaccessmaker')): ?>
				<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-primary border-0" style="width: 10em"
				   href="<?= Route::_('index.php?option=com_admintools&view=Htaccessmaker') ?>">
					<div class="bg-primary text-white d-block text-center p-3 h2">
						<span class="fa fa-file-alt" aria-hidden="true"></span>
					</div>
					<span>
						<?= Text::_('COM_ADMINTOOLS_TITLE_HTACCESSMAKER') ?>
					</span>
				</a>
			<?php endif; ?>

			<?php if($this->nginxMakerSupported && $this->canAccessView('Nginxconfmaker')): ?>
				<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-primary border-0" style="width: 10em"
				   href="<?= Route::_('index.php?option=com_admintools&view=Nginxconfmaker') ?>">
					<div class="bg-primary text-white d-block text-center p-3 h2">
						<span class="fa fa-file-alt" aria-hidden="true"></span>
					</div>
					<span>
						<?= Text::_('COM_ADMINTOOLS_TITLE_NGINXCONFMAKER') ?>
					</span>
				</a>
			<?php endif; ?>

			<?php if($this->webConfMakerSupported && $this->canAccessView('Webconfigmaker')): ?>
				<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-primary border-0" style="width: 10em"
				   href="<?= Route::_('index.php?option=com_admintools&view=Webconfigmaker') ?>">
					<div class="bg-primary text-white d-block text-center p-3 h2">
						<span class="fa fa-file-alt" aria-hidden="true"></span>
					</div>
					<span>
						<?= Text::_('COM_ADMINTOOLS_TITLE_WEBCONFIGMAKER') ?>
					</span>
				</a>
			<?php endif; ?>

			<?php if($this->canAccessView('Webapplicationfirewall')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-dark border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Webapplicationfirewall') ?>">
				<div class="bg-dark text-white d-block text-center p-3 h2">
					<span class="fa fa-times-circle" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_TITLE_WAF') ?>
				</span>
			</a>
			<?php endif; ?>

			<?php if($this->canAccessView('Scans')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-dark border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Scans') ?>">
				<div class="bg-dark text-white d-block text-center p-3 h2">
					<span class="fa fa-search" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_TITLE_SCANS') ?>
				</span>
			</a>
			<?php endif; ?>

			<?php if($this->canAccessView('Schedulinginformation')): ?>
			<a class="akeeba-cpanel-button text-center align-self-stretch btn btn-outline-dark border-0" style="width: 10em"
			   href="<?= Route::_('index.php?option=com_admintools&view=Schedulinginformation') ?>">
				<div class="bg-dark text-white d-block text-center p-3 h2">
					<span class="fa fa-calendar-alt" aria-hidden="true"></span>
				</div>
				<span>
					<?= Text::_('COM_ADMINTOOLS_TITLE_SCHEDULINGINFORMATION') ?>
				</span>
			</a>
			<?php endif; ?>

		<?php endif; ?>
	</div>
</div>
