<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

/**
 * Severity levels of Site Health Check items.
 *
 * Every check has exactly one severity. It tells the user how urgently they need to act when the check reports a
 * problem. Informational checks (INFO) never report a problem; they invite the user to look at a feature.
 *
 * Forward compatibility: once the minimum PHP version allows it, this may become a string-backed enum. The string
 * constants below will stay (enums may declare constants), and CheckInterface::getSeverity() will keep returning a
 * string, so code which uses these constants keeps working. Do not instantiate this class, nor type-hint against it.
 *
 * @since  7.10.0
 */
final class Severity
{
	/**
	 * Not instantiable, just like an enum.
	 *
	 * @since  7.10.0
	 */
	private function __construct()
	{
	}

	public const CRITICAL = 'critical';

	public const HIGH = 'high';

	public const MEDIUM = 'medium';

	public const LOW = 'low';

	public const INFO = 'info';

	/**
	 * All severities, most severe first.
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	public static function all(): array
	{
		return [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW, self::INFO];
	}

	/**
	 * Is this a known severity?
	 *
	 * @param   string  $severity
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isValid(string $severity): bool
	{
		return in_array($severity, self::all(), true);
	}

	/**
	 * Sort rank of a severity; lower is more severe. Unknown severities sort last.
	 *
	 * @param   string  $severity
	 *
	 * @return  int
	 * @since   7.10.0
	 */
	public static function rank(string $severity): int
	{
		$rank = array_search($severity, self::all(), true);

		return $rank === false ? 99 : (int) $rank;
	}

	/**
	 * Bootstrap 5 contextual colour for a severity.
	 *
	 * @param   string  $severity
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function bootstrapColour(string $severity): string
	{
		switch ($severity)
		{
			case self::CRITICAL:
				return 'danger';

			case self::HIGH:
				return 'warning';

			case self::MEDIUM:
				return 'primary';

			case self::LOW:
				return 'secondary';

			default:
				return 'info';
		}
	}
}
