<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\CloudIPRanges;
use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\Controller\ControlpanelController;
use Akeeba\Component\AdminTools\Administrator\Controller\ServerconfigmakerController;
use Akeeba\Component\AdminTools\Administrator\Helper\ServerTechnology;
use Akeeba\Component\AdminTools\Administrator\Helper\Storage;
use Akeeba\Component\AdminTools\Administrator\Mixin\RunPluginsTrait;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryAwareInterface;
use Joomla\CMS\Form\FormFactoryAwareTrait;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\MVC\Model\FormBehaviorTrait;
use Joomla\CMS\MVC\Model\FormModelInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;

#[\AllowDynamicProperties]
abstract class ServerconfigmakerModel extends BaseDatabaseModel implements FormFactoryAwareInterface, FormModelInterface
{
	use FormBehaviorTrait;
	use FormFactoryAwareTrait;
	use RunPluginsTrait;

	/**
	 * Regular expression alternation of the file extensions a server may hand to PHP: .php and its numbered variants
	 * (.php3, .php5, .php74, …), .phtml, .phtm, .pht, .phar and .phps (source display).
	 *
	 * Always match it case-insensitively: mod_mime and IIS treat extensions, and IIS file names, case-insensitively.
	 *
	 * @var string
	 */
	protected const PHP_EXTENSIONS = 'php\d*|phtml?|pht|phar|phps';

	/**
	 * Regular expression alternation of the static files commonly found in a site's root folder, which Server
	 * Protection always allows there, like robots.txt (gh-469): browser and app icons, web app manifests, ads.txt and
	 * the files search engines and social networks ask site owners to upload to prove they own the site. The same list
	 * as Admin Tools for WordPress. An apple-touch-icon may give its size before -precomposed, as iOS asks for it
	 * (apple-touch-icon-120x120-precomposed.png), or after it (gh-472).
	 *
	 * Match it against the file name in the site's root folder only, anchored at both ends, and case-insensitively.
	 * Every group is non-capturing, so it never shifts the back-references of the rule it is put in. It is a regular
	 * expression already: never pass it through pathRegex(), entryRegex() or fileTypesRegex().
	 *
	 * @var string
	 */
	protected const ROOT_STATIC_FILES = 'favicon\.(?:ico|png|svg)'
		. '|apple-touch-icon(?:(?:-\d+x\d+)?(?:-precomposed)?|-precomposed-\d+x\d+)\.png'
		. '|(?:app-)?ads\.txt|humans\.txt'
		. '|browserconfig\.xml|site\.webmanifest|manifest\.json'
		. '|google[0-9a-f]+\.html|BingSiteAuth\.xml|yandex_[0-9a-f]+\.html|pinterest-[0-9a-f]+\.html';

	/**
	 * PHP_EXTENSIONS without .phps: the extensions of the files a server may run as PHP.
	 *
	 * A .phps file is a PHP source file. Servers which know the extension show its source, highlighted, instead of
	 * running it. The file type lists (bepextypes, fepextypes) may allow it (gh-456), but never these. Everywhere else
	 * a .phps file is a PHP file all the same: PHP_EXTENSIONS. Always match it case-insensitively.
	 *
	 * @var string
	 */
	protected const PHP_EXECUTABLE_EXTENSIONS = 'php\d*|phtml?|pht|phar';

	/**
	 * The regular expressions of "Protect against common file injection attacks" (fileinj), matched against the raw query
	 * string, case-insensitively, by every maker; a request matching any of them is refused, unless it is exempt, see
	 * FILE_INJECTION_EXEMPT_FOLDERS and FILE_INJECTION_EXEMPT_QUERY. They are applied to every request method: the query
	 * string of a POST request reaches $_GET and $_REQUEST all the same.
	 *
	 * They match the raw characters only, never their URL-encoded forms (%2F, %3A, %2E, %5C, %68…). Joomla! extensions
	 * send URL-encoded URLs and paths in the query string as a matter of course: Akeeba Backup's OAuth2 callback and JSON
	 * API, return URLs, social login and payment extensions, folder browsers with paths such as
	 * %5BSITEROOT%5D%2F..%2F..%2Fbackups. Matching the encoded forms too (gh-485, before it was released) refused all of
	 * them. A raw URL or path in a query string is what a crafted request sends; a link built by an extension encodes it.
	 *
	 * Each one starts with [^&]=, the last character of a parameter name, whatever it is (x[]= too, which PHP turns into
	 * an array), and its =. A bare = would not do: mod_rewrite reads a pattern starting with = as a string comparison.
	 * They are, in order:
	 *
	 * - An http:// or https:// URL at the start of a value: remote file inclusion.
	 * - Directory traversal: a value starting with a ../ or ..\ path segment, or with two such segments in a row anywhere
	 *   in it, e.g. images/../../etc/passwd. A ./ segment or a repeated separator is a no-op to the file system, so it
	 *   does not break the row: a/.././../etc, a/..//../x, ./../etc and .//../etc are refused too. The .. must be a whole
	 *   segment, so text such as "wait.../what" or file names such as "x..y" pass. A single ../ later in a value passes
	 *   too, e.g. [SITEROOT]/../backups or a/../b/../c: Akeeba Backup, for one, uses such paths. The optional group ends
	 *   in a separator, not in (\.?[/\x5c])*: two such stars side by side would backtrack quadratically on a long run of
	 *   separators, and mod_rewrite and NginX treat a PCRE match limit error as "no match".
	 * - A value starting with a folder of one character, e.g. /a/, /./. Kept as it always was.
	 * - A value starting with /etc/, /proc/ or /root/: a system file. Only these, not every absolute path: sites, their
	 *   temporary and log folders, and backups, live under /var, /home, /srv, /usr, /tmp, /opt, a /dev staging folder…,
	 *   and Joomla! and its extensions put such paths, and site-relative paths like return=/, in query strings.
	 *
	 * Before gh-485 they matched http:// only (NginX, IIS), case-sensitively, after a parameter name ending in
	 * [a-zA-Z0-9_], ../ only at the very start of a value, and, in the .htaccess Maker, GET requests only.
	 *
	 * \x5c is the backslash, written so that no config file syntax ever needs to escape it.
	 *
	 * @since 7.10.0
	 */
	protected const FILE_INJECTION_PATTERNS = [
		'[^&]=https?://',
		'[^&]=([^&]*[/\x5c]\.\.[/\x5c])?(\.?[/\x5c])*\.\.[/\x5c]',
		'[^&]=/([a-z0-9_.]//?)+',
		'[^&]=/+(etc|proc|root)/',
	];

	/**
	 * The folders "Protect against common file injection attacks" (fileinj) never applies to, as a regular expression
	 * matching the start of a URL path relative to the site's root, case-insensitively: administrator and api, with or
	 * without anything after them (/administrator alone is redirected to /administrator/ by the server).
	 *
	 * These are Joomla!'s authenticated areas, the back-end and the API. Akeeba Backup and other administration tools pass
	 * paths and URLs in their query strings there, e.g. the folder browser's folder=[SITEROOT]/../../backups or
	 * folder=/root/site/images, and a site's administrator must never be locked out of their own back-end.
	 * The makers anchor it: .htaccess and web.config at their own folder (RewriteRule and <match url> see the path
	 * relative to it), NginX at the site's path ($uri is the whole path).
	 *
	 * @since 7.10.0
	 */
	protected const FILE_INJECTION_EXEMPT_FOLDERS = '(administrator|api)(/|$)';

	/**
	 * A query string "Protect against common file injection attacks" (fileinj) never refuses, matched case-insensitively:
	 * Akeeba Backup's OAuth2 helper (option=com_akeebabackup and view=oauth2, as whole parameters, in either order). It
	 * runs on the front end, and the OAuth2 provider, e.g. Google Drive or Dropbox, sends the user back to it with raw
	 * URLs in the query string, e.g. scope=https://www.googleapis.com/auth/drive.
	 *
	 * One alternation for both orders and no lookaround: IIS's URL Rewrite refuses some PCRE syntax, see
	 * WebconfigmakerModel::isIisRegex().
	 *
	 * @since 7.10.0
	 */
	protected const FILE_INJECTION_EXEMPT_QUERY = '(^|&)option=com_akeebabackup&(.*&)?view=oauth2(&|$)'
		. '|(^|&)view=oauth2&(.*&)?option=com_akeebabackup(&|$)';

	/**
	 * The configuration keys holding a list of IP addresses which saveConfiguration() normalises, see
	 * IpExpressionValidator::normaliseList(). The CLI's set commands normalise the value of --remove for these keys too.
	 *
	 * proxy_ips, the NginX Conf Maker's reverse proxy IPs, was missing (gh-477): saved with leading zeros, written as
	 * such to set_real_ip_from, and only removed with the CLI by the exact value. A key a maker does not have is skipped.
	 *
	 * @since 7.10.0
	 */
	public const IP_LIST_KEYS = ['restrictip_custom', 'proxy_ips'];

	/**
	 * The current configuration of this feature
	 *
	 * @var  object
	 */
	protected $config = null;

	/**
	 * The Admin Tools configuration key under which we'll save $config as a JSON-encoded string
	 *
	 * @var  string
	 */
	protected $configKey = '';

	/**
	 * The methods which are allowed to call the saveConfiguration method. Each line is in the format:
	 * Full\Class\Name::methodName
	 *
	 * @var  array
	 */
	protected $allowedCallersForSave = [];

	/**
	 * The methods which are allowed to call the writeConfigFile method. Each line is in the format:
	 * Full\Class\Name::methodName
	 *
	 * @var  array
	 */
	protected $allowedCallersForWrite = [];

	/**
	 * The methods which are allowed to call the makeConfigFile method. Each line is in the format:
	 * Full\Class\Name::methodName
	 *
	 * @var  array
	 */
	protected $allowedCallersForMake = [];

	/**
	 * These configuration keys are handled by subforms
	 *
	 * @var   string[]
	 * @since 7.0.0
	 */
	protected $subformConfigKeys = [
		'bepexdirs', 'fepexdirs', 'exceptionfiles', 'exceptiondirs', 'fullaccessdirs', 'httpsurls', 'proxy_ips',
	];

	/**
	 * Configuration keys which SHOULD contain arrays but MIGHT contain strings or objects
	 *
	 * The CLI's server configuration set commands only add to, or remove from, the keys listed here and in
	 * $subformConfigKeys; for any other key --add and --remove save no value at all.
	 *
	 * @var   array
	 * @since 7.0.11
	 */
	protected $arrayConfigKeys = [
		'hoggeragents', 'httpsurls', 'exceptionfiles', 'exceptiondirs', 'fullaccessdirs', 'bepextypes', 'fepextypes',
		'restrictip_custom',
	];

	/**
	 * Maps events to plugin groups.
	 *
	 * @var    array
	 * @since  7.0.0
	 */
	protected $events_map = null;

	/**
	 * The base name of the configuration file being saved by this feature, e.g. ".htaccess". The file is always saved
	 * in the site's root. Any old files under that name are renamed with a .admintools suffix.
	 *
	 * @var string
	 */
	protected $configFileName = '';

	/**
	 * Stores the version of the server engine.
	 *
	 * @var string
	 */
	protected $serverVersion = '';

	/**
	 * Should I put the config file in the public root?
	 *
	 * On Joomla 5 the public root (where the .htaccess and web.config file is written) may be different to the
	 * Joomla! installation root.
	 *
	 * For the .htaccess and web.config Maker we must always place the generated file in the public root, be it
	 * JPATH_ROOT or JPATH_PUBLIC since the server always looks for the file there.
	 *
	 * For the NginX Conf Maker it makes more sense to always use JPATH_ROOT. The server only looks for this file where
	 * the user explicitly tells it to, therefore it is safer to put in a non-public directory. The NginX Conf Maker
	 * sets this flag to false, communicating that.
	 *
	 * @var   bool
	 * @since 7.4.3
	 */
	protected $usePublicRoot = true;

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?FormFactoryInterface $formFactory = null)
	{
		$config['events_map'] = $config['events_map'] ?? [];

		$this->events_map = array_merge(
			['validate' => 'content'],
			$config['events_map']
		);

		parent::__construct($config, $factory);

		$this->setFormFactory($formFactory);

		// Set up the allowed callers
		$classParts = explode('\\', get_class($this));
		$viewName   = substr(array_pop($classParts), 0, -5);

		$this->allowedCallersForSave = [
			ServerconfigmakerController::class . '::reset',
			ServerconfigmakerController::class . '::saveOrApply',
			'Akeeba\Component\AdminTools\Administrator\Controller\\' . $viewName . 'Controller::reset',
			'Akeeba\Component\AdminTools\Administrator\Controller\\' . $viewName . 'Controller::saveOrApply',
            'Akeeba\Component\AdminTools\Administrator\Model\HtaccessmakerModel::includePhpHandlers',
			QuickstartModel::class . '::applyPreferences',
			QuickstartModel::class . '::applyHtmaker',
			ExportimportModel::class . '::importData',
			'Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration\ServerConfigurationSet::doExecute',
			'Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration\ServerConfigurationMake::doExecute',
			ControlpanelModel::class . '::serverConfigUpdateFrontendDirectories',
		];

		$this->allowedCallersForWrite = [
			ServerconfigmakerController::class . '::apply',
			ServerconfigmakerController::class . '::saveOrApply',
			'Akeeba\Component\AdminTools\Administrator\Controller\\' . $viewName . 'Controller::apply',
			'Akeeba\Component\AdminTools\Administrator\Controller\\' . $viewName . 'Controller::saveOrApply',
			ControlpanelController::class . '::regenerateServerConfig',
			QuickstartModel::class . '::applyPreferences',
			QuickstartModel::class . '::applyHtmaker',
			'Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration\ServerConfigurationSet::doExecute',
			'Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration\ServerConfigurationMake::doExecute',
			ControlpanelModel::class . '::serverConfigUpdateFrontendDirectories',
		];

		$this->allowedCallersForMake = [
			ServerconfigmakerController::class . '::apply',
			ServerconfigmakerController::class . '::saveOrApply',
			'Akeeba\Component\AdminTools\Administrator\Controller\\' . $viewName . 'Controller::apply',
			'Akeeba\Component\AdminTools\Administrator\Controller\\' . $viewName . 'Controller::saveOrApply',
			ControlpanelController::class . '::regenerateServerConfig',
			ControlpanelModel::class . '::serverConfigEdited',
			self::class . '::writeConfigFile',
			QuickstartModel::class . '::applyPreferences',
			QuickstartModel::class . '::applyHtmaker',
			'Akeeba\Component\AdminTools\Administrator\View\\' . $viewName . '\HtmlView::onBeforeMain',
			'Akeeba\Component\AdminTools\Administrator\View\\' . $viewName . '\HtmlView::onBeforePreview',
			'Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration\ServerConfigurationSet::doExecute',
			'Akeeba\Component\AdminTools\Administrator\CliCommand\ServerConfiguration\ServerConfigurationMake::doExecute',
			ControlpanelModel::class . '::getServerConfigInformation',
		];
	}

	/**
	 * Return the list of keys that are arrays
	 *
	 * @return array|string[]
	 */
	public function getArrayConfigKeys() : array
	{
		return $this->arrayConfigKeys;
	}

	/**
	 * Returns the list of configuration keys that are handled by a subform (ie they have array values)
	 *
	 * @return string[]
	 */
	public function getSubformConfigKeys(): array
	{
		return $this->subformConfigKeys;
	}

	/**
	 * Forces the server engine to a specific version
	 *
	 * @param string $version
	 *
	 * @return void
	 */
	public function setServerVersion(string $version)
	{
		$this->serverVersion = $version;
	}

	/**
	 * Method to validate the form data.
	 *
	 * @param   Form         $form   The form to validate against.
	 * @param   array        $data   The data to validate.
	 * @param   string|null  $group  The name of the field group to validate.
	 *
	 * @return  array|null  Array of filtered data if valid, null otherwise.
	 *
	 * @throws  Exception
	 * @see     FormRule
	 * @see     InputFilter
	 * @since   7.0.0
	 */
	public function validate(Form $form, array $data, ?string $group = null): ?array
	{
		// Include the plugins for the delete events.
		PluginHelper::importPlugin($this->events_map['validate']);

		$this->triggerPluginEvent('onContentBeforeValidateData', [$form, &$data]);

		// Filter and validate the form data.
		$data   = $form->filter($data);
		$return = $form->validate($data, $group);

		// Check for an error.
		if ($return instanceof Exception)
		{
			if (!method_exists($this, 'setError'))
			{
				throw new \RuntimeException($return->getMessage());
			}

			/** @noinspection PhpDeprecationInspection only called when deprecated code is not removed */
			$this->setError($return->getMessage());

			return null;
		}

		// Check the validation results.
		if ($return === false)
		{
			// Get the validation messages from the form.
			foreach ($form->getErrors() as $message)
			{
				if (!method_exists($this, 'setError'))
				{
					throw new \RuntimeException($message);
				}

				/** @noinspection PhpDeprecationInspection only called when deprecated code is not removed */
				$this->setError($message);
			}

			return null;
		}

		return $data;
	}

	/**
	 * @inheritDoc
	 */
	public function getForm($data = [], $loadData = true)
	{
		$form = $this->loadForm(
			'com_admintools.' . $this->getName(),
			$this->getName(),
			[
				'control'   => false,
				'load_data' => $loadData,
			]
		) ?: false;

		if (empty($form))
		{
			return false;
		}

		return $form;
	}

	/**
	 * Get the configuration file name.
	 *
	 * @param   bool  $absolutePath  Should I return the absolute path to it? Default: false (returns basename only).
	 *
	 * @return  string
	 */
	public function getConfigFileName(bool $absolutePath = false): string
	{
		if ($absolutePath)
		{
			$publicRoot = ($this->usePublicRoot && defined('JPATH_PUBLIC')) ? JPATH_PUBLIC : JPATH_ROOT;

			return $publicRoot . '/' . $this->configFileName;
		}

		return $this->configFileName;
	}

	/**
	 * Loads the feature's configuration from the database
	 *
	 * @param   bool  $withDefaults Should I load the defaults as well?
	 *
	 * @return  object
	 * @throws  Exception
	 */
	public function loadConfiguration(bool $withDefaults = true)
	{
		if (!is_null($this->config))
		{
			return (object) $this->config;
		}

		$params      = Storage::getInstance();
		$savedConfig = $params->getValue($this->configKey, '') ?: '';

		if (function_exists('base64_encode') && function_exists('base64_encode'))
		{
			$savedConfig = @base64_decode($savedConfig) ?: '[]';
		}

		$savedConfig  = @json_decode($savedConfig, true) ?: [];

		if ($withDefaults)
		{
			$this->config = array_merge($this->getDefaultConfig(), $savedConfig ?: []);
		}
		else
		{
			$this->config = $savedConfig ?: [];
		}

		$this->handlePotentialArrayKeys($this->config);

		return (object) $this->config;
	}

	/**
	 * Get the default configuration from the XML form's default value attributes
	 *
	 * @return  array
	 * @throws  Exception
	 * @since   7.0.0
	 */
	public function getDefaultConfig(): array
	{
		$form = $this->getForm([], false);

		// If the form cannot be loaded we know nothing about this feature's configuration keys.
		if (!$form instanceof Form)
		{
			return [];
		}

		$defaults = [];

		foreach ($form->getFieldsets() as $fieldset)
		{
			foreach ($form->getFieldset($fieldset->name) as $name => $field)
			{
				$value = $field->getAttribute('default');

				if (in_array($name, $this->subformConfigKeys))
				{
					$value = array_map('trim', explode(',', $value));
				}

				$defaults[$name] = $value;
			}
		}

		/**
		 * Finally, I get to fill in some values which cannot be placed in a static XML configuration file.
		 * These are things like the domain name, relative path to the site's root etc.
		 */
		$app      = Factory::getApplication();
		$uri      = Uri::getInstance(Uri::root(false));

		/**
		 * The site's path, as it is, e.g. /my site or /o'brien: every maker expects it so, and encodes it itself where a
		 * URL needs it (gh-454, gh-462). Joomla builds it from the decoded SCRIPT_NAME, but writes ', ", < and > as %27,
		 * %22, %3C and %3E: a site in o'brien defaulted to /o%27brien, which no request's path matches.
		 */
		$rootPath = str_replace(['%27', '%22', '%3C', '%3E'], ["'", '"', '<', '>'], Uri::root(true));

		$defaults['httphost']    = $uri->toString(['host', 'port']);
		$defaults['httpshost']   = $uri->toString(['host', 'port']);
		$defaults['rewritebase'] = $rootPath;

		return $defaults;
	}

	/**
	 * Save the configuration to the database
	 *
	 * @param   object|array  $data          The data to save
	 * @param   bool          $withDefaults  Should I get the default values before saving the configuration?
	 */
	public function saveConfiguration($data, bool $withDefaults = true)
	{
		// Make sure we are called by an expected caller
		ServerTechnology::checkCaller($this->allowedCallersForSave);

		$data          = is_object($data) ? (array) $data : $data;
		$defaultConfig = $this->getDefaultConfig();

		/**
		 * Filter out anything which is not a known configuration key.
		 *
		 * The list of known keys always comes from the form, never from the set of defaults we may be about to
		 * merge in. Deriving it from the latter would mean that calling this method with $withDefaults = false
		 * discards every single key of the data we were asked to save.
		 *
		 * If the form could not be read at all we have no whitelist to apply, in which case we let the data
		 * through unfiltered — that is the lesser evil compared to throwing all of it away.
		 */
		$knownConfigKeys = array_keys($defaultConfig);

		if (!empty($knownConfigKeys))
		{
			$data = array_filter($data, function ($key) use ($knownConfigKeys) {
				return in_array($key, $knownConfigKeys);
			}, ARRAY_FILTER_USE_KEY);
		}

		$config = $withDefaults ? array_merge($defaultConfig, $data) : $data;

		$this->handlePotentialArrayKeys($config);

		// Leading zeros in IPv4 octets are decimal; strip them from the IP lists, e.g. the custom IP restriction list.
		foreach (self::IP_LIST_KEYS as $key)
		{
			if (isset($config[$key]))
			{
				$config[$key] = IpExpressionValidator::normaliseList($config[$key]);
			}
		}

		// Make sure nobody tried to add the php extension to the list of allowed extension
		$filterPhpExtension = function ($v) {
			return strtolower($v) != 'php';
		};

		// Note: these keys are not necessarily present when we are saving without defaults.
		$config['bepextypes'] = array_filter($config['bepextypes'] ?? [], $filterPhpExtension);
		$config['fepextypes'] = array_filter($config['fepextypes'] ?? [], $filterPhpExtension);

		$this->config = $config;

		$config = json_encode($this->config);

		// This keeps Registry from happily corrupting our data :@
		if (function_exists('base64_encode') && function_exists('base64_encode'))
		{
			$config = base64_encode($config);
		}

		$params = Storage::getInstance();

		$params->setValue($this->configKey, $config);
		$params->setValue('quickstart', 1);

		$params->save();
	}

	/**
	 * Create and return the configuration file's contents. This is the heart of these features.
	 *
	 * @return  string
	 */
	abstract public function makeConfigFile();

	/**
	 * Make the configuration file and write it to the disk
	 *
	 * @return  bool
	 */
	public function writeConfigFile(): bool
	{
		// Make sure we are called by an expected caller
		ServerTechnology::checkCaller($this->allowedCallersForWrite);

		/**
		 * On Joomla 5 the public root (where the .htaccess and web.config file is written) may be different to the
		 * Joomla! installation root. However, for the NginX Conf Maker it makes more sense to always use JPATH_ROOT,
		 * as the file has to be explicitly included by the server's configuration, i.e. the server IS NOT necessarily
		 * looking for it in the public root.
		 */
		$htaccessPath = $this->getConfigFileName(true);
		$backupPath   = $htaccessPath . '.admintools';

		if (@file_exists($htaccessPath))
		{
			try
			{
				File::copy(basename($htaccessPath), basename($backupPath), dirname($htaccessPath));
			}
			catch (Exception $e)
			{
				// Swallow
			}
		}

		try
		{
			$configFileContents = $this->makeConfigFile();
		}
		catch (Exception $e)
		{
			Factory::getApplication()->enqueueMessage($e->getMessage(), 'warning');

			return false;
		}

		/**
		 * Convert CRLF to LF before saving the file. This would work around an issue with Windows browsers using CRLF
		 * line endings in text areas which would then be transferred verbatim to the output file. Most servers don't
		 * mind, but NginX will break hard when it sees the CR in the configuration file.
		 */
		$configFileContents = str_replace("\r\n", "\n", $configFileContents);

		try
		{
			return File::write($htaccessPath, $configFileContents);
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	/**
	 * Given a server configuration file, strips out header comments and create an hash of the remaining contents
	 *
	 * @param   string  $contents
	 *
	 * @return string
	 */
	public function getConfigHash(string $contents): string
	{
		// Get the lines of the configuration
		$lines = explode("\n", $contents);

		// Trim the lines and convert comments to empty lines
		$lines = array_map(function ($line) {
			$line = trim($line);

			if (substr($line, 0, 1) === '#')
			{
				$line = '';
			}

			return $line;
		}, $lines);

		// Remove empty lines
		$lines = array_filter($lines, function ($line) {
			return !empty($line);
		});

		// Get the MD5 of the normalised contents
		return hash('md5', implode("\n", $lines));
	}

	/**
	 * Checks if current redirection rules do match the URL saved inside the live_site variable. For example:
	 * - live_site: www.example.com - Redirect www to non-www   WRONG!
	 * - live_site: www.example.com - Redirect non-www to www   CORRECT!
	 *
	 * @return bool Are the live_site variable and current redirection rules compatible?
	 */
	public function enableRedirects(): bool
	{
		$live_site = Factory::getApplication()->get('live_site', '');

		// No value set (90% of cases), we're good to go
		if (!$live_site)
		{
			return true;
		}

		// The user put the protocol in the live site? That's an hard no
		if (stripos($live_site, 'http') !== false)
		{
			return false;
		}

		$config = $this->loadConfiguration();

		// No redirection set? We're good to go
		if (!$config->wwwredir)
		{
			return true;
		}

		// Got www site and a redirect from www to non-www, that's wrong
		if ((stripos($live_site, 'www.') === 0) && ($config->wwwredir === 2))
		{
			return false;
		}

		// Got non-www site and a redirect from non-www to www, that's wrong
		if ((stripos($live_site, 'www.') === false) && ($config->wwwredir === 1))
		{
			return false;
		}

		// Otherwise we're good to go
		return true;
	}

	/**
	 * Misconfigurations of the saved settings, which the maker's page warns about.
	 *
	 * - `restrictipEmpty`: "Restrict access by IP address" is set to a custom list, and the list the file would allow is
	 *   empty, see restrictIpList(). The file then has no IP restriction at all (gh-466).
	 * - `skippedUserAgents`: with "Block access from specific user agents" on, the entries of "User agents to block"
	 *   which this maker skips, as typed (trimmed), see blockedUserAgentRegex(): they are not a regular expression this
	 *   maker's server can use. The file is made without them, so that the site keeps working (gh-461, gh-462); the user
	 *   is told which (gh-466). Blank and duplicate entries are dropped silently, see normaliseUserAgents().
	 *
	 * Only the custom list is checked: the internal network list is never empty, and the lists of the CDN providers are
	 * downloaded, which a page load must not do. They are never empty either; a failed download stops the file being
	 * made at all.
	 *
	 * @return  array{restrictipEmpty: bool, skippedUserAgents: string[]}
	 * @since   7.10.0
	 */
	public function getConfigurationWarnings(): array
	{
		$config  = $this->loadConfiguration();
		$skipped = [];

		if (($config->nohoggers ?? 0) == 1)
		{
			foreach ($this->normaliseUserAgents($config->hoggeragents ?? []) as $agent)
			{
				if ($this->blockedUserAgentRegex($agent) === null)
				{
					$skipped[] = $agent;
				}
			}
		}

		return [
			'restrictipEmpty'   => ($config->restrictip ?? 'none') === 'custom' && $this->restrictIpList($config) === [],
			'skippedUserAgents' => $skipped,
		];
	}

	/**
	 * Is this configuration file type supported on this server?
	 *
	 * @return  int  0: no; 1: yes; 2: maybe (unsure)
	 */
	abstract public function isSupported(): int;

	public function convertFormDataToDatabaseData($data)
	{
		/**
		 * Subforms give us data in the format:
		 * ['__field1' => ['item' => 'directory1'], '__field2' => ['item' => 'directory2']]
		 * I need to convert to the format:
		 * ['directory1', 'directory2']
		 *
		 * Each entry is trimmed, and only blank entries are dropped, as handlePotentialArrayKeys() does. They were
		 * filtered with empty(), which also dropped an entry 0: the CLI can add one since gh-475, and the next save of
		 * the page in the back-end silently removed it (gh-477).
		 */
		foreach ($this->subformConfigKeys as $key)
		{
			$value = array_map(function ($subformFields) {
				$item = $subformFields['item'] ?? null;

				return is_string($item) ? trim($item) : $item;
			}, ($data[$key] ?? []) ?: []);

			$data[$key] = array_values(array_filter($value, function ($x) {
				return ($x !== null) && ($x !== '');
			}));
		}

		return $data;
	}

	/**
	 * Converts data in a database format (['value1', 'value2']) to a format accepted by Joomla Form object
	 * ['__field1' => ['item' => 'value1'], '__field2' => ['item' => 'value2']]
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function convertDatabaseDataToFormData($data): array
	{
		$result = [];
		$i      = 1;

		foreach ($data as $item)
		{
			$result['__field'.$i] = ['item' => $item];

			$i++;
		}

		return $result;
	}

    /**
     * Checks if the current configuration file contains any directive for handling the PHP interpreter. We're adding this
     * here for consistency, but in reality this would only happen under Apache, since in NginX users already have to provide
     * this info and under IIS this is handled inside its management console.
     *
     * @param string $server_config
     *
     * @return string|null
     */
    public function extractHandler(string $server_config): ?string
    {
        return null;
    }

    /**
     * Does the current server configuration have any directive for PHP handlers?
     *
     * @return bool
     */
    public function hasPhpHandlers(): bool
    {
        return false;
    }

    /**
     * Gets any PHP handler directly from root .htaccess file
     *
     * @return string|null
     */
    public function getPhpHandlers(): ?string
    {
        return null;
    }

	protected function loadFormData()
	{
		$data = (array) $this->loadConfiguration();

		/** @var CMSApplication $app */
		$app  = Factory::getApplication();

		// Fetch the data from the state only if we're not under CLI
		if (!$app->isClient('cli'))
		{
			$data = $app->getUserState('com_admintools.' . $this->getName() . '.data', $data);
		}

		$this->preprocessData('com_admintools.' . $this->getName(), $data);

		return $data;
	}

	protected function preprocessData($context, &$data, $group = 'content')
	{
		if (is_object($data))
		{
			$data = (array) $data;
		}

		foreach ($this->subformConfigKeys as $key)
		{
			$value      = $data[$key] ?? [];
			$data[$key] = array_map(function ($x) {
				return ['item' => $x];
			}, $value);
		}

		if (method_exists($this, 'enableRedirects') && !$this->enableRedirects())
		{
			$data['wwwredirs'] = 0;
		}

		// Get the dispatcher and load the users plugins.
		PluginHelper::importPlugin($group);

		// Trigger the data preparation event.
		$this->triggerPluginEvent('onContentPrepareData', [$context, &$data]);
	}

	protected function preprocessForm(Form $form, $data, $group = 'content')
	{
		/**
		 * Special case: wwwredirs must be disabled (value and interaction) if there's a $live_site URL hard-coded.
		 */
		if (method_exists($this, 'enableRedirects') && !$this->enableRedirects())
		{
			$form->setFieldAttribute('wwwredirs', 'disabled', 'true');
			$form->setFieldAttribute('wwwredirs', 'required', 'false');
			$form->setFieldAttribute('wwwredirs', 'filter', 'unset');
		}

		// Import the appropriate plugin group.
		PluginHelper::importPlugin($group);

		// Trigger the form preparation event.
		$this->triggerPluginEvent('onContentPrepareForm', [$form, $data]);

	}

	/**
	 * The host name part of a host name field, without the site's path, which our documentation used to tell users to
	 * append to it.
	 *
	 * For example, example.com/blog becomes example.com. A port, if any, is kept. An internationalised host name is in
	 * its ASCII (punycode) form, see asciiHost(): nëw.example.com becomes xn--nw-hja.example.com (gh-476).
	 *
	 * @param   string  $host  The value of a host name field, e.g. httphost
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	protected function hostWithoutPath(string $host): string
	{
		return $this->asciiHost(explode('/', trim($host), 2)[0]);
	}

	/**
	 * The ASCII form of a host name, which is what browsers send in the Host header (gh-476).
	 *
	 * An internationalised host name, one with a non-ASCII letter, is converted to punycode, with the non-transitional
	 * processing of the URL standard which browsers use: ält.example.com becomes xn--lt-uia.example.com. That also
	 * lowercases it. A port is kept. A host name of ASCII characters only is returned as it is, in the letter case it was
	 * typed in: the rules match host names case-insensitively anyway. So is any host name without the PHP intl
	 * extension, and one it cannot convert, e.g. one with an empty label.
	 *
	 * @param   string  $host  A host name, with or without a port, e.g. ält.example.com:8080
	 *
	 * @return  string  E.g. xn--lt-uia.example.com:8080
	 * @since   7.10.0
	 */
	protected function asciiHost(string $host): string
	{
		[$name, $port] = $this->splitHostPort($host);

		if (!preg_match('/[^\x00-\x7F]/', $name) || !$this->hasIdn())
		{
			return $host;
		}

		$ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

		return is_string($ascii) && $ascii !== '' ? $ascii . $port : $host;
	}

	/**
	 * Can host names be converted to their ASCII (punycode) form? See asciiHost().
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected function hasIdn(): bool
	{
		return function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46')
			&& defined('IDNA_NONTRANSITIONAL_TO_ASCII');
	}

	/**
	 * A host name in lower case, non-ASCII letters too with mbstring: strtolower() only folds ASCII letters (gh-473).
	 *
	 * @param   string  $host  A host name
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	protected function lowerHost(string $host): string
	{
		return function_exists('mb_strtolower') ? mb_strtolower($host, 'UTF-8') : strtolower($host);
	}

	/**
	 * Splits the host name part of a host name field into the host name and the port.
	 *
	 * For example, example.com:8080 becomes example.com and :8080, and [::1]:8080 becomes [::1] and :8080. An IPv6
	 * address without brackets has no port. NginX's $host never contains the port, and keeps the brackets of an IPv6
	 * address.
	 *
	 * @param   string  $host  The host name part of a host name field, see hostWithoutPath()
	 *
	 * @return  string[]  The host name, and the port with a leading colon or an empty string
	 * @since   7.10.0
	 */
	protected function splitHostPort(string $host): array
	{
		$host = trim($host);

		if (substr($host, 0, 1) === '[' && ($end = strpos($host, ']')) !== false)
		{
			$port = substr($host, $end + 1);

			return [substr($host, 0, $end + 1), preg_match('/^:\d+$/', $port) ? $port : ''];
		}

		if (substr_count($host, ':') === 1 && preg_match('/^(.+):(\d+)$/', $host, $matches))
		{
			return [$matches[1], ':' . $matches[2]];
		}

		return [$host, ''];
	}

	/**
	 * The regular expression which matches an old domain (a "Redirect these (old) domain names" entry) in a Host header.
	 *
	 * The old domain and any subdomain of it (gh-456), followed by any port or none: old.example.com matches
	 * old.example.com and www.old.example.com, but neither notold.example.com nor old.example.com.evil.test. A port typed
	 * with the old domain is ignored, like the NginX Conf Maker does since gh-449. The caller matches it
	 * case-insensitively: host names are. See oldDomainCoversHost() for the old domains which must be left out.
	 *
	 * An internationalised old domain is matched in its ASCII (punycode) form, which browsers send (gh-476): see
	 * asciiHost(). Its Unicode form never matched any request.
	 *
	 * @param   string  $oldDomain  The old domain, e.g. old.example.com or old.example.com:8080
	 *
	 * @return  string  E.g. ^(.+\.)?old\.example\.com(:[0-9]+)?$
	 * @since   7.10.0
	 */
	protected function oldDomainHostRegex(string $oldDomain): string
	{
		[$name] = $this->splitHostPort($this->asciiHost($oldDomain));

		return '^(.+\.)?' . $this->escape_string_for_regex($name) . '(:[0-9]+)?$';
	}

	/**
	 * Does this old domain match the host name of this host name field, in any letter case and with any port?
	 *
	 * That is, is the host name the old domain itself, or a subdomain of it: see oldDomainHostRegex(). Then redirecting
	 * the old domain to that host name would redirect the site to itself, in a loop, so the old domain must be left out.
	 * For example, the old domain example.com is left out for a site on www.example.com, while sub.www.example.com, a
	 * subdomain of the site's host name rather than a parent domain, is still redirected.
	 *
	 * Both are compared in their ASCII (punycode) form (gh-476), so ält.example.com is left out for a site on
	 * www.xn--lt-uia.example.com, and the other way round, and in lower case, non-ASCII letters too.
	 *
	 * @param   string  $oldDomain  The old domain, e.g. old.example.com:8080
	 * @param   string  $host       The value of a host name field, e.g. httphost
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected function oldDomainCoversHost(string $oldDomain, string $host): bool
	{
		[$oldName] = $this->splitHostPort($this->asciiHost(trim($oldDomain)));
		[$name]    = $this->splitHostPort($this->hostWithoutPath($host));
		$oldName   = $this->lowerHost($oldName);
		$name      = $this->lowerHost($name);

		return $oldName !== ''
			&& ($name === $oldName || substr($name, -strlen($oldName) - 1) === '.' . $oldName);
	}

	/**
	 * The entries of "Redirect these (old) domain names" (olddomain), each once.
	 *
	 * Each entry is trimmed, and blank entries are dropped. An entry whose host name is that of an earlier one, regardless
	 * of letter case and of the port typed with it, is dropped (gh-470): host names ignore letter case, and the rules
	 * ignore the port of an old domain, see oldDomainHostRegex() and oldDomainCoversHost(), so it would only repeat the
	 * same rules. The web.config Maker names each rule after its old domain, and IIS refuses two rules whose names only
	 * differ in letter case with HTTP 500 for every request. The first spelling and the order of the entries are kept.
	 *
	 * The letter case of non-ASCII letters is ignored too (gh-473), e.g. ÄLT.example.com and ält.example.com, with
	 * mbstring; strtolower() only folds ASCII letters. Without mbstring, only ASCII letters are.
	 *
	 * An internationalised entry is in its ASCII (punycode) form, in lower case (gh-476), see asciiHost(): browsers send
	 * an internationalised host name in that form, so ält.example.com, typed in Unicode, never matched any request. So
	 * ält.example.com and XN--LT-UIA.example.com are the same old domain, and the first is kept as
	 * xn--lt-uia.example.com. Without the PHP intl extension, it is kept as typed.
	 *
	 * @param   string|string[]|null  $domains  The old domains; a string is a comma separated list
	 *
	 * @return  string[]  E.g. ['old.example.com', 'other.example.com:8080', 'xn--lt-uia.example.com']
	 * @since   7.10.0
	 */
	protected function normaliseOldDomains($domains): array
	{
		$domains = is_array($domains) ? $domains : explode(',', (string) $domains);
		$result  = [];
		$seen    = [];

		foreach ($domains as $domain)
		{
			if (!is_scalar($domain))
			{
				continue;
			}

			$domain = $this->asciiHost(trim((string) $domain));
			[$name] = $this->splitHostPort($domain);
			$key    = $this->lowerHost($name);

			if ($domain === '' || isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$result[]   = $domain;
		}

		return $result;
	}

	/**
	 * Escapes a string so that it's a neutral string inside a regular expression.
	 *
	 * @param   string  $str  The string to escape
	 *
	 * @return  string  The escaped string
	 */
	protected function escape_string_for_regex($str): string
	{
		//All regex special chars (according to arkani at iol dot pt below):
		// \ ^ . $ | ( ) [ ]
		// * + ? { } , -

		/**
		 * The backslash MUST be escaped first, and separately.
		 *
		 * If it were part of the replacements below it would also escape the backslashes those
		 * replacements themselves add, e.g. `we\.ird` would come out as `we\\\\.ird` instead of
		 * `we\\\.ird`. Doing it first means the patterns below only ever see — and never touch — the
		 * backslashes we have already doubled.
		 */
		$str = str_replace('\\', '\\\\', $str);

		$patterns = [
			'/\//', '/\^/', '/\./', '/\$/', '/\|/',
			'/\(/', '/\)/', '/\[/', '/\]/', '/\*/', '/\+/',
			'/\?/', '/\{/', '/\}/', '/\,/', '/\-/',
		];

		/**
		 * In a preg_replace() replacement \$ is a literal $, without the backslash: the $ needs \\$, a literal backslash
		 * and then the $. Otherwise a $ in e.g. a Force HTTPS entry was an end anchor, and the rule never matched.
		 */
		$replace = [
			'\/', '\^', '\.', '\\\\$', '\|', '\(', '\)',
			'\[', '\]', '\*', '\+', '\?', '\{', '\}', '\,', '\-',
		];

		return preg_replace($patterns, $replace, $str);
	}

	/**
	 * The file types (extensions) of a file type list, e.g. bepextypes, which can safely go into a server configuration
	 * file.
	 *
	 * Every server configuration maker builds its rules from this, so that they all treat the same list the same way:
	 * - Each entry is trimmed, and a leading dot is dropped: `.css` is `css`.
	 * - Empty entries are dropped.
	 * - An entry with any character other than A-Z, a-z, 0-9, dot, underscore, plus and hyphen is dropped whole, not
	 *   stripped of that character. A space, `;` or `"` would break the configuration file, and stripping characters
	 *   could turn an entry into another, allowed, extension.
	 * - An entry which is, or has a part which is, a PHP extension a server may run as PHP (see
	 *   PHP_EXECUTABLE_EXTENSIONS) is dropped: these lists allow files through Server Protection, which must never let a
	 *   PHP script through. saveConfiguration() only drops `php` itself; `.php`, which never matched anything before the
	 *   leading dot was dropped, would otherwise allow .php files. `phps`, in any letter case, is allowed (gh-456): a
	 *   .phps file is not run, its source is shown, which is what a site listing it wants. So is an entry with phps as a
	 *   part, e.g. phps.txt, unless another part is a PHP extension, e.g. phar.phps.
	 *
	 * The letter case and the order of the entries are kept.
	 *
	 * @param   string|string[]|null  $types  The file types; a string is a comma separated list
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function normaliseFileTypes($types): array
	{
		$types  = is_array($types) ? $types : explode(',', (string) $types);
		$result = [];

		foreach ($types as $type)
		{
			$type = ltrim(trim((string) $type), '.');

			if ($type === '' || !preg_match('/^[A-Za-z0-9._+\-]+$/', $type))
			{
				continue;
			}

			foreach (explode('.', $type) as $part)
			{
				if (preg_match('/^(' . self::PHP_EXECUTABLE_EXTENSIONS . ')$/i', $part))
				{
					continue 2;
				}
			}

			$result[] = $type;
		}

		return $result;
	}

	/**
	 * The alternatives of a regular expression which match the file types (extensions) of a file type list.
	 *
	 * The list is normalised by normaliseFileTypes(), and each entry is escaped, so that e.g. the dot in tar.gz only
	 * matches a dot. The caller puts an escaped dot before the alternatives.
	 *
	 * An empty string means there is no file type to match. The caller must then leave out the rule, never write `()`:
	 * an empty group matches a file name ending in a dot, or, after an unescaped dot, every file.
	 *
	 * @param   string|string[]|null  $types  The file types, e.g. bepextypes; a string is a comma separated list
	 *
	 * @return  string  E.g. css|js|tar\.gz
	 * @since   7.10.0
	 */
	protected function fileTypesRegex($types): string
	{
		return implode('|', array_map([$this, 'escape_string_for_regex'], $this->normaliseFileTypes($types)));
	}

	/**
	 * The folders of a folder list, e.g. bepexdirs, which can safely go into a server configuration file.
	 *
	 * Each folder is trimmed of white space and slashes, and empty ones are dropped. A folder with a control character
	 * (white space other than the space included, e.g. a tab or a new line), a double quote, a backslash or a semicolon is
	 * dropped whole: it would break the configuration file of at least one server, e.g. a new line starts another
	 * directive and `;` ends one of NginX's.
	 *
	 * A space and an apostrophe are valid folder name characters which every maker can express (gh-456): see
	 * directoriesRegex().
	 *
	 * The files and folders of the Server Protection exceptions go through it too, see normaliseExceptions().
	 *
	 * Each folder is listed once (gh-492): e.g. foo.php, /foo.php and foo.php/ are the same entry. The NginX Conf Maker
	 * writes an exact location for each excepted file, and NginX refuses the whole configuration over a duplicate one.
	 * The comparison is case-sensitive, like NginX's exact locations: Foo.php and foo.php are different files on a
	 * case-sensitive file system. In the other makers a duplicate was only a repeated rule.
	 *
	 * @param   string|string[]|null  $directories  The folders; a string is a comma separated list
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function normaliseDirectories($directories): array
	{
		$directories = is_array($directories) ? $directories : explode(',', (string) $directories);
		$result      = [];

		foreach ($directories as $directory)
		{
			$directory = trim((string) $directory, " \t\n\r\0\x0B/");

			if ($directory === '' || preg_match('/[[:cntrl:]"\\\\;]/', $directory))
			{
				continue;
			}

			$result[] = $directory;
		}

		return array_values(array_unique($result));
	}

	/**
	 * The alternatives of a regular expression which match the folders of a folder list.
	 *
	 * The list is normalised by normaliseDirectories(), and each folder is escaped, so that e.g. the dot in my.dir only
	 * matches a dot. An empty string means there is no folder: the caller must then leave out the rule, never write
	 * `()`.
	 *
	 * A space is written as \x20, which every server's regular expressions read as a space: an unescaped space ends the
	 * pattern of Apache's RewriteRule and the regular expression of an unquoted NginX location (gh-456). An apostrophe
	 * needs no escaping in a regular expression; it is only special to NginX at the start of a word, and each folder
	 * comes after the ^ and the site's path. The web.config Maker XML-escapes the result.
	 *
	 * @param   string|string[]|null  $directories  The folders, e.g. fepexdirs
	 *
	 * @return  string  E.g. images|my\.dir|my\x20folder|o'brien
	 * @since   7.10.0
	 */
	protected function directoriesRegex($directories): string
	{
		return implode('|', array_map([$this, 'pathRegex'], $this->normaliseDirectories($directories)));
	}

	/**
	 * The configuration, with its Server Protection exceptions normalised: "Allow direct access to these files"
	 * (exceptionfiles), "Allow direct access, except .php files, to these directories" (exceptiondirs) and "Allow direct
	 * access, including .php files, to these directories" (fullaccessdirs).
	 *
	 * They go through normaliseDirectories(), like the Server Protection folders (gh-454): each entry is trimmed of white
	 * space and slashes, a leading slash typed before a file included; an entry with a control character, a double
	 * quote, a backslash or a semicolon is dropped whole. A space and an apostrophe are kept: see entryRegex(). Every
	 * maker calls this once, right after loading its configuration, so that every rule built from these lists sees the
	 * same entries.
	 *
	 * @param   object  $config  The configuration, see loadConfiguration()
	 *
	 * @return  object  The same configuration object
	 * @since   7.10.0
	 */
	protected function normaliseExceptions(object $config): object
	{
		foreach (['exceptionfiles', 'exceptiondirs', 'fullaccessdirs'] as $key)
		{
			$config->{$key} = $this->normaliseDirectories($config->{$key} ?? []);
		}

		return $config;
	}

	/**
	 * Escapes a file or folder name, e.g. a Server Protection exception, for a regular expression.
	 *
	 * Like escape_string_for_regex(), and a space is written as \x20, which every server's regular expressions read as a
	 * space: an unescaped space ends the pattern of Apache's RewriteRule and the regular expression of an unquoted NginX
	 * location. See directoriesRegex().
	 *
	 * @param   string  $entry  The file or folder, e.g. images/my photos
	 *
	 * @return  string  E.g. images\/my\x20photos
	 * @since   7.10.0
	 */
	protected function entryRegex(string $entry): string
	{
		return str_replace(' ', '\\x20', $this->escape_string_for_regex($entry));
	}

	/**
	 * Escapes a URL path, e.g. the site's path or a folder, for a regular expression, keeping its slashes as they are.
	 *
	 * A space is written as \x20, see entryRegex(): an unescaped space in the site's path ended NginX's regular expression
	 * locations (gh-454).
	 *
	 * @param   string  $path  The path
	 *
	 * @return  string  E.g. /f\.o/my\x20bar
	 * @since   7.10.0
	 */
	protected function pathRegex(string $path): string
	{
		return str_replace('\\/', '/', $this->entryRegex($path));
	}

	/**
	 * A regular expression matching a URL path as a client sends it in the request line, e.g. Apache's %{THE_REQUEST} or
	 * IIS's {UNENCODED_URL}, which are not URL-decoded (gh-462).
	 *
	 * Letters, digits, `-`, `.`, `_`, `~` and `/` match themselves (a dot escaped). Any other character matches itself or
	 * its URL-encoded form, %XX in either letter case: a browser sends a space as %20, and may send e.g. a $ either way.
	 * The character itself is escaped with a backslash, or written as \xXX if it is not printable ASCII, e.g. a space or
	 * a byte of a UTF-8 character. Both PCRE and IIS's regular expressions read that the same way.
	 *
	 * @param   string  $path  The URL path, not URL-encoded, e.g. /my site
	 *
	 * @return  string  E.g. /my(?:%20|\x20)site
	 * @since   7.10.0
	 */
	protected function requestPathRegex(string $path): string
	{
		$regex = '';

		foreach ($path === '' ? [] : str_split($path) as $char)
		{
			if (preg_match('#^[A-Za-z0-9_~/]$#', $char))
			{
				$regex .= $char;

				continue;
			}

			if ($char === '.' || $char === '-')
			{
				$regex .= '\\' . $char;

				continue;
			}

			$regex .= $this->encodedCharRegex($char);
		}

		return $regex;
	}

	/**
	 * A regular expression matching a character, or byte, of a URL as a client may send it: as itself, or URL-encoded as
	 * %XX in either letter case. The character itself is escaped with a backslash, or written as \xXX if it is not
	 * printable ASCII, e.g. a space or a byte of a UTF-8 character. Both PCRE and IIS's regular expressions read that the
	 * same way.
	 *
	 * @param   string  $char  One byte
	 *
	 * @return  string  E.g. (?:%20|\x20) for a space
	 * @since   7.10.0
	 */
	private function encodedCharRegex(string $char): string
	{
		$hex     = strtoupper(bin2hex($char));
		$encoded = '%';

		foreach (str_split($hex) as $digit)
		{
			$encoded .= ctype_digit($digit) ? $digit : '[' . $digit . strtolower($digit) . ']';
		}

		$literal = (ord($char) > 0x20 && ord($char) < 0x7F) ? '\\' . $char : '\\x' . $hex;

		return '(?:' . $encoded . '|' . $literal . ')';
	}

	/**
	 * A regular expression matching the query string of a "Force HTTPS for these URLs" entry exactly, as the server sees
	 * it: Apache's %{QUERY_STRING} and IIS's {QUERY_STRING}, which are not URL-decoded (gh-466).
	 *
	 * Each character matches itself: the regular expression metacharacters are escaped, see escape_string_for_regex(),
	 * and a % is a %, so an entry typed URL-encoded, e.g. q=a%20b, matches what the browser sends. The characters a
	 * browser always URL-encodes in a query string also match their %XX form, in either letter case: ", ', <, >, `
	 * and anything which is not ASCII, e.g. the bytes of a UTF-8 character, see encodedCharRegex(). A space matches %20
	 * and +, how browsers and forms send it; a raw space never reaches the server. So the regular expression has no
	 * space, which would end the pattern of Apache's RewriteCond.
	 *
	 * The match is exact, in the order typed: a=1&b=2 does not match b=2&a=1, nor a=1&b=2&c=3.
	 *
	 * @param   string  $query  The query string, without the ?, with no control character
	 *
	 * @return  string  E.g. option=com_users&view=login
	 * @since   7.10.0
	 */
	protected function queryStringRegex(string $query): string
	{
		$regex = '';

		foreach ($query === '' ? [] : str_split($query) as $char)
		{
			if ($char === ' ')
			{
				$regex .= '(?:%20|\\+)';

				continue;
			}

			if (ord($char) > 0x7E || strpos('"\'<>`', $char) !== false)
			{
				$regex .= $this->encodedCharRegex($char);

				continue;
			}

			$regex .= $this->escape_string_for_regex($char);
		}

		return $regex;
	}

	/**
	 * A URL path, URL-encoded, keeping its slashes: e.g. /my site to /my%20site, and /a$b to /a%24b.
	 *
	 * @param   string  $path  The URL path, not URL-encoded
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	protected function urlEncodedPath(string $path): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/**
	 * LEGACY FEATURE: "Force HTTPS for these URLs" will be removed in a future version; see the .htaccess and web.config
	 * Makers. The NginX Conf Maker never had it.
	 *
	 * The entries of "Force HTTPS for these URLs" (httpsurls), as paths relative to the site's root, each with its query
	 * string, if it has one: `path` or `path?query`.
	 *
	 * Each entry is trimmed, and the slashes it starts with are dropped: the rules match the path relative to the folder
	 * of the server configuration file, which never starts with a slash, so `/login` never matched. Empty entries, and
	 * entries which are only slashes, are dropped: one would match the site's root. Duplicates, e.g. `/login` and
	 * `login`, are dropped too: IIS refuses a web.config with two rules of the same name with HTTP 500.52. An entry with a
	 * control character, e.g. a new line or a tab, is dropped whole: a new line would start another directive of the
	 * .htaccess, and no URL has one.
	 *
	 * The fragment of an entry, from its first `#` on, is dropped first: browsers never send it, and keep it across the
	 * redirect, so `page.html#top` covers `page.html`, `#top` alone is dropped (it would match the site's root), and
	 * `login#a` is the same entry as `login`.
	 *
	 * The query string, from the first `?` on, is kept (gh-466): the rule then only redirects that path with exactly that
	 * query string, see queryStringRegex(). `index.php?option=com_users&view=login` covers that URL, not every index.php
	 * URL: since gh-458 such entries were dropped, as their path alone would cover far more, e.g. every SEF URL, which
	 * Joomla's SEF rule rewrites to index.php. The path and the query string are trimmed each. An empty query string, e.g.
	 * `login?`, is none: the entry is `login`. An entry which is only a query string, e.g. `?lang=en`, covers the site's
	 * root with exactly that query string. `login` and `login?x=1` are two entries.
	 *
	 * @param   string[]|null  $urls  The entries
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function normaliseForceHttpsUrls($urls): array
	{
		$result = [];

		foreach ((array) $urls as $url)
		{
			$url = trim((string) $url);

			// A control character anywhere, also in the fragment, drops the entry whole
			if (preg_match('/[[:cntrl:]]/', $url))
			{
				continue;
			}

			[$path, $query] = array_pad(explode('?', preg_replace('/#.*$/', '', $url), 2), 2, '');
			$path  = ltrim(trim($path), '/');
			$query = trim($query);

			if ($path === '' && $query === '')
			{
				continue;
			}

			$url = $query === '' ? $path : ($path . '?' . $query);

			if (!in_array($url, $result, true))
			{
				$result[] = $url;
			}
		}

		return $result;
	}

	/**
	 * The entries of "User agents to block" (hoggeragents) which can go into a server configuration file.
	 *
	 * Each entry is trimmed, and blank entries are dropped: a condition with an empty pattern matches every user agent,
	 * and would block every visitor. A list of blank entries only gives an empty list; the caller must then leave out the
	 * user agent block. The entries are regular expression fragments by design, e.g. maxthon$, which every maker matches
	 * regardless of letter case; they are never regex-quoted.
	 *
	 * An entry which is the same as an earlier one, regardless of letter case, is dropped (gh-461): the default list had
	 * casper, chinaclaw and clshttp twice each, and the lists saved with it still do. The first spelling and the order of
	 * the entries are kept.
	 *
	 * @param   string|string[]|null  $agents  The user agents; a string is a comma separated list
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function normaliseUserAgents($agents): array
	{
		$agents = is_array($agents) ? $agents : explode(',', (string) $agents);
		$result = [];
		$seen   = [];

		foreach ($agents as $agent)
		{
			if (!is_scalar($agent))
			{
				continue;
			}

			$agent = trim((string) $agent);

			if ($agent === '' || isset($seen[strtolower($agent)]))
			{
				continue;
			}

			$seen[strtolower($agent)] = true;
			$result[]                 = $agent;
		}

		return $result;
	}

	/**
	 * A user agent entry, see normaliseUserAgents(), as a regular expression without control characters, or null if it
	 * has a control character which is not white space.
	 *
	 * White space other than the space, e.g. a tab or a new line, is written as \s, also after a backslash: an escaped
	 * tab is a tab all the same. It would end the .htaccess Maker's pattern, or start another line of the configuration
	 * file. With $spaceToo the space is written as \s too, as the .htaccess Maker always did: an unescaped space ends its
	 * pattern. A backslash before any other character is kept as it is, with that character: \\ is an escaped backslash,
	 * so a backslash, a backslash and a tab are \\\s.
	 *
	 * @param   string  $agent     The user agent, e.g. mass downloader
	 * @param   bool    $spaceToo  Write the space as \s too?
	 *
	 * @return  string|null
	 * @since   7.10.0
	 */
	protected function userAgentRegex(string $agent, bool $spaceToo): ?string
	{
		if (preg_match('/[\x00-\x08\x0E-\x1F\x7F]/', $agent))
		{
			return null;
		}

		$whiteSpace = "\t\n\x0B\f\r" . ($spaceToo ? ' ' : '');

		return preg_replace_callback(
			'/\\\\(.)|[' . preg_quote($whiteSpace, '/') . ']/s',
			function (array $match) use ($whiteSpace): string {
				if (!isset($match[1]) || strpos($whiteSpace, $match[1]) !== false)
				{
					return '\\s';
				}

				return $match[0];
			},
			$agent
		);
	}

	/**
	 * A blocked user agent, see normaliseUserAgents(), as the regular expression this maker writes, before the escaping
	 * of its configuration file, or null if it skips the agent.
	 *
	 * Here, as the NginX Conf Maker does: white space other than the space is \s, and an agent with any other control
	 * character is skipped, see userAgentRegex(); so is one which is not a valid regular expression, see isValidRegex().
	 * The .htaccess and web.config Makers skip more, see theirs. getConfigurationWarnings() lists the skipped agents.
	 *
	 * @param   string  $agent  The user agent
	 *
	 * @return  string|null
	 * @since   7.10.0
	 */
	protected function blockedUserAgentRegex(string $agent): ?string
	{
		$regex = $this->userAgentRegex($agent, false);

		return ($regex !== null && $this->isValidRegex($regex)) ? $regex : null;
	}

	/**
	 * Is this a valid PCRE regular expression?
	 *
	 * Apache and NginX both use PCRE. A pattern they cannot compile makes Apache answer every request to the site with
	 * HTTP 500, and NginX refuse the whole configuration. Pass the pattern exactly as the server's regular expression
	 * engine gets it, after the configuration file's own escaping is undone. It must have no control character, see
	 * userAgentRegex(): \x01 is the delimiter.
	 *
	 * @param   string  $regex  The regular expression, without delimiters
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected function isValidRegex(string $regex): bool
	{
		if (strpos($regex, "\x01") !== false)
		{
			return false;
		}

		return @preg_match("\x01" . $regex . "\x01i", '') !== false;
	}

	/**
	 * The IP addresses and ranges "Restrict access by IP address" (restrictip) allows, as the maker writes them: an empty
	 * list when the restriction is off.
	 *
	 * The list comes from CloudIPRanges: the custom list of this maker, the internal network or a CDN provider's. Every
	 * entry is trimmed, and blank ones are dropped, after IpExpressionValidator::normalise(), which may turn an entry of
	 * white space into an empty string.
	 *
	 * An empty list, which only the custom list can be, turns the restriction off (gh-466), like an empty list does in
	 * other Admin Tools features: it is a misconfiguration, which the maker's page warns about, see
	 * getConfigurationWarnings(). It refused everyone, which locked the site's own users out, back-end included, until
	 * the file was removed by hand: the NginX Conf and web.config Makers always, the .htaccess Maker since gh-462. Before
	 * that, the .htaccess Maker wrote an empty <RequireAny>, and Apache answered every request with HTTP 500.
	 *
	 * @param   object  $config  The configuration, see loadConfiguration()
	 *
	 * @return  string[]
	 * @throws  \RuntimeException  When a CDN provider's list cannot be downloaded
	 * @since   7.10.0
	 */
	protected function restrictIpList(object $config): array
	{
		$type = $config->restrictip ?? 'none';

		if ($type === 'none')
		{
			return [];
		}

		$list = CloudIPRanges::getIPRanges($type, false, $this);

		if ($list === null)
		{
			$reason = CloudIPRanges::getLastError();

			throw new \RuntimeException(
				Text::_('COM_ADMINTOOLS_HTACCESSMAKER_ERR_INVALID_RESTRICTIP')
				. ($reason ? ' ' . Text::_($reason) : '')
			);
		}

		$list = array_map(function ($ip) {
			return is_scalar($ip) ? trim((string) $ip) : '';
		}, $list);

		return array_values(array_filter($list, function ($ip) {
			return $ip !== '';
		}));
	}

	protected function bugfixBackendProtectionExclusionDirectories(array $directories): array
	{
		if (in_array('component', $directories) && !in_array('components', $directories))
		{
			$index = array_search('component', $directories);
			$directories[$index] = 'components';
		}

		return $directories;
	}

	/**
	 * Turns the values of the list configuration keys ($arrayConfigKeys) into lists.
	 *
	 * A comma separated string is split, an object is cast to an array, a stored null becomes an empty list, and any other
	 * value which is not an array becomes an empty list. A missing key is not added: saving without defaults must only
	 * store the keys it was given, as a stored empty list would replace the default list when loading the configuration.
	 *
	 * Every string entry is trimmed, and blank entries, null included, are dropped, whichever way the list arrived: a
	 * blank entry makes no sense in any of these lists, and e.g. a blank user agent would block every visitor (gh-461).
	 * Before, only comma separated strings were, so a list from a settings import or the CLI kept its blank entries.
	 * Any other entry is kept as it is, e.g. the ['item' => …] rows of the restrictip_custom subform, blank or not. The
	 * keys are kept.
	 *
	 * @param   array  $config  The configuration
	 *
	 * @return  void
	 */
	protected function handlePotentialArrayKeys(array &$config): void
	{
		foreach ($this->arrayConfigKeys as $key)
		{
			if (!array_key_exists($key, $config))
			{
				continue;
			}

			$value = $config[$key];

			if (is_object($value))
			{
				$value = (array) $value;
			}
			elseif (is_string($value))
			{
				$value = explode(',', $value);
			}
			elseif (!is_array($value))
			{
				$value = [];
			}

			$value = array_map(function ($x) {
				return is_string($x) ? trim($x) : $x;
			}, $value);

			$config[$key] = array_filter($value, function ($x) {
				return ($x !== null) && ($x !== '');
			});
		}
	}
}