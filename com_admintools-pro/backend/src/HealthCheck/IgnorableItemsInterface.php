<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

/**
 * A Site Health Check item whose individual items (e.g. files) the user can ignore.
 *
 * The Runner stores the ignored item keys, and hands them to the check before every run. The check leaves them out of
 * its result, offers the FIX_IGNORE fix on every item it reports, and the FIX_UNIGNORE fix on the check as a whole while
 * any of its items are ignored. A check which only failed because of its items passes once they are all ignored.
 *
 * Both fixes are applied by the Runner itself, never by the check's fix() method, after a fresh run of the check
 * confirms that it still offers them.
 *
 * @since  7.10.0
 */
interface IgnorableItemsInterface
{
	/** Per-item fix: ignore this item. */
	public const FIX_IGNORE = 'ignore_item';

	/** Whole-check fix: stop ignoring all of this check's ignored items. */
	public const FIX_UNIGNORE = 'unignore_items';

	/**
	 * Tell the check which of its item keys the user ignores. Called before every run.
	 *
	 * @param   string[]  $keys  Item keys, as in the check's results
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function setIgnoredItems(array $keys): void;
}
