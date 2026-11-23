<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use DateTimeInterface;
use Joomla\CMS\Factory;

class AwaySchedule extends Base
{
	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		if (!$this->app->isClient('administrator'))
		{
			return false;
		}

		if (!$this->wafParams->getValue('awayschedule_from') || !$this->wafParams->getValue('awayschedule_to'))
		{
			return false;
		}

		return true;
	}

	/**
	 * Checks if the secret word is set in the URL query, or redirects the user
	 * back to the home page.
	 */
	public function onAfterInitialise(): void
	{
		$timezone = $this->app->get('offset', 'UTC');

		$now  = clone Factory::getDate('now', $timezone);
		$from = clone Factory::getDate($this->wafParams->getValue('awayschedule_from'), $timezone);
		$to   = clone Factory::getDate($this->wafParams->getValue('awayschedule_to'), $timezone);

		// Login attempt, while we set the away schedule, let's ban the user
		if (self::isWithinAwayWindow($now, $from, $to))
		{
			$this->redirectAdminToHome();
		}
	}

	/**
	 * Decide whether the current time falls inside the operator's configured "away" window.
	 *
	 * The configured window can be either a same-day interval (e.g. 13:00 - 18:00) or an overnight interval
	 * which crosses midnight (e.g. 17:30 - 11:00). In the latter case `$from > $to` after both have been
	 * normalised to today's date, so the comparison is shifted: `$from` is moved back by one day so that
	 * the half-open window [$from, $to) wraps around midnight correctly.
	 *
	 * The comparison itself is half-open: `$now > $from && $now < $to` — the boundary timestamps are NOT
	 * considered part of the window, matching the original inline check in `onAfterInitialise()`.
	 *
	 * The helper does not mutate any of its arguments: when an overnight window is detected, the shifted
	 * `$from` is a clone of the caller's `$from` with `-1 day` applied, leaving the caller's value
	 * untouched.
	 *
	 * @param   DateTimeInterface  $now   The current timestamp (any timezone; comparison is naive).
	 * @param   DateTimeInterface  $from  The window start timestamp, today.
	 * @param   DateTimeInterface  $to    The window end timestamp, today.
	 *
	 * @return  bool  True when $now is strictly inside the (possibly overnight-wrapped) window.
	 *
	 * @since   7.9.2
	 */
	public static function isWithinAwayWindow(
		DateTimeInterface $now, DateTimeInterface $from, DateTimeInterface $to
	): bool
	{
		if ($from > $to)
		{
			// Clone to avoid mutating the caller's DateTime (DateTime::modify() mutates and returns $this).
			$from = (clone $from)->modify('-1 day');
		}

		return $now > $from && $now < $to;
	}
}
