<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * A Site Health Check item.
 *
 * This is a public API. Third-party plugins implement it (usually by extending AbstractCheck) and register their
 * objects through the onAdminToolsSiteHealthCheckCollect event; see CollectChecksEvent.
 *
 * @since  7.10.0
 */
interface CheckInterface
{
	/**
	 * Unique ID of the check. Letters, digits, dot, dash and underscore only.
	 *
	 * It is stored in the list of ignored checks and in the results cache, so it must never change. Third-party
	 * checks MUST prefix it with their extension's name, e.g. `plg_system_example.foo`.
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public function getId(): string;

	/**
	 * ID of the section the check is listed under.
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public function getSection(): string;

	/**
	 * Translated title of the check (plain text).
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public function getTitle(): string;

	/**
	 * Translated general advice about the check (HTML, output as-is).
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public function getDescription(): string;

	/**
	 * Severity of the check; one of the Severity constants, e.g. `Severity::HIGH`.
	 *
	 * This will always be a string, even if Severity is ever turned into an enum.
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public function getSeverity(): string;

	/**
	 * Does this check apply to this site at all? Non-applicable checks are not shown, nor counted.
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function isApplicable(): bool;

	/**
	 * Is this check expensive to run? Results of expensive checks are cached for 30 minutes.
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function isExpensive(): bool;

	/**
	 * Run the check.
	 *
	 * Any exception thrown is caught and reported as an error result for this check only.
	 *
	 * @return  CheckResult
	 * @since   7.10.0
	 */
	public function run(): CheckResult;

	/**
	 * All the fixes this check can apply. The CheckResult says which ones are offered each time.
	 *
	 * @return  Fix[]
	 * @since   7.10.0
	 */
	public function getFixes(): array;

	/**
	 * May this user apply this fix?
	 *
	 * Access to the Site Health Check page only requires Admin Tools' Security permission. A fix which changes
	 * something governed by another permission (Global Configuration, plugins, users, …) MUST check that permission
	 * here as well. Fixes the user may not apply are neither shown nor executed.
	 *
	 * @param   string  $fixId  The fix ID.
	 * @param   User    $user   The user who wants to apply it.
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function canFix(string $fixId, User $user): bool;

	/**
	 * Apply a fix.
	 *
	 * It is only called for a fix the current, freshly computed, result offers, and which canFix() allows. Per-item
	 * fixes receive the item's key; the check MUST NOT trust it beyond its own list of items.
	 *
	 * @param   string       $fixId    The fix ID.
	 * @param   string|null  $itemKey  The item key, for per-item fixes; NULL for whole-check fixes.
	 *
	 * @return  string  Translated success message (plain text).
	 * @throws  \Throwable  On failure; the exception message is shown to the user.
	 * @since   7.10.0
	 */
	public function fix(string $fixId, ?string $itemKey = null): string;
}
