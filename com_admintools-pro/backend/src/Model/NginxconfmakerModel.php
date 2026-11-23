<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Akeeba\Component\AdminTools\Administrator\Helper\ServerTechnology;
use DateTimeZone;
use Joomla\CMS\Factory;

#[\AllowDynamicProperties]
class NginxconfmakerModel extends ServerconfigmakerModel
{

	/**
	 * The current configuration of this feature
	 *
	 * @var  object
	 */
	protected $configKey = 'nginxconfig';

	/**
	 * The base name of the configuration file being saved by this feature, e.g. ".htaccess". The file is always saved
	 * in the site's root. Any old files under that name are renamed with a .admintools suffix.
	 *
	 * @var string
	 */
	protected $configFileName = 'nginx.conf';

	/** @inheritdoc  */
	protected $usePublicRoot = false;

	/** @inheritdoc  */
	public function isSupported(): int
	{
		return ServerTechnology::isNginxSupported();
	}

	/**
	 * @inheritdoc
	 *
	 * Also `restrictipRealIp`: "Restrict access by IP address" is on, but "Allow IP forwarding" with trusted proxies
	 * makes NginX check the visitor's address instead of the proxy's, so the file leaves the restriction out (gh-483).
	 * See isRestrictIpOverriddenByRealIp().
	 *
	 * @return  array{restrictipEmpty: bool, skippedUserAgents: string[], restrictipRealIp: bool}
	 * @since   7.10.0
	 */
	public function getConfigurationWarnings(): array
	{
		$warnings = parent::getConfigurationWarnings();

		$warnings['restrictipRealIp'] = $this->isRestrictIpOverriddenByRealIp($this->loadConfiguration());

		return $warnings;
	}

	/**
	 * Does "Allow IP forwarding" make "Restrict access by IP address" refuse every visitor? Then it is left out (gh-483).
	 *
	 * With trusted proxies (set_real_ip_from), NginX's realip module replaces the client's address with the visitor's,
	 * taken from X-Forwarded-For, before the access phase, where allow and deny are checked. A list of the CDN's or
	 * proxy's addresses would then refuse every visitor who comes through it. Without trusted proxies the realip module
	 * changes no address, and the restriction works.
	 *
	 * An empty custom list is not: it turns the restriction off anyway, and has its own error, see restrictIpList().
	 * This does not download a CDN provider's list, so that the maker's page can call it.
	 *
	 * @param   object  $config  The configuration, see loadConfiguration()
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	protected function isRestrictIpOverriddenByRealIp(object $config): bool
	{
		$type = $config->restrictip ?? 'none';

		return $type !== 'none'
			&& ($config->cfipfwd ?? 0) == 1
			&& !empty($this->trustedProxyIps($config))
			&& ($type !== 'custom' || $this->restrictIpList($config) !== []);
	}

	/**
	 * The trusted reverse proxy addresses "Allow IP forwarding" writes as set_real_ip_from.
	 *
	 * Saving strips the leading zeros of the IPv4 octets (IP_LIST_KEYS), but a list saved before 7.10.0 may still hold
	 * them (gh-477). NginX reads them as decimal, as we do, so this changes no trusted address. Blank entries, and 0, are
	 * left out, as before.
	 *
	 * @param   object  $config  The configuration, see loadConfiguration()
	 *
	 * @return  string[]
	 * @since   7.10.0
	 */
	protected function trustedProxyIps(object $config): array
	{
		if (empty($config->proxy_ips))
		{
			return [];
		}

		return array_values(array_filter(
			array_map(function ($x) {
				return is_scalar($x) ? IpExpressionValidator::normalise((string) $x) : '';
			}, (array) $config->proxy_ips),
			function ($x) {
				return !empty($x);
			}
		));
	}

	/**
	 * Compile and return the contents of the NginX configuration file
	 *
	 * @return  string
	 */
	public function makeConfigFile()
	{
		// Make sure we are called by an expected caller
		ServerTechnology::checkCaller($this->allowedCallersForMake);

		$app = Factory::getApplication();

		$timezone = 'UTC';

		// Fetch the timezone from the user only if we're not in CLI
		if (!$app->isClient('cli'))
		{
			$timezone = $app->getIdentity()->getParam('timezone', $app->get('offset', 'UTC'));
		}

		$date = clone Factory::getDate();
		$tz   = new DateTimeZone($timezone);
		$date->setTimezone($tz);
		$d       = $date->format('Y-m-d H:i:s T', true);
		$version = ADMINTOOLS_VERSION;

		// The exceptions go through normaliseDirectories(), so that every rule below sees the same entries (gh-454)
		$config = $this->normaliseExceptions($this->loadConfiguration());

		/**
		 * This file always protects itself with an exact location for each of these names, see below. An excepted file
		 * with the same name would be a second, duplicate exact location, which makes NginX refuse the whole
		 * configuration (gh-492); serving the file would also disclose the server's configuration. The self-protection
		 * wins: such an exception is ignored.
		 */
		$config->exceptionfiles = array_values(array_diff(
			$config->exceptionfiles, ['nginx.conf', 'nginx.conf.admintools']
		));

		// Load the fastcgi_pass setting
		$fastcgi_pass_block = $config->fastcgi_pass_block;

		if (empty($fastcgi_pass_block))
		{
			$fastcgi_pass_block = 'fastcgi_pass   127.0.0.1:9000;';
		}

		$fastcgi_pass_block = trim($fastcgi_pass_block);

		// Get the directory to the site's root
		$rewritebase      = $config->rewritebase;
		$rewritebaseSlash = '/' . trim($rewritebase, '/ ');
		$rewritebaseSlash = ($rewritebaseSlash == '/') ? '' : $rewritebaseSlash;
		// The site's path in regular expression locations, e.g. /f\.o: a dot in it must only match a dot.
		$rewritebaseRegex = $this->pathRegex($rewritebaseSlash);
		$rewritebase      = '/' . trim($rewritebase, '/ ');

		/**
		 * The site's path in the directives which have it literally (gh-454).
		 *
		 * - Exact and prefix locations ($siteQuote): quoted if the path has white space, `;`, `{` or `}`, see
		 *   quoteArgument(). NginX expands no variables in a location, so a $ is a $ there.
		 * - try_files and rewrite ($siteUri, $siteUriQuote): the URI NginX hands the request to. NginX expands variables in
		 *   them and has no escape for a $: /a$b made NginX refuse the file (unknown "b" variable), and /x$uri silently
		 *   got the wrong path. A $ is written as ${akDollar} instead, which an `if` below takes from the request's URI.
		 *   Every request these directives handle is under the site's path, so its URI has that $. The URI is not decoded
		 *   again, so %24 would not do.
		 * - return ($siteUrl): the URL of a redirect, URL-encoded, e.g. %20 for a space and %24 for a $.
		 *
		 * A double quote, a backslash or a control character cannot go into a quoted argument as it is, and a semicolon
		 * ends the regular expression locations, which are not quoted. For such a path the file refuses every request
		 * and says why, rather than leave the site unprotected or have NginX refuse the whole configuration.
		 */
		$siteQuote    = preg_match('/[\s;{}]/', $rewritebaseSlash) ? '"' : '';
		$siteUri      = str_replace('$', '${akDollar}', $rewritebaseSlash);
		$siteUriQuote = preg_match('/[\s;{}]/', $siteUri) ? '"' : '';
		$siteUrl      = implode('/', array_map('rawurlencode', explode('/', $rewritebaseSlash)));

		$nginxConf = <<<END
### ===========================================================================
### Security Enhanced & Highly Optimized NginX Configuration File for Joomla!
### automatically generated by Admin Tools $version on $d
### ===========================================================================
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
### !!  Remember to include this file in your site's configuration file.     !!
### !!  Also remember to reload or restart NginX after making any change to  !!
### !!  this file.                                                           !!
### !!                                                                       !!
### !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
###

END;

		if (preg_match('/["\\\\;[:cntrl:]]/', $rewritebaseSlash))
		{
			return $nginxConf . <<<END
######################################################################
## NOT GENERATED: the site's path (Base directory of your site) has
## a double quote, a backslash, a semicolon or a control character,
## which NginX cannot read in this file. Every request is refused.
## Rename your site's folder, or remove the include of this file from
## your NginX configuration, then make this file again.
######################################################################
return 403;

END;
		}

		$nginxConf .= <<<END

### Prevent access to this file
location = {$siteQuote}$rewritebaseSlash/nginx.conf{$siteQuote} {
	log_not_found off;
	access_log off;
	return 404;
	break;
}

location = {$siteQuote}$rewritebaseSlash/nginx.conf.admintools{$siteQuote} {
	log_not_found off;
	access_log off;
	return 404;
	break;
}

# Unified HTTP/HTTPS protocol detection into the \$akActualProto variable 
set \$akActualProto "http";

if (\$http_x_forwarded_proto ~* "^https$") {
    set \$akActualProto "https";
}

if (\$scheme = "https") {
    set \$akActualProto "https";
}

END;

		// See $siteUri above
		if (strpos($rewritebaseSlash, '$') !== false)
		{
			$nginxConf .= <<<END
# The site's path has a \$, which NginX would read as a variable: try_files
# and rewrite use \${akDollar} instead, a \$ taken from the request's URI
if (\$uri ~ "(?<akDollar>\\\$)") {
}

END;
		}

		// Must come before any server-level return; see the method's docblock.
		$nginxConf .= $this->makeUrlDependentHeaders($config, $rewritebaseSlash);

		/**
		 * Let's start with IP restriction. An empty list writes nothing (gh-466), see restrictIpList(): it is a
		 * misconfiguration, which the maker's page warns about. It wrote deny all with no allow, which refused everyone.
		 *
		 * allow and deny go at the server level, where every location inherits them (gh-483). They were only in a
		 * `location /` of their own, and NginX applies them only to the requests whose final location is that one: any
		 * matching regular expression location wins over it, e.g. Server Protection's "everything else", /index.php,
		 * /api/ or the excepted files, so most requests were not restricted at all. No location of this file may have
		 * an allow or deny of its own, which would replace the server-level ones.
		 *
		 * With "Allow IP forwarding" and trusted proxies, the realip module replaces the client's address with the
		 * visitor's before the access check: allowing the CDN's addresses would refuse every visitor who comes through
		 * it. The restriction is left out then, and the maker's page warns about it; see isRestrictIpOverriddenByRealIp().
		 * restrictIpList() is not called then, so a CDN provider's list is not downloaded for nothing.
		 */
		if ($this->isRestrictIpOverriddenByRealIp($config))
		{
			$nginxConf .= <<< END
######################################################################
## Restrict access by IP address -- NOT APPLIED
## "Allow IP forwarding" trusts reverse proxies (set_real_ip_from), so
## NginX checks the visitor's IP address, not the proxy's. Allowing
## only the selected IP addresses would refuse every visitor who comes
## through the proxy. Restrict access to your server in its firewall,
## or turn off "Allow IP forwarding", instead.
######################################################################

END;
		}
		else
		{
			$restrictIPList = $this->restrictIpList($config);

			if (!empty($restrictIPList))
			{
				$nginxConf .= "\n##### Restricted access by IP address -- BEGIN\n";

				foreach ($restrictIPList as $ip)
				{
					$nginxConf .= 'allow ' . $ip . ";\n";
				}

				$nginxConf .= "deny all;\n##### Restricted access by IP address -- END\n\n";
			}
		}

		// Protect against common file injection attacks?
		if ($config->fileinj == 1)
		{
			$nginxConf .= <<< CONFDATA
######################################################################
## Protect against common file injection attacks
######################################################################
set \$file_injection 0;

CONFDATA;

			// See FILE_INJECTION_PATTERNS (gh-485): ~* matches case-insensitively. None of them has a " or a {.
			foreach (self::FILE_INJECTION_PATTERNS as $pattern)
			{
				$nginxConf .= 'if ($query_string ~* "' . $pattern . "\") {\n\tset \$file_injection 1;\n}\n";
			}

			/**
			 * The exemptions clear the flag again, as NginX's `if` cannot AND conditions: Akeeba Backup's OAuth2 helper
			 * (FILE_INJECTION_EXEMPT_QUERY), and the administrator and api folders (FILE_INJECTION_EXEMPT_FOLDERS). $uri
			 * is the whole, normalised path, so the site's path comes before them, written like the other regular
			 * expressions which have it; /administrator/../index.php is /index.php, which is not exempt.
			 */
			$nginxConf .= "# Akeeba Backup's OAuth2 helper, and the administrator and api folders, are exempt\n"
				. 'if ($query_string ~* "' . self::FILE_INJECTION_EXEMPT_QUERY . "\") {\n\tset \$file_injection 0;\n}\n"
				. 'if ($uri ~* "^' . $rewritebaseRegex . '/' . self::FILE_INJECTION_EXEMPT_FOLDERS
				. "\") {\n\tset \$file_injection 0;\n}\n";

			$nginxConf .= <<< CONFDATA
if (\$file_injection = 1) {
	return 403;
	break;
}

CONFDATA;
		}

		if ($config->leftovers == 1)
		{
			/**
			 * A leftover file which is also in "Allow direct access to these files" is not blocked: the exception wins,
			 * like in the .htaccess and web.config Makers, whose exception rules come first and stop processing. Its own
			 * exact location comes below; a second one for the same file makes NginX refuse the whole configuration
			 * (gh-492). The names are compared exactly, like the exact locations.
			 */
			$leftoverFiles = [
				'configuration.php-dist', 'htaccess.txt', 'web.config', 'configuration.php', 'CONTRIBUTING.md',
				'joomla.xml', 'LICENSE.txt', 'phpunit.xml', 'README.txt', 'web.config.txt',
			];
			$leftoverBlocks = [];

			foreach (array_diff($leftoverFiles, $config->exceptionfiles) as $leftoverFile)
			{
				$leftoverBlocks[] = <<<END
location = {$siteQuote}$rewritebaseSlash/$leftoverFile{$siteQuote} {
	log_not_found off;
	access_log off;
	return 404;
	break;
}

END;
			}

			$nginxConf .= <<<END
######################################################################
## Block access to configuration.php-dist and htaccess.txt
######################################################################

END;
			$nginxConf .= implode("\n", $leftoverBlocks);
		}

		if ($config->clickjacking == 1)
		{
			$nginxConf .= <<< ENDCONF
## Protect against clickjacking
add_header X-Frame-Options SAMEORIGIN;

ENDCONF;
		}

		/**
		 * Each agent goes into the quoted string of an `if`. NginX's parser turns \" into " and \\ into \, and keeps a
		 * backslash before any other character, so every backslash is doubled and every " escaped: PCRE gets the agent as
		 * typed, a regular expression fragment such as dot\.bot or maxthon$. A $ needs no escaping: NginX expands no
		 * variables in the regular expression of an `if`. Blank agents are skipped, as `~ ""` matches every user agent,
		 * and so are agents which are not a valid regular expression: NginX would refuse the whole configuration. White
		 * space other than the space is written as \s, and agents with any other control character are skipped, see
		 * userAgentRegex().
		 *
		 * The agents are matched regardless of letter case (~*), like the .htaccess Maker's [NC] and IIS' default
		 * ignoreCase="true": the default list is in lower case, and ~ did not block e.g. Wget/1.21 with wget.
		 */
		$hoggerAgents = [];

		foreach ($this->normaliseUserAgents($config->hoggeragents ?? []) as $agent)
		{
			$regex = $this->blockedUserAgentRegex($agent);

			if ($regex !== null)
			{
				$hoggerAgents[] = str_replace(['\\', '"'], ['\\\\', '\\"'], $regex);
			}
		}

		if (!empty($hoggerAgents) && ($config->nohoggers == 1))
		{
			$nginxConf .= <<< ENDCONF
######################################################################
## Block access from specific user agents
######################################################################
set \$bad_ua 0;

ENDCONF;

			foreach ($hoggerAgents as $agent)
			{
				$nginxConf .= <<< ENDCONF
if (\$http_user_agent ~* "$agent") {
	set \$bad_ua 1;
}

ENDCONF;
			}

			$nginxConf .= <<< ENDCONF
if (\$bad_ua = 1) {
	return 403;
}

ENDCONF;
		}

		if (($config->fileorder == 1) || ($config->nodirlists == 1))
		{
			$nginxConf .= <<<ENDCONF
######################################################################
## Directory indices and no automatic directory listings
## Forces index.php to be read before the index.htm(l) files
## Also disables showing files in a directory automatically
######################################################################
index index.php index.html index.htm;

ENDCONF;
		}

		// -1 (Server default) and 0 (Default) both leave it to the server. 1 (No, never) is `disable_symlinks on`.
		if (in_array((int) $config->symlinks, [1, 2], true))
		{
			$nginxConf .= <<<ENDCONF
######################################################################
## Disable following symlinks
######################################################################

ENDCONF;
			switch ($config->symlinks)
			{
				case 1:
					$nginxConf .= "disable_symlinks on;\n";
					break;

				case 2:
					$nginxConf .= "disable_symlinks if_not_owner;\n";
					break;
			}
		}

		// Must come before the exception and Server Protection locations; see the method's docblock.
		$nginxConf .= $this->makeExceptionPathInfoLocations($config, $rewritebaseSlash, $fastcgi_pass_block);

		if ($config->autocompress == 1)
		{
			$nginxConf .= <<<ENDCONF
######################################################################
## Automatic compression of static resources
## Compress text, html, javascript, css, xml and other static resources
## May kill access to your site for old versions of Internet Explorer
######################################################################
# The following is the actual automatic compression setup
gzip            on;
gzip_vary		on;
gzip_comp_level 6;
gzip_proxied	expired no-cache no-store private auth;
gzip_min_length 1000;
gzip_http_version 1.1;
gzip_types      text/plain text/css application/xhtml+xml application/xml+rss application/rss+xml application/x-javascript application/javascript text/javascript application/json text/xml application/xml image/svg+xml;
gzip_buffers    16 8k;
gzip_disable "MSIE [1-6]\.(?!.*SV1)";

ENDCONF;
		}

		if ($config->etagtype != -1)
		{
			$etagValue = ($config->etagtype == 1) ? 'on' : 'off';
			$nginxConf .= <<< CONF
## Send ETag (you have set it to '$etagValue')
etag $etagValue;

CONF;

		}

		/**
		 * The redirects below use the request URI as it is, which already starts with the site's subfolder, if any.
		 * Our documentation used to tell users to type the site's path after the host name (e.g. example.com/blog); $host
		 * never contains a path, and the path must not be added to the target again, so only the host name is used.
		 *
		 * $host never contains the port either, so it is compared against the host name without the port. The redirect
		 * target keeps the protocol of the request, and with it the port of that protocol's host name field: that of
		 * "Host name for HTTP requests" for HTTP and that of "Host name for HTTPS requests" for HTTPS.
		 */
		[$host, $httpPort] = $this->splitHostPort($this->hostWithoutPath((string) $config->httphost));
		[, $httpsPort]     = $this->splitHostPort($this->hostWithoutPath((string) $config->httpshost));
		$host              = $this->lowerHost($host);
		$hasWwwRedirect    = in_array((int) $config->wwwredir, [1, 2], true);
		$oldDomains        = $this->normaliseOldDomains($config->olddomain ?? '');
		$hasOldDomains     = $oldDomains !== [];
		$targetPort        = $httpPort;

		// An IP address has no www form; www.127.0.0.1 is not an address the site can be reached on.
		if ($hasWwwRedirect && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false)
		{
			$nginxConf .= <<<END
######################################################################
## Redirect www and non-www addresses: not possible, the host name
## for HTTP requests ($host) is an IP address
######################################################################

END;
			$hasWwwRedirect = false;
		}

		if ($httpPort !== $httpsPort)
		{
			$targetPort = '$akRedirectPort';

			if ($hasWwwRedirect || $hasOldDomains)
			{
				$nginxConf .= <<<END
######################################################################
## The port of the redirect targets: that of the host name for HTTP
## requests or HTTPS requests, whichever protocol the request has
######################################################################
set \$akRedirectPort "$httpPort";
if (\$akActualProto = "https") {
	set \$akRedirectPort "$httpsPort";
}

END;
			}
		}

		if (substr($host, 0, 4) == 'www.')
		{
			$wwwHost   = $host;
			$noWwwHost = substr($host, 4);
		}
		else
		{
			$noWwwHost = $host;
			$wwwHost   = 'www.' . $host;
		}

		switch ($hasWwwRedirect ? (int) $config->wwwredir : 0)
		{
			case 1:
				// non-www to www
				$nginxConf .= <<<END
######################################################################
## Redirect non-www to www
######################################################################
if (\$host = '$noWwwHost' ) {
	return 301 \$akActualProto://$wwwHost$targetPort\$request_uri;
}

END;
				break;

			case 2:
				// www to non-www
				$nginxConf .= <<<END
######################################################################
## Redirect www to non-www
######################################################################
if (\$host = '$wwwHost' ) {
	return 301 \$akActualProto://$noWwwHost$targetPort\$request_uri;
}

END;
				break;
		}

		if ($hasOldDomains)
		{
			$nginxConf .= <<<END
######################################################################
## Redirect old to new domains
######################################################################

END;
			// Each old domain once, regardless of letter case and port (gh-470)
			foreach ($oldDomains as $olddomain)
			{
				/**
				 * The site's own host name is the redirect target. An old domain which is that host name, or of which
				 * it is a subdomain (e.g. example.com for www.example.com), would redirect the site to itself, in a loop.
				 */
				if ($this->oldDomainCoversHost($olddomain, $host))
				{
					continue;
				}

				/**
				 * The old domain and its subdomains, the same regex as the other makers': unanchored, notold.example.com
				 * matched old.example.com. $host never contains the port, see above, so the optional port never
				 * matters. Case-insensitive: $host is lower case, and an old domain typed with capital letters never
				 * matched.
				 */
				$olddomain = $this->oldDomainHostRegex($olddomain);

				$nginxConf .= <<<END
if (\$host ~* "$olddomain" ) {
	return 301 \$akActualProto://$host$targetPort\$request_uri;
}

END;
			}
		}

		if ($config->hstsheader == 1)
		{
			$nginxConf .= <<<END
## HSTS Header - See http://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security
add_header Strict-Transport-Security "max-age=31536000" always;

END;
		}
		elseif ($config->hstsheader == 2)
		{
			$nginxConf .= <<<END
## HSTS Header - See http://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

END;
		}

		if ($config->cors == 1)
		{
			$nginxConf .= <<<END
## Explicitly enable Cross-Origin Resource Sharing (CORS)
add_header Access-Control-Allow-Origin "*";
add_header Timing-Allow-Origin "*";

END;
		}
		elseif ($config->cors == -1)
		{
			$nginxConf .= <<<END
## Explicitly disable Cross-Origin Resource Sharing (CORS)
add_header Cross-Origin-Resource-Policy "same-origin";

END;
		}

		if ($config->referrerpolicy !== '-1')
		{
			$nginxConf .= <<<END
## Referrer-policy
add_header Referrer-Policy "{$config->referrerpolicy}";

END;
		}

		if ($config->notracetrack == 1)
		{
			$nginxConf .= <<<END
## Disable HTTP methods TRACE and TRACK (protect against XST)
if (\$request_method ~ ^(TRACE|TRACK)$ ) {
	return 405;
}

END;
		}

		if ($config->reducemimetyperisks == 1)
		{
			$nginxConf .= <<< ENDCONF
## Reduce MIME type security risks
add_header X-Content-Type-Options "nosniff";

ENDCONF;
		}

		if ($config->reflectedxss == 1)
		{
			$nginxConf .= <<< ENDCONF
## Reflected XSS prevention
add_header X-XSS-Protection "1; mode=block";

ENDCONF;
		}


		/**
		 * add_header only adds headers, and skips one with an empty value; it never removes a header sent by PHP-FPM.
		 * fastcgi_hide_header does. It is inherited by every location which has no fastcgi_hide_header of its own, so a
		 * FastCGI pass block, or a PHP location of the server block, with its own fastcgi_hide_header must repeat these.
		 *
		 * NginX refuses a duplicate server_tokens directive, and "Tighten NginX security settings" emits it too.
		 */
		if ($config->noserversignature == 1)
		{
			$serverTokens = ($config->nginxsecurity == 1)
				? '# The NginX version is hidden by "Tighten NginX security settings" below'
				: 'server_tokens off;';

			$nginxConf .= <<< ENDCONF
## Remove NginX and PHP version signature
fastcgi_hide_header X-Powered-By;
fastcgi_hide_header X-Content-Powered-By;
$serverTokens

ENDCONF;
		}


		if ($config->cfipfwd == 1)
		{
			$trustedSources = '';
			$proxyIps       = $this->trustedProxyIps($config);

			if (!empty($proxyIps))
			{
				$trustedSources = "## User-supplied reverse proxy IP address(es)\n" .
					implode("\n", array_map(function ($x) {
						return 'set_real_ip_from ' . $x . ';';
					}, $proxyIps));
			}

			$nginxConf .= <<<END
######################################################################
## Real IP forwarding support 
######################################################################
$trustedSources
real_ip_header     X-Forwarded-For;

END;
		}

		if ($config->opttimeout == 1)
		{
			$nginxConf .= <<<END
# -- Timeout handling, see http://wiki.nginx.org/HttpCoreModule
client_header_timeout 10;
client_body_timeout   10;
send_timeout          30;
keepalive_timeout     30s;

END;
		}

		if ($config->optsockets == 1)
		{
			$nginxConf .= <<<END
# -- Socket settings, see http://wiki.nginx.org/HttpCoreModule
connection_pool_size        8192;
client_header_buffer_size   4k;
large_client_header_buffers 8 8k;
request_pool_size           8k;

END;
		}

		if ($config->opttcpperf == 1)
		{
			$nginxConf .= <<<END
# -- Performance, see http://wiki.nginx.org/HttpCoreModule
sendfile on;
sendfile_max_chunk 1m;
postpone_output 0;
tcp_nopush on;
tcp_nodelay on;

END;
		}

		if ($config->optoutbuf == 1)
		{
			$nginxConf .= <<<END
# -- Output buffering, see http://wiki.nginx.org/HttpCoreModule
output_buffers 8 32k;

END;
		}

		if ($config->optfhndlcache == 1)
		{
			$nginxConf .= <<<END
# -- Filehandle Cache, useful when serving a large number of static files (Joomla! sites do that)
open_file_cache max=2000 inactive=20s;
open_file_cache_valid 30s;
open_file_cache_min_uses 2;
open_file_cache_errors on;

END;
		}

		if ($config->encutf8 == 1)
		{
			$nginxConf .= <<<END
# -- Character encoding, see http://wiki.nginx.org/HttpCharsetModule
charset                 utf-8;
source_charset          utf-8;

END;
		}

		if ($config->nginxsecurity == 1)
		{
			$nginxConf .= <<<END
# -- Security options, see http://wiki.nginx.org/HttpCoreModule
server_name_in_redirect off;
server_tokens off;
ignore_invalid_headers on;

END;
		}

		if ($config->maxclientbody == 1)
		{
			$nginxConf .= <<<END
# -- Maximum client body size set to 1 Gigabyte
client_max_body_size 1G;

END;
		}

		if ($config->blockcommon == 1)
		{
			/**
			 * The <script> condition uses ~*, case-insensitive like the .htaccess Maker's [NC]: with ~, %3cscript%3e and
			 * <SCRIPT> went through (gh-491). The other conditions are case-sensitive in all three makers.
			 */
			$nginxConf .= <<<END
set \$common_exploit 0;
if (\$query_string ~ "proc/self/environ") {
	set \$common_exploit 1;
}
if (\$query_string ~ "mosConfig_[a-zA-Z_]{1,21}(=|\%3D)") {
	set \$common_exploit 1;
}
if (\$query_string ~ "base64_(en|de)code\(.*\)") {
	set \$common_exploit 1;
}
if (\$query_string ~* "(<|%3C).*script.*(>|%3E)") {
	set \$common_exploit 1;
}
if (\$query_string ~ "GLOBALS(=|\[|\%[0-9A-Z]{0,2})") {
	set \$common_exploit 1;
}
if (\$query_string ~ "_REQUEST(=|\[|\%[0-9A-Z]{0,2})") {
	set \$common_exploit 1;
}
if (\$common_exploit = 1) {
	return 403;
}

END;
		}

		if ($config->enablesef == 1)
		{
			$nginxConf .= <<<NGINX
## Enable SEF URLs

NGINX;
			$nginxConf .= <<<NGINX
# Joomla API application
location {$siteQuote}$rewritebaseSlash/api/{$siteQuote} {
	try_files \$uri \$uri/ {$siteUriQuote}{$siteUri}/api/index.php?\$args{$siteUriQuote};
}

NGINX;

			$nginxConf .= <<<NGINX
# Joomla public frontend application
location {$siteQuote}$rewritebaseSlash/{$siteQuote} {
	try_files \$uri \$uri/ {$siteUriQuote}{$siteUri}/index.php?\$args{$siteUriQuote};
}

NGINX;

			$nginxConf .= <<<NGINX
# Parse index.php as a PHP executable file
location ~* ^$rewritebaseRegex/index\.php$ {
	$fastcgi_pass_block
	break;
}

NGINX;
		}

		$nginxConf .= <<< END
######################################################################
## Advanced server protection rules exceptions
######################################################################

END;

		if ($config->frontendprot == 1 || $config->backendprot == 1)
		{
			/**
			 * Servers which map PHP by a file's extensions (e.g. Apache with AddHandler behind NginX) run any file with a
			 * PHP extension anywhere in its name, e.g. x.php.png, as a PHP script. A server-level "if" runs before any
			 * location is chosen, so neither the exceptions below nor the static file locations can let it through.
			 */
			$phpExt    = self::PHP_EXTENSIONS;
			$nginxConf .= <<< END
# Forbid PHP double extensions (e.g. file.php.png) everywhere
if (\$uri ~* "\.($phpExt)\.") {
	return 403;
}

END;
		}

		/**
		 * An exact location takes precedence over the server's own PHP location. Without a FastCGI handler NginX serves
		 * the file as a static file, i.e. sends its source code to the visitor. That applies to every PHP extension
		 * (.phtml, .php5, .phar …, any letter case), not just .php: NginX does not hand those to PHP-FPM, so they are
		 * refused instead.
		 *
		 * The path is quoted if it has a space, `{` or `}`, see quoteArgument(). NginX expands no variables in a location,
		 * so a $ is a $. The exceptions are normalised (gh-454): a double quote, a backslash, a semicolon and a control
		 * character drop the entry whole.
		 */
		$phpExt = self::PHP_EXTENSIONS;

		foreach ($config->exceptionfiles as $file)
		{
			$location = $this->quoteArgument($rewritebaseSlash . '/' . $file);

			if ($this->isDotPhpFile($file))
			{
				$nginxConf .= <<<END
location = $location {
	$fastcgi_pass_block
	break;
}

END;
				continue;
			}

			if (preg_match('#\.(' . $phpExt . ')$#i', $file))
			{
				$nginxConf .= <<<END
location = $location {
	return 403;
}

END;
				continue;
			}

			$nginxConf .= <<<END
location = $location {
	break;
}

END;
		}

		/**
		 * Every location below must say what happens to PHP files. These regular expression locations take precedence
		 * over the server's own PHP location, and a location without a FastCGI handler makes NginX serve a PHP file as
		 * a static file, i.e. send its source code to the visitor. That covers every PHP extension (.php5, .phtml,
		 * .phps …), not just .php: NginX does not hand those to PHP-FPM, so serving them can only disclose source code.
		 *
		 * Letter case: the folder only matches in the letter case it is typed in, (?-i:…), like the .htaccess Maker's
		 * exception rules; the site's path and the file extension match in any letter case. On a case-sensitive file
		 * system images/EXDIR/ is not the exception directory images/exdir, and must stay under Server Protection.
		 * Otherwise e.g. a PHP file in images/FULLDIR/ would run when images/fulldir is the full access directory.
		 *
		 * These regular expression locations are not quoted: a space in a folder is \x20 (gh-454), which would otherwise
		 * end the regular expression.
		 */
		$phpExt = self::PHP_EXTENSIONS;

		foreach ($config->exceptiondirs as $dir)
		{
			$dir       = $this->entryRegex($dir);
			$nginxConf .= <<<END
location ~* ^$rewritebaseRegex/(?-i:$dir)/.*\.($phpExt)$
{
	return 403;
}
location ~* ^$rewritebaseRegex/(?-i:$dir)/.*$
{
	break;
}

END;
		}

		/**
		 * The .php location only hands existing files to PHP. A request for e.g. /dir/upload.jpg/x.php would otherwise
		 * reach PHP-FPM, which runs upload.jpg as PHP when cgi.fix_pathinfo is 1 and security.limit_extensions is lax.
		 */
		foreach ($config->fullaccessdirs as $dir)
		{
			$dir       = $this->entryRegex($dir);
			$nginxConf .= <<<END
location ~* ^$rewritebaseRegex/(?-i:$dir)/.*\.php$
{
	if (!-f \$document_root\$fastcgi_script_name) {
		return 404;
	}
	$fastcgi_pass_block
	break;
}
location ~* ^$rewritebaseRegex/(?-i:$dir)/.*\.($phpExt)$
{
	return 403;
}
location ~* ^$rewritebaseRegex/(?-i:$dir)/.*$
{
	break;
}

END;
		}

		$nginxConf .= <<< END
######################################################################
## Advanced server protection
######################################################################

END;

		if ($config->backendprot == 1)
		{
			$beMediaRegex = $this->backendMediaRegex($config, $rewritebaseSlash);

			if ($beMediaRegex !== '')
			{
				$nginxConf .= <<<END
# Allow media files in select back-end directories
location ~* $beMediaRegex {
	break;
}


END;
			}

			/**
			 * A location is matched against the decoded path, never the query string, so the back-end index.php location
			 * matches the path only: index.php?foo=bar is index.php to NginX.
			 */
			$nginxConf .= <<<END
# Allow access to the back-end index.php file
location ~ ^$rewritebaseRegex/administrator/index\.php$ {
	$fastcgi_pass_block
	break;
}
location ~* ^$rewritebaseRegex/administrator$ {
	return 301 {$siteUrl}/administrator/index.php?\$args;
}
location ~* ^$rewritebaseRegex/administrator/$ {
	return 301 {$siteUrl}/administrator/index.php?\$args;
}

# Disable access to everything else.
location ~* ^$rewritebaseRegex/administrator.*$ {
	# If it is a file, directory or symlink and I haven't deliberately
	# enabled access to it, forbid any access to it!
	if (-e \$request_filename) {
		return 403;
	}
	# In any other case, just treat as a SEF URL
	try_files \$uri \$uri/ {$siteUriQuote}{$siteUri}/administrator/index.php?\$args{$siteUriQuote};
}

END;
		}

		if ($config->frontendprot == 1)
		{
			$tinyMceRegex = $this->tinyMceHtmlRegex($rewritebaseSlash);
			$feMediaRegex = $this->frontendMediaRegex($config, $rewritebaseSlash);
			$nginxConf    .= <<<END
# Allow limited access to additional TinyMCE plugins' HTML files
location ~* $tinyMceRegex {
	break;
}


END;

			if ($feMediaRegex !== '')
			{
				$nginxConf .= <<<END
# Allow media files in select front-end directories
location ~* $feMediaRegex {
	break;
}


END;
			}

			$nginxConf .= <<<END
## Disallow front-end access for certain Joomla! system directories (unless access to their files is allowed above)
location ~* ^$rewritebaseRegex/includes/js/ {
	return 403;
}
location ~* ^$rewritebaseRegex/(cache|includes|language|logs|log|tmp)/ {
	return 403;
}

END;
			if ($config->enablesef != 1)
			{
				$nginxConf .= <<<END
# Allow access to the front-end index.php file
location ~* ^$rewritebaseRegex/index\.php$ {
	$fastcgi_pass_block
	break;
}

END;
			}

			/**
			 * The SEF section's `location /api/` is a prefix location, so the "everything else" regular expression
			 * location below would take precedence over it. These come first, with SEF URLs on or off.
			 */
			$nginxConf .= <<<END
# Allow access to the API application's index.php file
location ~* ^$rewritebaseRegex/api/index\.php$ {
	$fastcgi_pass_block
	break;
}

# Joomla API application: refuse any other file, treat everything else as an API URL
location ~* ^$rewritebaseRegex/api/ {
	if (-e \$request_filename) {
		return 403;
	}
	try_files \$uri \$uri/ {$siteUriQuote}{$siteUri}/api/index.php?\$args{$siteUriQuote};
}

END;

			/**
			 * A regular expression location, not `location = /robots.txt`: many server blocks have an exact location
			 * for robots.txt already, and a duplicate exact location makes NginX refuse the whole configuration.
			 *
			 * The same goes for the common static files of the site's root, e.g. favicon.ico, ads.txt and the search
			 * engines' site verification files (ROOT_STATIC_FILES), which are allowed like robots.txt (gh-469): in any
			 * letter case (~*), and only in the site's root, as the pattern is anchored at both ends. A missing one still
			 * goes to Joomla!, like in the "everything else" location below, e.g. for an extension which generates it.
			 */
			$rootFiles = self::ROOT_STATIC_FILES;
			$nginxConf .= <<<END
# Allow access to the site's robots.txt file
location ~ ^$rewritebaseRegex/robots\.txt$ {
	break;
}

# Allow access to common static files in the site's root, e.g. favicon.ico, ads.txt and site verification files
location ~* ^$rewritebaseRegex/(?:$rootFiles)$ {
	try_files \$uri {$siteUriQuote}{$siteUri}/index.php?\$args{$siteUriQuote};
}

END;

			/**
			 * With back-end protection off, the "everything else" location would also forbid every back-end file,
			 * administrator/index.php included. It skips the back-end, which is then left to the server's own
			 * configuration, like the .htaccess Maker does. With back-end protection on, the back-end locations come
			 * first and take every request under /administrator anyway.
			 */
			$feEverythingElse = ($config->backendprot == 1) ? '.*' : '(?!administrator(/|$)).*';

			/**
			 * The folder of an excepted index.php, index.html or index.htm, e.g. docs/ for docs/index.html, is answered
			 * with that file, like the .htaccess and web.config Makers do (gh-470); "everything else" below refuses any
			 * other existing folder. An exact location takes precedence over the regular expression ones. Its own index
			 * lists the folder's excepted index files only, in the letter case typed, like their own `location =`:
			 * NginX answers the folder with the first one which exists, through an internal redirect which goes through
			 * the locations again, where that file's own exception lets it through. Any other file, e.g. an index.php
			 * which is not excepted, is never the folder's answer. Without any of them the folder is refused, never
			 * listed (autoindex off), like the .htaccess Maker does. A folder which does not exist goes to Joomla!, like
			 * in "everything else". Each folder once. Not the site's root, which is allowed below, nor a back-end
			 * folder: back-end protection refuses those, like the .htaccess Maker does, and without it the back-end is
			 * left to the server's own configuration.
			 */
			foreach ($this->exceptionFileIndexFolders($config->exceptionfiles) as $folder => $names)
			{
				$location  = $this->quoteArgument($rewritebaseSlash . '/' . $folder . '/');
				$names     = implode(' ', $names);
				$nginxConf .= <<<END
# Allow access to the folder of an excepted index file
location = $location {
	index $names;
	autoindex off;
	try_files \$uri/ {$siteUriQuote}{$siteUri}/index.php?\$args{$siteUriQuote};
}

END;
			}

			/**
			 * The site root is handed to index.php internally, not redirected. With Joomla's URL rewriting on, Joomla
			 * redirects /index.php to /, so redirecting / to /index.php would loop forever.
			 */
			$nginxConf .= <<<END
# Allow access to /
location ~* ^$rewritebaseRegex/$ {
	rewrite ^ {$siteUriQuote}{$siteUri}/index.php{$siteUriQuote} last;
}

# Disable access to everything else.
location ~* ^$rewritebaseRegex/{$feEverythingElse}$ {
	# If it is a file, directory or symlink and I haven't deliberately
	# enabled access to it, forbid any access to it!
	if (-e \$request_filename) {
		return 403;
	}
	# In any other case, just treat as a SEF URL
	try_files \$uri \$uri/ {$siteUriQuote}{$siteUri}/index.php?\$args{$siteUriQuote};
}

END;
		}

		$nginxConf .= "##### Advanced server protection -- END\n\n";

		return $nginxConf;
	}

	/**
	 * Response headers which only apply to some URLs.
	 *
	 * A location with its own add_header inherits none of the server-level add_header directives. Every security
	 * header (X-Frame-Options, HSTS, X-Content-Type-Options …) is set at the server level, so no location in this file
	 * may have an add_header of its own. Headers which only apply to some URLs are set at the server level as well, and
	 * take their value from a variable which server-level `if` blocks set for those URLs. NginX does not send a header
	 * whose value is empty.
	 *
	 * Server-level `if` blocks run in the order they appear, before NginX chooses a location, so a later `if` overrides
	 * an earlier one: the exceptions come after the media folders, like their locations take precedence over the media
	 * folders' locations. A server-level `return` stops processing them, and NginX then uses an uninitialised variable,
	 * logging a warning for every such request. This must come before any server-level `return`.
	 *
	 * `map` would be cleaner, but it is only allowed in the http block, and this file is included in a server block.
	 *
	 * The expiration time is set the same way, with `expires` and a variable. Locations of its own would be regular
	 * expression locations for file extensions, and NginX uses the first regular expression location which matches:
	 * placed before the Server Protection locations they would serve e.g. cache/foo.css instead of refusing it.
	 *
	 * @param   object  $config            The NginX Conf Maker configuration
	 * @param   string  $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty for root
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function makeUrlDependentHeaders(object $config, string $rewritebaseSlash): string
	{
		$nginxConf = $this->makeStaticRisksHeaders($config, $rewritebaseSlash)
			. $this->makeSvgHeaders($config)
			. $this->makeExpirationHeaders($config);

		if (empty($nginxConf))
		{
			return '';
		}

		return <<<END

######################################################################
## Response headers which only apply to some URLs
##
## They are set at the server level, from variables. A location with an
## add_header of its own loses every server-level add_header. NginX does
## not send a header whose value is empty.
######################################################################
$nginxConf
END;
	}

	/**
	 * The Content-Security-Policy of "Disable client-side risky behavior in back-end / front-end static content".
	 *
	 * Like the .htaccess Maker: the allowed media files of the back-end and front-end Server Protection get the strict
	 * policy. TinyMCE plugin files, which need to run scripts, and the Server Protection exceptions get no policy from
	 * this option, even when they are in a media folder.
	 *
	 * @param   object  $config            The NginX Conf Maker configuration
	 * @param   string  $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty for root
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function makeStaticRisksHeaders(object $config, string $rewritebaseSlash): string
	{
		$hasBackendCsp  = ($config->backendprot == 1) && ($config->bestaticrisks == 1);
		$hasFrontendCsp = ($config->frontendprot == 1) && ($config->festaticrisks == 1);

		if (!$hasBackendCsp && !$hasFrontendCsp)
		{
			return '';
		}

		$strict         = "default-src 'self'; script-src 'none';";
		$strictRegexes  = [];
		$tinyMceRegexes = [];

		if ($hasBackendCsp)
		{
			$strictRegexes[]  = $this->backendMediaRegex($config, $rewritebaseSlash);
			$tinyMceRegexes[] = $this->tinyMcePluginsRegex($rewritebaseSlash, $config->bepextypes);
		}

		if ($hasFrontendCsp)
		{
			$strictRegexes[]  = $this->frontendMediaRegex($config, $rewritebaseSlash);
			$tinyMceRegexes[] = $this->tinyMcePluginsRegex($rewritebaseSlash, $config->fepextypes);
		}

		// No file type to allow: no media location, so no policy either. See backendMediaRegex().
		$strictRegexes  = array_filter($strictRegexes);
		$tinyMceRegexes = array_filter($tinyMceRegexes);

		if (empty($strictRegexes))
		{
			return '';
		}

		$nginxConf = <<<END
## Disable client-side risky behavior in static content
set \$akStaticCsp "";

END;

		foreach ($strictRegexes as $regex)
		{
			$nginxConf .= <<<END
if (\$uri ~* "$regex") {
	set \$akStaticCsp "$strict";
}

END;
		}

		/*
		 * The exemptions come after the strict policy, so that they override it.
		 *
		 * The .htaccess Maker exempts the files of the enabled side's types in TinyMCE's plugins folder, and in the
		 * exception and full access directories. For those directories NginX exempts every file instead. That is the
		 * same thing: only the media file `if` blocks above set the policy, and only for files of those very types.
		 *
		 * Letter case, like the .htaccess Maker: an exemption must match exactly the files let through by the location
		 * it stands for. The site's path and the file type match in any letter case, like the policy. TinyMCE's plugins
		 * folder and the exception directories only match in the letter case they are typed in, (?-i:…), like their
		 * locations; excepted files are compared exactly, like their `location =`. On a case-sensitive file system the
		 * media location serves e.g. an uploaded images/EXDIR/x.svg, which must keep the policy when images/exdir is
		 * the exception directory.
		 */
		$nginxConf .= "# TinyMCE plugin files need to run scripts\n";

		foreach (array_unique($tinyMceRegexes) as $regex)
		{
			$nginxConf .= <<<END
if (\$uri ~* "$regex") {
	set \$akStaticCsp "";
}

END;
		}

		$nginxConf .= "# The Server Protection exceptions get no policy\n";

		foreach ($config->exceptionfiles ?: [] as $file)
		{
			/**
			 * NginX expands variables in the string an `if` compares $uri with, and has no escape for the $: a file name
			 * such as a$b.html made NginX refuse the whole configuration (unknown "b" variable), and c$uri.html never
			 * matched. A regular expression is not expanded: names with a $ are matched with one, anchored at both
			 * ends and case-sensitive, so it matches exactly what `location =` matches.
			 */
			$uriCondition = (strpos($rewritebaseSlash . '/' . $file, '$') === false)
				? "= \"$rewritebaseSlash/$file\""
				: '~ "^' . $this->entryRegex($rewritebaseSlash . '/' . $file) . '$"';
			$nginxConf .= <<<END
if (\$uri $uriCondition) {
	set \$akStaticCsp "";
}

END;

			// See makeExceptionPathInfoLocations()
			if (($config->exceptionpathinfo ?? 0) == 1 && $this->isDotPhpFile($file))
			{
				$pathRegex = $this->entryRegex($rewritebaseSlash . '/' . $file);
				$nginxConf .= <<<END
if (\$uri ~ "^$pathRegex/") {
	set \$akStaticCsp "";
}

END;
			}
		}

		$rewritebaseRegex = $this->pathRegex($rewritebaseSlash);

		foreach (array_merge($config->exceptiondirs ?: [], $config->fullaccessdirs ?: []) as $dir)
		{
			$dir       = $this->entryRegex($dir);
			$nginxConf .= <<<END
if (\$uri ~* "^$rewritebaseRegex/(?-i:$dir)/") {
	set \$akStaticCsp "";
}

END;
		}

		$nginxConf .= <<<END
add_header Content-Security-Policy \$akStaticCsp;


END;

		return $nginxConf;
	}

	/**
	 * The Content-Security-Policy of "Neutralise SVG script execution", for every SVG file wherever it is.
	 *
	 * It is a header of its own, not merged into the policy of "Disable client-side risky behavior". A browser enforces
	 * every Content-Security-Policy header it receives, so an SVG file which gets both, e.g. in a media folder or an
	 * exception directory, can run no script whichever the other policy is, and is otherwise restricted by both.
	 *
	 * @param   object  $config  The NginX Conf Maker configuration
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function makeSvgHeaders(object $config): string
	{
		if ($config->svgneutralise != 1)
		{
			return '';
		}

		return <<<ENDCONF
## Neutralize scripts in SVG files
set \$akSvgCsp "";
if (\$uri ~* "\.svgz?$") {
	set \$akSvgCsp "script-src 'none'";
}
add_header Content-Security-Policy \$akSvgCsp;


ENDCONF;
	}

	/**
	 * The default expiration time of static files.
	 *
	 * `expires` accepts a variable. Its "no expiration time" value is `off`; it applies to responses with the status
	 * codes 200, 201, 204, 206, 301, 302, 303, 304, 307 and 308 only, like add_header without `always`.
	 *
	 * The expiration times are the .htaccess Maker's, which sets them by MIME type: with exptime 1, one week for CSS,
	 * JavaScript and fonts, one month for images, audio and video; with exptime 2, one year for all of these. NginX's
	 * w, M and y are 7, 30 and 365 days, like mod_expires' week, month and year. The extensions are those Apache's
	 * mime.types maps to the MIME types the .htaccess Maker lists, plus a few Apache gives another MIME type or none,
	 * such as mkv and m4a, which stay in the group of their kind of file.
	 *
	 * A request with path info, e.g. /foobar.php/baz.png, runs a PHP file, which must not get the expiration time of
	 * the file type its path info ends with. A request NginX hands to index.php, e.g. a SEF URL ending in .json, is
	 * redirected internally to /index.php, which runs these `if` blocks again for the new URI.
	 *
	 * @param   object  $config  The NginX Conf Maker configuration
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function makeExpirationHeaders(object $config): string
	{
		if ($config->exptime == 0)
		{
			return '';
		}

		[$expWeekText, $expMonthText, $weekSeconds, $monthSeconds] = ($config->exptime == 2)
			? ['1 year', '1 year', 31536000, 31536000]
			: ['1 week', '1 month', 604800, 2592000];

		// gh-479: ONE Cache-Control header. `expires` and add_header would each send their own.

		return <<<ENDCONF
## Set default expiration time
# One Cache-Control header per response. Pages PHP generates keep their own Cache-Control header, which Admin Tools
# must not change.
set \$akCacheControl "";

# No caching for specific resource types
if (\$uri ~* "\.(appcache|mf)$") {
	set \$akCacheControl "no-cache";
}
if (\$uri ~* "\.(json|xml|xsl)$") {
	set \$akCacheControl "no-cache";
}

# RSS and Atom feeds : 1 hour (hardcoded)
if (\$uri ~* "\.(rss|atom)$") {
	set \$akCacheControl "max-age=3600, public";
}

# CSS and JavaScript : $expWeekText
if (\$uri ~* "\.(css|js|mjs|jsonld)$") {
	set \$akCacheControl "max-age=$weekSeconds, public";
}

# Image files : $expMonthText
if (\$uri ~* "\.(bmp|gif|jpg|jpeg|jpe|jp2|jfif|png|svg|svgz|tif|tiff|ico|wbmp|wbxml|smil|webp)$") {
	set \$akCacheControl "max-age=$monthSeconds, public";
}

# Font files : $expWeekText
if (\$uri ~* "\.(woff|woff2|ttf|otf|eot)$") {
	set \$akCacheControl "max-age=$weekSeconds, public";
}

# Audio files : $expMonthText
if (\$uri ~* "\.(3gp|3g2|aac|au|snd|mid|midi|kar|rmi|mp3|mpga|mp2|mp2a|m2a|m3a|m4a|m4r|aif|aiff|aifc|ra|ram|wav|voc|ogg|oga|ogx|opus|spx|m3u|m3u8)$") {
	set \$akCacheControl "max-age=$monthSeconds, public";
}

# Video files : $expMonthText
if (\$uri ~* "\.(swf|vrml|avi|mkv|mpg|mpeg|mpe|m1v|m2v|mp4|mp4v|mpg4|m4v|mov|qt|ogv|webm|asf|asx)$") {
	set \$akCacheControl "max-age=$monthSeconds, public";
}

# A PHP file with path info, e.g. /foobar.php/baz.png, is not a static file
if (\$uri ~* "\.php/") {
	set \$akCacheControl "";
}

add_header Cache-Control \$akCacheControl;


ENDCONF;
	}

	/**
	 * The regular expression for the media files of the back-end directories where file type exceptions are allowed.
	 *
	 * Used by both their location and the Content-Security-Policy of "Disable client-side risky behavior in back-end
	 * static content", so that the policy applies to exactly the files the location lets through. The dot before the
	 * file types is escaped: x_css is not a .css file.
	 *
	 * An empty string when there is no file type to allow: both the location and the policy are then left out, and no
	 * file in these directories is allowed.
	 *
	 * @param   object  $config            The NginX Conf Maker configuration
	 * @param   string  $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty for root
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function backendMediaRegex(object $config, string $rewritebaseSlash): string
	{
		$directories = $this->bugfixBackendProtectionExclusionDirectories(
			$this->normaliseDirectories($config->bepexdirs ?: [])
		);
		$bedirs      = $this->directoriesRegex($directories);
		$betypes     = $this->fileTypesRegex($config->bepextypes);

		if ($bedirs === '' || $betypes === '')
		{
			return '';
		}

		return "^{$this->pathRegex($rewritebaseSlash)}/administrator/($bedirs)/.*\.($betypes)$";
	}

	/**
	 * The regular expression for the media files of the front-end directories where file type exceptions are allowed.
	 *
	 * See backendMediaRegex(); an empty string as well when there is no file type to allow.
	 *
	 * @param   object  $config            The NginX Conf Maker configuration
	 * @param   string  $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty for root
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function frontendMediaRegex(object $config, string $rewritebaseSlash): string
	{
		$fedirs  = $this->directoriesRegex($config->fepexdirs);
		$fetypes = $this->fileTypesRegex($config->fepextypes);

		if ($fedirs === '' || $fetypes === '')
		{
			return '';
		}

		return "^{$this->pathRegex($rewritebaseSlash)}/($fedirs)/.*\.($fetypes)$";
	}

	/**
	 * The regular expression for the HTML files of TinyMCE's plugins, which their Server Protection location allows.
	 *
	 * @param   string  $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty for root
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function tinyMceHtmlRegex(string $rewritebaseSlash): string
	{
		return "^{$this->pathRegex($rewritebaseSlash)}/media/plg_editors_tinymce/js/plugins/.*\.(htm|html)$";
	}

	/**
	 * The regular expression for the files of TinyMCE's plugins of the given types, which "Disable client-side risky
	 * behavior" exempts, like the .htaccess Maker. An empty string when there are no such types: no file then gets the
	 * policy, and there is nothing to exempt.
	 *
	 * @param   string           $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty
	 *                                              for root
	 * @param   string|string[]  $types             The file types (extensions) of the back-end or front-end protection
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function tinyMcePluginsRegex(string $rewritebaseSlash, $types): string
	{
		$types = $this->fileTypesRegex($types);

		if ($types === '')
		{
			return '';
		}

		return "^{$this->pathRegex($rewritebaseSlash)}/(?-i:media/plg_editors_tinymce/js/plugins)/.*\.($types)$";
	}

	/**
	 * Locations which let the excepted PHP files receive path info, e.g. /foobar.php/baz.png.
	 *
	 * Only emitted when the user enables "Allow path info for excepted PHP files". They apply to the PHP files in "Allow
	 * direct access to these files", and to the PHP files under "Allow direct access, including .php files, to these
	 * directories". Nothing else may run PHP this way: a generic `\.php(/|$)` location would let e.g.
	 * /images/upload.jpg/x.php run an uploaded file.
	 *
	 * Placement matters. NginX uses the first regular expression location which matches, and the exception and Server
	 * Protection locations would otherwise capture e.g. /dir/foobar.php/baz.png and serve it as a missing static file,
	 * or refuse it.
	 *
	 * - Excepted files get a `^~` prefix location. The longest matching prefix location with `^~` stops NginX from
	 *   checking regular expression locations at all, so it works wherever it is placed, even after a regular
	 *   expression location in the site's own server block. The split regex is anchored on the exact, escaped file path,
	 *   so the script is always that file.
	 * - Directories need a regular expression location, which must come before the exception and Server Protection
	 *   locations.
	 *
	 * The FastCGI pass block must set SCRIPT_FILENAME and PATH_INFO from $fastcgi_script_name and $fastcgi_path_info;
	 * NginX's stock fastcgi_params file does not set PATH_INFO. We cannot check that, hence the documentation pointer.
	 *
	 * The FastCGI pass block is adapted in two ways, in these locations only:
	 *
	 * - Our fastcgi_split_path_info comes after the block. NginX accepts the directive more than once and uses the last
	 *   one, so ours replaces any split the block has, e.g. the greedy `^(.+\.php)(/.+)$` our documentation recommends.
	 * - A `try_files $uri =404;` in the block, which our documentation also recommends, would return 404 for every
	 *   request with path info, since $uri includes the path info. We remove try_files here.
	 *
	 * Instead, every one of these locations checks that the script file, as split by us, exists. Otherwise a request
	 * such as /dir/upload.jpg/x.php/y would reach PHP-FPM, which runs upload.jpg as PHP when cgi.fix_pathinfo is 1 and
	 * security.limit_extensions is lax.
	 *
	 * @param   object  $config            The NginX Conf Maker configuration
	 * @param   string  $rewritebaseSlash  The site's path, with a leading and without a trailing slash; empty for root
	 * @param   string  $fastcgiPassBlock  The FastCGI pass block
	 *
	 * @return  string
	 * @since   7.10.0
	 */
	private function makeExceptionPathInfoLocations(
		object $config, string $rewritebaseSlash, string $fastcgiPassBlock
	): string
	{
		if (($config->exceptionpathinfo ?? 0) != 1)
		{
			return '';
		}

		// See the docblock: drop try_files, and only hand existing script files, as split by us, to PHP.
		$fastcgiPassBlock = trim(preg_replace('/(^|[\s;{}])try_files\s[^;]*;/', '$1', $fastcgiPassBlock));
		$fastcgiPassBlock = <<<END
if (!-f \$document_root\$fastcgi_script_name) {
		return 404;
	}
	$fastcgiPassBlock
END;

		$locations = '';

		foreach ($config->exceptionfiles ?: [] as $file)
		{
			if (!$this->isDotPhpFile($file))
			{
				continue;
			}

			$path      = $rewritebaseSlash . '/' . $file;
			$pathRegex = $this->entryRegex($path);
			$prefix    = $this->quoteArgument($path . '/');
			$locations .= <<<END
location ^~ $prefix {
	$fastcgiPassBlock
	fastcgi_split_path_info ^($pathRegex)(/.*)$;
	break;
}

END;
		}

		foreach ($config->fullaccessdirs ?: [] as $dir)
		{
			$dirRegex  = $this->entryRegex($rewritebaseSlash . '/' . $dir);
			$locations .= <<<END
location ~ ^$dirRegex/.+?\.php/ {
	$fastcgiPassBlock
	fastcgi_split_path_info ^($dirRegex/.+?\.php)(/.*)$;
	break;
}

END;
		}

		if (empty($locations))
		{
			return '';
		}

		return <<<END
######################################################################
## Path info for excepted PHP files (e.g. /foobar.php/baz.png)
##
## Your FastCGI pass block must set SCRIPT_FILENAME and PATH_INFO, e.g.
##   fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
##   fastcgi_param PATH_INFO \$fastcgi_path_info;
## Otherwise PHP does not see the path info. These locations only run PHP
## files which exist, and leave out any try_files in the block.
## Read the documentation:
## https://www.akeeba.com/documentation/admin-tools-joomla/nginx-server-protection.html#nginx-path-info
######################################################################
$locations
END;
	}

	/**
	 * A literal argument of a directive, e.g. the path of an exact or prefix location, quoted if NginX would otherwise not
	 * read it as one argument (gh-454).
	 *
	 * NginX ends an unquoted argument at white space, `;` and `{`, and `}` ends a block. Such an argument is put in
	 * double quotes; any other is left as it is, so that the file only changes where it has to. The site's path and the
	 * Server Protection exceptions never have a double quote, a backslash or a control character here, which a quoted
	 * argument could not hold as they are. NginX expands variables in some quoted arguments, e.g. of try_files, exactly
	 * as in unquoted ones: see makeConfigFile() for the $ in the site's path.
	 *
	 * @param   string  $argument  The argument, e.g. /my site/nginx.conf
	 *
	 * @return  string  E.g. "/my site/nginx.conf"
	 * @since   7.10.0
	 */
	private function quoteArgument(string $argument): string
	{
		return preg_match('/[\s;{}]/', $argument) ? '"' . $argument . '"' : $argument;
	}

	/**
	 * The folders of the "Allow direct access to these files" entries (exceptionfiles) named index.php, index.html or
	 * index.htm, with those names, in that order (gh-470).
	 *
	 * The names are matched in the letter case typed, like the `location =` of each excepted file. Each folder is listed
	 * once, with each of its names once. The site's root and the back-end, in any letter case, are left out: see
	 * makeConfigFile().
	 *
	 * @param   string[]  $files  The excepted files, normalised, see normaliseExceptions()
	 *
	 * @return  array<string, string[]>  Folder => index file names, e.g. ['docs' => ['index.php', 'index.html']]
	 * @since   7.10.0
	 */
	private function exceptionFileIndexFolders(array $files): array
	{
		$order   = ['index.php', 'index.html', 'index.htm'];
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

			if (!in_array($name, $order, true) || preg_match('#^administrator(/|$)#i', $folder))
			{
				continue;
			}

			$folders[$folder][] = $name;
		}

		return array_map(function (array $names) use ($order): array {
			return array_values(array_intersect($order, $names));
		}, $folders);
	}

	/**
	 * Is this excepted file a .php file, in any letter case, which the NginX Conf Maker hands to PHP-FPM?
	 *
	 * Files with any other PHP extension (.phtml, .php5 …) are refused instead, see makeConfigFile().
	 *
	 * @param   string  $file  The excepted file, relative to the site's root
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private function isDotPhpFile(string $file): bool
	{
		return strtolower(substr($file, -4)) === '.php';
	}
}
