<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Scanner;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\IgnorableItemsInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Scanner\Collector;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Store;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Util\SiteFilesystem;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

/**
 * Base for checks reporting what the PHP File Change Scanner collected during its last complete run.
 *
 * Items which no longer exist (e.g. the user deleted them by hand) are left out, and so are the ones the user ignores.
 * Every other item is reported, whatever its name or location. When $deletable is set, items get a one-click delete
 * (folders are deleted recursively), except those which cannot be deleted safely, e.g. ones inside a symbolically
 * linked folder leading out of the site's root.
 *
 * The stored items, and the result item keys, are encoded paths; see Collector::encodeItem().
 *
 * @since  7.10.0
 */
abstract class AbstractFindingsCheck extends BuiltinCheck implements IgnorableItemsInterface
{
	protected $section = 'scanner';

	/** @var string The Collector category */
	protected $category = '';

	/** @var bool Offer to delete each item? */
	protected $deletable = true;

	public function run(): CheckResult
	{
		$data = (new Store())->get(Store::KEY_SCAN);

		// No complete scan yet, or the last one was run by a version which did not collect this category.
		if (!is_array($data) || !isset($data['findings'])
		    || !in_array($this->category, (array) ($data['collects'] ?? []), true))
		{
			return CheckResult::notAvailable(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SCANNER_NODATA'))
				->withLink('index.php?option=com_admintools&view=Scans', Text::_('COM_ADMINTOOLS_TITLE_SCANS'));
		}

		return $this->buildResult($data);
	}

	/**
	 * The result for a complete scan's stored data which includes this check's category.
	 *
	 * @param   array  $data  The stored scan data (Store::KEY_SCAN)
	 *
	 * @return  CheckResult
	 * @since   7.10.0
	 */
	protected function buildResult(array $data): CheckResult
	{
		$entry  = $data['findings'][$this->category] ?? ['total' => 0, 'items' => []];
		$stored = (array) ($entry['items'] ?? []);

		[$items, $ignored] = $this->buildItems($stored);

		// Items which are gone, no longer reported, or ignored are all among the stored ones, and in the stored total.
		$gone  = count($stored) - count($items);
		$total = max(count($items), (int) ($entry['total'] ?? 0) - $gone);

		// Items which were ignored when the scan found them were never stored, nor counted in the total.
		$ignored += $this->skippedIgnoredCount($entry, $ignored);

		if ($total === 0)
		{
			return self::withIgnoredItems(CheckResult::pass(), $ignored);
		}

		// The list is capped. Say so whenever it does not hold every item, not only when some items are gone.
		$listed   = array_slice($items, 0, static::MAX_ITEMS);
		$scanDate = HTMLHelper::_('date', (int) ($data['time'] ?? time()), Text::_('DATE_FORMAT_LC2'));

		return self::withIgnoredItems(
			CheckResult::fail(
				$this->text('MSG_FAIL', $total) . ' '
				. Text::sprintf('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_SCANNER_DATE', self::e($scanDate))
				. ($total > count($listed) ? ' ' . Text::sprintf('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_ITEMS_TRUNCATED', count($listed)) : ''),
				$listed,
				[],
				$total
			),
			$ignored
		);
	}

	/**
	 * How many items the scan left out because they were ignored (see Collector), and still are.
	 *
	 * Never more than the ignore list holds beyond the ignored items stored, so that un-ignoring takes effect at once.
	 *
	 * @param   array  $entry          The category's findings
	 * @param   int    $storedIgnored  Ignored items among the stored ones
	 *
	 * @return  int
	 * @since   7.10.0
	 */
	protected function skippedIgnoredCount(array $entry, int $storedIgnored): int
	{
		return max(0, min((int) ($entry['ignored'] ?? 0), count($this->ignoredItems) - $storedIgnored));
	}

	/**
	 * The result items for the stored items which still exist, and are neither left out by filterItems() nor ignored.
	 *
	 * @param   array        $stored  The stored items (encoded paths)
	 * @param   string|null  $root    The root the paths are relative to; NULL for the site's root (JPATH_ROOT).
	 *
	 * @return  array  [array[] $items, int $ignored]
	 * @since   7.10.0
	 */
	protected function buildItems(array $stored, ?string $root = null): array
	{
		$paths = [];

		foreach ($stored as $item)
		{
			$path = is_string($item) ? Collector::decodeItem($item) : null;

			if ($path !== null && SiteFilesystem::exists($path, $root))
			{
				$paths[] = $path;
			}
		}

		[$keys, $ignored] = $this->partitionIgnored(
			array_map([Collector::class, 'encodeItem'], $this->filterItems($paths))
		);

		$items = array_map(
			function (string $key) use ($root) {
				$path      = (string) Collector::decodeItem($key);
				$canDelete = $this->deletable && SiteFilesystem::canDelete($path, $root);

				return CheckResult::item(
					$key,
					Collector::displayPath($path),
					$this->itemFixes($canDelete ? ['delete'] : []),
					($this->deletable && !$canDelete) ? Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_NO_DELETE') : ''
				);
			},
			$keys
		);

		return [$items, count($ignored)];
	}

	/**
	 * Leave out stored items which should not be reported any more, e.g. because the rules changed since the scan. Items
	 * which no longer exist are already gone; ignored items are dealt with afterwards.
	 *
	 * @param   string[]  $items  Paths relative to the site's root (decoded; see Collector::decodeItem())
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function filterItems(array $items): array
	{
		return $items;
	}

	public function getFixes(): array
	{
		return $this->deletable ? [self::deleteFix()] : [];
	}

	public function canFix(string $fixId, User $user): bool
	{
		return $this->deletable && $fixId === 'delete' && self::canDeleteFiles($user);
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		if (!$this->deletable || $fixId !== 'delete')
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_FIX_NOT_SUPPORTED'));
		}

		// The Runner has verified that $itemKey is one of the items reported, and offered for deletion, just now.
		$path = Collector::decodeItem((string) $itemKey);

		if ($path === null)
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_INVALID_PATH'));
		}

		SiteFilesystem::delete($path);

		// The same entry may be listed by more than one check, e.g. a hidden copy of a file.
		foreach (Collector::CATEGORIES as $category)
		{
			Collector::removeStoredItem($category, $path);
		}

		return Text::sprintf('COM_ADMINTOOLS_SITEHEALTHCHECK_FIX_DELETE_DONE', Collector::displayPath($path));
	}
}
