<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\Files;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Check\BuiltinCheck;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckResult;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\IgnorableItemsInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Severity;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Util\SiteFilesystem;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

/**
 * Remove unneeded Joomla! core files from the site's root.
 *
 * @since  7.10.0
 */
class UnneededCoreFiles extends BuiltinCheck implements IgnorableItemsInterface
{
	/** File name => language key suffix of its explanation */
	public const FILES = [
		'htaccess.txt'    => 'HTACCESS',
		'LICENSE.txt'     => 'LICENSE',
		'robots.txt.dist' => 'ROBOTS',
		'web.config.txt'  => 'WEBCONFIG',
	];

	protected $id = 'unneeded_core_files';

	protected $section = 'files';

	protected $langKey = 'UNNEEDEDCOREFILES';

	protected $severity = Severity::LOW;

	public function run(): CheckResult
	{
		$present = array_values(
			array_filter(
				array_keys(self::FILES),
				function (string $file) {
					return @is_file(JPATH_ROOT . '/' . $file);
				}
			)
		);

		[$files, $ignored] = $this->partitionIgnored($present);

		if (empty($files))
		{
			return self::withIgnoredItems(CheckResult::pass(), count($ignored));
		}

		$items = array_map(
			function (string $file) {
				return CheckResult::item(
					$file, $file, $this->itemFixes(['delete']), Text::_($this->key('FILE_' . self::FILES[$file]))
				);
			},
			$files
		);

		return self::withIgnoredItems(CheckResult::fail($this->text('MSG_FAIL'), $items), count($ignored));
	}

	public function getFixes(): array
	{
		return [self::deleteFix()];
	}

	public function canFix(string $fixId, User $user): bool
	{
		return $fixId === 'delete' && self::canDeleteFiles($user);
	}

	public function fix(string $fixId, ?string $itemKey = null): string
	{
		if ($fixId !== 'delete' || !array_key_exists((string) $itemKey, self::FILES))
		{
			throw new \RuntimeException(Text::_('COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_INVALID_PATH'));
		}

		SiteFilesystem::delete((string) $itemKey);

		return $this->text('FIX_DONE', $itemKey);
	}
}
