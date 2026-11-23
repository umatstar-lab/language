<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\Helper\AllowedDomainsFilter;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Uri\Uri;
use Joomla\Utilities\IpHelper;

#[\AllowDynamicProperties]
class QuickstartModel extends BaseDatabaseModel
{
	/**
	 * The parameters storage model
	 *
	 * @var   Storage
	 */
	private $storageModel;

	/**
	 * Administrator password protection model
	 *
	 * @var   AdminpasswordModel
	 */
	private $adminPasswordModel;

	/**
	 * WAF Config model
	 *
	 * @var   ConfigurewafModel
	 */
	private $wafModel;

	/**
	 * WAF configuration
	 *
	 * @var   array
	 */
	private $config;

	/**
	 * Storage keys which survive the wizard's configuration reset.
	 *
	 * htconfig: holds the .htaccess Maker configuration, including the administrator's Custom Rules (gh-378).
	 * mainpassword, masterpassword, lockedviews: the Main Password and the views it locks.
	 * dirperms, fileperms, perms_show_hidden: Fix Permissions preferences, unrelated to the wizard.
	 * filescanner.memory, filescanner.timestamp: the state of a file scan which may be running.
	 *
	 * @var  string[]
	 */
	private const PRESERVED_STORAGE_KEYS = [
		'htconfig', 'mainpassword', 'masterpassword', 'lockedviews', 'dirperms', 'fileperms', 'perms_show_hidden',
		'filescanner.memory', 'filescanner.timestamp',
	];

	public function __construct($config = [], ?MVCFactoryInterface $factory = null)
	{
		parent::__construct($config, $factory);

		$this->storageModel       = Storage::getInstance();
		$this->adminPasswordModel = $this->getMVCFactory()->createModel('Adminpassword', 'Administrator', ['ignore_request' => true]);
		$this->wafModel           = $this->getMVCFactory()->createModel('Configurewaf', 'Administrator', ['ignore_request' => true]);
		$this->config             = $this->wafModel->getConfig();
	}

	/**
	 * Applies the wizard preferences to the component's configuration
	 *
	 * @return  void
	 */
	public function applyPreferences()
	{
		$this->resetStoredSettings();

		// Apply administrator secret URL parameter
		$this->config['adminpw'] = $this->getState('adminpw', '');
		$this->config['adminpw_action'] = 'redirect';

		// Password protect administrator
		$this->applyAdministratorPassword();

		// Apply email on admin login
		$this->config['emailonadminlogin']       = $this->getState('emailonadminlogin', '');
		$this->config['emailonfailedadminlogin'] = $this->getState('emailonadminlogin', '');

		// Apply IP whitelist
		$this->applyIpWhitelist();

		// Disable editing backend users' properties
		$this->config['nonewadmins'] = $this->getState('nonewadmins', 0);

		// Forbid front-end Super Administrator login
		$this->config['nofesalogin'] = $this->getState('nofesalogin', 0);

		// Enable WAF
		$this->applyWafPreferences($this->getState('enablewaf', 0));

		// Apply IP autoban preferences
		$this->applyAutoban($this->getState('autoban', 0));

		// Apply automatic permanent blacklist
		$this->applyBlacklist($this->getState('autoblacklist', 0));

		// Apply email address to report WAF exceptions and blocks
		$this->config['emailbreaches']       = $this->getState('emailbreaches', '');
		$this->config['emailafteripautoban'] = $this->getState('emailbreaches', '');

		// Apply allowed domains. This is now a repeatable subform field, like Configure WAF's, so it POSTs as
		// ['__field1' => ['item' => 'domain.tld'], …]. Reuse the WAF model's own subform-to-array conversion so
		// this never regresses to the gh-342 array-to-string bug.
		$allowedDomains = $this->getState('allowed_domains', []);
		$allowedDomains = is_array($allowedDomains) ? $allowedDomains : [];
		$allowedDomains = $this->wafModel->convertFormDataToDatabaseData(['allowed_domains' => $allowedDomains])['allowed_domains'];
		$allowedDomains = AllowedDomainsFilter::sanitize($allowedDomains);
		$allowedDomains = array_filter($allowedDomains, function ($x) {
			return !$this->isLocalhost(false, $x);
		});
		$this->config['allowed_domains'] = array_values($allowedDomains);

		// Project Honeypot HTTP:BL
		$this->applyProjectHoneypot($this->getState('bbhttpblkey', ''));

		// Save the WAF configuration
		$this->wafModel->saveConfig($this->config);

		// Apply .htaccess Maker
		if ($this->getState('htmaker', 0))
		{
			$written = $this->applyHtmaker();

			if (!$written)
			{
				Factory::getApplication()->enqueueMessage(Text::_('COM_ADMINTOOLS_QUICKSTART_MSG_HTMAKERNOTAPPLIED'), 'error');
			}
		}

		// Save a flag indicating we no longer need to run the Quick Start
		$this->storageModel->load();
		$this->storageModel->setValue('quickstart', 1, 1);
	}

	/**
	 * Resets the stored settings, except the keys in PRESERVED_STORAGE_KEYS
	 *
	 * @return  void
	 */
	private function resetStoredSettings()
	{
		$kept = [];

		foreach (self::PRESERVED_STORAGE_KEYS as $key)
		{
			$value = $this->storageModel->getValue($key);

			if ($value !== null)
			{
				$kept[$key] = $value;
			}
		}

		$this->storageModel->resetContents();

		foreach ($kept as $key => $value)
		{
			$this->storageModel->setValue($key, $value);
		}
	}

	/**
	 * Is it the Quick Setup Wizard's first run?
	 *
	 * @return  bool
	 */
	public function isFirstRun()
	{
		return $this->storageModel->getValue('quickstart', 0) == 0;
	}

	/**
	 * Password protect / unprotect administrator
	 *
	 * @return  void
	 */
	private function applyAdministratorPassword()
	{
		$username = $this->getState('admin_username', '');
		$password = $this->getState('admin_password', '');

		$this->adminPasswordModel->setState('mode', 'everything');
		$this->adminPasswordModel->setState('resetErrorPages', 'true');
		$this->adminPasswordModel->setState('username', $username);
		$this->adminPasswordModel->setState('password', $password);

		if (empty($username) || empty($password))
		{
			$this->adminPasswordModel->unprotect();

			return;
		}

		$this->adminPasswordModel->protect();
	}

	/**
	 * Apply administrator IP whitelist
	 *
	 * @return  void
	 */
	private function applyIpWhitelist()
	{
		$this->config['ipwl'] = $this->getState('ipwl', 0);

		if (!$this->config['ipwl'])
		{
			return;
		}

		// Remove all previously allowed IP addresses
		$db = $this->getDatabase();
		$db->truncateTable('#__admintools_adminiplist');

		// Add the current IP address as an exclusively allowed IP address
		$detectedIp = $this->getState('detectedip', '');
		$currentIP  = IpHelper::getIp();
		$ip         = (!empty($detectedIp) && ($detectedIp != $currentIP)) ? $detectedIp : $currentIP;

		$o = (object) [
			'ip' => IpExpressionValidator::normalise($ip),
			'description' => Text::_('COM_ADMINTOOLS_QUICKSTART_MSG_IPADDEDBYWIZARD'),
		];

		$db->insertObject('#__admintools_adminiplist', $o);
	}

	/**
	 * Apply main WAF preference (global disable/enable)
	 *
	 * @param   bool  $enabled  Should I enable WAF?
	 *
	 * @return  void
	 */
	private function applyWafPreferences($enabled = true)
	{
		$state = $enabled ? 1 : 0;

		$newValues = [
			'ipbl'                    => $state,
			'sqlishield'              => $state,
			'antispam'                => 0,
			'custgenerator'           => $state,
			'generator'               => 'MYOB',
			'tmpl'                    => $state,
			'template'                => $state,
			'logbreaches'             => 1,
			'muashield'               => $state,
			'rfishield'               => $state,
			'dfishield'               => $state,
			'uploadshield'            => $state,
			'uploadshield_htaccess'   => $state,
			'uploadshield_multiext'   => $state,
			'uploadshield_phpext'     => $state,
			'uploadshield_phpcontent' => $state,
			'sessionshield'           => $state,
			'tmplwhitelist'           => ['component','system','raw','koowa','cartupdate'],
			'allowsitetemplate'       => 0,
			'trackfailedlogins'       => $state,
			'use403view'              => 0,
			'iplookup'                => 'whatismyipaddress.com/ip/{ip}',
			'saveusersignupip'        => $state,
			'whitelist_domains'       => [],
			'reasons_nolog'           => ['crawler'],
			'reasons_noemail'         => ['crawler'],
			'resetjoomlatfa'          => 0,
			'email_throttle'          => 1,
			'selfprotect'             => 1,
			'criticalfiles'           => 1,
			'superuserslist'          => 0,
		];

		$this->config = array_merge($this->config, $newValues);
	}

	/**
	 * Apply automatic IP ban
	 *
	 * @param   bool  $enabled  Should I enable it?
	 *
	 * @return  void
	 */
	private function applyAutoban($enabled = true)
	{
		$state = $enabled ? 1 : 0;

		$newValues = [
			'tsrenable'       => $state,
			'tsrstrikes'      => 3,
			'tsrnumfreq'      => 1,
			'tsrfrequency'    => 'minute',
			'tsrbannum'       => 15,
			'tsrbanfrequency' => 'minute',
		];

		$this->config = array_merge($this->config, $newValues);
	}

	/**
	 * Apply automatic IP ban
	 *
	 * @param   bool  $enabled  Should I enable it?
	 *
	 * @return  void
	 */
	private function applyBlacklist($enabled = true)
	{
		$state = $enabled ? 1 : 0;

		$newValues = [
			'permaban'    => $state,
			'permabannum' => 3,
		];

		$this->config = array_merge($this->config, $newValues);
	}

	/**
	 * Apply Project Honeypot HTTP:BL settings
	 *
	 * @param   string  $key  The HTTP:BL key
	 *
	 * @return  void
	 */
	private function applyProjectHoneypot($key = '')
	{
		$state = empty($key) ? 0 : 1;

		$newValues = [
			'bbhttpblkey'           => $key,
			'httpblenable'          => $state,
			'httpblthreshold'       => 25,
			'httpblmaxage'          => 30,
			'httpblblocksuspicious' => 0,
		];

		$this->config = array_merge($this->config, $newValues);
	}

	private function applyHtmaker()
	{
		/** @var HtaccessmakerModel $htMakerModel */
		$htMakerModel = $this->getMVCFactory()->createModel('Htaccessmaker');

		// Get the site's hostname and base directory
		$hostname = $this->getSiteHostname();
		$baseDir  = $this->getSiteBaseDir();

		// Should I redirect non-www to www or vice versa?
		$wwwRedir = substr($hostname, 0, 4) == 'www.' ? 1 : 2;
		$wwwRedir = $this->isLocalhost() ? 0 : $wwwRedir;

		// Enable HSTS on HTTPS sites. Literal localhost will never have HSTS enabled on it.
		$isHSTS = !$this->isLocalhost(true) && Uri::getInstance()->getScheme() == 'https';

		// Create an object with fine-tuned rules for this site
		try
		{
			$defaultConfig = $htMakerModel->getDefaultConfig() ?: [];
		}
		catch (\Exception $e)
		{
			$defaultConfig = [];
		}

		// Keep the administrator's Custom Rules; the wizard must not reset them
		try
		{
			$currentConfig = $htMakerModel->loadConfiguration();
		}
		catch (\Exception $e)
		{
			$currentConfig = (object) [];
		}

		$custHead = (string) ($currentConfig->custhead ?? '');
		$custFoot = $htMakerModel->appendPhpHandlers((string) ($currentConfig->custfoot ?? ''), $htMakerModel->getPhpHandlers());

		$newConfig = array_merge($defaultConfig, [
			'nodirlists'          => 0,
			'symlinks'            => -1,
			'exptime'             => 2,
			'autocompress'        => 1,
			'autoroot'            => 0,
			'wwwredir'            => $wwwRedir,
			'hstsheader'          => $isHSTS ? 1 : 0,
			'nohoggers'           => 1,
			'clickjacking'        => 0,
			'reducemimetyperisks' => 0,
			'reflectedxss'        => 0,
			'notransform'         => 0,
			'httphost'            => $hostname,
			'httpshost'           => $hostname,
			'rewritebase'         => $baseDir,
			// The administrator's Custom Rules, plus any PHP handlers found in the current .htaccess file
			'custhead'            => $custHead,
			'custfoot'            => $custFoot,
		]);

		$newConfig['httpsurls'] = array_filter($newConfig['httpsurls'] ?? [], fn($x) => !empty($x));

		// Pass everything back to the model, it will merge the new config with the default one
		$htMakerModel->saveConfiguration($newConfig);

		return $htMakerModel->writeConfigFile();
	}

	private function getSiteHostname(): string
	{
		$app       = Factory::getApplication();
		$live_site = trim($app->get('live_site') ?: '');
		$uri       = empty($live_site) ? Uri::getInstance() : Uri::getInstance($live_site);

		return strtolower($uri->getHost() ?: '');
	}

	public function getSiteBaseDir(): string
	{
		$app      = Factory::getApplication();
		$sitePath = Uri::base(true);

		if (substr($sitePath, -13) === '/installation')
		{
			$sitePath = substr($sitePath, 0, -13);
		}
		elseif (substr($sitePath, -14) === '/administrator')
		{
			$sitePath = substr($sitePath, 0, -14);
		}
		elseif (substr($sitePath, -4) === '/api')
		{
			$sitePath = substr($sitePath, 0, -4);
		}

		return trim($sitePath, '/') ?: '/';
	}

	private function isLocalhost(bool $strict = false, ?string $hostName = null): bool
	{
		$hostName = $hostName ?? $this->getSiteHostname();

		if (empty($hostName))
		{
			return false;
		}

		if (in_array($hostName, ['localhost', 'localhost.localdomain']))
		{
			return true;
		}

		if ($strict)
		{
			return false;
		}

		$ip = gethostbyname($hostName);

		if (($ip === '127.0.0.1') || ($ip === '::1'))
		{
			return true;
		}

		return false;
	}
}