<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Uri\Uri;
use Joomla\Utilities\IpHelper;

#[\AllowDynamicProperties]
class EmergencyofflineModel extends BaseModel
{
	/**
	 * Checks if the Emergency Off-Line Mode .htaccess backup exists
	 *
	 * @return  bool
	 */
	public function isOffline(): bool
	{
		$backupFile = $this->getPublicFolder() . '/.htaccess.eom';

		try
		{
			if (!@is_file($backupFile))
			{
				return false;
			}
		}
		catch (\Exception $e)
		{
			return false;
		}

		$filedata = @file_get_contents($backupFile) ?: '';

		return substr($filedata, 0, 48) === '## EOMBAK - Do not remove this line or this file';
	}

	/**
	 * Tries to put the site in Emergency Off-Line Mode, backing up the original .htaccess file
	 *
	 * @param   string|null  $exemptIp  IP address exempted from the block; NULL to use the caller's own IP address.
	 *
	 * @return  bool True on success
	 */
	public function putOffline(?string $exemptIp = null): bool
	{
		// If the backup doesn't exist, try to create it
		$htaccessFilePath = $this->getPublicFolder() . '/.htaccess';

		if (!$this->isOffline())
		{
			$backupFile = $this->getPublicFolder() . '/.htaccess.eom';
			$sourceFile = $htaccessFilePath;

			$sourceData = @file_get_contents($sourceFile) ?: '';
			$sourceData = "## EOMBAK - Do not remove this line or this file\n" . $sourceData;
			try
			{
				if (!File::write($backupFile, $sourceData))
				{
					return false;
				}
			}
			catch (\Exception $e)
			{
				return false;
			}

			if (@file_exists($sourceFile))
			{
				try
				{
					File::delete($sourceFile);
				}
				catch (\Exception $e)
				{
					// Swallow
				}
			}
		}

		// Create the offline.html file, if it doesn't exist. If you can't create it, don't worry too much.
		$offlineFile = $this->getPublicFolder() . '/offline.html';

		if (!@file_exists($offlineFile))
		{
			$app      = Factory::getApplication();
			$message  = $app->get('offline_message');
			$sitename = $app->get('sitename');
			$langTag  = $app->getLanguage()->getTag();

			$fileContents = <<<ENDHTML
<html lang="$langTag">
<head>
	<title>$sitename</title>
	<meta name="color-scheme" content="light dark">
	<style>
		body{font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"}h1{font-size:1.5em;padding-bottom:0.5em;border-bottom:thin solid rgba(0,0,0,.1)}.container{padding:0;height:99vh;width:99vw;display:flex;justify-content:center;align-items:center}.banner{min-width:100px;max-width:800px;text-align:center;background:rgba(0,0,0,.2);padding:1em 3em;border:thin solid rgba(0,0,0,.3);border-radius:1em}@media (prefers-color-scheme: dark){h1{border-color:rgba(255,255,255,.3)}.banner{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.1)}}
    </style>
</head>
<body>
<div class="container">
	<div class="banner">
		<h1>
			$sitename
		</h1>
		<p>
			$message
		</p>
	</div>
</div>
</body>
</html>
ENDHTML;

			try
			{
				File::write($offlineFile, $fileContents);
			}
			catch (\Exception $e)
			{
				// Swallow.
			}
		}

		$htaccess = $this->getHtaccess($exemptIp);

		try
		{
			return File::write($htaccessFilePath, $htaccess);
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	/**
	 * Puts the site back on-line
	 *
	 * @return  bool  True on success
	 */
	public function putOnline(): bool
	{
		if (!$this->isOffline())
		{
			return false;
		}

		$htaccessPath    = $this->getPublicFolder() . '/.htaccess';
		$oldHtaccessPath = $this->getPublicFolder() . '/.htaccess.eom';

		try
		{
			if (@file_exists($htaccessPath) && !File::delete($htaccessPath))
			{
				return false;
			}
		}
		catch (\Exception $e)
		{
			return false;
		}

		if (!@file_exists($oldHtaccessPath))
		{
			return true;
		}

		$fileData = @file_get_contents($oldHtaccessPath) ?: '';
		// Removes the "## EOMBAK - Do not remove this line or this file" line
		$fileData = substr($fileData, 49);

		try
		{
			$isWritten = File::write($htaccessPath, $fileData);
		}
		catch (\Exception $e)
		{
			$isWritten = false;
		}

		if ($isWritten)
		{
			try
			{
				$isDeleted = File::delete($oldHtaccessPath);
			}
			catch (\Exception $e)
			{
				return false;
			}

			return $isDeleted;
		}

		return false;
	}

	/**
	 * Returns the contents of the stealthy .htaccess file
	 *
	 * @param   string|null  $exemptIp  IP address exempted from the block; NULL to use the caller's own IP address.
	 *
	 * @return string
	 */
	public function getHtaccess(?string $exemptIp = null)
	{
		return $this->buildHtaccess($this->getSiteBasePath(), $exemptIp ?? IpHelper::getIp());
	}

	/**
	 * Creates the contents of the stealthy .htaccess file.
	 *
	 * This is the pure, side effect free part of getHtaccess(): everything it needs to know about the site has already
	 * been resolved by its caller.
	 *
	 * @param   string       $basePath  URL path the site's public root is served from; '' when it is the domain root.
	 * @param   string|null  $exemptIp  IP address exempted from the block. Empty, NULL, or anything which is not a
	 *                                  valid IP address means nobody is exempt.
	 *
	 * @return  string
	 *
	 * @since   7.9.2
	 */
	protected function buildHtaccess(string $basePath, ?string $exemptIp): string
	{
		$basePath = rtrim($basePath, '/');
		$basePath = ($basePath === '' || $basePath[0] === '/') ? $basePath : '/' . $basePath;

		$lines = [
			'RewriteEngine On',
			'RewriteBase ' . ($basePath ?: '/'),
		];

		// Exempt the caller's own IP address from the block, so they do not lock themselves out.
		$exemptIp = IpExpressionValidator::normalise($exemptIp);

		/**
		 * If we do not know an IP address to exempt — which is always the case under CLI, where there is no
		 * REMOTE_ADDR — we must NOT emit the RewriteCond at all. A condition with an empty pattern matches
		 * everything, so its negation is never satisfied, the RewriteRule never fires, and the site silently stays
		 * on-line. No exemption at all is the safe failure mode: the block does apply, to everyone.
		 */
		if ($exemptIp !== '' && filter_var($exemptIp, FILTER_VALIDATE_IP) !== false)
		{
			// Anchored, so exempting 1.2.3.4 does not also exempt 1.2.3.40 through 1.2.3.49.
			$lines[] = 'RewriteCond %{REMOTE_ADDR}        !^' . preg_quote($exemptIp) . '$';
		}

		$lines[] = 'RewriteCond %{REQUEST_URI}        !offline\.html';
		$lines[] = 'RewriteCond %{REQUEST_URI}        !(\.png|\.jpg|\.gif|\.jpeg|\.bmp|\.swf|\.css|\.js)$';

		/**
		 * The substitution is an absolute URL path, not a relative one. Apache resolves a relative substitution in a
		 * per-directory (.htaccess) context against the filesystem directory when it cannot work out the URL prefix,
		 * which turns an external redirect into Location: /var/www/html/offline.html — broken, and a disclosure of
		 * the site's absolute filesystem path.
		 */
		$lines[] = 'RewriteRule (.*)                  ' . $basePath . '/offline.html    [R=307,L]';

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Returns the URL path the site's public root is served from, e.g. '' for a site at the root of its domain, or
	 * '/mysite' for a site installed in a subdirectory.
	 *
	 * @return  string
	 *
	 * @since   7.9.2
	 */
	private function getSiteBasePath(): string
	{
		// A RewriteBase in the site's own .htaccess is authoritative: the site's owner told us where the site lives.
		$rewriteBase = $this->sniffRewriteBase();

		if ($rewriteBase !== null)
		{
			return $rewriteBase;
		}

		// The configured Site URL, if any. This is the only source of truth available under CLI.
		try
		{
			$liveSite = trim(Factory::getApplication()->get('live_site') ?: '');
		}
		catch (\Throwable $e)
		{
			$liveSite = '';
		}

		if ($liveSite !== '')
		{
			return rtrim(parse_url($liveSite, PHP_URL_PATH) ?: '', '/');
		}

		/**
		 * Fall back to the URL of the request we are serving. Only under a web server: in a CLI context Uri::base()
		 * has nothing to go by but the path to the CLI script, i.e. a filesystem path, which is worse than useless
		 * here. We assume the site is at the root of its domain instead.
		 */
		if (strpos(PHP_SAPI, 'cli') !== false)
		{
			return '';
		}

		$basePath = rtrim(Uri::base(true), '/');

		// Uri::base() is the URL of the application which is running, so strip the well-known application folders.
		foreach (['/administrator', '/api', '/installation'] as $suffix)
		{
			if (substr($basePath, -strlen($suffix)) === $suffix)
			{
				return substr($basePath, 0, -strlen($suffix));
			}
		}

		return $basePath;
	}

	/**
	 * Returns the URL path of the RewriteBase line in the site's .htaccess, or NULL if there is none.
	 *
	 * @return  string|null
	 *
	 * @since   7.9.2
	 */
	private function sniffRewriteBase(): ?string
	{
		$sourceFile = $this->getPublicFolder() . '/.htaccess.eom';
		$sourceFile = @file_exists($sourceFile) ? $sourceFile : $this->getPublicFolder() . '/.htaccess';

		if (!@file_exists($sourceFile))
		{
			return null;
		}

		foreach (@file($sourceFile) ?: [] as $line)
		{
			$line = trim($line);

			if (substr($line, 0, 12) !== 'RewriteBase ')
			{
				continue;
			}

			$base = rtrim(trim(substr($line, 12)), '/');

			return ($base === '' || $base[0] === '/') ? $base : '/' . $base;
		}

		return null;
	}

	/**
	 * Returns the absolute filesystem folder to the site's public web root directory.
	 *
	 * Joomla! 5 can be installed with a custom public folder. In this case the .htaccess file needs to be written in
	 * the public directory.
	 *
	 * @return  string
	 *
	 * @since   7.4.3
	 */
	private function getPublicFolder(): string
	{
		return !defined('JPATH_PUBLIC') ? JPATH_ROOT : JPATH_PUBLIC;

	}

}