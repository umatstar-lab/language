<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\View\Obsoletecore;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Model\ObsoletecoreModel;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

class HtmlView extends BaseHtmlView
{
	/**
	 * Do we have more processing to do?
	 *
	 * @var  bool
	 */
	public $more;

	/**
	 * Obsolete files and directories which exist but could not be removed.
	 *
	 * Each entry is ['type' => 'file'|'folder', 'path' => site root relative path].
	 *
	 * @var  array
	 */
	public $failures = [];

	public function display($tpl = null)
	{
		/** @var ObsoletecoreModel $model */
		$model = $this->getModel();

		$this->more     = !$model->getState('scanstate', false);
		$this->failures = $this->more ? [] : $model->getFailures();

		$this->setLayout('default');

		$this->getDocument()
			->getWebAssetManager()
			->useScript('com_admintools.obsolete_core');

		parent::display($tpl);
	}
}
