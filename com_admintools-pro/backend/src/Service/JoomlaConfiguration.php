<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;
use RuntimeException;

/**
 * Changes Joomla's Global Configuration, i.e. writes the site's configuration.php file.
 *
 * This is the one place in the component which writes configuration.php. It does what Joomla's own Global
 * Configuration page does: it starts from the configuration as it is on disk (the JConfig class), changes only the
 * requested variables, and serialises the result back as the JConfig class. A read-only configuration.php is made
 * writable just for the write, then has its original permissions restored.
 *
 * Never serialise the application's in-memory configuration instead. Joomla adds values to it which only apply to the
 * current request, e.g. session_name (the application's name, when configuration.php does not set one), session,
 * uri, execution, and the current user's editor and language. Written to configuration.php, session_name gives the
 * frontend the backend's session cookie, so the two would share their sessions.
 *
 * @since  7.10.0
 */
class JoomlaConfiguration
{
	/**
	 * The variables earlier versions wrongly wrote into configuration.php, from the application's runtime configuration.
	 *
	 * Released versions up to 7.9.4 (Check Temp and Log Directories) and 7.10.0 development versions (Site Health Check)
	 * serialised the in-memory configuration. That left session_name (normally only set when Shared Sessions are on) and
	 * these runtime-only values in configuration.php. The acting user's editor and language were written, too, but
	 * those are real Global Configuration settings: we cannot tell them apart from a deliberate choice, so they stay.
	 *
	 * @since 7.10.0
	 */
	public const STRAY_RUNTIME_KEYS = ['session_name', 'session', 'uri', 'execution', 'theme', 'themeParams', 'cwd'];

	/**
	 * The configuration this request last wrote, per configuration.php path.
	 *
	 * Joomla loads the JConfig class once per request, so a new JConfig object still has the values of the start of
	 * the request. A second write in the same request (Check Temp and Log Directories may fix the temporary and then the
	 * logs folder) must start from what the first one wrote. Static, since every bootComponent() call gets a new
	 * instance of this service.
	 *
	 * @var   array<string,array>
	 * @since 7.10.0
	 */
	private static $written = [];

	/**
	 * The CMS application.
	 *
	 * @var   CMSApplicationInterface|\Joomla\CMS\Application\CMSApplication
	 * @since 7.10.0
	 */
	protected $app;

	public function __construct(CMSApplicationInterface $app)
	{
		$this->app = $app;
	}

	/**
	 * Change one Global Configuration variable and write configuration.php.
	 *
	 * @param   string  $key    The variable name, e.g. tmp_path
	 * @param   mixed   $value  Its new value
	 *
	 * @return  void
	 * @throws  RuntimeException  When configuration.php cannot be written
	 * @since   7.10.0
	 */
	public function set(string $key, $value): void
	{
		$this->setMany([$key => $value]);
	}

	/**
	 * Change several Global Configuration variables, and remove others, writing configuration.php once.
	 *
	 * The application's in-memory configuration is updated for the changed variables only. Removed variables are left
	 * alone there: Joomla may well have set them itself for the current request, e.g. session_name.
	 *
	 * @param   array     $values  Variable name => new value
	 * @param   string[]  $remove  Variables to remove from configuration.php
	 *
	 * @return  void
	 * @throws  RuntimeException  When configuration.php cannot be written
	 * @since   7.10.0
	 */
	public function setMany(array $values, array $remove = []): void
	{
		$path   = $this->getPath();
		$config = self::$written[$path] ?? $this->loadConfiguration();

		foreach ($values as $key => $value)
		{
			$config[$key] = $value;
		}

		foreach ($remove as $key)
		{
			unset($config[$key]);
		}

		$contents = (new Registry($config))->toString('PHP', ['class' => 'JConfig', 'closingtag' => false]);
		$perms    = @fileperms($path);
		$readOnly = @file_exists($path) && !@is_writable($path);

		if ($readOnly)
		{
			@chmod($path, 0644);
		}

		$result = @file_put_contents($path, $contents);

		if ($readOnly && $perms !== false)
		{
			@chmod($path, $perms & 0777);
		}

		if (!$result)
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_COMMON_ERR_CANNOT_WRITE_JCONFIG'));
		}

		self::$written[$path] = $config;

		foreach ($values as $key => $value)
		{
			$this->app->set($key, $value);
		}

		// Make sure the next request reads the new file, not a stale opcode cache entry.
		if (function_exists('apc_delete_file'))
		{
			@apc_delete_file($path);
		}

		if (function_exists('opcache_invalidate'))
		{
			@opcache_invalidate($path, true);
		}
	}

	/**
	 * The Global Configuration as it is in configuration.php: on disk, or as this request last wrote it.
	 *
	 * Unlike the application's configuration, this does not have the values Joomla only sets for the current request,
	 * e.g. session_name.
	 *
	 * @return  array
	 * @throws  RuntimeException  When there is no configuration to read
	 * @since   7.10.0
	 */
	public function getOnDisk(): array
	{
		return self::$written[$this->getPath()] ?? $this->loadConfiguration();
	}

	/**
	 * Does this configuration.php set a session name while Shared Sessions are disabled?
	 *
	 * Joomla names the session cookie md5(secret . session_name), with the application's name (site, administrator)
	 * when there is no session_name. A session_name in configuration.php gives the frontend and the backend the same
	 * session cookie: their sessions are shared, although Shared Sessions are off. Joomla only stores a session_name
	 * when Shared Sessions are turned on, and removes it when they are turned off; earlier Admin Tools versions wrote
	 * one by mistake (see STRAY_RUNTIME_KEYS).
	 *
	 * @param   array  $config  The configuration on disk, see getOnDisk()
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function hasStraySessionName(array $config): bool
	{
		// With Shared Sessions on, a session_name is how it is meant to be. The Shared Sessions check handles that.
		if (!empty($config['shared_session']))
		{
			return false;
		}

		$sessionName = $config['session_name'] ?? null;

		// Joomla's Registry treats an empty string as not set.
		return is_scalar($sessionName) && (string) $sessionName !== '';
	}

	/**
	 * Remove session_name and the other runtime-only variables earlier versions wrote into configuration.php.
	 *
	 * This changes the frontend's session cookie name, logging out everyone logged into the frontend.
	 *
	 * @return  void
	 * @throws  RuntimeException  When configuration.php cannot be written
	 * @since   7.10.0
	 */
	public function removeStrayRuntimeKeys(): void
	{
		$this->setMany([], self::STRAY_RUNTIME_KEYS);
	}

	/**
	 * Absolute path to the site's configuration.php file.
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public function getPath(): string
	{
		return JPATH_CONFIGURATION . '/configuration.php';
	}

	/**
	 * The Global Configuration as it is in configuration.php, like Joomla's Global Configuration page reads it.
	 *
	 * @return  array
	 * @throws  RuntimeException  When there is no configuration to start from
	 * @since   7.10.0
	 */
	protected function loadConfiguration(): array
	{
		$path = $this->getPath();

		// Joomla has always loaded it already; a missing JConfig class can only mean a broken bootstrap.
		if (!class_exists('JConfig', false) && @is_file($path))
		{
			include_once $path;
		}

		if (!class_exists('JConfig', false))
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_COMMON_ERR_CANNOT_WRITE_JCONFIG'));
		}

		return ArrayHelper::fromObject(new \JConfig());
	}
}
