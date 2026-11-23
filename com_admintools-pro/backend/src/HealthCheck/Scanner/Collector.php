<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Scanner;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\Store;
use Akeeba\Component\AdminTools\Administrator\Helper\CoreSums;
use Akeeba\Component\AdminTools\Administrator\Helper\Extensions;
use Akeeba\Component\AdminTools\Administrator\Scanner\Filter;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Configuration;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Filesystem;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Session;
use Joomla\CMS\Component\ComponentHelper;

/**
 * Collects information for the Site Health Check while the PHP File Change Scanner crawls the site.
 *
 * The crawler shows every directory entry to inspect(), whatever its file extension. The findings accumulate in the
 * scanner's session and are saved by persist() when, and only when, a scan completes.
 *
 * Once a folder is reported (it is hidden, or looks like a renamed copy), most of what is inside it is not reported any
 * more; the topmost item is what the user needs to deal with. Files PHP can run, and server configuration files, are the
 * exception: inside such a folder they are still reported as PHP in a media folder, as server configuration files
 * letting files run as PHP, or as hidden files. A hidden folder is just where a web shell would be put, and the user may
 * well ignore the folder itself.
 *
 * @since  7.10.0
 */
class Collector
{
	/** PHP files in media, image, language and similar folders */
	public const PHP_IN_MEDIA = 'phpmedia';

	/** Files and folders renamed to keep an old version */
	public const VERSIONED = 'versioned';

	/** Large files */
	public const LARGE = 'large';

	/** Hidden files and folders */
	public const HIDDEN = 'hidden';

	/** Non-core files in core folders, among the files the scanner actually scanned */
	public const NONCORE = 'noncore';

	/** .htaccess and .user.ini files which let files run as PHP or CGI scripts, or change PHP's settings */
	public const SERVER_CONFIG = 'serverconfig';

	/** Every category this version collects. Stored with the findings, so checks can tell old data apart. */
	public const CATEGORIES = [
		self::PHP_IN_MEDIA, self::SERVER_CONFIG, self::VERSIONED, self::LARGE, self::HIDDEN, self::NONCORE,
	];

	/**
	 * The Site Health Check items which report each category, i.e. whose ignore list applies to it. An item is only
	 * left out of the findings when every one of them ignores it.
	 */
	public const CATEGORY_CHECKS = [
		self::PHP_IN_MEDIA   => ['admintools.php_in_media_folders'],
		self::SERVER_CONFIG  => ['admintools.server_config_files'],
		self::VERSIONED      => ['admintools.versioned_names'],
		self::LARGE          => ['admintools.large_files'],
		self::HIDDEN         => ['admintools.hidden_files'],
		self::NONCORE        => ['admintools.noncore_in_core_folders'],
	];

	/**
	 * Server configuration files examined for directives which let files run as PHP, or change PHP's settings. Matched
	 * case-insensitively: on case-insensitive filesystems the web server reads .HTACCESS as well.
	 */
	public const SERVER_CONFIG_FILES = ['.htaccess', '.user.ini'];

	/**
	 * Folders whose own server configuration files are not examined: the site's root and the administrator folder, where
	 * hosts routinely select the PHP version (e.g. AddHandler application/x-httpd-php83 .php). Their subfolders are.
	 */
	private const SERVER_CONFIG_EXEMPT_FOLDERS = ['', 'administrator'];

	/** Server configuration files larger than this (1 MiB) are reported without examining them. */
	private const MAX_SERVER_CONFIG_SIZE = 1048576;

	/**
	 * A handler or MIME type which runs a file as code: PHP (php-script, application/x-httpd-php, cPanel's
	 * application/x-httpd-ea-php83, LiteSpeed's application/x-httpd-lsphp, 1&1's x-mapp-php5, PHP-FPM through
	 * proxy:unix:…|fcgi://…), CGI (cgi-script, fcgid-script) or server-side includes (server-parsed). Not: none,
	 * default-handler, text/plain, font/woff2, application/javascript and the like.
	 */
	private const RUNNABLE_HANDLER_PATTERN = '/php|cgi|x-httpd-|x-mapp-|proxy:|server-parsed/i';

	/**
	 * Extensions which web servers commonly run as PHP: php, php followed by one or two digits (php3; php5 to php8 on
	 * cPanel's EA-PHP), pht, phtml, phar (Debian and Ubuntu's default handler), phps and inc. Matched case-insensitively.
	 */
	private const PHP_EXTENSION_PATTERN = '/^(?:php\\d{0,2}|pht|phtml|phar|phps|inc)$/i';

	/** Maximum number of items stored per category; the total is always counted. */
	public const MAX_ITEMS = 5000;

	/** Maximum number of reported folders remembered for suppressing their contents. */
	private const MAX_SUPPRESSED = 5000;

	/**
	 * Hidden file names which are legitimate: server configuration and password files. Only as files; a folder by that
	 * name is no server configuration, just a hiding place, and is reported.
	 */
	public const LEGIT_HIDDEN_FILES = ['.htaccess', '.htpasswd', '.user.ini'];

	/**
	 * Hidden folder names which are legitimate. Only the folder itself is not reported; its contents are examined like
	 * any other folder's. PHP files in the site's root .well-known folder are reported, as the .htaccess Maker blocks
	 * them there.
	 */
	public const LEGIT_HIDDEN_FOLDERS = ['.well-known'];

	/**
	 * Joomla core files which the CoreSums checksums do not cover, as they only list PHP, JavaScript and INI files.
	 *
	 * - plugins/system/webauthn/fido.jwt: the FIDO Alliance authenticator metadata, a multi-MiB file (Joomla 4.2+).
	 */
	public const UNCHECKSUMMED_CORE_FILES = ['plugins/system/webauthn/fido.jwt'];

	/** Folders managed by Joomla itself, never examined: Joomla's Composer dependencies. */
	private const SKIP_FOLDERS = ['libraries/vendor'];

	/**
	 * Hidden files and folders which third-party libraries routinely ship: version control, CI, editor and code
	 * quality tool metadata. They are not reported inside a `vendor` folder. Secrets (e.g. `.env`) and version
	 * control checkouts (`.git`) still are.
	 */
	private const VENDOR_DOTFILES = [
		'.gitignore', '.gitattributes', '.gitkeep', '.gitmodules', '.gitlab-ci.yml', '.github', '.gitlab',
		'.editorconfig', '.travis.yml', '.scrutinizer.yml', '.styleci.yml', '.coveralls.yml', '.codeclimate.yml',
		'.circleci', '.dependabot', '.deepsource.toml', '.readthedocs.yml', '.readthedocs.yaml', '.yamllint',
		'.php_cs', '.php_cs.dist', '.php-cs-fixer.php', '.php-cs-fixer.dist.php', '.phpcs.xml', '.phpcs.xml.dist',
		'.phpstorm.meta.php', '.phplint.yml', '.npmignore', '.eslintrc', '.eslintrc.js', '.eslintrc.json',
		'.eslintignore', '.prettierrc', '.prettierignore', '.babelrc', '.buildpath', '.project', '.settings',
		'.eclipse-php-formatter.xml', '.idea', '.vscode',
	];

	/** @var Configuration */
	private $configuration;

	/** @var Session */
	private $session;

	/** @var Filter|null */
	private $filter;

	/** @var Store|null Site Health Check storage; read lazily, for the ignore lists */
	private $store;

	/** @var array<string, array<string, true>>|null Category => encoded items which every reporting check ignores */
	private $ignoredItems;

	/** @var array|null Computed settings; see getSettings() */
	private $settings;

	/** @var array|null Findings, loaded lazily from the session */
	private $findings;

	/** @var string[]|null Reported folders whose contents are not examined */
	private $suppressed;

	/** @var bool Have findings or suppressed folders changed since they were loaded? */
	private $dirty = false;

	public function __construct(Configuration $configuration, Session $session, ?Filter $filter = null, ?Store $store = null)
	{
		$this->store         = $store;
		$this->configuration = $configuration;
		$this->session       = $session;
		$this->filter        = $filter;
	}

	public function __destruct()
	{
		$this->save();
	}

	/**
	 * Should the crawler descend into a folder excluded from scanning, just to collect information?
	 *
	 * Only folders the user excluded qualify; the scanner's built-in exclusions (temporary, log and cache folders) are
	 * always skipped.
	 *
	 * @param   string  $absolutePath
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function shouldCollectInExcludedFolder(string $absolutePath): bool
	{
		return (bool) $this->configuration->get('healthcheckIgnoreExclusions')
		       && $this->filter !== null
		       && !$this->filter->isDefaultExcludedFolder($absolutePath);
	}

	/**
	 * Record a file the PHP File Change Scanner has just scanned, for the non-core files in core folders.
	 *
	 * This is not part of inspect(): the crawler lists a folder (inspect()) before it scans the files in it, and only the
	 * scan knows whether the user marked the file safe. A non-core file marked safe is managed in the scan report, and is
	 * neither stored nor counted here: it would take up the slots meant for the files found after it, so a crowd of them
	 * could hide a real finding. Because only scanned files are recorded, the files are exactly those the scanner sees,
	 * with the current classification rules. Each complete scan records them afresh, so a file which is no longer part
	 * of a scan (deleted, or excluded from scanning since) is no longer reported.
	 *
	 * @param   string  $absolutePath  The file's absolute path
	 * @param   bool    $markedSafe    Is the file's current version marked safe? See FileScanner::processFile()
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function recordScannedFile(string $absolutePath, bool $markedSafe): void
	{
		$rel = Filesystem::relativePath($absolutePath);

		if ($markedSafe || $rel === '' || $this->configuration->get('nonCoreInCoreFolders') === 'ignore'
		    || CoreSums::classify($rel) !== 'noncore')
		{
			return;
		}

		$this->add(self::NONCORE, $rel);
	}

	/**
	 * Inspect a directory entry.
	 *
	 * @param   string    $absolutePath  The entry's absolute path
	 * @param   bool      $isDir         Is it a folder?
	 * @param   int|null  $size          File size in bytes; NULL for folders
	 * @param   bool      $scanned       Will the PHP File Change Scanner scan this file for changes?
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function inspect(string $absolutePath, bool $isDir, ?int $size = null): void
	{
		$rel = Filesystem::relativePath($absolutePath);

		if ($rel === '' || self::isUnder($rel, self::SKIP_FOLDERS))
		{
			return;
		}

		$isServerConfig = !$isDir && self::isServerConfigName(basename($rel));

		// Beneath a reported folder only files PHP can run, and server configuration files, are examined.
		$beneathReported = self::isUnder($rel, $this->getSuppressed());

		if ($beneathReported && ($isDir || !($isServerConfig || $this->isPhpFileName(basename($rel)))))
		{
			return;
		}

		// When exclusions are honoured, entries the user excluded are not examined.
		if (!$this->configuration->get('healthcheckIgnoreExclusions') && $this->filter !== null)
		{
			if ($isDir ? $this->filter->isExcludedFolder($absolutePath) : $this->filter->isExcludedFile($absolutePath))
			{
				return;
			}
		}

		$basename = basename($rel);

		/**
		 * Server configuration files are expected, but they can make any file run as PHP: an images/.htaccess with
		 * AddType application/x-httpd-php .jpg, or a .user.ini with auto_prepend_file=images/logo.png.
		 */
		if ($isServerConfig && self::isServerConfigExamined($rel)
		    && self::isUnsafeServerConfigFile($absolutePath, $basename, $size))
		{
			$this->add(self::SERVER_CONFIG, $rel);
		}

		// Legitimate hidden files (.htaccess, .htpasswd, .user.ini) are expected. A legitimate hidden folder is not reported
		// by classify() below, but its contents are still examined.
		if (!$isDir && self::isLegitHiddenName($basename, false))
		{
			return;
		}

		// Known, unmodified-or-not, core files are the File Change Scanner's business.
		if (!$isDir && self::isCoreFile($rel))
		{
			return;
		}

		$categories = self::classify($rel, $isDir, $size, $this->getSettings(), [$this, 'isPhpFileName']);

		// Extensions often put an index.php which only stops execution in their media folders, to block listings.
		if (in_array(self::PHP_IN_MEDIA, $categories, true) && strtolower($basename) === 'index.php'
		    && $size !== null && $size <= 4096 && self::isDieOnlyPhp((string) @file_get_contents($absolutePath)))
		{
			$categories = array_values(array_diff($categories, [self::PHP_IN_MEDIA]));
		}

		// Beneath a reported folder, a PHP file is only reported as PHP in a media folder, or as a hidden file.
		if ($beneathReported)
		{
			$categories = array_values(array_intersect($categories, [self::PHP_IN_MEDIA, self::HIDDEN]));
		}

		foreach ($categories as $category)
		{
			$this->add($category, $rel);
		}

		if ($isDir && !empty($categories))
		{
			$this->suppress($rel);
		}
	}

	/**
	 * Which categories does a directory entry belong to?
	 *
	 * @param   string    $rel       Path relative to the site's root
	 * @param   bool      $isDir     Is it a folder?
	 * @param   int|null  $size      File size in bytes; NULL for folders
	 * @param   array     $settings  See getSettings()
	 * @param   callable  $isPhp     Callback telling whether a web server may run a file name as PHP
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	public static function classify(string $rel, bool $isDir, ?int $size, array $settings, callable $isPhp): array
	{
		$basename   = basename($rel);
		$categories = [];

		/**
		 * Library metadata in vendor folders is expected, unless it is a file PHP can run (e.g. .php-cs-fixer.php):
		 * that name is just as good a hiding place for a web shell as any other.
		 */
		if (($settings['hidden'] ?? true) && self::isHiddenName($basename, $isDir)
		    && !(self::isVendorMetadata($rel) && ($isDir || !$isPhp($basename))))
		{
			$categories[] = self::HIDDEN;
		}

		// Copies like "photo (1).jpg" are normal in media folders; PHP files there are caught as PHP in media folders.
		if (self::isVersionedName($basename, $isDir) && !self::isUnder($rel, $settings['versionedExempt'] ?? []))
		{
			$categories[] = self::VERSIONED;
		}

		if ($isDir)
		{
			return $categories;
		}

		if (self::isUnder(dirname($rel), $settings['phpRoots'] ?? []) && $isPhp($basename)
		    && !self::isLanguageLocalise($rel))
		{
			$categories[] = self::PHP_IN_MEDIA;
		}

		$threshold = (int) ($settings['largeThreshold'] ?? 0);

		if ($threshold > 0 && $size !== null && $size >= $threshold
		    && !self::isUnder($rel, $settings['largeExempt'] ?? []))
		{
			$categories[] = self::LARGE;
		}

		return $categories;
	}

	/**
	 * Is this a Joomla core file: one in the CoreSums checksums, or one of the core files they do not cover?
	 *
	 * @param   string  $rel  Path relative to the site's root
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isCoreFile(string $rel): bool
	{
		return in_array($rel, self::UNCHECKSUMMED_CORE_FILES, true) || CoreSums::isCoreFile($rel);
	}

	/**
	 * Is this a version control, CI, editor or tool metadata file or folder of a third-party library, i.e. one of the
	 * expected hidden names inside a `vendor` folder?
	 *
	 * @param   string  $rel  Path relative to the site's root
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isVendorMetadata(string $rel): bool
	{
		$segments = explode('/', $rel);
		$basename = array_pop($segments);

		return in_array('vendor', $segments, true) && in_array(strtolower($basename), self::VENDOR_DOTFILES, true);
	}

	/**
	 * Does this PHP code stop execution before doing anything else, e.g. `<?php die();`?
	 *
	 * The file must start with `<?php` and its first statement must be an unconditional die or exit, optionally with a
	 * literal message or status. Whitespace and comments may come before it. Nothing after that statement can ever
	 * run, whether the file is requested or included, so the rest of the file does not matter. It does not matter
	 * either how the scanning process's short_open_tag setting would read that part, which may differ from the web
	 * server's.
	 *
	 * A guarded die such as `<?php defined('_JEXEC') or die;` does not qualify: when the file is included the guard
	 * passes, and whatever follows it runs.
	 *
	 * @param   string  $code  The file's contents
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isDieOnlyPhp(string $code): bool
	{
		$code = preg_replace('/^\xEF\xBB\xBF/', '', $code);

		if (stripos(ltrim($code), '<?php') !== 0 || !function_exists('token_get_all'))
		{
			return false;
		}

		/**
		 * Read the tokens up to the end of the first statement, skipping whitespace and comments, and match them against
		 * `die|exit [ ( [literal] ) ] ;|?>|EOF`. Only literals without interpolation may be the message or status: anything
		 * else, e.g. die(system('id')), is evaluated before the script stops.
		 *
		 * Steps: 0 = expect the open tag, 1 = die/exit, 2 = `(` or the end, 3 = literal or `)`, 4 = `)`, 5 = the end.
		 */
		$step = 0;

		foreach (token_get_all(ltrim($code)) as $token)
		{
			$id   = is_array($token) ? $token[0] : null;
			$text = is_array($token) ? $token[1] : $token;

			if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
			{
				continue;
			}

			$isEnd = $id === T_CLOSE_TAG || $text === ';';

			switch ($step)
			{
				case 0:
					$ok = $id === T_OPEN_TAG;
					break;

				case 1:
					$ok = $id === T_EXIT;
					break;

				case 2:
					if ($isEnd)
					{
						return true;
					}

					$ok = $text === '(';
					break;

				case 3:
					if ($text === ')')
					{
						$step = 5;

						continue 2;
					}

					$ok = in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true);
					break;

				case 4:
					$ok = $text === ')';
					break;

				default:
					return $isEnd;
			}

			if (!$ok)
			{
				return false;
			}

			$step++;
		}

		// The file ends right after `die` or `die(…)`: still a complete statement.
		return $step === 2 || $step === 5;
	}

	/**
	 * Is this the name of a hidden file or folder (starting with a dot), other than the legitimate ones?
	 *
	 * @param   string  $basename
	 * @param   bool    $isDir     Is it a folder?
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isHiddenName(string $basename, bool $isDir = false): bool
	{
		return $basename !== '' && $basename[0] === '.' && !in_array($basename, ['.', '..'], true)
		       && !self::isLegitHiddenName($basename, $isDir);
	}

	/**
	 * Is this a legitimate hidden name for a file (e.g. .htaccess, .htpasswd) or a folder (.well-known)?
	 *
	 * @param   string  $basename
	 * @param   bool    $isDir     Is it a folder?
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isLegitHiddenName(string $basename, bool $isDir): bool
	{
		return in_array($basename, $isDir ? self::LEGIT_HIDDEN_FOLDERS : self::LEGIT_HIDDEN_FILES, true);
	}

	/**
	 * Does this name look like a file or folder renamed to keep an old version?
	 *
	 * Examples: `foo-bak`, `foo_old`, `foo-copy.php`, `foo copy.php`, `foo - Copy.php`, `foo (1).php`, `foo-dist`,
	 * `foo.php.bak`, `foo.old.php`, `foo.php.1`, `foo.php~`.
	 *
	 * Only the last extension may be a plain number, so version numbers (`jquery-3.6.0.min.js`) do not match.
	 *
	 * @param   string  $basename
	 * @param   bool    $isDir
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isVersionedName(string $basename, bool $isDir = false): bool
	{
		$name = ltrim($basename, '.');

		if ($name === '')
		{
			return false;
		}

		// Editor backup files, e.g. foo.php~
		if (substr($name, -1) === '~')
		{
			return true;
		}

		$segments = explode('.', $name);
		$stem     = $isDir ? $name : array_shift($segments);

		// Stem suffixes: -bak, _bak, -old, _old, -copy, -backup, -orig, -dist (optionally numbered), (1), " copy", " - Copy"
		$stemPattern = '/(?:[-_ ](?:bak|old)\d*|[- ](?:copy|backup|orig|dist)\d*|\s*\(\d+\)|\s+-\s+copy(?:\s*\(\d+\))?|\s+copy(?:\s+\d+)?)$/i';

		if (preg_match($stemPattern, $stem))
		{
			return true;
		}

		if ($isDir)
		{
			return false;
		}

		$lastIndex = count($segments) - 1;

		foreach ($segments as $index => $segment)
		{
			if (preg_match('/^(?:bak|old|copy|backup|orig|save)\d*$/i', $segment))
			{
				return true;
			}

			if ($index === $lastIndex && preg_match('/^\d+$/', $segment))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this the name of a server configuration file which can let files run as PHP (.htaccess, .user.ini)?
	 *
	 * @param   string  $basename
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isServerConfigName(string $basename): bool
	{
		return in_array(strtolower($basename), self::SERVER_CONFIG_FILES, true);
	}

	/**
	 * Is a server configuration file in a folder whose server configuration files are examined: anywhere but directly in
	 * the site's root or the administrator folder?
	 *
	 * @param   string  $rel  Path relative to the site's root
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isServerConfigExamined(string $rel): bool
	{
		$slash = strrpos($rel, '/');

		return !in_array($slash === false ? '' : substr($rel, 0, $slash), self::SERVER_CONFIG_EXEMPT_FOLDERS, true);
	}

	/**
	 * Does a server configuration file let files run as PHP or CGI scripts, or change PHP's settings? See
	 * isUnsafeServerConfig(). A file too large to examine is reported; one which cannot be read is not, as the web
	 * server cannot use it either.
	 *
	 * @param   string    $absolutePath
	 * @param   string    $basename
	 * @param   int|null  $size          File size in bytes, if known
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private static function isUnsafeServerConfigFile(string $absolutePath, string $basename, ?int $size): bool
	{
		if ($size !== null && $size > self::MAX_SERVER_CONFIG_SIZE)
		{
			return true;
		}

		$contents = @file_get_contents($absolutePath, false, null, 0, self::MAX_SERVER_CONFIG_SIZE + 1);

		if (!is_string($contents))
		{
			return false;
		}

		return strlen($contents) > self::MAX_SERVER_CONFIG_SIZE || self::isUnsafeServerConfig($basename, $contents);
	}

	/**
	 * Does the content of a server configuration file let files run as PHP or CGI scripts, or change PHP's settings?
	 *
	 * A .htaccess file is unsafe when, outside a comment, it has:
	 * - AddHandler, AddType, SetHandler or ForceType with a handler or MIME type which runs files as code (see
	 *   RUNNABLE_HANDLER_PATTERN). The ones which stop files running, like SetHandler none or AddType text/plain .php,
	 *   are fine.
	 * - Action, which runs a CGI program for any handler or MIME type, including one a harmless-looking AddHandler sets.
	 * - An output filter including INCLUDES (server-side includes, which can run shell commands).
	 * - php_value, php_flag, php_admin_value or php_admin_flag (which includes auto_prepend_file and auto_append_file),
	 *   except php_flag engine off, which stops PHP running.
	 *
	 * A .user.ini file is unsafe when, outside a comment, it sets auto_prepend_file or auto_append_file to a file.
	 *
	 * @param   string  $basename  The file's name; .user.ini (in any letter case), or else a .htaccess file
	 * @param   string  $contents  The file's contents
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isUnsafeServerConfig(string $basename, string $contents): bool
	{
		$contents = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $contents));

		return strtolower($basename) === '.user.ini' ? self::isUnsafeUserIni($contents) : self::isUnsafeHtaccess($contents);
	}

	/**
	 * See isUnsafeServerConfig().
	 *
	 * @param   string  $contents  With \n line endings
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private static function isUnsafeHtaccess(string $contents): bool
	{
		/**
		 * Apache joins a line ending in a backslash with the next one before it looks for comments, so a directive's
		 * arguments may be on the next line, and a comment may swallow the line after it.
		 */
		$contents = str_replace("\\\n", '', $contents);

		foreach (explode("\n", $contents) as $line)
		{
			$line = trim($line);

			// Apache only knows comments on lines of their own, starting with a hash sign.
			if ($line === '' || $line[0] === '#')
			{
				continue;
			}

			$args      = preg_split('/\s+/', $line);
			$directive = strtolower(array_shift($args));
			$args      = array_map(
				function (string $arg): string {
					return trim($arg, '"\'');
				},
				$args
			);
			$first     = strtolower($args[0] ?? '');

			switch ($directive)
			{
				case 'addhandler':
				case 'addtype':
				case 'sethandler':
				case 'forcetype':
					if (preg_match(self::RUNNABLE_HANDLER_PATTERN, $first))
					{
						return true;
					}
					break;

				// Runs a CGI program for files of a type or handler, whatever their name: AddHandler foo .jpg + Action foo …
				case 'action':
					return true;

				// Server-side includes, which can run shell commands (<!--#exec cmd="…" -->). Not: DEFLATE and the like.
				case 'addoutputfilter':
				case 'setoutputfilter':
				case 'addoutputfilterbytype':
					if (preg_match('/(?:^|;)includes(?:;|$)/', $first))
					{
						return true;
					}
					break;

				case 'php_value':
				case 'php_flag':
				case 'php_admin_value':
				case 'php_admin_flag':
					if ($first !== 'engine' || !in_array(strtolower($args[1] ?? ''), ['off', '0', 'false'], true))
					{
						return true;
					}
					break;
			}
		}

		return false;
	}

	/**
	 * See isUnsafeServerConfig().
	 *
	 * @param   string  $contents  With \n line endings
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private static function isUnsafeUserIni(string $contents): bool
	{
		foreach (explode("\n", $contents) as $line)
		{
			if (!preg_match('/^\s*auto_(?:pre|ap)pend_file\s*=(.*)$/i', $line, $matches))
			{
				continue;
			}

			// A quoted value, or everything up to an inline comment. "none", or nothing at all, means no file.
			preg_match('/^\s*(?:"([^"]*)"|\'([^\']*)\'|([^;]*))/', $matches[1], $value);

			$file = trim(($value[1] ?? '') . ($value[2] ?? '') . ($value[3] ?? ''));

			if ($file !== '' && strtolower($file) !== 'none')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Is a path one of the roots, or inside one of them?
	 *
	 * @param   string    $rel
	 * @param   string[]  $roots
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isUnder(string $rel, array $roots): bool
	{
		foreach ($roots as $root)
		{
			$root = trim((string) $root, '/');

			if ($root !== '' && ($rel === $root || strpos($rel, $root . '/') === 0))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this a language pack's localise.php file, directly in a language folder (e.g. language/fr-FR/localise.php)?
	 *
	 * Only where Joomla loads one (Language::__construct()): in the site's or the administrator's language folder, in a
	 * folder whose name is a language tag as LanguageHelper::parseLanguageFiles() recognises it (xx-XX or xxx-XX). Joomla
	 * never treats any other folder as a language, so a localise.php there is just a PHP file. Every accredited Joomla
	 * 3 to 6 translation matches, including Esperanto (eo-XX), Dari (prs-AF) and Montenegrin (srp-ME).
	 *
	 * @param   string  $rel
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function isLanguageLocalise(string $rel): bool
	{
		// D: a trailing newline does not match. Apache's FilesMatch \.php$ runs "localise.php\n" (CVE-2017-15715).
		return (bool) preg_match('#^(?:administrator/)?language/[a-z]{2,3}-[A-Z]{2}/localise\.php$#D', $rel);
	}

	/**
	 * Encode a path for storage, and for use as a Site Health Check item key, without losing anything.
	 *
	 * The findings are stored as JSON, which can only hold valid UTF-8; file names need not be. A path which is valid
	 * UTF-8 is stored as it is, as it always has been. Any other path is stored Base64-encoded, after a prefix no path
	 * starts with. A path starting with that very prefix is encoded too, so that decodeItem() is never ambiguous.
	 *
	 * @param   string  $rel  Path relative to the site's root
	 *
	 * @return  string  Valid UTF-8
	 * @since   7.10.0
	 */
	public static function encodeItem(string $rel): string
	{
		return Filesystem::encodePath($rel);
	}

	/**
	 * Decode a stored item, or a Site Health Check item key, to the path it stands for. See encodeItem().
	 *
	 * @param   string  $item
	 *
	 * @return  string|null  Path relative to the site's root; NULL for a corrupt encoded item.
	 * @since   7.10.0
	 */
	public static function decodeItem(string $item): ?string
	{
		return Filesystem::decodePath($item);
	}

	/**
	 * A path as shown to the user: bytes which are not valid UTF-8 are replaced by U+FFFD.
	 *
	 * @param   string  $rel
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	public static function displayPath(string $rel): string
	{
		return Filesystem::displayPath($rel);
	}

	/**
	 * May a web server run this file as PHP? That is, does its name have a PHP extension (see hasPhpExtension()), or one
	 * of the extensions the PHP File Change Scanner scans?
	 *
	 * The scanner's list alone is not enough: the user may have shortened it, its default lacks some extensions servers
	 * commonly run as PHP, and it is matched case-sensitively by default.
	 *
	 * @param   string  $basename
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public function isPhpFileName(string $basename): bool
	{
		return self::hasPhpExtension($basename)
		       || ($this->filter !== null && !$this->filter->isExcludedByExtension($basename));
	}

	/**
	 * Does a file name have an extension which web servers commonly run as PHP, in any of its extension segments, in any
	 * letter case?
	 *
	 * - Every extension segment counts, not just the last one: with Apache's mod_mime handlers (AddHandler), shell.php.jpg
	 *   runs as PHP.
	 * - Letter case does not matter: mod_mime matches extensions case-insensitively, and so do IIS and Windows.
	 *
	 * @param   string  $basename
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	public static function hasPhpExtension(string $basename): bool
	{
		$segments = explode('.', $basename);

		// Drop the name itself; only the extension segments are left.
		array_shift($segments);

		foreach ($segments as $segment)
		{
			if (preg_match(self::PHP_EXTENSION_PATTERN, $segment))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Save the findings of a complete scan for the Site Health Check, replacing those of the previous scan.
	 *
	 * @param   int    $scanId  The scan record's ID
	 * @param   Store  $store   The Site Health Check storage
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function persist(int $scanId, ?Store $store = null): void
	{
		$store = $store ?? new Store();

		$store->set(
			Store::KEY_SCAN,
			[
				'scan_id'           => $scanId,
				'time'              => time(),
				'ignoreExclusions'  => (bool) $this->configuration->get('healthcheckIgnoreExclusions'),
				'largeFiles'        => (bool) $this->configuration->get('healthcheckLargeFiles'),
				'largeThreshold'    => $this->getSettings()['largeThreshold'],
				'collects'          => self::CATEGORIES,
				'findings'          => $this->getFindings(),
			]
		);
	}

	/**
	 * Forget the findings collected in the scanner's session.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function reset(): void
	{
		$this->findings   = [];
		$this->suppressed = [];
		$this->dirty      = false;

		$this->session->remove('healthCheckFindings');
		$this->session->remove('healthCheckSuppressed');
	}

	/**
	 * The findings so far.
	 *
	 * @return  array  Category => ['total' => int, 'items' => string[]]; each item is encoded, see encodeItem()
	 * @since   7.10.0
	 */
	public function getFindings(): array
	{
		if ($this->findings === null)
		{
			$findings       = $this->session->get('healthCheckFindings', []);
			$this->findings = is_array($findings) ? $findings : [];
		}

		return $this->findings;
	}

	/**
	 * Remove an item, and anything stored beneath it, from the stored findings of the last complete scan, e.g. after
	 * deleting it.
	 *
	 * @param   string  $category
	 * @param   string  $rel       Path relative to the site's root (decoded; see decodeItem())
	 * @param   Store   $store
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public static function removeStoredItem(string $category, string $rel, ?Store $store = null): void
	{
		$store = $store ?? new Store();
		$data  = $store->get(Store::KEY_SCAN);

		if (!is_array($data) || !isset($data['findings'][$category]['items']))
		{
			return;
		}

		$items = $data['findings'][$category]['items'];
		$kept  = array_values(
			array_filter(
				$items,
				function ($item) use ($rel) {
					$path = is_string($item) ? self::decodeItem($item) : null;

					return $path === null || ($path !== $rel && strpos($path, $rel . '/') !== 0);
				}
			)
		);

		$data['findings'][$category]['items'] = $kept;
		$data['findings'][$category]['total'] = max(
			0, (int) ($data['findings'][$category]['total'] ?? 0) - (count($items) - count($kept))
		);

		$store->set(Store::KEY_SCAN, $data);
	}

	/**
	 * The settings which drive classify().
	 *
	 * @return  array
	 * @since   7.10.0
	 */
	public function getSettings(): array
	{
		if ($this->settings !== null)
		{
			return $this->settings;
		}

		$mediaRoots = self::getMediaRoots();
		$sizeMiB    = max(1, (int) $this->configuration->get('healthcheckLargeFileSize'));

		return $this->settings = [
			'hidden'          => DIRECTORY_SEPARATOR !== '\\',
			'phpRoots'        => array_values(
				array_unique(
					array_merge($mediaRoots, ['media', 'files', 'language', 'administrator/language', '.well-known'])
				)
			),
			'largeExempt'     => array_values(array_unique(array_merge($mediaRoots, ['files']))),
			'versionedExempt' => array_values(array_unique(array_merge($mediaRoots, ['files']))),
			'largeThreshold'  => $this->configuration->get('healthcheckLargeFiles') ? $sizeMiB * 1048576 : 0,
		];
	}

	/**
	 * The Media Manager's root folders, relative to the site's root.
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	public static function getMediaRoots(): array
	{
		$roots       = ['images'];
		$mediaParams = ComponentHelper::getParams('com_media');

		foreach (['image_path', 'file_path'] as $key)
		{
			$roots[] = (string) $mediaParams->get($key, '');
		}

		foreach ((array) Extensions::getParams('plg_filesystem_local')->get('directories', []) as $directory)
		{
			$roots[] = (string) (((array) $directory)['directory'] ?? '');
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						function (string $root) {
							return trim(str_replace('\\', '/', $root), '/');
						},
						$roots
					)
				)
			)
		);
	}

	private function add(string $category, string $rel): void
	{
		$this->getFindings();

		$entry = $this->findings[$category] ?? ['total' => 0, 'items' => []];

		/**
		 * Items the user ignores are not stored, and not part of the total: they would take up the slots meant for
		 * everything found after them, and a planted crowd of ignored decoys would hide a real finding forever.
		 */
		if (isset($this->getIgnoredItems()[$category][self::encodeItem($rel)]))
		{
			$entry['ignored'] = ($entry['ignored'] ?? 0) + 1;

			$this->findings[$category] = $entry;
			$this->dirty               = true;

			return;
		}

		$entry['total']++;

		if (count($entry['items']) < self::MAX_ITEMS)
		{
			$entry['items'][] = self::encodeItem($rel);
		}

		$this->findings[$category] = $entry;
		$this->dirty               = true;
	}

	/**
	 * The encoded items every check reporting a category ignores. Read once per Collector, i.e. once per scan step.
	 *
	 * @return  array<string, array<string, true>>  Category => [encoded item => true]
	 */
	private function getIgnoredItems(): array
	{
		if ($this->ignoredItems !== null)
		{
			return $this->ignoredItems;
		}

		$this->ignoredItems = [];

		$all = ($this->store ?? new Store())->get(Store::KEY_IGNORED_ITEMS, []);

		if (!is_array($all) || empty($all))
		{
			return $this->ignoredItems;
		}

		foreach (self::CATEGORY_CHECKS as $category => $checkIds)
		{
			$common = null;

			foreach ($checkIds as $checkId)
			{
				$keys   = is_array($all[$checkId] ?? null) ? array_filter($all[$checkId], 'is_string') : [];
				$common = $common === null ? $keys : array_intersect($common, $keys);
			}

			if (!empty($common))
			{
				$this->ignoredItems[$category] = array_fill_keys($common, true);
			}
		}

		return $this->ignoredItems;
	}

	private function suppress(string $rel): void
	{
		$this->getSuppressed();

		if (count($this->suppressed) < self::MAX_SUPPRESSED)
		{
			$this->suppressed[] = $rel;
			$this->dirty        = true;
		}
	}

	private function getSuppressed(): array
	{
		if ($this->suppressed === null)
		{
			$suppressed       = $this->session->get('healthCheckSuppressed', []);
			$this->suppressed = is_array($suppressed) ? $suppressed : [];
		}

		return $this->suppressed;
	}

	/**
	 * Write the findings to the scanner's session.
	 *
	 * @return  void
	 * @since   7.10.0
	 */
	public function save(): void
	{
		if (!$this->dirty)
		{
			return;
		}

		$this->session->set('healthCheckFindings', $this->findings ?? []);
		$this->session->set('healthCheckSuppressed', $this->suppressed ?? []);

		$this->dirty = false;
	}
}
