<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\Extensions;
use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Exception;
use Joomla\Filesystem\File;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Table\Extension;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use SimpleXMLElement;

#[\AllowDynamicProperties]
class UpdatesModel extends BaseDatabaseModel
{
	/** @var int The extension_id of this component */
	protected $extension_id = 0;

	/** @var string The currently installed version, as reported by the #__extensions table */
	protected $version = 'dev';

	/** @var string The URL to the component's update XML stream */
	protected $updateSite;

	/** @var string The name to the component's update site (description of the update XML stream) */
	protected $updateSiteName;

	protected $extensionKey = 'pkg_admintools';

	private const CORE_UPDATE_SITE = 'https://cdn.akeeba.com/updates/pkgadmintoolscore.xml';

	private const CORE_UPDATE_SITE_NAME = 'Admin Tools Core for Joomla!';

	private const PRO_UPDATE_SITE = 'https://cdn.akeeba.com/updates/pkgadmintoolspro.xml';

	private const PRO_UPDATE_SITE_NAME = 'Admin Tools Professional for Joomla!';

	public function __construct($config = [], ?MVCFactoryInterface $factory = null)
	{
		parent::__construct($config, $factory);

		$this->version = defined('ADMINTOOLS_VERSION') ? ADMINTOOLS_VERSION : 'dev';

		$this->setEdition((bool) (defined('ADMINTOOLS_PRO') ? ADMINTOOLS_PRO : 0));

		$this->extension_id = (int) Extensions::getId($this->extensionKey);

		if (empty($this->extension_id))
		{
			$this->createFakePackageExtension();
			$this->extension_id = (int) Extensions::getId($this->extensionKey);
		}
	}

	/**
	 * Sets the update site URL and name for the Core or Pro edition.
	 *
	 * @param   bool  $isPro  True for Pro, false for Core.
	 *
	 * @return  void
	 */
	private function setEdition(bool $isPro): void
	{
		$this->updateSite     = $isPro ? self::PRO_UPDATE_SITE : self::CORE_UPDATE_SITE;
		$this->updateSiteName = $isPro ? self::PRO_UPDATE_SITE_NAME : self::CORE_UPDATE_SITE_NAME;
	}

	/**
	 * Refreshes the Joomla! update sites for this extension as needed
	 *
	 * Exactly one update site, for the current edition, will be linked to the package afterwards. It is matched by
	 * location only: Joomla keeps stale names when it relocates an update site (Joomla 5.4+), and on Joomla 4 it adds
	 * a second site when switching between Core and Pro. A Core site never has a Download ID; a Pro site keeps the
	 * Download ID of the first site which has one, regardless of the order the sites are stored in.
	 *
	 * @param   bool|null  $isPro  Force the edition (true: Pro, false: Core). NULL: use the detected edition.
	 *
	 * @return  void
	 */
	public function refreshUpdateSite(?bool $isPro = null): void
	{
		if (empty($this->extension_id))
		{
			return;
		}

		if ($isPro !== null)
		{
			$this->setEdition($isPro);
		}

		$isProSite = $this->updateSite === self::PRO_UPDATE_SITE;
		$db        = $this->getDatabase();

		/** @var object[] $sites All existing, linked update sites */
		$sites = [];

		/** @var int[] $dangling Update site IDs which are linked to us but no longer exist */
		$dangling = [];

		foreach ($this->getUpdateSiteIds() as $id)
		{
			$id    = (int) $id;
			$query = DbQuery::create($db)
				->select('*')
				->from($db->qn('#__update_sites'))
				->where($db->qn('update_site_id') . ' = :usid')
				->bind(':usid', $id, ParameterType::INTEGER);

			try
			{
				$aSite = $db->setQuery($query)->loadObject() ?: null;
			}
			catch (Exception $e)
			{
				$aSite = null;
			}

			if (empty($aSite))
			{
				$dangling[] = $id;

				continue;
			}

			$sites[] = $aSite;
		}

		// The keeper is the first site with the correct location.
		$keeper = null;

		foreach ($sites as $aSite)
		{
			if ($aSite->location === $this->updateSite)
			{
				$keeper = $aSite;

				break;
			}
		}

		// Decide on the Download ID before anything is written.
		$extraQuery = '';

		if ($isProSite)
		{
			$extraQuery = $keeper ? (string) ($keeper->extra_query ?? '') : '';

			if ($extraQuery === '')
			{
				foreach ($sites as $aSite)
				{
					if (!empty($aSite->extra_query))
					{
						$extraQuery = (string) $aSite->extra_query;

						break;
					}
				}
			}
		}

		if ($keeper)
		{
			if (
				$keeper->enabled != 1
				|| (string) ($keeper->extra_query ?? '') !== $extraQuery
				|| $keeper->name !== $this->updateSiteName
			)
			{
				$keeper->enabled     = 1;
				$keeper->extra_query = $extraQuery;
				$keeper->name        = $this->updateSiteName;

				$db->updateObject('#__update_sites', $keeper, 'update_site_id', true);
			}
		}

		// Everything else is obsolete: other sites, and dangling links.
		$deleteOldSites = $dangling;

		foreach ($sites as $aSite)
		{
			if ($aSite !== $keeper)
			{
				$deleteOldSites[] = (int) $aSite->update_site_id;
			}
		}

		if (!empty($deleteOldSites))
		{
			try
			{
				$siteIds = array_values(array_unique($deleteOldSites));

				// Delete update sites
				$query = DbQuery::create($db)
					->delete('#__update_sites')
					->whereIn($db->qn('update_site_id'), $siteIds, ParameterType::INTEGER);
				$db->setQuery($query)->execute();

				// Delete update sites to extension ID records
				$query = DbQuery::create($db)
					->delete('#__update_sites_extensions')
					->whereIn($db->qn('update_site_id'), $siteIds, ParameterType::INTEGER);
				$db->setQuery($query)->execute();
			}
			catch (\Exception $e)
			{
				// Do nothing on failure
				return;
			}
		}

		if ($keeper)
		{
			return;
		}

		// No suitable update site. Create a new one.
		$newSite = (object) [
			'name'                 => $this->updateSiteName,
			'type'                 => 'extension',
			'location'             => $this->updateSite,
			'enabled'              => 1,
			'last_check_timestamp' => 0,
			'extra_query'          => $extraQuery,
		];
		$db->insertObject('#__update_sites', $newSite);

		$id                  = $db->insertid();
		$updateSiteExtension = (object) [
			'update_site_id' => $id,
			'extension_id'   => $this->extension_id,
		];
		$db->insertObject('#__update_sites_extensions', $updateSiteExtension);
	}

	/**
	 * Gets the update site Ids for our extension.
	 *
	 * @return    array    An array of IDs
	 */
	public function getUpdateSiteIds(): array
	{
		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->select($db->qn('update_site_id'))
			->from($db->qn('#__update_sites_extensions'))
			->where($db->qn('extension_id') . ' = :eid')
			->bind(':eid', $this->extension_id, ParameterType::INTEGER);

		try
		{
			$ret = $db->setQuery($query)->loadColumn(0);
		}
		catch (Exception $e)
		{
			$ret = null;
		}

		return is_array($ret) ? $ret : [];
	}

	/**
	 * Get the contents of all the update sites of the configured extension
	 *
	 * @return  array|null
	 */
	public function getUpdateSites(): ?array
	{
		$updateSiteIDs = array_map('intval', $this->getUpdateSiteIds());

		if (empty($updateSiteIDs))
		{
			return [];
		}

		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->select('*')
			->from($db->qn('#__update_sites'))
			->whereIn($db->qn('update_site_id'), $updateSiteIDs);

		try
		{
			$db->setQuery($query);

			$ret = $db->loadAssocList('update_site_id');
		}
		catch (Exception $e)
		{
			$ret = null;
		}

		return empty($ret) ? [] : $ret;
	}

	/**
	 * Gets the license key for a paid extension.
	 *
	 * On Joomla! 3 or when $forceLegacy is true we look in the component Options.
	 *
	 * On Joomla! 4 we use the information in the dlid element of the extension's XML manifest to parse the extra_query
	 * fields of all configured update sites of the extension. This is the same thing Joomla does when it tries to
	 * determine the license key of our extension when installing updates. If the extension is missing, it has no
	 * associated update sites, the update sites are missing / rebuilt / disassociated from the extension or the
	 * extra_query of all update site records is empty we parse the $extraQuery set in the constructor, if any. Also
	 * note that on Joomla 4 mode if the extension does not exist, does not have a manifest or does not have a valid
	 * dlid element in its manifest we will end up returning an empty string, just like Joomla! itself would have done
	 * when installing updates.
	 *
	 * @param   bool  $forceLegacy  Should I always retrieve the legacy license key, even in J4?
	 *
	 * @return  string
	 */
	public function getLicenseKey(bool $forceLegacy = false): string
	{
		// Joomla! 4. We need to parse the extra_query of the update sites to get the correct Download ID.
		$updateSites = $this->getUpdateSites();
		$extra_query = array_reduce($updateSites, function ($extra_query, $updateSite) {
			if (!empty($extra_query))
			{
				return $extra_query;
			}

			return $updateSite['extra_query'];
		}, '');

		// Fall back to legacy extra query
		if (empty($extra_query))
		{
			return '';
		}

		// Return the parsed results.
		return $this->getLicenseKeyFromExtraQuery($extra_query);
	}

	/**
	 * Returns an object with the #__extensions table record for the current extension.
	 *
	 * @return  object|null
	 */
	public function getExtensionObject()
	{
		return Extensions::getRecord($this->extensionKey);
	}

	/**
	 * Sanitizes the license key.
	 *
	 * YOU SHOULD OVERRIDE THIS METHOD. The default implementation returns a lowercase string with all characters except
	 * letters, numbers and colons removed.
	 *
	 * @param   string  $licenseKey
	 *
	 * @return  string  The sanitized license key
	 */
	public function sanitizeLicenseKey(string $licenseKey): string
	{
		return strtolower(preg_replace("/[^a-zA-Z0-9:]/", "", $licenseKey));
	}

	/**
	 * Is the provided string a valid license key?
	 *
	 * YOU SHOULD OVERRIDE THIS METHOD. The default implementation checks for valid Download IDs in the format used by
	 * Akeeba software.
	 *
	 * @param   string  $licenseKey
	 *
	 * @return  bool
	 */
	public function isValidLicenseKey(string $licenseKey): bool
	{
		return preg_match('/^(\d{1,}:)?[0-9a-f]{32}$/i', $licenseKey) === 1;
	}

	/**
	 * Extract the download ID from an extra_query based on the prefix and suffix information stored in the dlid element
	 * of the extension's XML manifest file.
	 *
	 * @param   string  $extra_query
	 *
	 * @return string
	 */
	protected function getLicenseKeyFromExtraQuery(?string $extra_query): string
	{
		$extra_query = trim($extra_query ?? '');

		if (empty($extra_query))
		{
			return '';
		}

		// Get the extension XML manifest. If the extension or the manifest don't exist return an empty string.
		$extension = $this->getExtensionObject();

		if (!$extension)
		{
			return '';
		}

		$installXmlFile = $this->getManifestXML(
			$extension->element,
			$extension->type,
			(int) $extension->client_id,
			$extension->folder
		);

		if (!$installXmlFile)
		{
			return '';
		}

		// If the manifest does not have a dlid element return an empty string.
		if (!isset($installXmlFile->dlid))
		{
			return '';
		}

		// Naive parsing of the extra_query, the same way Joomla does.
		$prefix     = (string) $installXmlFile->dlid['prefix'];
		$suffix     = (string) $installXmlFile->dlid['suffix'];
		$licenseKey = substr($extra_query, strlen($prefix));

		if ($licenseKey === false)
		{
			return '';
		}

		if ($suffix !== '')
		{
			$licenseKey = substr($licenseKey, 0, -strlen($suffix));
		}

		return ($licenseKey === false) ? '' : $licenseKey;
	}

	/**
	 * Get the manifest XML file of a given extension.
	 *
	 * @param   string   $element    element of an extension
	 * @param   string   $type       type of an extension
	 * @param   integer  $client_id  client_id of an extension
	 * @param   string   $folder     folder of an extension
	 *
	 * @return  SimpleXMLElement|bool False on failure
	 */
	protected function getManifestXML(string $element, string $type, int $client_id = 1, ?string $folder = null)
	{
		$path = ($client_id !== 0) ? JPATH_ADMINISTRATOR : JPATH_ROOT;

		switch ($type)
		{
			case 'component':
				$path .= '/components/' . $element . '/' . substr($element, 4) . '.xml';
				break;
			case 'plugin':
				$path .= '/plugins/' . $folder . '/' . $element . '/' . $element . '.xml';
				break;
			case 'module':
				$path .= '/modules/' . $element . '/' . $element . '.xml';
				break;
			case 'template':
				$path .= '/templates/' . $element . '/templateDetails.xml';
				break;
			case 'library':
				$path = JPATH_ADMINISTRATOR . '/manifests/libraries/' . $element . '.xml';
				break;
			case 'file':
				$path = JPATH_ADMINISTRATOR . '/manifests/files/' . $element . '.xml';
				break;
			case 'package':
				$path = JPATH_ADMINISTRATOR . '/manifests/packages/' . $element . '.xml';
		}

		return simplexml_load_file($path);
	}


	private function createFakePackageExtension()
	{
		/** @var DatabaseDriver $db */
		$db = $this->getDatabase();

		$manifestCacheJson = json_encode([
			'name'         => 'Admin Tools package',
			'type'         => 'package',
			'creationDate' => gmdate('Y-m-d'),
			'author'       => 'Nicholas K. Dionysopoulos',
			'copyright'    => sprintf('Copyright (c)2010-%d Akeeba Ltd / Nicholas K. Dionysopoulos', gmdate('Y')),
			'authorEmail'  => '',
			'authorUrl'    => 'https://www.akeeba.com',
			'version'      => $this->version,
			'description'  => sprintf('Admin TOols installation package v.%s', $this->version),
			'group'        => '',
			'filename'     => 'pkg_admintools',
		]);

		$extensionRecord = [
			'name'             => 'Admin Tools package',
			'type'             => 'package',
			'element'          => 'pkg_admintools',
			'folder'           => '',
			'client_id'        => 0,
			'enabled'          => 1,
			'access'           => 1,
			'protected'        => 0,
			'manifest_cache'   => $manifestCacheJson,
			'params'           => '{}',
			'checked_out'      => 0,
			'checked_out_time' => null,
			'state'            => 0,
		];

		$extension = new Extension($db);
		$extension->save($extensionRecord);

		$this->createFakePackageManifest();
	}

	private function createFakePackageManifest()
	{
		$path = sprintf("%s/manifests/packages/%s.xml", JPATH_ADMINISTRATOR, $this->extensionKey);

		if (file_exists($path))
		{
			return;
		}

		$isPro   = defined('ADMINTOOLS_PRO') ? ADMINTOOLS_PRO : 0;
		$proCore = $isPro ? 'pro' : 'core';
		$dlid    = $isPro ? '<dlid prefix="dlid=" suffix=""/>' : '';
		$year    = gmdate('Y');
		$date    = gmdate('Y-m-d');

		$proPlugins = <<< END
		<file type="plugin" group="console" id="admintools">plg_console_admintools.zip</file>
END;
		$proPlugins = $isPro ? $proPlugins : '';

		$content = <<< XML
<?xml version="1.0" encoding="utf-8"?>
<extension version="3.9.0" type="package" method="upgrade">
	$dlid
    <name>Admin Tools package</name>
    <author>Nicholas K. Dionysopoulos</author>
    <creationDate>$date</creationDate>
    <packagename>admintools</packagename>
    <version>{$this->version}</version>
    <url>https://www.akeeba.com</url>
    <packager>Akeeba Ltd</packager>
    <packagerurl>https://www.akeeba.com</packagerurl>
    <copyright>Copyright (c)2010-$year Akeeba Ltd / Nicholas K. Dionysopoulos</copyright>
    <license>GNU GPL v3 or later</license>
    <description>Admin Tools installation package {$this->version}</description>

    <files>
        <file type="component" id="com_admintools">com_admintools-{$proCore}.zip</file>
        <file type="plugin" group="system" id="admintools">plg_system_admintools-{$proCore}.zip</file>
        <file type="plugin" group="actionlog" id="admintools">plg_actionlog_admintools.zip</file>
        $proPlugins
    </files>

    <scriptfile>script.admintools.php</scriptfile>
</extension>
XML;

		if (!@file_put_contents($content, $path))
		{
			try
			{
				File::write($path, $content);
			}
			catch (Exception $e)
			{
				// Swallow
			}
		}
	}
}