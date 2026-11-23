<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Joomla\CMS\Application\WebApplication;
use ReflectionProperty;
use Throwable;

/**
 * Scans every file uploaded during the current request and blocks it when it looks like it could
 * be used to execute code on the server.
 *
 * This is the modern reincarnation of the pre-Joomla 4 "UploadShield" feature. It has four
 * independently toggleable checks, all enabled by default:
 *
 * - uploadshield_phpext:       the uploaded file's own extension is a PHP file extension.
 * - uploadshield_multiext:     any dot-separated segment of the file name is a PHP file extension
 *                              (e.g. evil.php.jpg).
 * - uploadshield_htaccess:     an uploaded .htaccess file contains a SetHandler/AddHandler
 *                              directive.
 * - uploadshield_phpcontent:   the file's contents contain what looks like PHP code, regardless of
 *                              its name.
 *
 * The feature never runs for logged in Super Users, so that they can legitimately upload template
 * overrides (which may well be .php files) through the back-end.
 */
class UploadShield extends Base
{
	/**
	 * The cap, in bytes, on how much of an uploaded file we will ever read into memory.
	 *
	 * @var  int
	 */
	private const READ_CAP = 1048576;

	/**
	 * The size, in bytes, of the chunks used to scan a file for a PHP open tag.
	 *
	 * @var  int
	 */
	private const CHUNK_SIZE = 131072;

	/**
	 * The base list of PHP file extensions. Digit-suffixed variants (php4, phps2, ...) are also
	 * recognised by isPhpExtension().
	 *
	 * @var  string[]
	 */
	private const PHP_EXTENSIONS = ['php', 'phps', 'phtml', 'inc'];

	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		// There are no file uploads to inspect outside of a web application (e.g. the CLI console).
		if (!($this->app instanceof WebApplication))
		{
			return false;
		}

		if ($this->skipFiltering)
		{
			return false;
		}

		if ($this->wafParams->getValue('uploadshield', 1) != 1)
		{
			return false;
		}

		// If every sub-check is disabled there is nothing for this feature to do.
		if (
			($this->wafParams->getValue('uploadshield_htaccess', 1) != 1)
			&& ($this->wafParams->getValue('uploadshield_multiext', 1) != 1)
			&& ($this->wafParams->getValue('uploadshield_phpext', 1) != 1)
			&& ($this->wafParams->getValue('uploadshield_phpcontent', 1) != 1)
		)
		{
			return false;
		}

		return true;
	}

	/**
	 * Scans all uploaded files for the four UploadShield checks and blocks the request on the first
	 * match.
	 */
	public function onAfterInitialise(): void
	{
		/**
		 * Never block a logged in Super User; they may legitimately need to upload a .php override.
		 *
		 * This deliberately lives here, not in isEnabled(). Features are constructed — and isEnabled()
		 * evaluated — while the system plugins are being imported, at which point the application may
		 * not have loaded its identity yet. By the time onAfterInitialise() runs it always has.
		 */
		$user = $this->app->getIdentity();

		if ($user !== null && !$user->guest && $user->authorise('core.admin'))
		{
			return;
		}

		$files = $this->getUploadedFiles();

		if (empty($files))
		{
			return;
		}

		$checkPhpExt     = $this->wafParams->getValue('uploadshield_phpext', 1) == 1;
		$checkMultiExt   = $this->wafParams->getValue('uploadshield_multiext', 1) == 1;
		$checkHtaccess   = $this->wafParams->getValue('uploadshield_htaccess', 1) == 1;
		$checkPhpContent = $this->wafParams->getValue('uploadshield_phpcontent', 1) == 1;

		foreach ($files as $file)
		{
			$name    = $file['name'];
			$tmpName = $file['tmp_name'];

			if ($checkPhpExt && self::hasPhpExtension($name))
			{
				$this->blockUpload($name, $tmpName, 'the file extension is a PHP file extension');

				return;
			}

			if ($checkMultiExt && self::hasPhpMultiExtension($name))
			{
				$this->blockUpload($name, $tmpName, 'the file name contains a PHP file extension as one of its dot-separated segments');

				return;
			}

			$isHtaccessFile = strtolower(pathinfo($name, PATHINFO_BASENAME)) === '.htaccess';

			if ($checkHtaccess && $isHtaccessFile)
			{
				$contents = $this->readHeadOfFile($tmpName);

				if ($contents !== null && self::isMaliciousHtaccess($contents))
				{
					$this->blockUpload($name, $tmpName, 'the uploaded .htaccess file contains a SetHandler or AddHandler directive');

					return;
				}
			}

			if ($checkPhpContent)
			{
				$snippet = $this->readPhpTagSnippet($tmpName);

				if ($snippet !== null && self::containsPhpCode($snippet))
				{
					$this->blockUpload($name, $tmpName, 'the file contains what looks like PHP code');

					return;
				}
			}
		}
	}

	/**
	 * Logs and blocks the current request because of a malicious upload. Never includes the
	 * uploaded file's contents, only its (client-supplied) name and its server-side temporary name.
	 *
	 * @param   string  $name     The intended (client-supplied) file name.
	 * @param   string  $tmpName  The server-side temporary file name.
	 * @param   string  $reason   A human-readable description of which check fired.
	 *
	 * @return  void
	 */
	private function blockUpload(string $name, string $tmpName, string $reason): void
	{
		$extraInfo = "Intended file name : $name\n";
		$extraInfo .= "Temporary file name: $tmpName\n";
		$extraInfo .= "Block reason: $reason\n";

		$this->exceptionsHandler->blockRequest('uploadshield', null, $extraInfo);
	}

	/**
	 * Collects a flat, de-duplicated list of every file uploaded in the current request, from both
	 * the PHP $_FILES superglobal and Joomla's own Input\Files object (which may have been
	 * populated slightly differently, e.g. under certain SAPIs or test harnesses).
	 *
	 * @return  array  A list of ['name' => string, 'tmp_name' => string] descriptors.
	 */
	private function getUploadedFiles(): array
	{
		$descriptors = [];

		if (!empty($_FILES) && is_array($_FILES))
		{
			$this->collectDescriptors($_FILES, $descriptors);
		}

		$input = $this->input->files;
		$ref   = new ReflectionProperty($input, 'data');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$ref->setAccessible(true);
		}

		$allVars = $ref->getValue($input);

		if (!empty($allVars) && is_array($allVars))
		{
			$this->collectDescriptors($allVars, $descriptors);
		}

		if (empty($descriptors))
		{
			return [];
		}

		$seen   = [];
		$result = [];

		foreach ($descriptors as $descriptor)
		{
			$key = $descriptor['tmp_name'] . '|' . $descriptor['name'];

			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$result[]   = $descriptor;
		}

		return $result;
	}

	/**
	 * Recursively walks an arbitrarily nested $_FILES-shaped array (or Joomla Input\Files data)
	 * looking for file descriptors, appending normalised ['name' => ..., 'tmp_name' => ...] entries
	 * to $descriptors by reference.
	 *
	 * @param   array  $node         The (sub-)array to inspect.
	 * @param   array  $descriptors  The flat list of normalised descriptors, built up by reference.
	 *
	 * @return  void
	 */
	private function collectDescriptors(array $node, array &$descriptors): void
	{
		// A file descriptor (single or multi-file) always has a 'tmp_name' key.
		if (array_key_exists('tmp_name', $node))
		{
			$this->collectFromDescriptor($node, $descriptors);

			return;
		}

		// Otherwise keep recursing into every array-valued member (e.g. jform[images] style input).
		foreach ($node as $value)
		{
			if (is_array($value))
			{
				$this->collectDescriptors($value, $descriptors);
			}
		}
	}

	/**
	 * Normalises a single file descriptor, which may describe one file (scalar 'name'/'tmp_name')
	 * or several files uploaded through the same <input type="file" multiple> / array-style field
	 * (parallel 'name'/'tmp_name'/'error' arrays, possibly nested further still).
	 *
	 * @param   array  $descriptor   The descriptor to normalise.
	 * @param   array  $descriptors  The flat list of normalised descriptors, built up by reference.
	 *
	 * @return  void
	 */
	private function collectFromDescriptor(array $descriptor, array &$descriptors): void
	{
		$tmpNames = $descriptor['tmp_name'] ?? null;
		$names    = $descriptor['name'] ?? null;
		$errors   = $descriptor['error'] ?? null;

		if (is_array($tmpNames))
		{
			foreach ($tmpNames as $key => $tmpName)
			{
				$name  = is_array($names) ? ($names[$key] ?? null) : null;
				$error = is_array($errors) ? ($errors[$key] ?? null) : $errors;

				if (is_array($tmpName))
				{
					// Still nested (e.g. jform[images][0][...]): recurse one level further.
					$this->collectFromDescriptor([
						'tmp_name' => $tmpName,
						'name'     => $name,
						'error'    => $error,
					], $descriptors);

					continue;
				}

				$this->addDescriptor($name, $tmpName, $error, $descriptors);
			}

			return;
		}

		$this->addDescriptor($names, $tmpNames, $errors, $descriptors);
	}

	/**
	 * Validates and appends a single normalised descriptor to the list, skipping entries with an
	 * upload error, or an empty/non-scalar name or temporary file name.
	 *
	 * @param   mixed  $name         The intended (client-supplied) file name.
	 * @param   mixed  $tmpName      The server-side temporary file name.
	 * @param   mixed  $error        The upload error code for this entry, if any.
	 * @param   array  $descriptors  The flat list of normalised descriptors, built up by reference.
	 *
	 * @return  void
	 */
	private function addDescriptor($name, $tmpName, $error, array &$descriptors): void
	{
		// Nested array leftovers we could not resolve down to a scalar; nothing sensible to check.
		if (is_array($name) || is_array($tmpName))
		{
			return;
		}

		if (is_array($error))
		{
			$error = null;
		}

		if ($error !== null && (int) $error !== UPLOAD_ERR_OK)
		{
			return;
		}

		$name    = (string) $name;
		$tmpName = (string) $tmpName;

		if ($name === '' || $tmpName === '')
		{
			return;
		}

		$descriptors[] = ['name' => $name, 'tmp_name' => $tmpName];
	}

	/**
	 * Reads at most the first READ_CAP bytes of a file. Used for the (bounded) .htaccess content
	 * check, where the whole file is small by definition.
	 *
	 * @param   string  $tmpName  The path to read.
	 *
	 * @return  string|null  The file's leading bytes, or NULL if it could not be read.
	 */
	private function readHeadOfFile(string $tmpName): ?string
	{
		$contents = @file_get_contents($tmpName, false, null, 0, self::READ_CAP);

		return $contents === false ? null : $contents;
	}

	/**
	 * Efficiently locates the first "<?php" tag (case-insensitive) inside a (potentially huge)
	 * uploaded file without ever loading the whole file into memory, then returns up to
	 * READ_CAP + 2 bytes starting at that tag for containsPhpCode() to analyse.
	 *
	 * @param   string  $tmpName  The path to scan.
	 *
	 * @return  string|null  The snippet starting at the tag, or NULL if no tag was found / the file
	 *                        could not be read.
	 */
	private function readPhpTagSnippet(string $tmpName): ?string
	{
		$fp = @fopen($tmpName, 'rb');

		if ($fp === false)
		{
			return null;
		}

		$carry   = '';
		$offset  = 0;
		$foundAt = null;

		while (!feof($fp))
		{
			$chunk = @fread($fp, self::CHUNK_SIZE);

			if ($chunk === false || $chunk === '')
			{
				break;
			}

			$buffer = $carry . $chunk;
			$pos    = stripos($buffer, '<?php');

			if ($pos !== false)
			{
				$foundAt = $offset - strlen($carry) + $pos;

				break;
			}

			// Carry the last 4 bytes over: "<?php" is 5 bytes long, so a 4-byte overlap guarantees
			// a tag split across a chunk boundary is still found on the next iteration.
			$carry  = substr($chunk, -4);
			$offset += strlen($chunk);
		}

		if ($foundAt === null)
		{
			fclose($fp);

			return null;
		}

		if (fseek($fp, $foundAt) !== 0)
		{
			fclose($fp);

			return null;
		}

		$snippet = fread($fp, self::READ_CAP + 2);

		fclose($fp);

		return $snippet === false ? null : $snippet;
	}

	/**
	 * Is the given file extension a PHP file extension?
	 *
	 * Case-insensitive. Matches the base list of PHP extensions (php, phps, phtml, inc) as well as
	 * their digit-suffixed variants (php4, php7, phps2, phtml3, inc9, ...).
	 *
	 * @param   string  $extension  The file extension to check, without the leading dot.
	 *
	 * @return  bool
	 */
	public static function isPhpExtension(string $extension): bool
	{
		$extension = strtolower($extension);
		$pattern   = '/^(?:' . implode('|', self::PHP_EXTENSIONS) . ')\d*$/';

		return (bool) preg_match($pattern, $extension);
	}

	/**
	 * Does the file's own (final) extension look like a PHP file extension?
	 *
	 * Only the final dot-separated segment of the basename is considered, e.g. for "evil.jpg.php"
	 * this checks "php", not "jpg".
	 *
	 * @param   string  $fileName  The (client-supplied) file name.
	 *
	 * @return  bool
	 */
	public static function hasPhpExtension(string $fileName): bool
	{
		$basename  = pathinfo($fileName, PATHINFO_BASENAME);
		$extension = pathinfo($basename, PATHINFO_EXTENSION);

		return self::isPhpExtension($extension);
	}

	/**
	 * Does any dot-separated extension segment of the file name look like a PHP file extension?
	 *
	 * This mirrors Filter::fileHasScannedExtension() with double-extension scanning enabled, but
	 * against the fixed PHP extension list rather than the user-configured scanner extensions. It
	 * deliberately subsumes hasPhpExtension(): e.g. "evil.php.jpg" and "evil.jpg.php" both match.
	 *
	 * @param   string  $fileName  The (client-supplied) file name.
	 *
	 * @return  bool
	 *
	 * @see  \Akeeba\Component\AdminTools\Administrator\Scanner\Filter::fileHasScannedExtension()
	 */
	public static function hasPhpMultiExtension(string $fileName): bool
	{
		$basename = pathinfo($fileName, PATHINFO_BASENAME);
		$segments = explode('.', $basename);

		// Drop the file name itself, keep only the extension segments.
		array_shift($segments);

		foreach ($segments as $segment)
		{
			if (self::isPhpExtension($segment))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Does the content of an uploaded .htaccess file contain a directive commonly used to make
	 * Apache execute an otherwise inert file (e.g. an image) as PHP code?
	 *
	 * @param   string  $contents  The (bounded) contents of the .htaccess file.
	 *
	 * @return  bool
	 */
	public static function isMaliciousHtaccess(string $contents): bool
	{
		return (preg_match('/SetHandler\s+/i', $contents) === 1)
			|| (preg_match('/AddHandler\s+/i', $contents) === 1);
	}

	/**
	 * Does the given string contain what looks like real, executable PHP code?
	 *
	 * Finds the first "<?php" tag (case-insensitive) and inspects the snippet starting there:
	 *
	 * - If the tokenizer extension is available and the snippet is complete (i.e. it was not cut
	 *   short by our 1 MiB read cap), the snippet is fed to token_get_all(). A snippet that fails to
	 *   tokenise is not valid PHP and is not considered a match. A snippet that tokenises is only
	 *   considered a match if the lexer actually recognised an opening tag (T_OPEN_TAG) somewhere in
	 *   it: PHP itself treats a literal "<?php" that is NOT followed by whitespace (e.g.
	 *   "<?phpsomething") as inline HTML, not as the start of a PHP code block, so such a tokenising
	 *   -but-inert snippet must not be treated as code.
	 * - Otherwise (no tokenizer, or the snippet had to be truncated at the cap) we fall back to a
	 *   simple heuristic: the tag is considered real PHP when the character immediately following
	 *   it is a space, tab, CR or LF.
	 *
	 * @param   string  $contents  The string to inspect (may be the whole file, or just a snippet
	 *                             starting at, or shortly before, a "<?php" tag).
	 *
	 * @return  bool
	 */
	public static function containsPhpCode(string $contents): bool
	{
		$start = stripos($contents, '<?php');

		if ($start === false)
		{
			return false;
		}

		$end = strpos($contents, '?>', $start);

		if (($end !== false) && (($end - $start) <= self::READ_CAP))
		{
			$snippet  = substr($contents, $start, $end - $start + 2);
			$complete = true;
		}
		elseif ((strlen($contents) - $start) <= self::READ_CAP)
		{
			$snippet  = substr($contents, $start);
			$complete = true;
		}
		else
		{
			$snippet  = substr($contents, $start, self::READ_CAP);
			$complete = false;
		}

		if ($complete && function_exists('token_get_all') && defined('TOKEN_PARSE'))
		{
			try
			{
				$tokens = @token_get_all($snippet, TOKEN_PARSE);
			}
			catch (Throwable $e)
			{
				// It does not tokenise, therefore it is not (valid) PHP code.
				return false;
			}

			foreach ($tokens as $token)
			{
				if (is_array($token) && ($token[0] === T_OPEN_TAG))
				{
					return true;
				}
			}

			// It tokenised, but only as inline HTML: the "<?php" we found was never recognised by
			// the lexer as an opening tag (e.g. it was not followed by whitespace).
			return false;
		}

		// No tokenizer available, or the snippet was truncated at the cap: fall back to checking
		// whether the tag is immediately followed by whitespace.
		$char = substr($contents, $start + 5, 1);

		if ($char === '')
		{
			return false;
		}

		return in_array($char, [' ', "\t", "\r", "\n"], true);
	}
}
