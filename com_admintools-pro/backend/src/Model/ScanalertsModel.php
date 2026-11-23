<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

#[\AllowDynamicProperties]
class ScanalertsModel extends ListModel
{
	/**
	 * The user-selectable filters of the scan report, i.e. the fields of the search tools form.
	 *
	 * The scan_id filter is deliberately not part of this list. It is not user-selectable; it is always set by whoever
	 * is asking for the report of a specific scan.
	 *
	 * @var   string[]
	 * @since 7.9.1
	 */
	public const EXPORTABLE_FILTERS = ['search', 'status', 'acknowledged', 'flag'];

	public function __construct($config = [], ?MVCFactoryInterface $factory = null)
	{
		$config['filter_fields'] = $config['filter_fields'] ?? [];
		$config['filter_fields'] = $config['filter_fields'] ?: [
			'search',
			'id',
			'path',
			'scan_id',
			'diff',
			'threat_score',
			'acknowledged',
			'status',
			'newfile',
			'suspicious',
			'filestatus',
			'flag',
		];

		parent::__construct($config, $factory);
	}

	/**
	 * Mark all entries of the specified scan as safe.
	 *
	 * Every alert of the scan is marked safe, whatever its threat score. Added, modified, and
	 * non-core files have a zero threat score when their contents are innocuous; they used to be
	 * left untouched, which made the button look as if it did nothing at all on the very reports it
	 * is most often used on. Since a file is only ever left out of the following scans when its
	 * latest alert is marked safe, this also meant these files kept coming back scan after scan, no
	 * matter how many times you clicked the button.
	 *
	 * @param   int  $scan_id  The ID of the scan
	 *
	 * @since   5.2.1
	 */
	public function markAllSafe($scan_id)
	{
		$scan_id = max(0, (int) $scan_id);

		if ($scan_id == 0)
		{
			return;
		}

		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->update($db->qn('#__admintools_scanalerts'))
			->set($db->qn('acknowledged') . ' = 1')
			->where($db->qn('scan_id') . ' = :scan_id')
			->bind(':scan_id', $scan_id, ParameterType::INTEGER);
		$db->setQuery($query)->execute();
	}

	protected function populateState($ordering = 'path', $direction = 'asc')
	{
		$app = Factory::getApplication();

		$search = $app->getUserStateFromRequest($this->context . 'filter.search', 'filter_search', '', 'string');
		$this->setState('filter.search', $search);

		$status = $app->getUserStateFromRequest($this->context . 'filter.status', 'filter_status', '', 'string');
		$this->setState('filter.status', $status);

		$scan_id = $app->getUserStateFromRequest($this->context . 'filter.scan_id', 'filter_scan_id', '', 'string');
		$this->setState('filter.scan_id', ($scan_id === '') ? $scan_id : (int) $scan_id);

		$acknowledged = $app->getUserStateFromRequest($this->context . 'filter.acknowledged', 'filter_acknowledged', '', 'string');
		$this->setState('filter.acknowledged', ($acknowledged === '') ? $acknowledged : (int) $acknowledged);

		$flag = $app->getUserStateFromRequest($this->context . 'filter.flag', 'filter_flag', '', 'string');
		$this->setState('filter.flag', $flag);

		parent::populateState($ordering, $direction);
	}

	protected function getStoreId($id = '')
	{
		$id .= ':' . $this->getState('filter.search');
		$id .= ':' . $this->getState('filter.scan_id');
		$id .= ':' . $this->getState('filter.status');
		$id .= ':' . $this->getState('filter.acknowledged');
		$id .= ':' . $this->getState('filter.flag');

		return parent::getStoreId($id);
	}

	protected function getListQuery()
	{
		$db = $this->getDatabase();

		$query = DbQuery::create($db)
			->select([
				$db->quoteName('a.id'),
				'CASE WHEN ' . $db->quoteName('diff') . ' = ' . $db->quote('') . ' AND ' . $db->quoteName('threat_score') . ' = 0 THEN 1 ELSE 0 END AS ' . $db->quoteName('newfile'),
				'CASE WHEN ' . $db->quoteName('diff') . ' LIKE ' . $db->q('###SUSPICIOUS FILE###%') . ' OR (' . $db->quoteName('diff') . ' = ' . $db->quote('') . ' AND ' . $db->quoteName('threat_score') . ' > 0) THEN 1 ELSE 0 END AS ' . $db->quoteName('suspicious'),
				'CASE WHEN ' . $db->quoteName('a.flag') . ' = ' . $db->quote('core') . ' THEN ' . $db->quote('3-invalidchecksum') . ' WHEN ' . $db->quoteName('diff') . ' = ' . $db->quote('') . ' AND ' . $db->quoteName('threat_score') . ' = 0 THEN ' . $db->quote('1-new') . ' WHEN ' . $db->quoteName('diff') . ' LIKE ' . $db->quote('###SUSPICIOUS FILE###%') . ' OR (' . $db->quoteName('diff') . ' = ' . $db->quote('') . ' AND ' . $db->quoteName('threat_score') . ' > 0) THEN ' . $db->quote('0-suspicious') . ' ELSE ' . $db->quote('2-modified') . ' END AS ' . $db->quoteName('filestatus'),

				'CASE WHEN ' . $db->quoteName('threat_score') . ' = 0 THEN ' . $db->quote('none') .
				'WHEN ' . $db->quoteName('threat_score') . ' < 10 THEN ' . $db->quote('low') .
				'WHEN ' . $db->quoteName('threat_score') . ' < 100 THEN ' . $db->quote('medium') .
				'ELSE ' . $db->quote('high') . ' END AS ' . $db->quoteName('threatindex'),
				$db->qn('path'),
				$db->qn('threat_score'),
				$db->qn('acknowledged'),
				$db->qn('scan_id'),
				$db->qn('s.scanstart', 'scandate'),
				$db->qn('a.flag'),
			])
			->from($db->quoteName('#__admintools_scanalerts', 'a'))
			->join('inner', $db->quoteName('#__admintools_scans', 's'),
				$db->quoteName('s.id') . ' = ' . $db->quoteName('a.scan_id')
			);

		$search = $this->getState('filter.search');

		if (!empty($search))
		{
			if (substr($search, 0, 3) === 'id:')
			{
				$id = (int) substr($search, 3);

				$query->where($db->quoteName('id') . ' = :id')
					->bind(':id', $id, ParameterType::INTEGER);
			}
			else
			{
				$search = '%' . $search . '%';

				$query->where($db->quoteName('path') . ' LIKE :search')
					->bind(':search', $search, ParameterType::STRING);
			}
		}

		$scan_id = $this->getState('filter.scan_id');

		if (is_numeric($scan_id))
		{
			$scan_id = (int) $scan_id;

			$query->where($db->quoteName('scan_id') . ' = :scan_id')
				->bind(':scan_id', $scan_id);
		}

		$status = $this->getState('filter.status');

		switch ($status)
		{
			case 'new':
				$query->where($db->qn('diff') . ' = ' . $db->q(''));
				$query->where($db->qn('threat_score') . ' = 0');
				break;

			case 'suspicious':
				$query->where('(' . $db->qn('diff') . ' LIKE ' . $db->q('###SUSPICIOUS FILE###%') .
					' OR (' . $db->qn('diff') . ' = ' . $db->q('') . ' AND ' . $db->qn('threat_score') . ' > 0))');
				break;

			case 'modified':
				$query->where($db->qn('diff') . ' != ' . $db->q(''));
				$query->where($db->qn('diff') . ' NOT LIKE ' . $db->q('###SUSPICIOUS FILE###%'));
				break;
		}

		$acknowledged = $this->getState('filter.acknowledged');

		if (is_numeric($acknowledged))
		{
			$acknowledged = (int) $acknowledged;

			$query->where($db->quoteName('acknowledged') . ' = :acknowledged')
				->bind(':acknowledged', $acknowledged, ParameterType::INTEGER);
		}

		$flag = $this->getState('filter.flag');

		switch ($flag)
		{
			case 'core':
				$query->where($db->qn('a.flag') . ' = ' . $db->q('core'));
				break;

			case 'noncore':
				$query->where($db->qn('a.flag') . ' = ' . $db->q('noncore'));
				break;

			case 'outofscope':
				$query->where($db->qn('a.flag') . ' IS NULL');
				break;
		}

		// List ordering clause
		$orderCol  = $this->state->get('list.ordering', 'path');
		$orderDirn = strtoupper($this->state->get('list.direction', 'asc')) === 'DESC' ? 'DESC' : 'ASC';

		$query->order($db->quoteName($orderCol) . ' ' . $orderDirn);

		return $query;
	}
}