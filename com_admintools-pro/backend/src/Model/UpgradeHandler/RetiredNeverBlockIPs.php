<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model\UpgradeHandler;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Model\UpgradeModel;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\Database\DatabaseDriver;

/**
 * Custom UpgradeModel handler removing the former default addresses from the "Never block these IPs" list.
 *
 * Until 7.9.3 the default list hard-coded 13 DuckDuckBot addresses. 11 of them (Amazon EC2) are no longer DuckDuckGo's
 * and may have been reassigned to anyone, who would be exempt from every WAF block; the other 2 are 2 of DuckDuckGo's
 * 486 addresses and give no real protection. Site owners did not choose them, so we remove them, unless the
 * administrator gave the entry a description (i.e. they deliberately maintain it).
 *
 * @since 7.9.4
 */
class RetiredNeverBlockIPs
{
	private const RETIRED_IPS = [
		'20.191.45.212', '23.21.227.69', '40.88.21.235', '50.16.241.113', '50.16.241.114', '50.16.241.117',
		'50.16.247.234', '52.5.190.19', '52.204.97.54', '54.197.234.188', '54.208.100.253', '54.208.102.37',
		'107.21.1.8',
	];

	public function __construct(UpgradeModel $upgradeModel, DatabaseDriver $dbo)
	{
	}

	public function onUpdate(string $type, ?PackageAdapter $parent = null)
	{
		// Storage depends on KeyValueStorage, which older versions did not have.
		if (!class_exists('Akeeba\Component\AdminTools\Administrator\Helper\KeyValueStorage'))
		{
			@include_once __DIR__ . '/../../Helper/KeyValueStorage.php';
		}

		if (!class_exists('Akeeba\Component\AdminTools\Administrator\Helper\Storage'))
		{
			@include_once __DIR__ . '/../../Helper/Storage.php';
		}

		if (!class_exists('Akeeba\Component\AdminTools\Administrator\Helper\Storage'))
		{
			return;
		}

		$storage = new Storage();
		$stored  = $storage->getValue('neverblockips', null);

		if (!is_array($stored) || empty($stored))
		{
			return;
		}

		$kept = array_values(array_filter($stored, function ($entry) {
			$ip          = trim((string) (is_array($entry) ? ($entry[0] ?? '') : $entry));
			$description = trim((string) (is_array($entry) ? ($entry[1] ?? '') : ''));

			return $description !== '' || !in_array($ip, self::RETIRED_IPS, true);
		}));

		if (count($kept) === count($stored))
		{
			return;
		}

		$storage->setValue('neverblockips', $kept, true);
	}
}
