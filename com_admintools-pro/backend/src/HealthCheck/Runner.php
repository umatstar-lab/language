<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Event\CollectChecksEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;

/**
 * Collects, runs and caches the Site Health Check items, and keeps track of the ignored ones.
 *
 * @since  7.10.0
 */
final class Runner
{
	/** How long the results of expensive checks are cached, in seconds. */
	public const CACHE_TTL = 1800;

	/** @var Store */
	private $store;

	/** @var Section[] */
	private $sections = [];

	/** @var CheckInterface[] */
	private $checks = [];

	/** @var bool */
	private $collected = false;

	/** @var bool */
	private $triggerPlugins;

	/** @var bool */
	private $registerBuiltins;

	/**
	 * @param   Store|null  $store             Persistent storage
	 * @param   bool        $triggerPlugins    Let plugins add sections and checks?
	 * @param   bool        $registerBuiltins  Register Admin Tools' own sections and checks?
	 *
	 * @since   7.10.0
	 */
	public function __construct(?Store $store = null, bool $triggerPlugins = true, bool $registerBuiltins = true)
	{
		$this->store            = $store ?? new Store();
		$this->triggerPlugins   = $triggerPlugins;
		$this->registerBuiltins = $registerBuiltins;
	}

	/**
	 * Register a section. A section whose ID is already registered is ignored.
	 *
	 * @param   Section  $section
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function addSection(Section $section): void
	{
		if (isset($this->sections[$section->getId()]))
		{
			return;
		}

		$this->sections[$section->getId()] = $section;
	}

	/**
	 * Register a check. A check with an invalid or already registered ID, or an unknown section, is ignored.
	 *
	 * @param   CheckInterface  $check
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function addCheck(CheckInterface $check): void
	{
		try
		{
			$id      = $check->getId();
			$section = $check->getSection();
		}
		catch (\Throwable $e)
		{
			return;
		}

		if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $id) || isset($this->checks[$id]) || !isset($this->sections[$section]))
		{
			return;
		}

		$this->checks[$id] = $check;
	}

	/**
	 * The registered sections, in display order.
	 *
	 * @return  Section[]
	 * @since   7.10.0
	 */
	public function getSections(): array
	{
		$this->collect();

		$sections = array_values($this->sections);

		usort(
			$sections,
			function (Section $a, Section $b) {
				return [$a->getOrdering(), $a->getId()] <=> [$b->getOrdering(), $b->getId()];
			}
		);

		return $sections;
	}

	/**
	 * The checks which apply to this site, in registration order.
	 *
	 * @return  CheckInterface[]
	 * @since   7.10.0
	 */
	public function getChecks(): array
	{
		$this->collect();

		return array_filter(
			$this->checks,
			function (CheckInterface $check) {
				try
				{
					return $check->isApplicable();
				}
				catch (\Throwable $e)
				{
					return false;
				}
			}
		);
	}

	/**
	 * Get an applicable check by ID.
	 *
	 * @param   string  $id
	 *
	 * @return  CheckInterface|null
	 * @since   7.10.0
	 */
	public function getCheck(string $id): ?CheckInterface
	{
		return $this->getChecks()[$id] ?? null;
	}

	/**
	 * Run all applicable checks.
	 *
	 * Each entry of the returned array is ['check' => CheckInterface, 'result' => CheckResult, 'ignored' => bool,
	 * 'cachedAt' => int|null], keyed by check ID. Entries are sorted by section, then by severity.
	 *
	 * @param   bool  $refresh  Ignore cached results and run expensive checks again?
	 *
	 * @return  array[]
	 * @since   7.10.0
	 */
	public function run(bool $refresh = false): array
	{
		$checks       = $this->getChecks();
		$ignored      = $this->getIgnored();
		$cache        = $refresh ? [] : $this->getCache();
		$cacheChanged = $refresh;
		$now          = time();
		$entries      = [];

		foreach ($checks as $id => $check)
		{
			$cachedAt  = null;
			$result    = null;
			$expensive = $this->isExpensive($check);

			if ($expensive && isset($cache[$id]['time'], $cache[$id]['result'])
			    && ($now - (int) $cache[$id]['time']) < self::CACHE_TTL)
			{
				try
				{
					$result   = CheckResult::fromArray((array) $cache[$id]['result']);
					$cachedAt = (int) $cache[$id]['time'];
				}
				catch (\Throwable $e)
				{
					$result = null;
				}
			}

			if ($result === null)
			{
				$result = $this->runCheck($check);

				if ($expensive)
				{
					$cache[$id]   = ['time' => $now, 'result' => $result->toArray()];
					$cachedAt     = $now;
					$cacheChanged = true;
				}
			}

			$entries[$id] = [
				'check'    => $check,
				'result'   => $result,
				'ignored'  => in_array($id, $ignored, true),
				'cachedAt' => $cachedAt,
			];
		}

		if ($cacheChanged)
		{
			// Only keep the entries of checks which still exist. Caching is an optimisation; failing it must not fail the run.
			try
			{
				$this->store->set(Store::KEY_CACHE, array_intersect_key($cache, $checks));
			}
			catch (\Throwable $e)
			{
				// No problem; the expensive checks will simply run again next time.
			}
		}

		return $this->sortEntries($entries);
	}

	/**
	 * Run a single check, never using the cache, and update its cache entry.
	 *
	 * @param   CheckInterface  $check
	 *
	 * @return  CheckResult
	 * @since   7.10.0
	 */
	public function runFresh(CheckInterface $check): CheckResult
	{
		$result = $this->runCheck($check);

		$this->clearCache($check->getId());

		return $result;
	}

	/**
	 * Count the results.
	 *
	 * Returns ['total' => int, 'problems' => int, 'severity' => [severity => int, …], 'passed' => int,
	 * 'ignored' => int, 'info' => int, 'na' => int, 'error' => int]. Ignored checks are only counted as ignored.
	 *
	 * @param   array[]  $entries  The output of run()
	 *
	 * @return  array
	 * @since   7.10.0
	 */
	public static function summarise(array $entries): array
	{
		$summary = [
			'total'    => count($entries),
			'problems' => 0,
			'severity' => array_fill_keys(array_diff(Severity::all(), [Severity::INFO]), 0),
			'passed'   => 0,
			'ignored'  => 0,
			'info'     => 0,
			'na'       => 0,
			'error'    => 0,
		];

		foreach ($entries as $entry)
		{
			/** @var CheckResult $result */
			$result = $entry['result'];

			if ($entry['ignored'])
			{
				$summary['ignored']++;

				continue;
			}

			switch ($result->getStatus())
			{
				case CheckResult::STATUS_FAIL:
					$severity = $entry['check']->getSeverity();
					$severity = isset($summary['severity'][$severity]) ? $severity : Severity::LOW;

					$summary['problems']++;
					$summary['severity'][$severity]++;
					break;

				case CheckResult::STATUS_PASS:
					$summary['passed']++;
					break;

				case CheckResult::STATUS_INFO:
					$summary['info']++;
					break;

				case CheckResult::STATUS_NA:
					$summary['na']++;
					break;

				default:
					$summary['error']++;
					break;
			}
		}

		return $summary;
	}

	/**
	 * Is an entry of run() hidden unless the user asks to see all checks? Passed and ignored checks are.
	 *
	 * Problems, errors, checks which could not be performed yet and informational items are always shown: a check
	 * without data has not passed, and the user needs to know it exists to act on it.
	 *
	 * @param   array  $entry  One entry of the run() output
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isSecondary(array $entry): bool
	{
		return !empty($entry['ignored']) || $entry['result']->getStatus() === CheckResult::STATUS_PASS;
	}

	/**
	 * Apply a one-click fix.
	 *
	 * The check is run afresh first; the fix is only applied if the current result still offers it and the user is
	 * allowed to apply it.
	 *
	 * Per-item fixes name their item by its token (CheckResult::itemToken()), as the page does. The token is mapped back
	 * to the exact key of an item in the fresh result; the check's fix() only ever sees that key.
	 *
	 * @param   string       $checkId    The check ID
	 * @param   string       $fixId      The fix ID
	 * @param   string|null  $itemToken  The item's token, for per-item fixes; NULL or empty for whole-check fixes
	 * @param   User         $user       The user applying the fix
	 *
	 * @return  string  Success message
	 * @throws  \Throwable
	 * @since   7.10.0
	 */
	public function applyFix(string $checkId, string $fixId, ?string $itemToken, User $user): string
	{
		$check = $this->getCheck($checkId);

		if ($check === null)
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_NO_SUCH_CHECK'));
		}

		$itemToken = ($itemToken === '') ? null : $itemToken;
		$isIgnore  = $check instanceof IgnorableItemsInterface
		             && in_array($fixId, [IgnorableItemsInterface::FIX_IGNORE, IgnorableItemsInterface::FIX_UNIGNORE], true);

		// Ignoring items, like ignoring checks, only needs access to the Site Health Check. Anything else is the check's call.
		if (!$isIgnore && !$check->canFix($fixId, $user))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$result  = $this->runCheck($check);
		$itemKey = ($itemToken === null) ? null : $result->findItemKey($itemToken);

		// An item which is not listed is never fixable; above all, it must not fall back to the whole-check fix.
		if (($itemToken !== null && ($itemKey === null || $itemKey === '')) || !$result->offersFix($fixId, $itemKey))
		{
			$this->clearCache($checkId);

			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_FIX_NOT_APPLICABLE'));
		}

		try
		{
			// The check's own fix() never sees the ignore fixes; there, an unknown fix ID may well mean "delete".
			if ($isIgnore)
			{
				return $this->applyIgnoreFix($checkId, $fixId, $itemKey);
			}

			return $check->fix($fixId, $itemKey);
		}
		finally
		{
			$this->clearCache($checkId);
		}
	}

	/**
	 * The descriptors of the fixes which ignore and stop ignoring items, keyed by fix ID.
	 *
	 * @return  Fix[]
	 * @since   7.10.0
	 */
	public static function getIgnoreFixes(): array
	{
		return [
			IgnorableItemsInterface::FIX_IGNORE   => new Fix(
				IgnorableItemsInterface::FIX_IGNORE,
				Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_IGNORE'),
				'',
				'outline-secondary',
				'fa fa-eye-slash'
			),
			IgnorableItemsInterface::FIX_UNIGNORE => new Fix(
				IgnorableItemsInterface::FIX_UNIGNORE,
				Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_BTN_UNIGNORE_ITEMS'),
				'',
				'outline-secondary',
				'fa fa-eye'
			),
		];
	}

	/**
	 * The item keys of a check which the user ignores.
	 *
	 * @param   string  $checkId
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	public function getIgnoredItems(string $checkId): array
	{
		$keys = $this->getAllIgnoredItems()[$checkId] ?? [];

		return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
	}

	/**
	 * IDs of the ignored checks.
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	public function getIgnored(): array
	{
		$ignored = $this->store->get(Store::KEY_IGNORED, []);

		return is_array($ignored) ? array_values(array_filter($ignored, 'is_string')) : [];
	}

	/**
	 * Ignore a check.
	 *
	 * @param   string  $checkId
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function ignore(string $checkId): void
	{
		if ($this->getCheck($checkId) === null)
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_NO_SUCH_CHECK'));
		}

		$ignored   = $this->getIgnored();
		$ignored[] = $checkId;

		$this->store->set(Store::KEY_IGNORED, array_values(array_unique($ignored)));
	}

	/**
	 * Stop ignoring a check.
	 *
	 * @param   string  $checkId
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function unignore(string $checkId): void
	{
		$this->store->set(Store::KEY_IGNORED, array_values(array_diff($this->getIgnored(), [$checkId])));
	}

	/**
	 * Stop ignoring all checks, and all ignored items of every check.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function resetIgnored(): void
	{
		$this->store->remove(Store::KEY_IGNORED);
		$this->store->remove(Store::KEY_IGNORED_ITEMS);

		// Cached results leave the ignored items out.
		$this->clearCache();
	}

	/**
	 * Clear the cached results of one or all expensive checks.
	 *
	 * @param   string|null  $checkId  NULL to clear all cached results.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function clearCache(?string $checkId = null): void
	{
		if ($checkId === null)
		{
			$this->store->remove(Store::KEY_CACHE);

			return;
		}

		$cache = $this->getCache();

		if (!isset($cache[$checkId]))
		{
			return;
		}

		unset($cache[$checkId]);

		$this->store->set(Store::KEY_CACHE, $cache);
	}

	/**
	 * Register the built-in sections and checks, then let plugins add theirs. Runs once.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	private function collect(): void
	{
		if ($this->collected)
		{
			return;
		}

		$this->collected = true;

		if ($this->registerBuiltins)
		{
			foreach (BuiltinChecks::getSections() as $section)
			{
				$this->addSection($section);
			}

			foreach (BuiltinChecks::getChecks() as $check)
			{
				$this->addCheck($check);
			}
		}

		if (!$this->triggerPlugins)
		{
			return;
		}

		try
		{
			PluginHelper::importPlugin('admintools');
			PluginHelper::importPlugin('system');

			Factory::getApplication()->getDispatcher()->dispatch(CollectChecksEvent::NAME, new CollectChecksEvent($this));
		}
		catch (\Throwable $e)
		{
			// A broken third-party plugin must not take the Site Health Check down with it.
		}
	}

	/**
	 * Ignore an item of a check, or stop ignoring all of its items. The fresh result has been verified to offer it.
	 *
	 * @param   string       $checkId
	 * @param   string       $fixId    IgnorableItemsInterface::FIX_IGNORE or FIX_UNIGNORE
	 * @param   string|null  $itemKey  The item to ignore
	 *
	 * @return  string  Success message
	 * @since   7.10.0
	 */
	private function applyIgnoreFix(string $checkId, string $fixId, ?string $itemKey): string
	{
		$all = $this->getAllIgnoredItems();

		if ($fixId === IgnorableItemsInterface::FIX_UNIGNORE)
		{
			unset($all[$checkId]);
		}
		else
		{
			$keys          = $this->getIgnoredItems($checkId);
			$keys[]        = (string) $itemKey;
			$all[$checkId] = array_values(array_unique($keys));
		}

		if (empty($all))
		{
			$this->store->remove(Store::KEY_IGNORED_ITEMS);
		}
		else
		{
			$this->store->set(Store::KEY_IGNORED_ITEMS, $all);
		}

		return $fixId === IgnorableItemsInterface::FIX_UNIGNORE
			? Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_MSG_ITEMS_UNIGNORED')
			: Text::sprintf('COM_ADMINTOOLS_SITEHEALTHCHECK_MSG_ITEM_IGNORED', (string) $itemKey);
	}

	private function getAllIgnoredItems(): array
	{
		$all = $this->store->get(Store::KEY_IGNORED_ITEMS, []);

		return is_array($all) ? $all : [];
	}

	private function runCheck(CheckInterface $check): CheckResult
	{
		try
		{
			if ($check instanceof IgnorableItemsInterface)
			{
				$check->setIgnoredItems($this->getIgnoredItems($check->getId()));
			}

			return $check->run();
		}
		catch (\Throwable $e)
		{
			return CheckResult::error(
				Text::sprintf(
					'COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_CHECK_FAILED',
					htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				)
			);
		}
	}

	private function isExpensive(CheckInterface $check): bool
	{
		try
		{
			return $check->isExpensive();
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	private function getCache(): array
	{
		$cache = $this->store->get(Store::KEY_CACHE, []);

		return is_array($cache) ? $cache : [];
	}

	/**
	 * Sort entries by section ordering, then severity, keeping registration order otherwise.
	 *
	 * @param   array[]  $entries
	 *
	 * @return  array[]
	 * @since   7.10.0
	 */
	private function sortEntries(array $entries): array
	{
		$sectionOrder = array_flip(
			array_map(
				function (Section $section) {
					return $section->getId();
				},
				$this->getSections()
			)
		);
		$position     = array_flip(array_keys($entries));

		uksort(
			$entries,
			function ($idA, $idB) use ($entries, $sectionOrder, $position) {
				/** @var CheckInterface $a */
				$a = $entries[$idA]['check'];
				/** @var CheckInterface $b */
				$b = $entries[$idB]['check'];

				return [$sectionOrder[$a->getSection()] ?? PHP_INT_MAX, Severity::rank($a->getSeverity()), $position[$idA]]
					<=> [$sectionOrder[$b->getSection()] ?? PHP_INT_MAX, Severity::rank($b->getSeverity()), $position[$idB]];
			}
		);

		return $entries;
	}
}
