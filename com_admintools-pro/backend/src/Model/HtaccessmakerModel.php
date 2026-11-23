<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\ServerTechnology;
use DateTimeZone;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

#[\AllowDynamicProperties]
class HtaccessmakerModel extends ServerconfigmakerModel
{
	use ApacheVersionTrait;

	/**
	 * The current configuration of this feature
	 *
	 * @var  object
	 */
	protected $configKey = 'htconfig';

	/**
	 * The base name of the configuration file being saved by this feature, e.g. ".htaccess". The file is always saved
	 * in the site's root. Any old files under that name are renamed with a .admintools suffix.
	 *
	 * @var string
	 */
	protected $configFileName = '.htaccess';

	/** @inheritdoc */
	public function isSupported(): int
	{
		return ServerTechnology::isHtaccessSupported();
	}

    /** @inheritdoc  */
    public function hasPhpHandlers(): bool
    {
        $htaccess_file = JPATH_ROOT.'/.htaccess';

        if (!file_exists($htaccess_file))
        {
            return false;
        }

        $contents = file_get_contents($htaccess_file);

	    if ($contents === false)
	    {
		    return false;
	    }

        return !is_null($this->extractHandler($contents));
    }

    /** @inheritdoc  */
	public function getPhpHandlers(): ?string
	{
		$htaccess_file = JPATH_ROOT.'/.htaccess';

		if (!file_exists($htaccess_file))
		{
			return null;
		}

		$contents = @file_get_contents($htaccess_file);

		if ($contents === false)
		{
			return null;
		}

		return $this->extractHandler($contents);
	}

    /**
     * This function will copy the handlers from the main .htaccess file and it will save it inside the custom rules field
     *
     * @return void
     * @throws \Exception
     */
    public function includePhpHandlers()
    {
        $htaccess_file = JPATH_ROOT.'/.htaccess';

        if (!file_exists($htaccess_file))
        {
            throw new \RuntimeException(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_LBL_PHPHANDLERS_ERR_NO_HTACCESS'));
        }

        $contents = file_get_contents($htaccess_file);
        $handlers = $this->extractHandler(is_string($contents) ? $contents : '');

        if (!$handlers)
        {
            throw new \RuntimeException(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_LBL_PHPHANDLERS_ERR_NO_HANDLERS'));
        }

        // Be sure we do have a loaded configuration
        $data = $this->loadConfiguration();

        // Double check that we do not already have some handlers in the custom footer field
        if ($data->custfoot)
        {
            $handlers_footer = $this->extractHandler($data->custfoot);

            if ($handlers_footer)
            {
                throw new \RuntimeException(Text::_('COM_ADMINTOOLS_HTACCESSMAKER_LBL_PHPHANDLERS_ERR_ALREADY_SAVED'));
            }
        }

        $data->custfoot .= "\n".$handlers;

        $this->saveConfiguration($data);
    }

    /** @inheritdoc  */
    public function extractHandler(string $server_config): ?string
    {
        // Normalize the .htaccess
        $server_config = $this->normalizeHtaccess($server_config);

        // Look for SetHandler and AddHandler in Files and FilesMatch containers
        foreach (['Files', 'FilesMatch'] as $container)
        {
            $result = $this->extractContainer($container, $server_config);

            if (!is_null($result))
            {
                return $result;
            }
        }

        // Fallback: extract an AddHandler line
        $found = preg_match('#^AddHandler\s?.*\.php.*$#mi', $server_config, $matches);

        if ($found >= 1)
        {
            return $matches[0];
        }

        return null;
    }

    /**
     * Adds the PHP handlers to the Custom Rules (footer), unless the footer already has a PHP handler.
     *
     * Idempotent: running it again on its own output changes nothing, because the handlers it appended are
     * detected by extractHandler() the next time around.
     */
    public function appendPhpHandlers(string $custfoot, ?string $handlers): string
    {
        $handlers = trim($handlers ?? '');

        if ($handlers === '')
        {
            return $custfoot;
        }

        if (trim($custfoot) === '')
        {
            return $handlers;
        }

        // The footer already has a PHP handler (ours from an earlier run, or one the administrator typed): keep it.
        if ($this->extractHandler($custfoot) !== null)
        {
            return $custfoot;
        }

        return rtrim($custfoot) . "\n" . $handlers;
    }

    /**
     * Normalize the .htaccess file content, making it suitable for handler extraction
     *
     * @param string $htaccess  The original file
     *
     * @return  string  The normalized file
     */
    private function normalizeHtaccess(string $htaccess): string
    {
        // Convert all newlines into UNIX style
        $htaccess = str_replace("\r\n", "\n", $htaccess);
        $htaccess = str_replace("\r", "\n", $htaccess);

		// Squash whitespace
	    $htaccess = preg_replace('/[\040\011]{1,}/m', ' ', $htaccess);
	    $htaccess = preg_replace('/^[\040\011]{1,}/m', '', $htaccess);

        // Return only non-comment, non-empty lines
        $isNonEmptyNonComment = function ($line) {
            $line = trim($line);

            return !empty($line) && (substr($line, 0, 1) !== '#');
        };

        $lines = array_map('trim', explode("\n", $htaccess));

        return implode("\n", array_filter($lines, $isNonEmptyNonComment));
    }

    /**
     * Extracts a Files or FilesMatch container with an AddHandler or SetHandler line
     *
     * @param string $container  "Files" or "FilesMatch"
     * @param string $htaccess   The .htaccess file content
     *
     * @return  string|null  NULL when not found
     */
    protected function extractContainer(string $container, string $htaccess): ?string
    {
        // Try to find the opening container tag e.g. <Files....>
        $pattern = sprintf('#<%s\s*.*\.php.*>#m', $container);
        $found   = preg_match_all($pattern, $htaccess, $matches, PREG_OFFSET_CAPTURE);

        if (!$found)
        {
            return null;
        }

		foreach ($matches[0] as $thisMatch)
		{
			// Get the rest of the .htaccess sample
			$openContainer  = $thisMatch[0];
			$subsetHtaccess = trim(substr($htaccess, $thisMatch[1] + strlen($thisMatch[0])));

			// Try to find the closing container tag
			$pattern    = sprintf('#<\s*/%s.*>#m', $container);
			$innerFound = preg_match($pattern, $subsetHtaccess, $innerMatches, PREG_OFFSET_CAPTURE);

			if (!$innerFound)
			{
				continue;
			}

			// Get the rest of the .htaccess sample
			$subsetHtaccess = trim(substr($subsetHtaccess, 0, $innerMatches[$innerFound - 1][1]));
			$closeContainer = $innerMatches[$innerFound - 1][0];

			if (empty($subsetHtaccess))
			{
				continue;
			}

			// Now we'll explode remaining lines and find the first SetHandler or AddHandler line
			$lines = array_map('trim', explode("\n", $subsetHtaccess));
			$lines = array_filter(
				$lines,
				function ($line) {
					$lowercaseLine = strtolower($line);

					return (strpos($lowercaseLine, 'addhandler') === 0)
						|| (strpos($lowercaseLine, 'sethandler') === 0)
						|| (strpos($lowercaseLine, 'fcgid') === 0);
				}
			);

			if (empty($lines))
			{
				continue;
			}

			return $openContainer . "\n" . array_shift($lines) . "\n" . $closeContainer;
		}

	    return null;
    }

	/**
	 * Compile and return the contents of the .htaccess configuration file
	 *
	 * @return  string
	 */
	public function makeConfigFile()
	{
		// Make sure we are called by an expected caller
		ServerTechnology::checkCaller($this->allowedCallersForMake);

		$app = Factory::getApplication();

		// Guess Apache features
		$apacheVersion = $this->apacheVersion();
		$serverCaps = (object) [
			'authz'        => version_compare($apacheVersion, '2.4', 'ge'), // "Require" rules (instead of allow / deny)
			'modfilter'    => version_compare($apacheVersion, '2.4', 'ge'), // Is mod_filter supported?
			'customCodes'  => version_compare($apacheVersion, '2.2', 'ge'), // Custom redirections, e.g. R=301
			'deflate'      => version_compare($apacheVersion, '2.0', 'ge'), // mod_deflate support
			'always_set'   => version_compare($apacheVersion, '2.0', 'ge'), // "always set" supported by mod_header
			'setenvifexpr' => version_compare($apacheVersion, '2.4', 'ge'), // mod_setenvif's SetEnvIfExpr
			// mod_headers' expr= conditions and Header edit. LiteSpeed passes for 2.4, but its support of them is not verified.
			'headerexpr'   => version_compare($apacheVersion, '2.4', 'ge')
				&& strtoupper(substr(ServerTechnology::getServerString(), 0, 9)) !== 'LITESPEED',
		];
		$redirCode     = $serverCaps->customCodes ? '[R=301,L]' : '[R,L]';

		$timezone = 'UTC';

		// Fetch the timezone from the user only if we're not in CLI
		if (!$app->isClient('cli'))
		{
			$timezone = $app->getIdentity()->getParam('timezone', $app->get('offset', 'UTC'));
		}

		$date = clone Factory::getDate();
		$tz   = new DateTimeZone($timezone);
		$date->setTimezone($tz);
		$d        = $date->format('Y-m-d H:i:s T', true);
		$version  = ADMINTOOLS_VERSION;
		$htaccess = <<<END
### ===========================================================================
### Security Enhanced & Highly Optimized .htaccess File for Joomla!
### automatically generated by Admin Tools $version on $d
### Auto-detected Apache version: $apacheVersion (best guess)
### ===========================================================================
###
### The contents of this file are based on the same author's work "Master
### .htaccess".
###
### Admin Tools is Free Software, distributed under the terms of the GNU
### General Public License version 3 or, at your option, any later version
### published by the Free Software Foundation.
###
### !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! IMPORTANT !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
### !!                                                                       !!
### !!  If you get an Internal Server Error 500 or a blank page when trying  !!
### !!  to access your site, remove this file and try tweaking its settings  !!
### !!  in the back-end of the Admin Tools component.                        !!
### !!                                                                       !!
### !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
###


END;

		// The exceptions go through normaliseDirectories(), so that every rule below sees the same entries (gh-454)
		$config = $this->normaliseExceptions($this->loadConfiguration());

		/**
		 * The site's path ("Base directory of your site"), as it is, e.g. my site (gh-462). Each directive which has it
		 * writes it the way that directive needs: URL-encoded in RewriteBase, escaped in the redirect substitutions (see
		 * substitutionLiteral()), and as a regular expression matching the request line in "Redirect index.php to /".
		 *
		 * A path with a double quote, a backslash, a question mark or a control character cannot be written into all of
		 * them: a ? in a substitution always starts the query string, and a new line would start another directive. For
		 * such a path the file refuses every request and says why, like the NginX Conf Maker does (gh-454), rather than
		 * leave the site unprotected or make Apache answer every request with HTTP 500.
		 */
		$sitePath = trim((string) ($config->rewritebase ?? ''), '/');

		if (preg_match('/["\\\\?[:cntrl:]]/', $sitePath))
		{
			$deny = $serverCaps->authz ? 'Require all denied' : "Order Deny,Allow\nDeny from all";

			return $htaccess . <<<END
##### NOT GENERATED: the site's path (Base directory of your site) has
##### a double quote, a backslash, a question mark or a control
##### character, which this file cannot have. Every request is refused.
##### Rename your site's folder, or delete this file, then make this
##### file again.
$deny

END;
		}

		/**
		 * IP restriction. An empty list writes nothing (gh-466), see restrictIpList(): it is a misconfiguration, which
		 * the maker's page warns about. An empty <RequireAny> made Apache answer every request with HTTP 500, and since
		 * gh-462 the file refused everyone instead.
		 */
		$restrictIPList = $this->restrictIpList($config);

		if (!empty($restrictIPList))
		{
			$htaccess .= <<< END
##### Restricted access by IP address -- BEGIN

END;

			if (!$serverCaps->authz)
			{
				$htaccess .= <<<END
Order Deny,Allow
Deny from all

END;
			}
			else
			{
				$htaccess .= <<<END
<RequireAny>

END;
			}

			foreach ($restrictIPList as $ip)
			{
				$htaccess .= ($serverCaps->authz ? 'Require ip ' : 'Allow from ').$ip."\n";
			}

			if ($serverCaps->authz)
			{
				$htaccess .= <<<END
</RequireAny>

END;

			}

			$htaccess .= <<< END
##### Restricted access by IP address -- END


END;
		}

		// Is HSTS enabled?
		$hasHSTS = $config->hstsheader != 0;

		$htaccess .= <<< HTACCESS
##### RewriteEngine enabled - BEGIN
RewriteEngine On
##### RewriteEngine enabled - END

# PHP FastCGI fix for HTTP Authorization
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

HTACCESS;

		if ($config->frontendprot == 1 || $config->backendprot == 1)
		{
			/**
			 * Hosts which map PHP with AddHandler run any file with a PHP extension anywhere in its name, e.g.
			 * x.php.png, as a PHP script. Path info (x.php.png/y.png) and any letter case are covered as well.
			 *
			 * This must come before every other rule which can end rule processing or redirect the request, not just
			 * before the server protection exceptions, which all match the last extension of the file, or the folder
			 * alone. The automatic compression rules end rule processing ([L]) for names ending in .css.br, .js.br,
			 * .css.gz and .js.gz, so x.php.js.br was run as PHP. The Custom Rules (Top of File) come after it too: a
			 * rule typed there must not let such a file through either.
			 */
			$phpExt   = self::PHP_EXTENSIONS;
			$htaccess .= <<<END
##### Forbid PHP double extensions (e.g. file.php.png) everywhere
RewriteRule \.($phpExt)\. - [F,NC]


END;
		}

		if ($config->notracetrack == 1)
		{
			/**
			 * TraceEnable (https://httpd.apache.org/docs/2.4/mod/core.html#traceenable) is not allowed in a .htaccess
			 * file, only in the server and virtual host configuration: a rewrite rule is the only way to refuse these
			 * methods here.
			 *
			 * It refuses TRACK, and any TRACE which reaches it (gh-473). With TraceEnable On, Apache's default, the
			 * server answers TRACE itself before it reads any .htaccess file: only TraceEnable Off refuses it. The
			 * condition was ^TRACE, which never matched TRACK.
			 *
			 * It must come before every rule which can end the rule processing or redirect the request, right after the
			 * PHP double extensions rule. It came after the Server Protection rules, whose [L] ends the rule processing
			 * for every file they let through, e.g. the site's index.php: TRACK /index.php ran the site.
			 */
			$tmpRedirCode = $serverCaps->customCodes ? '[R=405,L]' : '[F,L]';
			$htaccess     .= <<<END
## Disable HTTP methods TRACE and TRACK (protect against XST)
RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK)$
RewriteRule ^ - $tmpRedirCode


END;
		}

		/**
		 * User agents to block.
		 *
		 * Do NOT wrap the pattern in double quotes: in a mod_rewrite RewriteCond the quotes are treated as literal
		 * characters in the regular expression, so nothing ever matches. White space inside a pattern, the space
		 * included, is written as \s so multi-word agents (e.g. "mass downloader") remain a single RewriteCond argument
		 * and still match; a tab ended the pattern, and Apache answered every request with HTTP 500.
		 *
		 * mod_rewrite reads a pattern starting with " or ' as a quoted argument, which swallowed the flags and broke the
		 * OR chain; one starting with ! as a negated pattern, which blocked every other user agent; one starting with -
		 * as a file test (-d, -f, …) and one starting with =, < or > as a string comparison. That first character is
		 * escaped with a backslash, which PCRE reads as the character itself. A final escaped backslash is written as
		 * \x5C. See blockedUserAgentRegex().
		 *
		 * Blank agents are skipped: an empty pattern made mod_rewrite read the flags as the pattern. So are agents which
		 * are not a valid regular expression, which made Apache answer every request with HTTP 500, and agents with a
		 * control character other than white space; the maker's page lists these (gh-466). The last condition is the
		 * last one written, without OR.
		 */
		$hoggerAgents = [];

		foreach ($this->normaliseUserAgents($config->hoggeragents ?? []) as $agent)
		{
			$regex = $this->blockedUserAgentRegex($agent);

			if ($regex !== null)
			{
				$hoggerAgents[] = $regex;
			}
		}

		if (!empty($hoggerAgents) && ($config->nohoggers == 1))
		{
			$htaccess .= "##### Common hacking tools and bandwidth hoggers block -- BEGIN\n";

			$last  = count($hoggerAgents) - 1;
			$lines = [];

			foreach ($hoggerAgents as $i => $pattern)
			{
				$lines[] = "RewriteCond %{HTTP_USER_AGENT} $pattern " . ($i < $last ? '[NC,OR]' : '[NC]');
			}

			$htaccess .= implode("\n", $lines) . "\n";
			$htaccess .= "RewriteRule ^ - [F,L]\n";
			$htaccess .= "##### Common hacking tools and bandwidth hoggers block -- END\n\n";
		}

		$rewritebase = $config->rewritebase;

		if (!empty($rewritebase))
		{
			/**
			 * URL-encoded (gh-462): a space ended the argument, and Apache answered every request with HTTP 500. Apache
			 * decodes the URL it rewrites to, so a raw % made it answer HTTP 400.
			 */
			$htaccess    .= "##### RewriteBase set - BEGIN\n";
			$rewritebase = $this->urlEncodedPath($sitePath);
			$htaccess    .= "RewriteBase /$rewritebase\n";
			$htaccess    .= "##### RewriteBase set - END\n\n";
		}

		/**
		 * Every check of the X-Forwarded-Proto header a TLS-terminating proxy sends, here and in the redirects below, is
		 * a case-insensitive regular expression: ^https$ [NC] or !^https$ [NC]. Never a lexicographic comparison: in
		 * `==https` mod_rewrite takes the second "=" as part of the string, so it never matches, and `!=https` is
		 * case-sensitive, so a proxy sending "HTTPS" would get redirect loops and http:// redirects.
		 */
		if ($hasHSTS)
		{
			// %{REQUEST_URI} already holds the site's path: only the host name part of the host name field is used.
			$httpsHost = $this->hostWithoutPath((string) ($config->httpshost ?? ''));
			$htaccess  .= <<< END
##### HTTP to HTTPS redirection
## Since you have enabled HSTS the first redirection rule will instruct the browser to visit the HTTPS version of your
## site. This prevents unsafe redirections through HTTP.
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !^https$ [NC]
RewriteRule .* https://$httpsHost%{REQUEST_URI} [L,R=301]


END;
		}

		if (!empty($config->custhead))
		{
			$htaccess .= "##### Custom Rules (Top of File) -- BEGIN\n";
			$htaccess .= $config->custhead . "\n";
			$htaccess .= "##### Custom Rules (Top of File) -- END\n\n";
		}

		if ($config->fileorder == 1)
		{
			/**
			 * Every directive of an optional module must be inside an <IfModule> for it: Apache answers every request
			 * with HTTP 500 when a .htaccess file uses a directive of a module it has not loaded. Here: mod_dir.
			 */
			$htaccess .= "##### File execution order -- BEGIN\n";
			$htaccess .= "<IfModule mod_dir.c>\n";
			$htaccess .= "\tDirectoryIndex index.php index.html index.htm\n";
			$htaccess .= "</IfModule>\n";
			$htaccess .= "##### File execution order -- END\n\n";
		}

		if ($config->nodirlists == 1)
		{
			// Without mod_autoindex there are no directory listings; Options is a core directive.
			$htaccess .= "##### No directory listings -- BEGIN\n";
			$htaccess .= "<IfModule mod_autoindex.c>\n";
			$htaccess .= "\tIndexIgnore *\n";
			$htaccess .= "</IfModule>\n";

			switch ($config->symlinks)
			{
				case 0:
					$htaccess .= "Options -Indexes\n";
					break;

				case 1:
					$htaccess .= "Options -Indexes +FollowSymLinks\n";
					break;

				case 2:
					$htaccess .= "Options -Indexes +SymLinksIfOwnerMatch\n";
					break;
			}

			$htaccess .= "##### No directory listings -- END\n\n";
		}
		elseif ($config->symlinks != 0)
		{
			$htaccess .= "##### Follow symlinks -- BEGIN\n";

			switch ($config->symlinks)
			{
				case 1:
					$htaccess .= "Options +FollowSymLinks\n";
					break;

				case 2:
					$htaccess .= "Options +SymLinksIfOwnerMatch\n";
					break;
			}

			$htaccess .= "##### Follow symlinks -- END\n\n";
		}

		if ($config->exptime != 0)
		{
			/**
			 * The administrator's index.php must not be cached (gh-473). This was a <Files "administrator/index.php">
			 * section, which never matched: <Files> only sees the file's base name. mod_setenvif marks the request by
			 * its URI instead, which is the whole path, also for a site in a subfolder, and URL-decoded: the pattern
			 * starts with the site's path, escaped with pathRegex(), like the "Disable client-side risky behavior"
			 * patterns. /administrator/ too, which mod_dir serves with that index.php. Any letter case: a site on a
			 * case-insensitive file system serves it in any letter case too. Not <If>, which needs Apache 2.4.
			 *
			 * ExpiresActive Off cannot depend on a variable; it is not needed either. mod_headers runs after
			 * mod_expires, so Header set replaces the Expires and Cache-Control headers mod_expires sends. Joomla
			 * sends its own Cache-Control, Pragma and Expires headers for these pages. A PHP-FPM or CGI response's
			 * headers are in the table of "Header always", which "Header set" does not change: they are removed first,
			 * otherwise the response had both. Each module's directives are in an <IfModule> of their own, otherwise
			 * Apache answers every request with HTTP 500 when the module is not loaded.
			 */
			$adminPathRegex = ($sitePath === '' ? '' : '/' . $this->pathRegex($sitePath)) . '/administrator/(index\.php)?';
			$alwaysUnset    = $serverCaps->always_set ? 'always unset' : 'unset';

			$expWeek  = '1 week';
			$expMonth = '1 month';

			if ($config->exptime == 2)
			{
				$expWeek  = '1 year';
				$expMonth = '1 year';
			}

			$htaccess .= <<<END
##### Optimal default expiration time - BEGIN
<IfModule mod_expires.c>
	# Enable expiration control
	ExpiresActive On
	
	# No caching for specific resource types
	## -- Application cache manifest
	ExpiresByType text/cache-manifest "now"
	## -- XML and JSON
	ExpiresByType application/json "now"
	ExpiresByType application/xml "now"
	ExpiresByType text/xml "now"

	## RSS and Atom feeds: 1 hour (hardcoded)
	ExpiresByType application/atom+xml "now plus 1 hour"
	ExpiresByType application/rss+xml "now plus 1 hour"

	# CSS and JS expiration: $expWeek after request
	ExpiresByType text/css "now plus $expWeek"
	ExpiresByType text/javascript "now plus $expWeek"
	ExpiresByType application/javascript "now plus $expWeek"
	ExpiresByType application/ld+json "now plus $expWeek"
	ExpiresByType application/x-javascript "now plus $expWeek"

	# Image files expiration: $expMonth after request
	ExpiresByType application/ico "now plus $expMonth"
	ExpiresByType application/smil "now plus $expMonth"
	ExpiresByType application/vnd.wap.wbxml "now plus $expMonth"
	ExpiresByType image/bmp "now plus $expMonth"
	ExpiresByType image/gif "now plus $expMonth"
	ExpiresByType image/ico "now plus $expMonth"
	ExpiresByType image/icon "now plus $expMonth"
	ExpiresByType image/jp2 "now plus $expMonth"
	ExpiresByType image/jpeg "now plus $expMonth"
	ExpiresByType image/jpg "now plus $expMonth"
	ExpiresByType image/pipeg "now plus $expMonth"
	ExpiresByType image/png "now plus $expMonth"
	ExpiresByType image/svg+xml "now plus $expMonth"
	ExpiresByType image/tiff "now plus $expMonth"
	ExpiresByType image/vnd.microsoft.icon "now plus $expMonth"
	ExpiresByType image/vnd.wap.wbmp "now plus $expMonth"
	ExpiresByType image/webp "now plus $expMonth"
	ExpiresByType image/x-icon "now plus $expMonth"
	ExpiresByType text/ico "now plus $expMonth"
	
	# Font files expiration: $expWeek after request
	ExpiresByType application/font-woff "now plus $expWeek"
	ExpiresByType application/font-woff2 "now plus $expWeek"
	ExpiresByType application/vnd.ms-fontobject "now plus $expWeek"
	ExpiresByType application/x-font-opentype "now plus $expWeek"
	ExpiresByType application/x-font-ttf "now plus $expWeek"
	ExpiresByType application/x-font-woff "now plus $expWeek"
	ExpiresByType font/opentype "now plus $expWeek"
	ExpiresByType font/otf "now plus $expWeek"
	ExpiresByType font/ttf "now plus $expWeek"
	ExpiresByType font/woff "now plus $expWeek"
	ExpiresByType font/woff2 "now plus $expWeek"

	# Audio files expiration: $expMonth after request
	ExpiresByType application/ogg "now plus $expMonth"
	ExpiresByType audio/3gpp "now plus $expMonth"
	ExpiresByType audio/3gpp2 "now plus $expMonth"
	ExpiresByType audio/aac "now plus $expMonth"
	ExpiresByType audio/basic "now plus $expMonth"
	ExpiresByType audio/mid "now plus $expMonth"
	ExpiresByType audio/midi "now plus $expMonth"
	ExpiresByType audio/mp3 "now plus $expMonth"
	ExpiresByType audio/mpeg "now plus $expMonth"
	ExpiresByType audio/ogg "now plus $expMonth"
	ExpiresByType audio/opus "now plus $expMonth"
	ExpiresByType audio/x-aiff "now plus $expMonth"
	ExpiresByType audio/x-mpegurl "now plus $expMonth"
	ExpiresByType audio/x-pn-realaudio "now plus $expMonth"
	ExpiresByType audio/x-wav "now plus $expMonth"
	ExpiresByType audio/wav "now plus $expMonth"

	# Movie files expiration: $expMonth after request
	ExpiresByType application/x-shockwave-flash "now plus $expMonth"
	ExpiresByType video/3gpp "now plus $expMonth"
	ExpiresByType video/3gpp2 "now plus $expMonth"
	ExpiresByType video/mp4 "now plus $expMonth"
	ExpiresByType video/mpeg "now plus $expMonth"
	ExpiresByType video/ogg "now plus $expMonth"
	ExpiresByType video/quicktime "now plus $expMonth"
	ExpiresByType video/webm "now plus $expMonth"
	ExpiresByType video/x-la-asf "now plus $expMonth"
	ExpiresByType video/x-ms-asf "now plus $expMonth"
	ExpiresByType video/x-msvideo "now plus $expMonth"
	ExpiresByType x-world/x-vrml "now plus $expMonth"
</IfModule>

# Disable caching of administrator/index.php
<IfModule mod_setenvif.c>
	SetEnvIfNoCase Request_URI "^$adminPathRegex$" admintools_admin_nocache
</IfModule>
<IfModule mod_headers.c>
	Header unset ETag env=admintools_admin_nocache
	Header $alwaysUnset Cache-Control env=admintools_admin_nocache
	Header $alwaysUnset Pragma env=admintools_admin_nocache
	Header $alwaysUnset Expires env=admintools_admin_nocache
	Header set Cache-Control "max-age=0, no-cache, no-store, must-revalidate" env=admintools_admin_nocache
	Header set Pragma "no-cache" env=admintools_admin_nocache
	Header set Expires "Wed, 11 Jan 1984 05:00:00 GMT" env=admintools_admin_nocache
</IfModule>

##### Optimal default expiration time - END


END;
		}

		if ($config->etagtype != 'default')
		{
			$htaccess .= "## Send ETag (selected method: {$config->etagtype})\n";

			switch ($config->etagtype)
			{
				case 'full':
					$htaccess .= <<< HTACCESS
FileETag All

HTACCESS;
					break;

				case 'sizetime':
					$htaccess .= <<< HTACCESS
FileETag MTime Size

HTACCESS;
					break;

				case 'size':
					$htaccess .= <<< HTACCESS
FileETag Size

HTACCESS;
					break;

				case 'none':
					$htaccess .= <<< HTACCESS
<IfModule mod_headers.c>
	Header unset ETag
</IfModule>

FileETag None

HTACCESS;
					break;
			}
		}

		if ($config->autoroot)
		{
			/**
			 * Only a request for the site's index.php itself, as the client sent it, without a query string or path
			 * info, and not a POST. %{THE_REQUEST} is the request line as received, before any rewrite, so a request
			 * for the site's root which Joomla's SEF rule turns into index.php is not redirected back to it in a loop.
			 *
			 * Both the condition and the target are built from the "Base directory of your site" (rewritebase), so
			 * that a site in a subfolder redirects its own index.php, e.g. /foo/index.php to /foo/. %{THE_REQUEST} is not
			 * URL-decoded: the condition matches the path as the browser sends it, e.g. %20 for a space (gh-462).
			 *
			 * The plain target is relative to the domain; Apache makes it absolute with the scheme of its own
			 * connection. Behind a reverse proxy or load balancer which terminates TLS that is plain HTTP, and an HTTPS
			 * visitor would be sent to http://. A first rule, for X-Forwarded-Proto: https in any letter case,
			 * redirects to an absolute https:// target instead, like the www and domain redirects below do.
			 */
			$base        = $sitePath === '' ? '' : '/' . $sitePath;
			$basePattern = $this->requestPathRegex($base);
			$base        = $this->substitutionLiteral($base);

			$htaccess .= <<<END
##### Redirect index.php to / -- BEGIN
# Behind a reverse proxy or load balancer which received the request over HTTPS
RewriteCond %{THE_REQUEST} !^POST
RewriteCond %{THE_REQUEST} ^[A-Z]{3,9}\ $basePattern/index\.php\ HTTP/
RewriteCond %{HTTP:X-Forwarded-Proto} ^https$ [NC]
RewriteRule ^index\.php$ https://%{HTTP_HOST}$base/ $redirCode
# Any other request
RewriteCond %{THE_REQUEST} !^POST
RewriteCond %{THE_REQUEST} ^[A-Z]{3,9}\ $basePattern/index\.php\ HTTP/
RewriteRule ^index\.php$ $base/ $redirCode
##### Redirect index.php to / -- END

END;
		}

		/**
		 * In a .htaccess, a RewriteRule's pattern, and so $1, is relative to the folder of the .htaccess. For a site in a
		 * subfolder, every absolute target of the www, old domain and Force HTTPS redirects must add the subfolder back:
		 * "Base directory of your site". The host name fields may also hold the site's path, as our documentation used
		 * to tell users to type it there: only their host name part is used, so that the subfolder is never there twice.
		 */
		$subfolder = $sitePath === '' ? '' : $this->substitutionLiteral($sitePath) . '/';

		switch ($config->wwwredir)
		{
			// non-www to www
			case 1:
				if ($hasHSTS)
				{
					$htaccess .= <<<END
##### Redirect non-www to www -- BEGIN
RewriteCond %{HTTP_HOST} !^www\. [NC]
RewriteRule ^(.*)$ https://www.%{HTTP_HOST}/$subfolder$1 $redirCode
##### Redirect non-www to www -- END


END;
				}
				else
				{
					{
						$htaccess .= <<<END
##### Redirect non-www to www -- BEGIN
# HTTP
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !^https$ [NC]
RewriteCond %{HTTP_HOST} !^www\. [NC]
RewriteRule ^(.*)$ http://www.%{HTTP_HOST}/$subfolder$1 $redirCode
# HTTPS
RewriteCond %{HTTPS} =on [OR]
RewriteCond %{HTTP:X-Forwarded-Proto} ^https$ [NC]
RewriteCond %{HTTP_HOST} !^www\. [NC]
RewriteRule ^(.*)$ https://www.%{HTTP_HOST}/$subfolder$1 $redirCode
##### Redirect non-www to www -- END

END;
					}
				}

				break;

			// www to non-www
			case 2:
				if ($hasHSTS)
				{
					$htaccess .= <<<END
##### Redirect www to non-www -- BEGIN
RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^(.*)$ https://%1/$subfolder$1 $redirCode
##### Redirect www to non-www -- END


END;
				}
				else
				{
					$htaccess .= <<<END
##### Redirect www to non-www -- BEGIN
# HTTP
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !^https$ [NC]
RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^(.*)$ http://%1/$subfolder$1 $redirCode
# HTTPS
RewriteCond %{HTTPS} =on [OR]
RewriteCond %{HTTP:X-Forwarded-Proto} ^https$ [NC]
RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^(.*)$ https://%1/$subfolder$1 $redirCode
##### Redirect www to non-www -- END


END;
				}
				break;
		}

		// Each old domain once, regardless of letter case and port (gh-470)
		$domains = $this->normaliseOldDomains($config->olddomain ?? '');

		if (!empty($domains))
		{
			$htaccess .= "##### Redirect old to new domain -- BEGIN\n";

			$newHTTPDomain  = $this->hostWithoutPath((string) ($config->httphost ?? ''));
			$newHTTPSDomain = $this->hostWithoutPath((string) ($config->httpshost ?? ''));

			foreach ($domains as $olddomain)
			{
				/**
				 * The old domain and its subdomains, with any port or none, in any letter case ([NC]): without the right
				 * anchor, old.example.com.evil.test matched old.example.com. An old domain which is a protocol's host
				 * name, or of which that host name is a subdomain (e.g. example.com for www.example.com), in any letter
				 * case and with any port, is not redirected for that protocol: it would loop.
				 */
				$httpRedirect  = !$this->oldDomainCoversHost($olddomain, (string) $newHTTPDomain);
				$httpsRedirect = !$this->oldDomainCoversHost($olddomain, (string) $newHTTPSDomain);
				$olddomain     = $this->oldDomainHostRegex($olddomain);

				if ($httpRedirect && !$hasHSTS)
				{
					$htaccess .= <<<END
## Plain HTTP
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !^https$ [NC]
RewriteCond %{HTTP_HOST} $olddomain [NC]
RewriteRule (.*) http://$newHTTPDomain/$subfolder$1 $redirCode

END;
				}

				if ($httpsRedirect && !$hasHSTS)
				{
					$htaccess .= <<<END
## HTTPS
RewriteCond %{HTTPS} =on [OR]
RewriteCond %{HTTP:X-Forwarded-Proto} ^https$ [NC]
RewriteCond %{HTTP_HOST} $olddomain [NC]
RewriteRule (.*) https://$newHTTPSDomain/$subfolder$1 $redirCode

END;
				}

				if ($httpsRedirect && $hasHSTS)
				{
					$htaccess .= <<<END
## Forced HTTPS - You have enabled the HSTS feature
RewriteCond %{HTTP_HOST} $olddomain [NC]
RewriteRule (.*) https://$newHTTPSDomain/$subfolder$1 $redirCode

END;

				}
			}
			$htaccess .= "##### Redirect old to new domain -- END\n\n";
		}

		/**
		 * LEGACY FEATURE: "Force HTTPS for these URLs" (httpsurls) will be removed in a future version. It predates sites
		 * served entirely over HTTPS; today the whole site should be on HTTPS, e.g. with the HSTS Header option.
		 * Keep it working, but do not extend it.
		 *
		 * The pattern is matched against the path relative to the .htaccess's folder, which never starts with a slash:
		 * an entry typed with one, e.g. /login, is normalised to login, otherwise it would never match. An entry with a
		 * query string only matches that path with exactly that query string (gh-466), see normaliseForceHttpsUrls().
		 */
		$httpsUrls = $this->normaliseForceHttpsUrls($config->httpsurls ?? []);

		if (!empty($httpsUrls))
		{
			$httpsHost = $this->hostWithoutPath((string) ($config->httpshost ?? ''));
			$htaccess  .= "##### Force HTTPS for certain pages -- BEGIN\n";

			foreach ($httpsUrls as $url)
			{
				[$path, $query] = array_pad(explode('?', $url, 2), 2, null);

				/**
				 * The pattern and the substitution are unquoted arguments, and the entry must be literal in both. A space
				 * would end either, and Apache would refuse the whole .htaccess with HTTP 500: in the regex-escaped
				 * pattern it is \x20. In the substitution a backslash escapes the next character: $N, %N, ${…} and %{…}
				 * would otherwise be back-references, RewriteMap lookups or server variables. mod_rewrite encodes the
				 * space and the % again in the Location header.
				 *
				 * The substitution has no query string, so Apache appends the one the visitor sent, as it is: the redirect
				 * is to the same URL over HTTPS. An entry's query string is a condition on %{QUERY_STRING}, which is not
				 * URL-decoded, matched exactly and in the order typed (gh-466). Its regular expression has no space, see
				 * queryStringRegex(). A CondPattern has no back-references or server variables, so a % is a %.
				 */
				$urlesc    = '^' . str_replace(' ', '\x20', $this->escape_string_for_regex($path)) . '$';
				$urlTarget = $this->substitutionLiteral($path);
				$queryCond = $query === null
					? ''
					: 'RewriteCond %{QUERY_STRING} ^' . $this->queryStringRegex($query) . "$\n";

				/**
				 * Only the request the visitor sent. mod_rewrite runs the .htaccess's rules again after an internal
				 * rewrite, e.g. Joomla's SEF rule rewriting any SEF URL to index.php, and Apache sets REDIRECT_STATUS on
				 * that pass only. Without this condition a rule for index.php redirected every SEF URL to the HTTPS
				 * index.php, the home page.
				 */
				$htaccess  .= <<<END
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !^https$ [NC]
{$queryCond}RewriteRule $urlesc https://$httpsHost/$subfolder$urlTarget $redirCode

END;
			}
			$htaccess .= "##### Force HTTPS for certain pages -- END\n\n";
		}

		/**
		 * The automatic compression rules come after every redirect: "Redirect index.php to /", the www / non-www and old
		 * domain redirects and "Force HTTPS for these URLs". They rewrite x.css to an existing x.css.gz, x.css.br etc.,
		 * and end rule processing ([L]) for such names, so a redirect after them never saw a pre-compressed CSS or
		 * JavaScript file, or a request for one: it was served on any host name and over HTTP. They still come before the
		 * common exploits, file injection and server protection blocks, which must not refuse the .gz and .br files.
		 */
		if (($config->autocompress == 1) && ($serverCaps->deflate))
		{
			// See https://stackoverflow.com/questions/5230202/apache-addoutputfilterbytype-is-deprecated-how-to-rewrite-using-mod-filter
			$apacheModuleForDeflate = $serverCaps->modfilter ? 'mod_filter' : 'mod_deflate';

			/**
			 * AddOutputFilterByType is mod_filter's on Apache 2.4 and core on earlier versions. Each filter is its own
			 * module's: BROTLI_COMPRESS is mod_brotli's, DEFLATE mod_deflate's. Without the module Apache answers every
			 * request with HTTP 500 ("Invalid command", "Unknown filter provider"), so each one has its <IfModule>.
			 */
			$mimeTypes = 'text/plain text/xml text/css application/xml application/xhtml+xml application/rss+xml application/javascript application/x-javascript text/javascript image/svg+xml';

			if ($serverCaps->modfilter)
			{
				$brotliFilter  = "<IfModule mod_brotli.c>\n\t<IfModule mod_filter.c>\n\t\tAddOutputFilterByType BROTLI_COMPRESS $mimeTypes\n\t</IfModule>\n</IfModule>";
				$deflateFilter = "<IfModule mod_filter.c>\n\t<IfModule mod_deflate.c>\n\t\tAddOutputFilterByType DEFLATE $mimeTypes\n\t</IfModule>\n</IfModule>";
			}
			else
			{
				$brotliFilter  = "<IfModule mod_brotli.c>\n\tAddOutputFilterByType BROTLI_COMPRESS $mimeTypes\n</IfModule>";
				$deflateFilter = "<IfModule mod_deflate.c>\n\tAddOutputFilterByType DEFLATE $mimeTypes\n</IfModule>";
			}

			/**
			 * The rules serving a pre-compressed file match names ending in .css or .js only (gh-462). Without the $
			 * anchor, a request for x.css.map was rewritten to x.css.br whenever x.css.map.br existed (the condition tests
			 * the requested file's own .br), and one for x.json to x.js.br: the wrong file was served.
			 *
			 * The rules which set the content type of a .css.br, .js.br, .css.gz or .js.gz file end rule processing
			 * ([L]) before every protection below. Their pattern is matched against the URL path, path info included,
			 * so each one also needs the file the request maps to (%{REQUEST_FILENAME}, without path info) to have that
			 * name (gh-482). Otherwise a request for images/x.php/a.css.gz ran x.php past Server Protection, and one for
			 * index.php/a.css.gz skipped the common exploits and file injection filters.
			 */
			$htaccess               .= <<<HTACCESS
##### Automatic compression of resources -- BEGIN
# Automatically serve .css.gz, .css.br, .js.gz or .js.br instead of the original file
# These are versions of the files pre-compressed with GZip or Brotli, respectively
<IfModule mod_headers.c>
    # Serve Brotli compressed CSS files if they exist and the client accepts Brotli.
    RewriteCond "%{HTTP:Accept-encoding}" "br"
    RewriteCond "%{REQUEST_FILENAME}\.br" -s
    RewriteRule "^(.*)\.css$" "$1\.css\.br" [QSA]

    # Serve Brotli compressed JS files if they exist and the client accepts Brotli.
    RewriteCond "%{HTTP:Accept-encoding}" "br"
    RewriteCond "%{REQUEST_FILENAME}\.br" -s
    RewriteRule "^(.*)\.js$" "$1\.js\.br" [QSA]
    
    # Serve correct content types, and prevent double compression.
    # Only for the files themselves: the request's path info must not end rule processing before the protections.
    RewriteCond "%{REQUEST_FILENAME}" "\.css\.br$"
    RewriteRule "\.css\.br$" "-" [T=text/css,E=no-gzip:1,E=no-brotli:1,L]
    RewriteCond "%{REQUEST_FILENAME}" "\.js\.br$"
    RewriteRule "\.js\.br$" "-" [T=text/javascript,E=no-gzip:1,E=no-brotli:1,L]
    
    <FilesMatch "(\.js\.br|\.css\.br)$">
      # Serve correct encoding type.
      Header set Content-Encoding br

      # Force proxies to cache gzipped & non-gzipped css/js files separately.
      Header append Vary Accept-Encoding
    </FilesMatch>

    # Serve gzip compressed CSS files if they exist and the client accepts gzip.
    RewriteCond "%{HTTP:Accept-encoding}" "gzip"
    RewriteCond "%{REQUEST_FILENAME}\.gz" -s
    RewriteRule "^(.*)\.css$" "$1\.css\.gz" [QSA]

    # Serve gzip compressed JS files if they exist and the client accepts gzip.
    RewriteCond "%{HTTP:Accept-encoding}" "gzip"
    RewriteCond "%{REQUEST_FILENAME}\.gz" -s
    RewriteRule "^(.*)\.js$" "$1\.js\.gz" [QSA]

    # Serve correct content types, and prevent $apacheModuleForDeflate double gzip.
    # Also set it as the last rule to prevent the Front- or Backend protection from preventing access to the .gz file.
    # Only for the files themselves: the request's path info must not end rule processing before the protections.
    RewriteCond "%{REQUEST_FILENAME}" "\.css\.gz$"
    RewriteRule "\.css\.gz$" "-" [T=text/css,E=no-gzip:1,E=no-brotli:1,L]
    RewriteCond "%{REQUEST_FILENAME}" "\.js\.gz$"
    RewriteRule "\.js\.gz$" "-" [T=text/javascript,E=no-gzip:1,E=no-brotli:1,L]

    <FilesMatch "(\.js\.gz|\.css\.gz)$">
      # Serve correct encoding type.
      Header set Content-Encoding gzip

      # Force proxies to cache gzipped & non-gzipped css/js files separately.
      Header append Vary Accept-Encoding
    </FilesMatch>
</IfModule>

## Automatically compress by MIME type using mod_brotli. Takes priority due to better compression ratio.
$brotliFilter

## Automatically compress by MIME type using mod_deflate.
$deflateFilter

## Fallback to mod_gzip when neither mod_brotli nor $apacheModuleForDeflate is available
<IfModule !mod_brotli.c>
	<IfModule !{$apacheModuleForDeflate}.c>
		<IfModule mod_gzip.c>
			mod_gzip_on Yes
			mod_gzip_dechunk Yes
			mod_gzip_keep_workfiles No
			mod_gzip_can_negotiate Yes
			mod_gzip_add_header_count Yes
			mod_gzip_send_vary Yes
			mod_gzip_min_http 1000
			mod_gzip_minimum_file_size 300
			mod_gzip_maximum_file_size 512000
			mod_gzip_maximum_inmem_size 60000
			mod_gzip_handle_methods GET
			mod_gzip_item_include file \.(html?|txt|css|js|php|pl|xml|rb|py|svg|scgz)$
			mod_gzip_item_include mime ^text/javascript$
			mod_gzip_item_include mime ^text/plain$
			mod_gzip_item_include mime ^text/xml$
			mod_gzip_item_include mime ^text/css$
			mod_gzip_item_include mime ^application/xml$
			mod_gzip_item_include mime ^application/xhtml+xml$
			mod_gzip_item_include mime ^application/rss+xml$
			mod_gzip_item_include mime ^application/javascript$
			mod_gzip_item_include mime ^application/x-javascript$
			mod_gzip_item_include mime ^image/svg+xml$
			mod_gzip_item_exclude rspheader ^Content-Encoding:.*gzip.*
			mod_gzip_item_include handler ^cgi-script$
			mod_gzip_item_include handler ^server-status$
			mod_gzip_item_include handler ^server-info$
			mod_gzip_item_include handler ^application/x-httpd-php
			mod_gzip_item_exclude mime ^image/.*
		</ifmodule>
	</IfModule>
</IfModule>
##### Automatic compression of resources -- END

HTACCESS;

			if ($config->forcegzip == 1)
			{
				$htaccess .= <<< HTACCESS
## Force GZip compression for mangled Accept-Encoding headers
<IfModule mod_setenvif.c>
	<IfModule mod_headers.c>
		SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$ ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding
		RequestHeader append Accept-Encoding "gzip,deflate" env=HAVE_Accept-Encoding
	</IfModule>
</IfModule>

HTACCESS;
			}
		}

		$htaccess .= <<<END
##### Rewrite rules to block out some common exploits -- BEGIN
RewriteCond %{QUERY_STRING} proc/self/environ [OR]
RewriteCond %{QUERY_STRING} mosConfig_[a-zA-Z_]{1,21}(=|\%3D) [OR]
RewriteCond %{QUERY_STRING} base64_(en|de)code\(.*\) [OR]
RewriteCond %{QUERY_STRING} (<|%3C).*script.*(>|%3E) [NC,OR]
RewriteCond %{QUERY_STRING} GLOBALS(=|\[|\%[0-9A-Z]{0,2}) [OR]
RewriteCond %{QUERY_STRING} _REQUEST(=|\[|\%[0-9A-Z]{0,2})
RewriteRule .* index.php [F]
##### Rewrite rules to block out some common exploits -- END

END;

		if ($config->fileinj == 1)
		{
			/**
			 * See FILE_INJECTION_PATTERNS (gh-485). Every condition is case-insensitive, and there is no REQUEST_METHOD
			 * condition any more: it limited the block to GET requests, while the query string of a POST request reaches
			 * the application just the same, and the NginX Conf and web.config Makers have always checked every method.
			 *
			 * mod_rewrite ORs a condition with the next one, so this reads (P1 || P2 || P3 || P4) && !exempt query: Akeeba
			 * Backup's OAuth2 helper is never refused. The RewriteRule's negated pattern leaves out the administrator and
			 * api folders, see FILE_INJECTION_EXEMPT_FOLDERS; it is relative to this file's folder, the site's root, so
			 * it needs no RewriteBase.
			 */
			$htaccess .= "##### File injection protection -- BEGIN\n";
			$lastIndex = count(self::FILE_INJECTION_PATTERNS) - 1;

			foreach (self::FILE_INJECTION_PATTERNS as $index => $pattern)
			{
				$htaccess .= 'RewriteCond %{QUERY_STRING} ' . $pattern . ($index === $lastIndex ? ' [NC]' : ' [NC,OR]') . "\n";
			}

			$htaccess .= 'RewriteCond %{QUERY_STRING} !' . self::FILE_INJECTION_EXEMPT_QUERY . " [NC]\n"
				. 'RewriteRule !^' . self::FILE_INJECTION_EXEMPT_FOLDERS . " - [F,NC]\n"
				. "##### File injection protection -- END\n\n";
		}

		if ($config->leftovers == 1)
		{
			/**
			 * Hide the leftover files in the site's root with a 404, as documented and like the NginX Conf Maker does
			 * (gh-486). It returned 403, did not cover web.config.txt, and matched the letter case exactly: on a
			 * case-insensitive file system, e.g. Windows or macOS, /PHP.INI was served. It comes before the Server
			 * Protection rules, so that front-end protection's 403 for htaccess.txt and web.config.txt does not apply
			 * first. Only the site's root: the pattern is anchored, and relative to the folder of this file.
			 */
			$leftoverCode = $serverCaps->customCodes ? '[NC,R=404,L]' : '[NC,F]';
			$htaccess     .= <<<END
## Hide htaccess.txt, web.config.txt, configuration.php-dist, php.ini and .user.ini in the site's root (404 Not Found)
RewriteRule ^(htaccess\.txt|web\.config\.txt|configuration\.php-dist|php\.ini|\.user\.ini)$ - $leftoverCode

END;
		}

		// Advanced server protection
		if ($config->frontendprot == 1 || $config->backendprot == 1)
		{
			$htaccess .= "##### Advanced server protection rules exceptions -- BEGIN\n";

			if (!empty($config->exceptionfiles))
			{
				$phpExt = self::PHP_EXTENSIONS;

				foreach ($config->exceptionfiles as $file)
				{
					/**
					 * Excepted PHP files must receive the full URL path, i.e. path info. Every PHP extension in any
					 * letter case, not just .php (gh-468), like the web.config Maker: the rule below refuses path info
					 * after any of them. A space is \x20: it would end the pattern.
					 */
					$pathInfo = preg_match('#\.(' . $phpExt . ')$#i', $file) ? '(/.*)?' : '';
					$file     = '^' . $this->entryRegex($file) . $pathInfo . '$';
					$htaccess .= <<<END
RewriteRule $file - [L]

END;
				}
			}

			if (!empty($config->exceptiondirs))
			{
				/**
				 * "Except .php files" means every file the server could run as PHP: hosts which map PHP with AddHandler
				 * also run .phtml, .php5 etc., and a case-sensitive handler serves x.PHP as plain text, disclosing its
				 * source. Such files fall through to the rest of the server protection rules. Double extensions are
				 * already refused above.
				 */
				$phpExt = self::PHP_EXTENSIONS;

				foreach ($config->exceptiondirs as $dir)
				{
					$dir      = $this->entryRegex($dir);
					$htaccess .= <<<END
RewriteCond %{REQUEST_FILENAME} !\.($phpExt)$ [NC]
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^$dir/ - [L]

END;
				}
			}

			if (!empty($config->fullaccessdirs))
			{
				foreach ($config->fullaccessdirs as $dir)
				{
					$dir      = $this->entryRegex($dir);

					if ($config->frontendprot == 1 && ($config->fenonexistent ?? 'server') === 'joomla')
					{
						$htaccess .= <<<END
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^$dir/ index.php [L]

END;
					}

					$htaccess .= <<<END
RewriteRule ^$dir/ - [L]

END;
				}
			}

			$htaccess .= "##### Advanced server protection rules exceptions -- END\n\n";
		}

		$htaccess .= "##### Advanced server protection -- BEGIN\n\n";

		if ($config->frontendprot == 1 || $config->backendprot == 1)
		{
			/**
			 * Every PHP extension, not just .php: hosts which map PHP with AddHandler also run e.g. x.phtml/y.png as
			 * x.phtml, and the static file exceptions below match the path info's extension. Excepted files and
			 * "including .php files" folders come before this rule, so they keep their path info.
			 */
			$phpExt   = self::PHP_EXTENSIONS;
			$htaccess .= <<<END
## Blocked PHP files (except Joomla index files) must not receive path information
RewriteRule ^(?!index\.php/|api/index\.php/|administrator/index\.php/).*\.($phpExt)/ - [F,NC]


END;

			if ($config->backendprot == 1)
			{
				/**
				 * Both are empty when there is no folder or no file type to allow: the rules which need them are then
				 * left out.
				 */
				$directories = $this->bugfixBackendProtectionExclusionDirectories(
					$this->normaliseDirectories($config->bepexdirs ?: [])
				);
				$bedirs      = $this->directoriesRegex($directories);
				$betypes     = $this->fileTypesRegex($config->bepextypes);
				/**
				 * The administrator folder in any letter case ([NC]), like the web.config Maker (gh-490). On a
				 * case-insensitive file system (Windows, macOS) /ADMINISTRATOR/… is the same folder: with front-end
				 * protection off, a case-sensitive catch-all let its files through. The allow rules ignore letter case
				 * too, so /Administrator/ still reaches the login page there.
				 */
				$htaccess    .= <<<END
#### Back-end protection
RewriteRule ^administrator/?$ - [L,NC]
RewriteRule ^administrator/index\.(php|html?)$ - [L,NC]
RewriteRule ^administrator/index\.php/ - [L,NC]

END;

				if (($config->benonexistent ?? 'server') === 'joomla' && $bedirs !== '')
				{
					$htaccess .= <<<END
## Let Joomla! handle requests for non-existent files in the directories below
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^administrator/($bedirs)/ administrator/index.php [L,NC]

END;
				}

				if ($bedirs !== '' && $betypes !== '' && $config->bestaticrisks == 1)
				{
					// See the front-end's rule below (gh-488)
					$htaccess .= <<<END
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^administrator/($bedirs)/.*\.($betypes)$ - [E=disable_risky_behaviour:1,NC]

END;
				}

				if ($bedirs !== '' && $betypes !== '')
				{
					$htaccess .= <<<END
RewriteRule ^administrator/($bedirs)/.*\.($betypes)$ - [L,NC]

END;
				}

				$htaccess .= <<<END
RewriteRule ^administrator/ - [F,NC]

END;
			}

			if ($config->frontendprot == 1)
			{
				// Both are empty when there is no folder or no file type: the rules which need them are then left out.
				$fedirs   = $this->directoriesRegex($config->fepexdirs);
				$fetypes  = $this->fileTypesRegex($config->fepextypes);
				$htaccess .= <<<END
#### Front-end protection

END;

				if ($config->backendprot != 1)
				{
					/**
					 * When we have frontend protection enabled BUT backend protection disabled, the "Disallow access to all
					 * other front-end folders" and the "Disallow access to all other front-end files" rules will also block
					 * access to the administrator directory. Therefore we need to explicitly allow it _before_ we apply the
					 * front-end protection
					 */
					$htaccess .= <<< HTACCESS
## Prevent administrator access from being blocked by the front-end protection
RewriteRule ^administrator$ - [L]
RewriteRule ^administrator/ - [L]

HTACCESS;

				}


				if (($config->fenonexistent ?? 'server') === 'joomla' && $fedirs !== '')
				{
					$htaccess .= <<<END
## Let Joomla! handle requests for non-existent files in the directories below (e.g. so redirects can apply to them)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^($fedirs)/ index.php [L,NC]

END;
				}

				$htaccess .= <<<END
## Allow limited access to additional TinyMCE plugins' HTML files
RewriteRule ^media/plg_editors_tinymce/js/plugins/.*\.(htm|html)$ - [L,NC]
## Allow limited access for certain directories with client-accessible content

END;

				if ($fedirs !== '' && $fetypes !== '' && $config->festaticrisks == 1)
				{
					/**
					 * "Disable client-side risky behavior" (gh-488). The SetEnvIf rules further below test the URL as
					 * requested. With MultiViews, /images/x is served as images/x.html, which they do not match, while
					 * this rule tests the file the server negotiated. So the rule which lets the file through also sets
					 * the policy's variable. Every exemption is an earlier rule which stops here ([L]): excepted files,
					 * exception and full access directories, TinyMCE's plugins' HTML files, pre-compressed files. The
					 * TinyMCE plugins' other files are let through by the next rule: they are left out here, in the
					 * letter case of the SetEnvIf exemption. The SetEnvIf rules stay: they still apply where a .htaccess
					 * in a subfolder replaces these rewrite rules. Not Options -MultiViews: where AllowOverride does
					 * not allow Options, every request fails with HTTP 500, and it breaks sites which use negotiation.
					 */
					$htaccess .= <<<END
RewriteCond %{REQUEST_FILENAME} -f
RewriteCond $0 !^media/plg_editors_tinymce/js/plugins/
RewriteRule ^($fedirs)/.*\.($fetypes)$ - [E=disable_risky_behaviour:1,NC]

END;
				}

				if ($fedirs !== '' && $fetypes !== '')
				{
					$htaccess .= <<<END
RewriteRule ^($fedirs)/.*\.($fetypes)$ - [L,NC]

END;
				}

				if ($fedirs !== '')
				{
					$htaccess .= <<<END
RewriteRule ^($fedirs)/ - [F]

END;
				}

				/**
				 * The "all other PHP files" rule covers every PHP extension in any letter case, like the web.config
				 * Maker's. It tests the file Apache maps the request to, so a PHP file followed by path info is refused
				 * as well. The "all other front-end files" rule at the end refuses these files too; this rule must not
				 * depend on it.
				 */
				$phpExt   = self::PHP_EXTENSIONS;
				/**
				 * The common static files of the site's root, e.g. favicon.ico, ads.txt and the search engines' site
				 * verification files (ROOT_STATIC_FILES), like robots.txt (gh-469). In any letter case, and only in the
				 * site's root: the pattern is anchored at both ends, and relative to the folder of this file, so it
				 * also works for a site in a subfolder. Only existing files: a missing one still reaches Joomla!'s SEF
				 * rule, like any other URL which is not a file, e.g. for an extension which generates it.
				 */
				$rootFiles = self::ROOT_STATIC_FILES;
				$htaccess .= <<< END
## Disallow front-end access for certain Joomla! system directories (unless access to their files is allowed above)
RewriteRule ^(cache|includes|language|logs|log|tmp)/ - [F]
RewriteRule ^(configuration\.php|CONTRIBUTING\.md|htaccess\.txt|joomla\.xml|LICENSE\.txt|phpunit\.xml|README\.txt|web\.config\.txt) - [F]

## Explicitly allow access to the site's index.php main entry point file
RewriteRule ^index\.php(/.*){0,1}$ - [L]
## Explicitly allow access to the API application's index.php main entry point file
RewriteRule ^api/index\.php(/.*){0,1}$ - [L]
## Explicitly allow access to the site's robots.txt file
RewriteRule ^robots\.txt$ - [L]
## Explicitly allow access to common static files in the site's root, e.g. favicon.ico, ads.txt and site verification files
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^(?:$rootFiles)$ - [L,NC]

## Disallow access to all other PHP files throughout the site, unless they are explicitly allowed
RewriteCond %{REQUEST_FILENAME} \.($phpExt)$ [NC]
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule .* - [F]

END;
			}
		}

		/**
		 * Advanced server protection rule exceptions also bypass Disable Client-side Risky Behaviour features.
		 *
		 * The policy applies to the files of the allowed types in the media directories. With no file type or no folder
		 * to allow there are no such files, so that side has no policy, and no exemption from it either.
		 */
		$beStaticRisks = $config->bestaticrisks == 1 && $config->backendprot == 1 && ($betypes ?? '') !== ''
			&& ($bedirs ?? '') !== '';
		$feStaticRisks = $config->festaticrisks == 1 && $config->frontendprot == 1 && ($fetypes ?? '') !== ''
			&& ($fedirs ?? '') !== '';

		if ($beStaticRisks || $feStaticRisks)
		{
			/**
			 * SetEnvIf's Request_URI is the whole path of the request, also in the .htaccess of a site in a subfolder,
			 * unlike a RewriteRule's pattern: every pattern starts with the "Base directory of your site". Otherwise,
			 * for a site in a subfolder, neither the policy nor its exemptions ever applied.
			 */
			$sitePathRegex = $sitePath === '' ? '' : '/' . $this->pathRegex($sitePath);

			/**
			 * Letter case. The media folder rules above let the files of the allowed types through in any letter case
			 * ([NC]), so the policy must match in any letter case too (SetEnvIfNoCase). Otherwise e.g. images/x.SVG was
			 * served without the policy.
			 *
			 * An exemption must match exactly the files let through by the rule it stands for, no more, no less. The
			 * site's path and the file type match in any letter case, like the policy. The folder or file of the
			 * exemption only matches in the letter case it is typed in, (?-i:…), like the Server Protection exception
			 * rules, which are case-sensitive. On a case-sensitive file system images/EXDIR/x.svg is not a file of the
			 * exception directory images/exdir, and the media folder rule serves it: exempting it would let an uploaded
			 * SVG file in such a folder run scripts. Joomla itself only uses lower case for TinyMCE's plugins folder.
			 */
			/**
			 * SetEnvIf* is mod_setenvif's and Header is mod_headers': each one must be inside an <IfModule> for its
			 * module, otherwise Apache answers every request with HTTP 500 when the module is not loaded. Without
			 * either module the policy cannot be applied at all.
			 */
			$htaccess .= "<IfModule mod_setenvif.c>\n";

			if ($beStaticRisks)
			{
				$htaccess .= <<< END
#### Disable client-side risky behavior in backend static content
SetEnvIfNoCase Request_URI "^$sitePathRegex/administrator/($bedirs)/.*\.($betypes)$" disable_risky_behaviour

END;
			}

			if ($feStaticRisks)
			{
				$htaccess .= <<< END
#### Disable client-side risky behavior in frontend static content
SetEnvIfNoCase Request_URI "^$sitePathRegex/($fedirs)/.*\.($fetypes)$" disable_risky_behaviour

END;
			}

			$htaccess .= "##### Always allow TinyMCE plugin files to load scripts (they need to)\n";
			if ($beStaticRisks)
			{
				$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:media/plg_editors_tinymce/js/plugins)/.*\.($betypes)$\" !disable_risky_behaviour\n";
			}
			if ($feStaticRisks)
			{
				$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:media/plg_editors_tinymce/js/plugins)/.*\.($fetypes)$\" !disable_risky_behaviour\n";
			}

			$htaccess .= "\n##### Advanced server protection rules exceptions also bypass the “disable client-side risky behavior” features -- BEGIN\n";

			/**
			 * The exceptions are normalised (gh-454): a double quote, which would end the quoted pattern, a backslash and
			 * a control character drop the entry whole, as in the exception rules above.
			 *
			 * An excepted file with a PHP extension a server may run, in any letter case, is let through with path info
			 * (gh-468), e.g. images/x.php/y.png, which the policy matches by its .png: its exemption covers it with path
			 * info too (gh-470), like the NginX Conf Maker's. Not a .phps file: it is the PHP source, which runs no
			 * scripts, and keeps the policy (fail closed).
			 */
			$phpExecExt = self::PHP_EXECUTABLE_EXTENSIONS;

			foreach ($config->exceptionfiles as $file)
			{
				$pathInfo = preg_match('#\.(' . $phpExecExt . ')$#i', $file) ? '(?:/.*)?' : '';
				$file     = $this->entryRegex($file);
				$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:$file)$pathInfo$\" !disable_risky_behaviour\n";
			}

			foreach ($config->exceptiondirs as $dir)
			{
				$dir      = $this->entryRegex($dir);

				if ($beStaticRisks)
				{
					$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:$dir)/.*\.($betypes)$\" !disable_risky_behaviour\n";
				}
				if ($feStaticRisks)
				{
					$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:$dir)/.*\.($fetypes)$\" !disable_risky_behaviour\n";
				}
			}

			foreach ($config->fullaccessdirs as $dir)
			{
				$dir      = $this->entryRegex($dir);

				if ($beStaticRisks)
				{
					$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:$dir)/.*\.($betypes)$\" !disable_risky_behaviour\n";
				}
				if ($feStaticRisks)
				{
					$htaccess .= "SetEnvIfNoCase Request_URI \"^$sitePathRegex/(?-i:$dir)/.*\.($fetypes)$\" !disable_risky_behaviour\n";
				}
			}


			$htaccess .= "##### Advanced server protection rules exceptions also bypass the “disable client-side risky behavior” features -- END\n";
			$htaccess .= "</IfModule>\n\n";

			$htaccess .= <<< HTACCESS

# Apply the "Disable client-side risky behavior" features
<IfModule mod_headers.c>
	Header always set Content-Security-Policy "default-src 'self'; script-src 'none';" env=disable_risky_behaviour
</IfModule>

HTACCESS;
		}

		if ($config->frontendprot == 1)
		{
			/**
			 * Refuse the URL of any existing folder which no rule above let through, like the NginX Conf Maker and the
			 * web.config Maker do. Its condition used to be `RewriteCond %{REQUEST_URI} !^/`, which never holds, so the
			 * rule never applied (gh-454). Apache checks a folder's default document (DirectoryIndex) against this file
			 * again, which refused an index.html or index.php no rule allows; but a folder without an index file was
			 * listed, if the server allows directory listings and "Disable directory listings" is off.
			 *
			 * The pattern is relative to the folder of this file, so this works for a site in a subfolder too. Exempt
			 * are the site's root (the empty path), api/, and these, each with or without the trailing slash, which
			 * Apache adds with a redirect:
			 * - the exception directories and full access directories, and their subfolders. A full access directory
			 *   is let through above anyway, but only with the trailing slash;
			 * - the folder of each excepted file named like a default document (DirectoryIndex) Apache uses, index.php,
			 *   index.html or index.htm: Apache answers the folder's URL with that file. Only those (gh-462): an
			 *   excepted x/readme.txt let /x/ be listed, as x/ has no default document. The site needs index.php as a
			 *   default document, and index.html is Apache's own; with "Force index.php parsing before index.html" on,
			 *   this file sets these two and index.htm (gh-466). See exceptionFileIndexFolders(). A folder without any of
			 *   its excepted default documents is refused anyway, by the rule before, so that it is never listed.
			 * Each folder is exempt once, however many entries name it (gh-462). The back-end is handled above.
			 */
			$folderExemptions = ['api/?'];
			$exceptionDirs    = $this->directoriesRegex(array_values(array_unique(
				array_merge($config->exceptiondirs, $config->fullaccessdirs)
			)));
			$indexFolders     = $this->exceptionFileIndexFolders($config->exceptionfiles, $config->fileorder == 1);
			$exceptionFolders = $this->directoriesRegex(array_keys($indexFolders));

			if ($exceptionDirs !== '')
			{
				$folderExemptions[] = "($exceptionDirs)(/.*)?";
			}

			if ($exceptionFolders !== '')
			{
				$folderExemptions[] = "($exceptionFolders)/?";
			}

			$folderExemptions = implode('|', $folderExemptions);

			foreach ($indexFolders as $folder => $names)
			{
				$folderRegex = $this->entryRegex($folder);
				$conditions  = '';

				foreach ($names as $name)
				{
					$conditions .= 'RewriteCond %{REQUEST_FILENAME}/' . $name . " !-f\n";
				}

				$htaccess .= <<<END
# Disallow access to the folder of an excepted default document which does not exist
RewriteCond %{REQUEST_FILENAME} -d
{$conditions}RewriteRule ^$folderRegex/?$ - [F]

END;
			}

			$htaccess .= <<<END
# Disallow access to all other front-end folders
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule !^($folderExemptions)?$ - [F]

# Disallow access to all other front-end files
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule !^index\.php$ - [F]

END;

		}

		if ($config->clickjacking == 1)
		{
			/**
			 * The unset must use the same table as the set (gh-493). "Header always set" puts the header in the table
			 * Apache also sends with error responses; a plain "Header unset" only removes it from the other one, so
			 * static files still got X-Frame-Options. The <FilesMatch> is merged after the rest of this file, so its
			 * unset runs after the set.
			 */
			$action      = $serverCaps->always_set ? 'always set' : 'set';
			$unsetAction = $serverCaps->always_set ? 'always unset' : 'unset';
			$htaccess    .= <<< ENDCONF
## Protect against clickjacking
<IfModule mod_headers.c>

	Header $action X-Frame-Options SAMEORIGIN

	# The `X-Frame-Options` response header should be sent only for
	# HTML documents and not for the other resources.

	<FilesMatch "\.(appcache|atom|bbaw|bmp|crx|css|cur|eot|f4[abpv]|flv|geojson|gif|htc|ico|jpe?g|js|json(ld)?|m4[av]|manifest|map|mp4|oex|og[agv]|opus|otf|pdf|png|rdf|rss|safariextz|svgz?|swf|topojson|tt[cf]|txt|vcard|vcf|vtt|webapp|web[mp]|woff2?|xloc|xml|xpi)$">
		Header $unsetAction X-Frame-Options
	</FilesMatch>

</IfModule>

ENDCONF;
		}

		if ($config->reducemimetyperisks == 1)
		{
			$htaccess .= <<< HTACCESS
## Reduce MIME type security risks
<IfModule mod_headers.c>
	Header set X-Content-Type-Options "nosniff"
</IfModule>

HTACCESS;
		}

		if ($config->reflectedxss == 1)
		{
			$htaccess .= <<< HTACCESS
## Reflected XSS prevention
<IfModule mod_headers.c>
Header set X-XSS-Protection "1; mode=block"
</IfModule>

# mod_headers cannot match based on the content-type, however,
# the X-XSS-Protection response header should be sent only for
# HTML documents and not for the other resources.

<IfModule mod_headers.c>
	<FilesMatch "\.(appcache|atom|bbaw|bmp|crx|css|cur|eot|f4[abpv]|flv|geojson|gif|htc|ico|jpe?g|js|json(ld)?|m4[av]|manifest|map|mp4|oex|og[agv]|opus|otf|pdf|png|rdf|rss|safariextz|svgz?|swf|topojson|tt[cf]|txt|vcard|vcf|vtt|webapp|web[mp]|webmanifest|woff2?|xloc|xml|xpi)$">
		Header unset X-XSS-Protection
	</FilesMatch>
</IfModule>

HTACCESS;
		}

		if ($config->svgneutralise)
		{
			/**
			 * Every SVG file, .svg and .svgz, in any letter case, as mod_mime serves them all as SVG (gh-489). Not the
			 * files which get the stricter "Disable client-side risky behavior" policy: <FilesMatch> is merged last, so
			 * this header replaced that policy with this weaker one.
			 */
			$htaccess .= <<< HTACCESS
## Neutralize scripts in SVG files
<FilesMatch "\.(?i:svgz?)$">
  <IfModule mod_headers.c>
    Header always set Content-Security-Policy "script-src 'none'" env=!disable_risky_behaviour
  </IfModule>
</FilesMatch>

HTACCESS;
		}

		if ($config->noserversignature == 1)
		{
			/**
			 * Both header tables (gh-487). "Header always unset" only edits the one PHP-FPM and CGI responses put their
			 * headers in; mod_php puts X-Powered-By in the other one, which only plain "Header unset" edits.
			 */
			$htaccess .= <<< HTACCESS
## Remove Apache and PHP version signature
<IfModule mod_headers.c>
	Header unset X-Powered-By
	Header unset X-Content-Powered-By
	Header always unset X-Powered-By
	Header always unset X-Content-Powered-By
</IfModule>

ServerSignature Off

HTACCESS;
		}

		if ($config->notransform == 1)
		{
			/**
			 * One Cache-Control header with no-transform (gh-476). Apache keeps a PHP-FPM or CGI response's headers in the
			 * table of "Header always", and mod_expires's in the other one, which plain Header changes. So
			 * `Header merge` alone added a second Cache-Control header to PHP pages, while `Header always merge` would
			 * add one to static files with an expiration time. The first line adds no-transform to a Cache-Control
			 * header in the "always" table, if there is one. The second adds it to the other table, unless the
			 * response's Cache-Control header already has it: resp() reads both tables. "Header always" runs first. The
			 * administrator's no-cache headers (exptime) are in the second table. Apache before 2.4 has no expr=.
			 */
			$noTransform = $serverCaps->headerexpr
				? <<< HTACCESS
	Header always edit Cache-Control "^(?!.*no-transform)(.*)$" "$1, no-transform"
	Header merge Cache-Control "no-transform" "expr=resp('Cache-Control') !~ /no-transform/i"
HTACCESS
				: <<< HTACCESS
	Header merge Cache-Control "no-transform"
HTACCESS;

			$htaccess .= <<< HTACCESS
## Prevent content transformation
<IfModule mod_headers.c>
$noTransform
</IfModule>

HTACCESS;
		}

		$htaccess .= "##### Advanced server protection -- END\n\n";

		if (in_array((int) $config->hstsheader, [1, 2], true))
		{
			$action    = $serverCaps->always_set ? 'always set' : 'set';
			$hstsValue = ($config->hstsheader == 2) ? 'max-age=31536000; includeSubDomains; preload' : 'max-age=31536000';

			if ($serverCaps->setenvifexpr)
			{
				$htaccess .= <<<END
## HSTS Header - See http://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security
<IfModule mod_headers.c>
<IfModule mod_setenvif.c>
SetEnvIfExpr "%{HTTPS}='on'" USE_HSTS_HEADER
SetEnvIfNoCase X-Forwarded-Proto "https" USE_HSTS_HEADER
Header $action Strict-Transport-Security "$hstsValue" env=USE_HSTS_HEADER
</IfModule>
</IfModule>

END;
			}
			else
			{
				/**
				 * SetEnvIfExpr only exists since Apache 2.4. Before that, inside <IfModule mod_setenvif.c>, it made every
				 * request fail with HTTP 500 (Invalid command 'SetEnvIfExpr'). The header is sent with every response
				 * instead: browsers ignore it on a response received over HTTP (RFC 6797, section 8.1), so it only
				 * applies to HTTPS, also behind a TLS-terminating proxy.
				 */
				$htaccess .= <<<END
## HSTS Header - See http://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security
## Before Apache 2.4 this file cannot test for HTTPS: the header is sent with every response.
## Browsers ignore it on a response received over HTTP (RFC 6797, section 8.1).
<IfModule mod_headers.c>
Header $action Strict-Transport-Security "$hstsValue"
</IfModule>

END;
			}
		}

		if ($config->cors == 1)
		{
			$action   = $serverCaps->always_set ? 'always set' : 'set';
			$htaccess .= <<<END
## Explicitly enable Cross-Origin Resource Sharing (CORS) -- See http://enable-cors.org/
<IfModule mod_headers.c>
	Header $action Access-Control-Allow-Origin "*"
	Header $action Timing-Allow-Origin "*"
</IfModule>

END;
		}
		elseif ($config->cors == -1)
		{
			$action   = $serverCaps->always_set ? 'always set' : 'set';
			$htaccess .= <<<END
## Explicitly disable Cross-Origin Resource Sharing (CORS) -- See http://enable-cors.org/
<IfModule mod_headers.c>
	Header $action Cross-Origin-Resource-Policy "same-origin"
</IfModule>

END;

		}

		if ($config->referrerpolicy !== '-1')
		{
			$action   = $serverCaps->always_set ? 'always set' : 'set';
			$htaccess .= <<<END
## Referrer-policy
<IfModule mod_headers.c>
	Header $action Referrer-Policy "{$config->referrerpolicy}"
</IfModule>

END;
		}

		if ($config->utf8charset == 1)
		{
			$htaccess .= <<<END
## Set the UTF-8 character set as the default
#  Serve all resources labeled as `text/html` or `text/plain`
#  with the media type `charset` parameter set to `UTF-8`.

AddDefaultCharset utf-8

# Serve the following file types with the media type `charset`
# parameter set to `UTF-8`.
#
# https://httpd.apache.org/docs/current/mod/mod_mime.html#addcharset

<IfModule mod_mime.c>
	AddCharset utf-8 .atom \
					 .bbaw \
					 .css \
					 .geojson \
					 .js \
					 .json \
					 .jsonld \
					 .rdf \
					 .rss \
					 .topojson \
					 .vtt \
					 .webapp \
					 .xloc \
					 .xml
</IfModule>

END;
		}

		/**
		 * %{REQUEST_URI} is the whole path of the request, also in the .htaccess of a site in a subfolder, so the
		 * conditions start with the "Base directory of your site". Otherwise, for a site in /sub, the API rule never
		 * fired, and the API application's SEF URLs, e.g. /sub/api/v1/content/articles, ran the front-end index.php
		 * (gh-463). Joomla's own htaccess.txt has the same limitation. %{REQUEST_URI} is URL-decoded: the path is
		 * escaped as a regular expression, a space written as \x20, like the Request_URI patterns of the "Disable
		 * client-side risky behavior" rules. For a site in the domain's root they are ^/api/ and ^/index\.php, as
		 * before. The substitutions are relative, so RewriteBase, or the folder of this file without it, comes first.
		 */
		$sefSitePath = $sitePath === '' ? '' : '/' . $this->pathRegex($sitePath);

		$htaccess .= <<< APACHE
##### Joomla! core SEF Section -- BEGIN
# -- SEF URLs for the API application
RewriteCond %{REQUEST_URI} ^$sefSitePath/api/
RewriteCond %{REQUEST_URI} !^$sefSitePath/api/index\.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* api/index.php [L]

# -- SEF URLs for the public frontend application

APACHE;

		$htaccess .= <<<END
RewriteCond %{REQUEST_URI} !^$sefSitePath/index\.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* index.php [L]
##### Joomla! core SEF Section -- END


END;

		$htaccess .= "\n\n" . $config->custfoot . "\n";

		return $htaccess;
	}

	/**
	 * A blocked user agent as a RewriteCond pattern, or null if it is skipped, see makeConfigFile().
	 *
	 * White space is \s, the space included, see userAgentRegex(). A leading ", ', !, -, =, < or > is escaped with a
	 * backslash, which PCRE reads as the character itself: mod_rewrite would read it as a quoted argument, a negated
	 * pattern, a file test or a string comparison. A pattern which is not a valid regular expression is skipped. A final
	 * escaped backslash is written as \x5C: mod_rewrite reads a backslash before a space as an escaped space, so the
	 * pattern would swallow the space and the flags after it.
	 *
	 * @param   string  $agent  The user agent, see normaliseUserAgents()
	 *
	 * @return  string|null
	 * @since   7.10.0
	 */
	protected function blockedUserAgentRegex(string $agent): ?string
	{
		$regex = $this->userAgentRegex($agent, true);

		if ($regex === null)
		{
			return null;
		}

		if (strpos('"\'!-=<>', $regex[0]) !== false)
		{
			$regex = '\\' . $regex;
		}

		if (!$this->isValidRegex($regex))
		{
			return null;
		}

		if (substr($regex, -1) === '\\')
		{
			$regex = substr($regex, 0, -2) . '\\x5C';
		}

		return $regex;
	}

	/**
	 * The folders of the "Allow direct access to these files" entries (exceptionfiles) named like a default document
	 * Apache uses for a folder URL, index.php, index.html or index.htm, with those names.
	 *
	 * Apache answers a folder's URL with its default document (DirectoryIndex), so such a folder's URL must not be
	 * refused by front-end protection: it serves the excepted file. Any other excepted file, e.g. x/readme.txt, is never
	 * a folder's default document, and its folder's URL is refused like any other's (gh-462). The names are matched in
	 * the letter case typed, like the excepted file rules and DirectoryIndex. Each folder is listed once, with each of
	 * its names once (gh-462): the default list had two files in administrator/components/com_joomlaupdate.
	 *
	 * index.htm counts only when this file sets the default documents, with "Force index.php parsing before index.html"
	 * on (gh-466). Otherwise the server's own list applies, and index.htm is not on every server's: Apache's own default
	 * is index.html alone, and the site needs index.php. On a server without it, the folder of an excepted index.htm
	 * would be listed, like x/ of an excepted x/readme.txt was (gh-462); it is refused instead.
	 *
	 * @param   string[]  $files    The excepted files, normalised, see normaliseExceptions()
	 * @param   bool      $ownList  Does this file set the default documents (fileorder=1)?
	 *
	 * @return  array<string, string[]>  Folder => default document names, e.g. ['docs' => ['index.html']]
	 * @since   7.10.0
	 */
	private function exceptionFileIndexFolders(array $files, bool $ownList): array
	{
		$names   = $ownList ? ['index.php', 'index.html', 'index.htm'] : ['index.php', 'index.html'];
		$folders = [];

		foreach ($files as $file)
		{
			$slash = strrpos($file, '/');

			if ($slash === false)
			{
				continue;
			}

			$folder = substr($file, 0, $slash);
			$name   = substr($file, $slash + 1);

			if (!in_array($name, $names, true))
			{
				continue;
			}

			$folders[$folder] = $folders[$folder] ?? [];

			if (!in_array($name, $folders[$folder], true))
			{
				$folders[$folder][] = $name;
			}
		}

		return $folders;
	}

	/**
	 * Escapes a literal part of a RewriteRule substitution, e.g. the site's path or a Force HTTPS entry (gh-455, gh-462).
	 *
	 * The substitution is an unquoted argument: a space would end it, and Apache would answer every request with HTTP
	 * 500. A backslash escapes the next character, so $N, %N, ${…} and %{…} are not back-references, RewriteMap lookups
	 * or server variables, and the space does not end the argument. A redirect's target is URL-encoded by mod_rewrite
	 * itself (no [NE] flag): the space becomes %20, the % %25 and # %23. A ? cannot be escaped: it always starts the
	 * query string.
	 *
	 * @param   string  $literal  The text, e.g. my site
	 *
	 * @return  string  E.g. my\ site
	 * @since   7.10.0
	 */
	private function substitutionLiteral(string $literal): string
	{
		return str_replace(['\\', '$', '%', ' '], ['\\\\', '\\$', '\\%', '\\ '], $literal);
	}
}