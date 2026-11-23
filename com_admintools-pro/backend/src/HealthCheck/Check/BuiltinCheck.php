<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\AbstractCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Fix;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\IgnorableItemsInterface;
use Akeeba\Component\AdminTools\Administrator\Extension\AdminToolsComponent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

/**
 * Base class for Admin Tools' own Site Health Check items.
 *
 * Language strings follow the COM_ADMINTOOLS_SITEHEALTHCHECK_CHECK_<LANGKEY>_<SUFFIX> convention: _TITLE, _DESC
 * (general advice) and any outcome-specific messages.
 *
 * @since  7.10.0
 */
abstract class BuiltinCheck extends AbstractCheck
{
	/** Maximum number of items listed in a result. */
	protected const MAX_ITEMS = 100;

	/** @var string Check ID, without the admintools. prefix */
	protected $id = '';

	/** @var string Section ID */
	protected $section = '';

	/** @var string Severity */
	protected $severity = '';

	/** @var string Language key infix */
	protected $langKey = '';

	/** @var string[] Item keys the user ignores; only used by checks implementing IgnorableItemsInterface */
	protected $ignoredItems = [];

	/**
	 * Which of this check's item keys the user ignores. See IgnorableItemsInterface.
	 *
	 * @param   string[]  $keys
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function setIgnoredItems(array $keys): void
	{
		$this->ignoredItems = array_values(array_filter($keys, 'is_string'));
	}

	public function getId(): string
	{
		return 'admintools.' . $this->id;
	}

	public function getSection(): string
	{
		return $this->section;
	}

	public function getSeverity(): string
	{
		return $this->severity;
	}

	public function getTitle(): string
	{
		return Text::_($this->key('TITLE'));
	}

	public function getDescription(): string
	{
		return Text::_($this->key('DESC'));
	}

	/**
	 * Full language key for a suffix of this check.
	 *
	 * @param   string  $suffix
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	protected function key(string $suffix): string
	{
		return 'COM_ADMINTOOLS_SITEHEALTHCHECK_CHECK_' . $this->langKey . '_' . $suffix;
	}

	/**
	 * Translate a message of this check. Extra arguments are passed to sprintf; escape them first!
	 *
	 * @param   string  $suffix
	 * @param   mixed   ...$args
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	protected function text(string $suffix, ...$args): string
	{
		return empty($args) ? Text::_($this->key($suffix)) : Text::sprintf($this->key($suffix), ...$args);
	}

	/**
	 * Build a list of result items from relative paths, truncated to MAX_ITEMS.
	 *
	 * @param   string[]  $paths
	 * @param   string[]  $fixes  Per-item fix IDs
	 *
	 * @return  array[]
	 * @since   7.10.0
	 */
	protected static function pathItems(array $paths, array $fixes = []): array
	{
		return array_map(
			function (string $path) use ($fixes) {
				return CheckResult::item($path, $path, $fixes);
			},
			array_slice(array_values($paths), 0, static::MAX_ITEMS)
		);
	}

	/**
	 * Split item keys into those to report and those the user ignores.
	 *
	 * @param   string[]  $keys
	 *
	 * @return  array  [string[] $reported, string[] $ignored], both in their original order
	 * @since   7.10.0
	 */
	protected function partitionIgnored(array $keys): array
	{
		$reported = [];
		$ignored  = [];

		foreach (array_values($keys) as $key)
		{
			if (in_array($key, $this->ignoredItems, true))
			{
				$ignored[] = $key;
			}
			else
			{
				$reported[] = $key;
			}
		}

		return [$reported, $ignored];
	}

	/**
	 * The per-item fixes, plus Ignore for checks whose items can be ignored.
	 *
	 * @param   string[]  $fixes
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function itemFixes(array $fixes = []): array
	{
		return $this instanceof IgnorableItemsInterface
			? array_merge($fixes, [IgnorableItemsInterface::FIX_IGNORE]) : $fixes;
	}

	/**
	 * Mention the ignored items in a result, and offer to stop ignoring them. A result is returned as-is when nothing
	 * is ignored.
	 *
	 * @param   CheckResult  $result
	 * @param   int          $ignored  How many of the check's items are ignored
	 *
	 * @return  CheckResult
	 * @since   7.10.0
	 */
	protected static function withIgnoredItems(CheckResult $result, int $ignored): CheckResult
	{
		if ($ignored <= 0)
		{
			return $result;
		}

		$new = new CheckResult(
			$result->getStatus(),
			trim($result->getMessage() . ' ' . Text::plural('COM_ADMINTOOLS_SITEHEALTHCHECK_LBL_IGNORED_ITEMS', $ignored)),
			$result->getItems(),
			array_merge($result->getFixes(), [IgnorableItemsInterface::FIX_UNIGNORE]),
			$result->getTotalItems()
		);

		foreach ($result->getLinks() as $link)
		{
			$new = $new->withLink($link['url'], $link['label']);
		}

		return $new;
	}

	/**
	 * Standard "delete this file / folder" fix descriptor.
	 *
	 * @return  Fix
	 * @since   7.10.0
	 */
	protected static function deleteFix(): Fix
	{
		return new Fix(
			'delete',
			Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_FIX_DELETE'),
			Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_FIX_DELETE_CONFIRM'),
			'danger',
			'fa fa-trash'
		);
	}

	/**
	 * Is the user a global Super User?
	 *
	 * @param   User  $user
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected static function isSuperUser(User $user): bool
	{
		return (bool) $user->authorise('core.admin');
	}

	/**
	 * May the user delete files and folders from the site's root through Admin Tools?
	 *
	 * This is the same privilege which governs Admin Tools' other destructive file maintenance features.
	 *
	 * @param   User  $user
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected static function canDeleteFiles(User $user): bool
	{
		return (bool) $user->authorise('admintools.maintenance', 'com_admintools');
	}

	/**
	 * The booted Admin Tools component, for its services.
	 *
	 * @return  AdminToolsComponent
	 * @since   7.10.0
	 */
	protected static function getComponent(): AdminToolsComponent
	{
		/** @var AdminToolsComponent $component */
		$component = Factory::getApplication()->bootComponent('com_admintools');

		return $component;
	}
}
