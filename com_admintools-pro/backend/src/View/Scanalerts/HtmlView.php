<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\View\Scanalerts;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Mixin\ViewListLimitFixTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ViewLoadAnyTemplateTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ViewTableUITrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ViewTaskBasedEventsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ViewToolbarTrait;
use Akeeba\Component\AdminTools\Administrator\Model\ScanalertsModel;
use Akeeba\Component\AdminTools\Administrator\Table\ScanTable;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Button\DropdownButton;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Registry\Registry;

class HtmlView extends BaseHtmlView
{
	use ViewLoadAnyTemplateTrait;
	use ViewTaskBasedEventsTrait;
	use ViewTableUITrait;
	use ViewListLimitFixTrait;
	use ViewToolbarTrait;

	/**
	 * @var   ScanTable
	 * @since 7.0.0
	 */
	public $scan;

	/**
	 * The search tools form
	 *
	 * @var    Form
	 * @since  7.0.0
	 */
	public $filterForm;

	/**
	 * The active search filters
	 *
	 * @var    array
	 * @since  7.0.0
	 */
	public $activeFilters = [];

	/**
	 * An array of items
	 *
	 * @var    array
	 * @since  7.0.0
	 */
	protected $items = [];

	/**
	 * The pagination object
	 *
	 * @var    Pagination
	 * @since  7.0.0
	 */
	protected $pagination;

	/**
	 * The model state
	 *
	 * @var    Registry
	 * @since  7.0.0
	 */
	protected $state;

	/**
	 * Is this view an Empty State
	 *
	 * @var   boolean
	 * @since 7.0.0
	 */
	private $isEmptyState = false;

	public function display($tpl = null)
	{
		/** @var ScanalertsModel $model */
		$model               = $this->getModel();

		if ($this->getLayout() === 'print')
		{
			/**
			 * Okay, this is weird. If I do $model->setState('list.start', 0) — or the same with list.limit — the state
			 * does not change. This might be a PHP issue since the code is dead simple and doesn't seem off. In any
			 * case, I really need to set this two values. Oh, well, let's do it like Deadpool. MAXIMUM EFFORT!
			 */
			$wtf = $model->getState();

			$wtf->set('list.start', 0);
			$wtf->set('list.limit', 0);
		}

		// WHY DOES THE FIRST FORM NOT WORK?!!!!!!
		// $model->setState('filter.scan_id', $this->scan->id);
		$model->getState()->set('filter.scan_id', $this->scan->id);

		$this->fixListLimitPastTotal($model);
		$this->items         = $model->getItems();
		$this->pagination    = $model->getPagination();
		$this->state         = $model->getState();
		$this->filterForm    = $model->getFilterForm();
		$this->activeFilters = $model->getActiveFilters();

		// Check for errors.
		if (method_exists($this->getModel(), 'getErrors'))
		{
			/** @noinspection PhpDeprecationInspection */
			$errors = $this->getModel()->getErrors();

			if (is_countable($errors) && count($errors))
			{
				throw new GenericDataException(implode("\n", $errors), 500);
			}
		}

		$this->addToolbar();

		parent::display($tpl);
	}

	private function addToolbar()
	{
		$user = Factory::getApplication()->getIdentity();

		// Get the toolbar object instance
		$toolbar = $this->getToolbarCompat();

		ToolbarHelper::title(Text::sprintf('COM_ADMINTOOLS_TITLE_SCANALERTS', $this->scan->id), 'icon-admintools');

		$canEditState = $user->authorise('core.edit.state', 'com_admintools');

		if ($canEditState)
		{
			/** @var DropdownButton $dropdown */
			$dropdown = $toolbar->dropdownButton('status-group')
				->text('JTOOLBAR_CHANGE_STATUS')
				->toggleSplit(false)
				->icon('icon-ellipsis-h')
				->buttonClass('btn btn-action')
				->listCheck(true);

			$childBar = $dropdown->getChildToolbar();

			if ($canEditState)
			{
				$childBar->publish('scanalerts.publish')
					->icon('fa fa-check-circle')
					->text('COM_ADMINTOOLS_SCANALERTS_LBL_MARKSAFE')
					->listCheck(true);

				$childBar->unpublish('scanalerts.unpublish')
					->icon('fa fa-times-circle')
					->text('COM_ADMINTOOLS_SCANALERTS_LBL_MARKUNSAFE')
					->listCheck(true);

			}
		}

		// Marking every alert on a scan "safe" nullifies a security control, so this needs admintools.security,
		// not the weaker core.edit.state that merely toggles an individual row's published state above.
		if ($user->authorise('admintools.security', 'com_admintools'))
		{
			$markAllSafeLink = 'index.php?option=com_admintools&view=Scanalerts&task=markallsafe&scan_id=' . $this->scan->id
				. '&' . Session::getFormToken() . '=1';
			$toolbar->link(Text::_('COM_ADMINTOOLS_SCANALERTS_LBL_MARKALLSAFE'), $markAllSafeLink)
				->icon('fa fa-check-double');
		}

		$toolbar
			->link('COM_ADMINTOOLS_SCANALERTS_LBL_PRINT', 'index.php?option=com_admintools&view=Scanalerts&tmpl=component&layout=print&scan_id=' . $this->scan->id)
			->icon('fa fa-print');

		$exportRoot = 'index.php?option=com_admintools&view=Scanalerts&format=raw&scan_id=' . $this->scan->id
			. '&' . Session::getFormToken() . '=1&task=';

		/**
		 * The filtered exports carry the currently active filters in their URL instead of relying on them still being
		 * in the session by the time the export request is served. This way the exported file is guaranteed to match
		 * the report you are looking at, and the export link remains meaningful if you bookmark or share it.
		 */
		$filterQuery = $this->getActiveFilterQuery();

		/**
		 * A split button: the first child button is rendered as the main button, the rest as dropdown items.
		 *
		 * @var DropdownButton $exportDropdown
		 */
		$exportDropdown = $toolbar->dropdownButton('export-group')
			->toggleSplit(true);

		$exportBar = $exportDropdown->getChildToolbar();

		$exportBar->linkButton('exportcsv', 'COM_ADMINTOOLS_SCANALERTS_LBL_CSV')
			->url($exportRoot . 'exportcsv')
			->icon('fa fa-file-csv')
			->buttonClass('btn btn-primary');

		$exportBar->linkButton('exportfilteredcsv', 'COM_ADMINTOOLS_SCANALERTS_LBL_CSV_FILTERED')
			->url($exportRoot . 'exportfilteredcsv' . $filterQuery)
			->icon('fa fa-filter');

		$exportBar->linkButton('exportpaths', 'COM_ADMINTOOLS_SCANALERTS_LBL_EXPORT_PATHS')
			->url($exportRoot . 'exportpaths' . $filterQuery)
			->icon('fa fa-file-alt');

		ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_admintools&view=Scans');

		ToolbarHelper::help(null, false, 'https://www.akeeba.com/documentation/admin-tools-joomla/php-file-scanner-scan.html');
	}

	/**
	 * Get the currently active search and filter options as a URL query string fragment.
	 *
	 * The returned string always contains every filter, including the ones which are currently unset. This is
	 * deliberate: it tells the export tasks that they are looking at an explicit, complete set of filters, instead of
	 * having to guess whether an absent filter means "unset" or "not specified, use whatever is in the session".
	 *
	 * @return  string  A query string fragment, starting with an ampersand.
	 * @since   7.9.1
	 */
	private function getActiveFilterQuery(): string
	{
		$filters = [];

		foreach (ScanalertsModel::EXPORTABLE_FILTERS as $name)
		{
			$value = $this->state->get('filter.' . $name);

			$filters[$name] = is_scalar($value) ? (string) $value : '';
		}

		return '&' . http_build_query(['filter' => $filters]);
	}
}
