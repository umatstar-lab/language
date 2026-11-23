<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;

/**
 * Convenience base class for Site Health Check items, built-in and third-party alike.
 *
 * It provides sensible defaults: the check applies everywhere, is cheap to run, and offers no fixes.
 *
 * @since  7.10.0
 */
abstract class AbstractCheck implements CheckInterface
{
	public function isApplicable(): bool
	{
		return true;
	}

	public function isExpensive(): bool
	{
		return false;
	}

	public function getFixes(): array
	{
		return [];
	}

	public function canFix(string $fixId, User $user): bool
	{
		return false;
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_FIX_NOT_SUPPORTED'));
	}

	/**
	 * Get a fix descriptor by ID.
	 *
	 * @param   string  $fixId
	 *
	 * @return  Fix|null
	 * @since   7.10.0
	 */
	public function getFix(string $fixId): ?Fix
	{
		foreach ($this->getFixes() as $fix)
		{
			if ($fix->getId() === $fixId)
			{
				return $fix;
			}
		}

		return null;
	}

	/**
	 * Get the site's database driver.
	 *
	 * @return  DatabaseDriver
	 * @since   7.10.0
	 */
	protected function getDatabase(): DatabaseDriver
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}

	/**
	 * Escape a value for output in an HTML message.
	 *
	 * @param   string|null  $value
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	protected static function e(?string $value): string
	{
		return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
