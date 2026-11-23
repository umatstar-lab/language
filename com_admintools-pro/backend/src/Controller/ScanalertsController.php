<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerCustomACLTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerEventsTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerRegisterTasksTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ControllerReusableModelsTrait;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Filesystem;
use Akeeba\Component\AdminTools\Administrator\Model\ScanalertsModel;
use Akeeba\Component\AdminTools\Administrator\Table\ScanTable;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\RawDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;
use RuntimeException;

class ScanalertsController extends AdminController
{
	use ControllerEventsTrait;
	use ControllerCustomACLTrait;
	use ControllerRegisterTasksTrait;
	use ControllerReusableModelsTrait;

	protected $text_prefix = 'COM_ADMINTOOLS_SCANALERTS';

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?CMSApplication $app = null, ?Input $input = null)
	{
		parent::__construct($config, $factory, $app, $input);

		$this->registerControllerTasks('main');
	}

	public function main()
	{
		/** @var ScanTable $scan */
		$scan    = $this->getModel('Scan', 'Administrator')->getTable('Scan', 'Administrator');
		$scan_id = $this->app->getUserStateFromRequest('com_admintools.scanalerts.scan_id', 'scan_id', 0, 'int');

		if (empty($scan_id) || !$scan->load($scan_id))
		{
			throw new RuntimeException(Text::sprintf('COM_ADMINTOOLS_TITLE_SCANALERT_ERR_NO_SUCH_SCAN', (int) $scan_id));
		}

		$view       = $this->getView();
		$view->scan = $scan;

		$this->display(false);
	}

	public function getModel($name = 'Scanalert', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}

	public function markallsafe()
	{
		$this->checkToken('get');

		/**
		 * Deliberately `admintools.security`, NOT the Joomla-standard `core.edit.state` this used to check.
		 * Marking every alert on a scan "safe" nullifies a security control (it silences future breach
		 * notifications for those files), so only Super Users and users explicitly delegated
		 * `admintools.security` may do it. A plain Manager who merely has `core.edit.state` on this
		 * component must NOT be able to silently mark a malicious file safe.
		 */
		$user = $this->app->getIdentity();

		if (!$user->authorise('admintools.security', 'com_admintools'))
		{
			throw new RuntimeException('JERROR_ALERTNOAUTHOR', 403);
		}

		$scan_id = $this->input->getInt('scan_id', 0);

		if (!empty($scan_id))
		{
			/** @var ScanalertsModel $model */
			$model = $this->getModel('Scanalerts', 'Administrator', ['ignore_request' => true]);
			$model->markAllSafe($scan_id);
		}

		$default = 'index.php?option=com_admintools&view=Scanalerts&scan_id=' . $scan_id;
		$encoded = $this->input->getBase64('returnurl', '');
		$url     = empty($encoded) ? $default : base64_decode($encoded);

		$this->setRedirect((($url !== '') && Uri::isInternal($url)) ? $url : $default);
	}

	/**
	 * Export the entire scan report as a CSV file.
	 *
	 * The search and filter options currently applied to the scan report are ignored on purpose.
	 *
	 * @return  void
	 * @since   5.0.0
	 */
	public function exportcsv()
	{
		$scan_id = $this->assertCanExport();
		$items   = $this->getExportItems($scan_id, false);

		$this->setUpDownload('text/csv', sprintf('scan_results_%d.csv', $scan_id));

		$this->outputCSV($items);
	}

	/**
	 * Export the currently filtered scan report as a CSV file.
	 *
	 * Unlike exportcsv(), this method respects the search and filter options currently applied to the scan report.
	 *
	 * @return  void
	 * @since   7.9.1
	 */
	public function exportfilteredcsv()
	{
		$scan_id = $this->assertCanExport();
		$items   = $this->getExportItems($scan_id, true);

		$this->setUpDownload('text/csv', sprintf('scan_results_filtered_%d.csv', $scan_id));

		$this->outputCSV($items);
	}

	/**
	 * Export the file paths of the currently filtered scan report as a plain text file.
	 *
	 * The generated file has one site-root-relative file path per line, using UNIX (LF) line endings, so that it can be
	 * fed directly to command line tooling.
	 *
	 * @return  void
	 * @since   7.9.1
	 */
	public function exportpaths()
	{
		$scan_id = $this->assertCanExport();
		$items   = $this->getExportItems($scan_id, true);

		$this->setUpDownload('text/plain', sprintf('scan_paths_%d.txt', $scan_id));

		$this->outputPaths($items);
	}

	/**
	 * Make sure the current user can export the currently selected scan report.
	 *
	 * @return  int  The ID of the scan whose report will be exported.
	 * @since   7.9.1
	 */
	private function assertCanExport(): int
	{
		$this->checkToken('get');

		$user = $this->app->getIdentity();

		if ((!$user->authorise('admintools.security', 'com_admintools')) || $this->input->getCmd('format', 'html') !== 'raw')
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		/** @var ScanTable $scan */
		$scan    = $this->getModel('Scan', 'Administrator')->getTable('Scan', 'Administrator');
		$scan_id = $this->app->getUserStateFromRequest('com_admintools.scanalerts.scan_id', 'scan_id', 0, 'int');

		if (empty($scan_id) || !$scan->load($scan_id))
		{
			throw new RuntimeException(Text::sprintf('COM_ADMINTOOLS_TITLE_SCANALERT_ERR_NO_SUCH_SCAN', (int) $scan_id));
		}

		return (int) $scan_id;
	}

	/**
	 * Get all scan report items which will take part in an export.
	 *
	 * @param   int   $scan_id        The ID of the scan whose report will be exported.
	 * @param   bool  $applyFilters   Should I apply the search and filter options currently applied to the report?
	 *
	 * @return  object[]
	 * @since   7.9.1
	 */
	private function getExportItems(int $scan_id, bool $applyFilters): array
	{
		/** @var ScanalertsModel $model */
		$model = $this->getModel('Scanalerts', 'Administrator', ['ignore_request' => true]);

		/**
		 * Getting the state object forces the model to populate its state from the session and the request. We can then
		 * modify the state in place. Using setState() would not work: the state is populated lazily, the first time the
		 * model reads it, which would overwrite whatever we had set beforehand.
		 */
		$state = $model->getState();

		// Always export the report of the scan we were asked about, in its entirety (no pagination).
		$state->set('filter.scan_id', $scan_id);
		$state->set('list.limit', 0);
		$state->set('list.start', 0);

		if (!$applyFilters)
		{
			// We were asked for the complete report. Remove every filter the model picked up from the session.
			foreach (ScanalertsModel::EXPORTABLE_FILTERS as $name)
			{
				$state->set('filter.' . $name, '');
			}
		}
		elseif ($this->input->exists('filter'))
		{
			/**
			 * We were given an explicit, complete set of filters in the request — this is what the toolbar's export
			 * links do. Apply exactly these, so that the exported file always matches the report the user was looking
			 * at, instead of whatever the session happens to hold when the export request is served.
			 */
			$filters = $this->input->get('filter', [], 'array');

			foreach (ScanalertsModel::EXPORTABLE_FILTERS as $name)
			{
				$value = $filters[$name] ?? '';

				$state->set('filter.' . $name, is_scalar($value) ? (string) $value : '');
			}
		}

		return $model->getItems() ?: [];
	}

	/**
	 * Send the HTTP headers which make the browser download the generated output as a file.
	 *
	 * @param   string  $mimeType  The MIME type of the generated file.
	 * @param   string  $fileName  The name of the generated file.
	 *
	 * @return  void
	 * @since   7.9.1
	 */
	private function setUpDownload(string $mimeType, string $fileName): void
	{
		/** @var RawDocument $document */
		$document = $this->app->getDocument();
		$document->setMimeEncoding($mimeType);
		$this->app->setHeader('Expires', '0');
		/**
		 * This construct is required to work around bad quality hosts who blacklist files based on broken malware
		 * scanners. The only way to beat them is... wait for it... write our software using the same obscure constructs
		 * actual malware is using to evade these broken malware scanners. The irony is not lost on me.
		 */
		$xo   = substr("revenge", 0, 3);
		$xoxo = substr("calibrate", 1, 2);
		$this->app->setHeader('Cache-Control', 'must-' . $xo . $xoxo . 'idate, post-check=0, pre-check=0');

		$this->app->setHeader('Cache-Control', 'public', false);
		$this->app->setHeader('Content-Description', 'File Transfer');
		$this->app->setHeader('Content-Disposition', sprintf('attachment; filename="%s"', $fileName));
		$this->app->setHeader('X-Content-Type-Options', 'nosniff');
	}

	/**
	 * Output the scan report items as CSV data.
	 *
	 * @param   object[]  $items  The scan report items to export.
	 *
	 * @return  void
	 * @since   7.9.1
	 */
	private function outputCSV(array $items): void
	{
		/**
		 * The first four columns are in this exact order for backwards compatibility with the CSV files generated by
		 * Admin Tools 7.8 and earlier. Any new column MUST be appended at the end of the list.
		 */
		$columns = ['path', 'filestatus', 'threatindex', 'acknowledged', 'flag', 'threat_score'];

		echo $this->toCSVLine($columns);

		foreach ($items as $item)
		{
			echo $this->toCSVLine(
				array_map(
					function (string $col) use ($item) {
						switch ($col)
						{
							// The raw value is sorting-prefixed, e.g. '0-suspicious'. Strip the prefix.
							case 'filestatus':
								return substr($item->filestatus, 2);

							// Files outside the scope of the CoreSums checksums have a NULL flag.
							case 'flag':
								return $item->flag ?: 'outofscope';

							// The file's real name, whatever bytes it is made of; the database may hold it encoded.
							case 'path':
								return Filesystem::decodePath((string) $item->path) ?? $item->path;

							default:
								return $item->{$col};
						}
					},
					$columns
				)
			);
		}
	}

	/**
	 * Output the file paths of the scan report items, one per line.
	 *
	 * UNIX (LF) line endings are used on purpose. This file is meant to be consumed by command line tooling, e.g. a
	 * Bash `while read` loop, which would otherwise choke on the trailing carriage returns.
	 *
	 * @param   object[]  $items  The scan report items to export.
	 *
	 * @return  void
	 * @since   7.9.1
	 */
	private function outputPaths(array $items): void
	{
		foreach ($items as $item)
		{
			// The file's real name, so that tooling can act on it; the database may hold it encoded.
			echo (Filesystem::decodePath((string) $item->path) ?? $item->path) . "\n";
		}
	}

	/**
	 * Convert an array of values into a single, RFC 4180 compliant CSV line.
	 *
	 * @param   array  $row  The values to convert.
	 *
	 * @return  string
	 * @since   7.9.1
	 */
	private function toCSVLine(array $row): string
	{
		return implode(
				',',
				array_map(
					function ($v) {
						return sprintf('"%s"', str_replace('"', '""', $this->neutraliseCSVFormula((string) ($v ?? ''))));
					},
					$row
				)
			) . "\r\n";
	}

	/**
	 * Defeat CSV/spreadsheet formula injection (OWASP "CSV Injection") by prefixing a cell whose first
	 * character would be interpreted as a formula trigger by Excel, LibreOffice Calc, and similar software.
	 *
	 * @param   string  $value  The raw cell value, before quote-doubling and quoting.
	 *
	 * @return  string  The value, prefixed with a single quote if it starts with a formula-triggering character.
	 * @since   7.9.3
	 */
	private function neutraliseCSVFormula(string $value): string
	{
		if ($value !== '' && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true))
		{
			return "'" . $value;
		}

		return $value;
	}

	protected function getRedirectToListAppend()
	{
		$scan_id = $this->app->getUserStateFromRequest('com_admintools.scanalerts.scan_id', 'scan_id', 0, 'int');

		return parent::getRedirectToListAppend() . '&scan_id=' . $scan_id;
	}
}