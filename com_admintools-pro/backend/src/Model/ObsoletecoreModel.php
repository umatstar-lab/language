<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use DirectoryIterator;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseModel;
use PhpToken;
use RuntimeException;

/**
 * Removes obsolete Joomla! core files and directories.
 *
 * Joomla's post-update script, `administrator/components/com_admin/script.php`, knows which files and directories
 * each Joomla version made obsolete: its `deleteUnexistingFiles()` method carries a `$files` and a `$folders` array
 * of paths relative to the site's root. Joomla only ever acts on that list as a side effect of an update which runs
 * to completion. When the update script does not run at all, is interrupted, hits file ownership or permission
 * problems, or the site was restored on top of an older copy of itself, the obsolete files stay behind and can break
 * the site outright — Joomla's backwards compatibility plugin registers class aliases for classes it believes have
 * been deleted, and `class_alias()` is a fatal error when a class of that name has already been loaded from a file
 * which should not exist any more.
 *
 * This model extracts that list and removes whatever is still there.
 *
 * @since 7.9.2
 */
#[\AllowDynamicProperties]
class ObsoletecoreModel extends BaseModel
{
	/**
	 * Maximum amount of time to spend deleting files and directories (seconds).
	 *
	 * @var   float
	 * @since 7.9.2
	 */
	private float $timeoutThreshold = 1.0;

	/**
	 * Timestamp we started the current batch at.
	 *
	 * @var   float
	 * @since 7.9.2
	 */
	private float $startTime = 0.0;

	/**
	 * Work queue of items which exist and still have to be removed.
	 *
	 * Each entry is ['type' => 'file'|'folder', 'path' => site root relative path].
	 *
	 * @var   array
	 * @since 7.9.2
	 */
	private array $queue = [];

	/**
	 * Items which exist but which we could not remove.
	 *
	 * Same shape as the queue entries.
	 *
	 * @var   array
	 * @since 7.9.2
	 */
	private array $failures = [];

	/**
	 * Have we already built the work queue?
	 *
	 * @var   bool
	 * @since 7.9.2
	 */
	private bool $initialised = false;

	/**
	 * How many batches in a row the item at the head of the queue has failed to finish.
	 *
	 * @var   int
	 * @since 7.9.2
	 */
	private int $headRetries = 0;

	/**
	 * How many consecutive batches one directory may fail to finish before we give up on it.
	 *
	 * Removing a large directory legitimately spans several batches, so we have to allow retries. Without a ceiling,
	 * though, a directory we can never finish — say, one holding a single undeletable file past the point each batch
	 * runs out of time at — would keep the browser reloading the pop-up forever.
	 *
	 * @since 7.9.2
	 */
	private const MAX_HEAD_RETRIES = 100;

	/**
	 * Returns Joomla's list of obsolete files and directories.
	 *
	 * The information is extracted from the `deleteUnexistingFiles()` method of Joomla's post-update script. Both
	 * extraction methods below are deliberately scoped to the body of that one method: `script.php` declares a
	 * `$files` variable in several other methods as well — `fixFilenameCasing()`, whose array is keyed by the old
	 * file name, and `fixFilesystemPermissions()` — and picking up the wrong one would give us a list of files
	 * which are supposed to be there.
	 *
	 * @return  array  ['files' => string[], 'folders' => string[]]
	 *
	 * @throws  RuntimeException  If the script file is missing, unreadable, or cannot be parsed.
	 * @since   7.9.2
	 */
	public function getObsoleteFilesList(): array
	{
		$scriptFile = JPATH_ADMINISTRATOR . '/components/com_admin/script.php';

		if (!@is_file($scriptFile) || !@is_readable($scriptFile))
		{
			throw new RuntimeException(Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_READ_SCRIPT', $scriptFile));
		}

		$source = @file_get_contents($scriptFile);

		if ($source === false || $source === '')
		{
			throw new RuntimeException(Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_READ_SCRIPT', $scriptFile));
		}

		/**
		 * Feature detection, NOT a check on whether the "tokenizer" extension module is present: the tokenizer is
		 * very frequently compiled into PHP rather than loaded as a shared module, in which case asking about the
		 * module gives the wrong answer. \PhpToken requires PHP 8.0 or later; on PHP 7.4 we always take the
		 * fallback branch.
		 */
		if (class_exists('\PhpToken') && function_exists('token_get_all') && function_exists('token_name'))
		{
			return $this->parseWithTokenizer($source);
		}

		return $this->parseWithoutTokenizer($source);
	}

	/**
	 * Build the work queue: everything on Joomla's obsolete list which actually exists on this site.
	 *
	 * @return  void
	 *
	 * @throws  RuntimeException  If Joomla's obsolete list cannot be retrieved.
	 * @since   7.9.2
	 */
	public function initialise(): void
	{
		$lists = $this->getObsoleteFilesList();

		$this->queue       = [];
		$this->failures    = [];
		$this->headRetries = 0;

		/**
		 * Files first, then directories, each in the order Joomla lists them. Joomla's `$folders` array already
		 * lists nested directories deepest first, which lets a plain, non-recursive pass over it succeed; we must
		 * not sort or otherwise reorder it.
		 */
		foreach (($lists['files'] ?? []) as $path)
		{
			$absolutePath = $this->getSafeAbsolutePath($path);

			if ($absolutePath === null || !@is_file($absolutePath))
			{
				continue;
			}

			$this->queue[] = ['type' => 'file', 'path' => $path];
		}

		foreach (($lists['folders'] ?? []) as $path)
		{
			$absolutePath = $this->getSafeAbsolutePath($path);

			if ($absolutePath === null || !@is_dir($absolutePath))
			{
				continue;
			}

			$this->queue[] = ['type' => 'folder', 'path' => $path];
		}

		$this->initialised = true;
	}

	/**
	 * Process a batch of the work queue, for at most $timeoutThreshold seconds.
	 *
	 * @return  bool  True when the queue has been emptied, i.e. there is nothing left to do.
	 *
	 * @throws  RuntimeException  If the queue had to be built and Joomla's obsolete list cannot be retrieved.
	 * @since   7.9.2
	 */
	public function process(): bool
	{
		if (!$this->initialised)
		{
			$this->initialise();
		}

		$this->restartTimer();

		while (!empty($this->queue))
		{
			if ($this->isTimeout())
			{
				return false;
			}

			// Peek, don't pop: a directory removal which times out half way through has to stay in the queue.
			$item         = reset($this->queue);
			$absolutePath = $this->getSafeAbsolutePath($item['path']);

			if ($absolutePath === null)
			{
				array_shift($this->queue);

				continue;
			}

			if ($item['type'] === 'file')
			{
				array_shift($this->queue);

				if (@is_file($absolutePath) && !$this->removeFile($absolutePath))
				{
					$this->failures[] = $item;
				}

				continue;
			}

			if (!@is_dir($absolutePath))
			{
				array_shift($this->queue);

				continue;
			}

			/**
			 * Removing a directory can take longer than a single batch. When it does we leave it at the head of the
			 * queue and return; the next batch walks whatever is left of it. Since everything we already deleted
			 * stays deleted, repeated passes converge.
			 */
			if (!$this->removeDirectory($absolutePath))
			{
				if ($this->isTimeout() && ++$this->headRetries < self::MAX_HEAD_RETRIES)
				{
					return false;
				}

				array_shift($this->queue);

				$this->headRetries = 0;
				$this->failures[]  = $item;

				continue;
			}

			array_shift($this->queue);

			$this->headRetries = 0;
		}

		return true;
	}

	/**
	 * Items which exist but which could not be removed.
	 *
	 * @return  array  Each entry is ['type' => 'file'|'folder', 'path' => site root relative path].
	 * @since   7.9.2
	 */
	public function getFailures(): array
	{
		return $this->failures;
	}

	/**
	 * The items which exist and are still waiting to be processed.
	 *
	 * @return  array  Each entry is ['type' => 'file'|'folder', 'path' => site root relative path].
	 * @since   7.9.2
	 */
	public function getQueue(): array
	{
		return $this->queue;
	}

	/**
	 * Absolute path of a site root relative path from the obsolete list, or null if it is unsafe or gone.
	 *
	 * Exposed so callers which only report on the list — the CLI command's dry run mode, for one — can inspect the
	 * real paths without duplicating the safety checks.
	 *
	 * @param   string  $relativePath  The path, relative to the site's root.
	 *
	 * @return  string|null
	 * @since   7.9.2
	 */
	public function getAbsolutePath(string $relativePath): ?string
	{
		return $this->getSafeAbsolutePath($relativePath);
	}

	/**
	 * Sets the timeout threshold. The value cannot be less than 0.1 seconds.
	 *
	 * @param   float  $timeoutThreshold  The desired timeout threshold in seconds.
	 *
	 * @return  void
	 * @since   7.9.2
	 */
	public function setTimeoutThreshold(float $timeoutThreshold): void
	{
		$this->timeoutThreshold = max(0.1, $timeoutThreshold);
	}

	/**
	 * Extract the two arrays using PHP's tokenizer.
	 *
	 * @param   string  $source  The contents of Joomla's post-update script.
	 *
	 * @return  array  ['files' => string[], 'folders' => string[]]
	 *
	 * @throws  RuntimeException  If the method or either array cannot be located.
	 * @since   7.9.2
	 */
	private function parseWithTokenizer(string $source): array
	{
		try
		{
			$tokens = PhpToken::tokenize($source);
		}
		catch (\Throwable $e)
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_PARSE_SCRIPT'), 0, $e);
		}

		[$bodyStart, $bodyEnd] = $this->findMethodBody($tokens, 'deleteUnexistingFiles');

		return [
			'files'   => $this->extractArrayFromTokens($tokens, $bodyStart, $bodyEnd, 'files'),
			'folders' => $this->extractArrayFromTokens($tokens, $bodyStart, $bodyEnd, 'folders'),
		];
	}

	/**
	 * Locate the token range of a method's body.
	 *
	 * @param   PhpToken[]  $tokens      The tokenised source.
	 * @param   string      $methodName  The name of the method to locate.
	 *
	 * @return  array  [index of the token after the opening brace, index of the matching closing brace]
	 *
	 * @throws  RuntimeException  If the method cannot be located.
	 * @since   7.9.2
	 */
	private function findMethodBody(array $tokens, string $methodName): array
	{
		$count       = count($tokens);
		$nameIndex   = null;

		for ($i = 0; $i < $count; $i++)
		{
			if (!$tokens[$i]->is(T_FUNCTION))
			{
				continue;
			}

			$next = $this->nextMeaningfulToken($tokens, $i + 1, $count);

			if ($next !== null && $tokens[$next]->is(T_STRING) && $tokens[$next]->text === $methodName)
			{
				$nameIndex = $next;

				break;
			}
		}

		if ($nameIndex === null)
		{
			throw new RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_METHOD', $methodName)
			);
		}

		// Find the opening brace of the method body, skipping over the parameter list and any return type.
		$depth      = 0;
		$braceStart = null;

		for ($i = $nameIndex; $i < $count; $i++)
		{
			$text = $tokens[$i]->text;

			if ($text === '(')
			{
				$depth++;
			}
			elseif ($text === ')')
			{
				$depth--;
			}
			elseif ($text === '{' && $depth === 0)
			{
				$braceStart = $i;

				break;
			}
			elseif ($text === ';' && $depth === 0)
			{
				// An abstract or interface method declaration: there is no body to find.
				break;
			}
		}

		if ($braceStart === null)
		{
			throw new RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_METHOD', $methodName)
			);
		}

		// Walk forward to the matching closing brace.
		$depth = 0;

		for ($i = $braceStart; $i < $count; $i++)
		{
			$token = $tokens[$i];

			if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]))
			{
				$depth++;

				continue;
			}

			if ($token->text === '}')
			{
				$depth--;

				if ($depth === 0)
				{
					return [$braceStart + 1, $i];
				}
			}
		}

		throw new RuntimeException(Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_METHOD', $methodName));
	}

	/**
	 * Extract the string entries of the first `$<name> = [...]` assignment inside a token range.
	 *
	 * @param   PhpToken[]  $tokens        The tokenised source.
	 * @param   int         $start         First token index to consider.
	 * @param   int         $end           Last token index to consider.
	 * @param   string      $variableName  Variable name, without the leading dollar sign.
	 *
	 * @return  string[]
	 *
	 * @throws  RuntimeException  If the assignment cannot be located.
	 * @since   7.9.2
	 */
	private function extractArrayFromTokens(array $tokens, int $start, int $end, string $variableName): array
	{
		$openIndex = null;

		for ($i = $start; $i < $end; $i++)
		{
			if (!$tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$' . $variableName)
			{
				continue;
			}

			$assignment = $this->nextMeaningfulToken($tokens, $i + 1, $end);

			if ($assignment === null || $tokens[$assignment]->text !== '=')
			{
				continue;
			}

			$arrayStart = $this->nextMeaningfulToken($tokens, $assignment + 1, $end);

			if ($arrayStart === null)
			{
				continue;
			}

			if ($tokens[$arrayStart]->text === '[')
			{
				$openIndex = $arrayStart;

				break;
			}

			if ($tokens[$arrayStart]->is(T_ARRAY))
			{
				$parenthesis = $this->nextMeaningfulToken($tokens, $arrayStart + 1, $end);

				if ($parenthesis !== null && $tokens[$parenthesis]->text === '(')
				{
					$openIndex = $parenthesis;

					break;
				}
			}
		}

		if ($openIndex === null)
		{
			throw new RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_ARRAY', '$' . $variableName)
			);
		}

		$depth = 0;
		$ret   = [];

		/**
		 * Count both bracket kinds, so a nested `array(...)` inside a `[...]` (or the other way round) is tracked
		 * correctly. The `T_ARRAY` token itself never changes the depth; the parenthesis which follows it does.
		 */
		for ($i = $openIndex; $i < $end; $i++)
		{
			$token = $tokens[$i];

			if ($token->text === '[' || $token->text === '(')
			{
				$depth++;

				continue;
			}

			if ($token->text === ']' || $token->text === ')')
			{
				$depth--;

				if ($depth === 0)
				{
					// We can only get here on the bracket matching the one we opened with.
					return $ret;
				}

				continue;
			}

			if ($depth !== 1 || !$token->is(T_CONSTANT_ENCAPSED_STRING))
			{
				continue;
			}

			/**
			 * Skip array keys. `fixFilenameCasing()` uses a `'old' => 'new'` array; should we ever end up looking at
			 * an associative array we must take the values, never the keys.
			 */
			$following = $this->nextMeaningfulToken($tokens, $i + 1, $end);

			if ($following !== null && $tokens[$following]->is(T_DOUBLE_ARROW))
			{
				continue;
			}

			$value = $this->decodeStringLiteral($token->text);

			if ($value !== null && $value !== '')
			{
				$ret[] = $value;
			}
		}

		throw new RuntimeException(
			Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_ARRAY', '$' . $variableName)
		);
	}

	/**
	 * Index of the next token which is neither whitespace nor a comment, or null if there is none.
	 *
	 * @param   PhpToken[]  $tokens  The tokenised source.
	 * @param   int         $from    First token index to consider.
	 * @param   int         $limit   One past the last token index to consider.
	 *
	 * @return  int|null
	 * @since   7.9.2
	 */
	private function nextMeaningfulToken(array $tokens, int $from, int $limit): ?int
	{
		for ($i = $from; $i < $limit && $i < count($tokens); $i++)
		{
			if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]))
			{
				return $i;
			}
		}

		return null;
	}

	/**
	 * Decode a single or double quoted PHP string literal into its value.
	 *
	 * Only literals we can decode unambiguously are accepted. Anything with variable interpolation in it is not a
	 * `T_CONSTANT_ENCAPSED_STRING` in the first place, so we only have to deal with escape sequences here.
	 *
	 * @param   string  $literal  The literal, including its surrounding quotes.
	 *
	 * @return  string|null  The decoded value, or null if it could not be decoded.
	 * @since   7.9.2
	 */
	private function decodeStringLiteral(string $literal): ?string
	{
		if (strlen($literal) < 2)
		{
			return null;
		}

		$quote = substr($literal, 0, 1);
		$body  = substr($literal, 1, -1);

		if ($quote === "'" && substr($literal, -1) === "'")
		{
			return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
		}

		if ($quote !== '"' || substr($literal, -1) !== '"')
		{
			return null;
		}

		return preg_replace_callback(
			'/\\\\(n|r|t|v|e|f|\\\\|\\$|"|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
			function (array $matches): string {
				$escape = $matches[1];

				switch ($escape)
				{
					case 'n':
						return "\n";

					case 'r':
						return "\r";

					case 't':
						return "\t";

					case 'v':
						return "\v";

					case 'e':
						return "\x1B";

					case 'f':
						return "\f";

					case '\\':
					case '$':
					case '"':
						return $escape;
				}

				if ($escape[0] === 'x')
				{
					return chr(hexdec(substr($escape, 1)));
				}

				if ($escape[0] === 'u')
				{
					$codepoint = hexdec(substr($escape, 2, -1));

					return function_exists('mb_chr') ? (string) mb_chr($codepoint, 'UTF-8') : '';
				}

				return chr(octdec($escape));
			},
			$body
		);
	}

	/**
	 * Extract the two arrays without the tokenizer.
	 *
	 * We locate the start and end of each array definition line by line, verify that what we captured is nothing but
	 * string literals, commas, comments and whitespace, write it out to a temporary PHP file, include that file to
	 * bring the definitions into scope, and delete it.
	 *
	 * @param   string  $source  The contents of Joomla's post-update script.
	 *
	 * @return  array  ['files' => string[], 'folders' => string[]]
	 *
	 * @throws  RuntimeException  If the method or either array cannot be located, or the temporary file cannot be used.
	 * @since   7.9.2
	 */
	private function parseWithoutTokenizer(string $source): array
	{
		$lines      = preg_split('/\r\n|\r|\n/', $source);
		$lineCount  = count($lines);
		$startLine  = null;

		for ($i = 0; $i < $lineCount; $i++)
		{
			if (preg_match('/function\s+deleteUnexistingFiles\s*\(/i', $lines[$i]))
			{
				$startLine = $i;

				break;
			}
		}

		if ($startLine === null)
		{
			throw new RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_METHOD', 'deleteUnexistingFiles')
			);
		}

		$filesCode   = $this->captureArrayLines($lines, $startLine, 'files');
		$foldersCode = $this->captureArrayLines($lines, $startLine, 'folders');

		$tempDirectory = Factory::getApplication()->get('tmp_path');

		if (empty($tempDirectory) || !@is_dir($tempDirectory) || !@is_writable($tempDirectory))
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_OBSOLETECORE_ERR_TMPDIR_UNWRITABLE'));
		}

		$tempFile = rtrim($tempDirectory, '/\\') . '/admintools_obsoletecore_' . bin2hex(random_bytes(12)) . '.php';
		$contents = "<?php\ndefined('_JEXEC') or die;\n\n" . $filesCode . "\n\n" . $foldersCode
			. "\n\nreturn ['files' => \$files, 'folders' => \$folders];\n";

		if (@file_put_contents($tempFile, $contents) === false)
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_OBSOLETECORE_ERR_TMPDIR_UNWRITABLE'));
		}

		try
		{
			$result = include $tempFile;
		}
		catch (\Throwable $e)
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_PARSE_SCRIPT'), 0, $e);
		}
		finally
		{
			@unlink($tempFile);
		}

		if (!is_array($result) || !isset($result['files']) || !isset($result['folders']))
		{
			throw new RuntimeException(Text::_('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_PARSE_SCRIPT'));
		}

		return [
			'files'   => array_values(array_filter(array_map('strval', (array) $result['files']))),
			'folders' => array_values(array_filter(array_map('strval', (array) $result['folders']))),
		];
	}

	/**
	 * Capture the source lines of an array assignment, starting the search at a given line.
	 *
	 * @param   string[]  $lines         The source, split into lines.
	 * @param   int       $startLine     Index of the line to start searching at.
	 * @param   string    $variableName  Variable name, without the leading dollar sign.
	 *
	 * @return  string  The captured PHP code, a complete assignment statement.
	 *
	 * @throws  RuntimeException  If the assignment cannot be located, or contains anything but string literals.
	 * @since   7.9.2
	 */
	private function captureArrayLines(array $lines, int $startLine, string $variableName): string
	{
		$lineCount     = count($lines);
		$assignmentRow = null;
		$closer        = null;

		for ($i = $startLine; $i < $lineCount; $i++)
		{
			if (preg_match('/^\s*\$' . preg_quote($variableName, '/') . '\s*=\s*(\[|array\s*\()\s*$/i', $lines[$i], $matches))
			{
				$assignmentRow = $i;
				$closer        = (substr(ltrim($matches[1]), 0, 1) === '[') ? ']' : ')';

				break;
			}
		}

		if ($assignmentRow === null)
		{
			throw new RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_ARRAY', '$' . $variableName)
			);
		}

		$captured = [$lines[$assignmentRow]];

		for ($i = $assignmentRow + 1; $i < $lineCount; $i++)
		{
			$captured[] = $lines[$i];

			if (preg_match('/^\s*' . preg_quote($closer, '/') . '\s*;\s*$/', $lines[$i]))
			{
				$code = implode("\n", $captured);

				$this->assertOnlyStringLiterals($code, $variableName);

				return $code;
			}
		}

		throw new RuntimeException(
			Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_CANNOT_FIND_ARRAY', '$' . $variableName)
		);
	}

	/**
	 * Make sure a captured array assignment holds nothing but string literals before we include it.
	 *
	 * We are about to write this code out and execute it. If `script.php` has been tampered with, or our line based
	 * capture went wrong and swallowed something it should not have, this is what stops us from executing it.
	 *
	 * @param   string  $code          The captured PHP code.
	 * @param   string  $variableName  Variable name, without the leading dollar sign. Used for the error message.
	 *
	 * @return  void
	 *
	 * @throws  RuntimeException  If the code contains anything other than string literals, commas and comments.
	 * @since   7.9.2
	 */
	private function assertOnlyStringLiterals(string $code, string $variableName): void
	{
		$stripped = $code;

		// Remove the assignment itself, and the statement terminator.
		$stripped = preg_replace('/^\s*\$' . preg_quote($variableName, '/') . '\s*=\s*(\[|array\s*\()/i', '', $stripped, 1);
		$stripped = preg_replace('/(\]|\))\s*;\s*$/', '', $stripped, 1);

		// Remove comments.
		$stripped = preg_replace('#/\*.*?\*/#s', '', $stripped);
		$stripped = preg_replace('#(//|\#).*$#m', '', $stripped);

		// Remove single and double quoted string literals.
		$stripped = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'/s', '', $stripped);
		$stripped = preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '', $stripped);

		// Whatever is left must be commas, double arrows and whitespace. Anything else and we refuse to execute it.
		if (preg_match('/[^\s,]/', str_replace('=>', '', $stripped)))
		{
			throw new RuntimeException(
				Text::sprintf('COM_ADMINTOOLS_OBSOLETECORE_ERR_UNSAFE_ARRAY', '$' . $variableName)
			);
		}
	}

	/**
	 * Convert a site root relative path into a safe absolute path.
	 *
	 * @param   string  $relativePath  The path, relative to the site's root.
	 *
	 * @return  string|null  The absolute path, or null if the path is unsafe or does not exist.
	 * @since   7.9.2
	 */
	private function getSafeAbsolutePath(string $relativePath): ?string
	{
		$relativePath = trim($relativePath);

		if ($relativePath === '' || $relativePath === '/' || $relativePath === '\\')
		{
			return null;
		}

		// Refuse anything which tries to climb out of the site's root, or which is an absolute path of its own.
		if (strpos($relativePath, '..') !== false || strpos($relativePath, "\0") !== false)
		{
			return null;
		}

		$normalised   = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
		$absolutePath = JPATH_ROOT . $normalised;

		if (!@file_exists($absolutePath) && !@is_link($absolutePath))
		{
			return null;
		}

		/**
		 * Symbolic links are removed as links; we neither follow them nor compare their target against the site root,
		 * which would reject a perfectly legitimate link pointing outside the site.
		 */
		if (@is_link($absolutePath))
		{
			return $absolutePath;
		}

		$realPath = @realpath($absolutePath);
		$realRoot = @realpath(JPATH_ROOT);

		if ($realPath === false || $realRoot === false)
		{
			return null;
		}

		$realRoot = rtrim($realRoot, '/\\');

		if ($realPath !== $realRoot && strpos($realPath, $realRoot . DIRECTORY_SEPARATOR) !== 0)
		{
			return null;
		}

		// Never let the site's root itself, however we got there, be handed to the removal code.
		if ($realPath === $realRoot)
		{
			return null;
		}

		return $absolutePath;
	}

	/**
	 * Remove a single file.
	 *
	 * Deliberately does NOT use Joomla's `Joomla\Filesystem\File` / `Joomla\CMS\Filesystem\File`. Those classes are
	 * themselves part of the Joomla core, and are exactly the kind of thing which may have been left in an
	 * inconsistent state by the failed update this feature exists to clean up after — a class file left behind
	 * alongside the class alias which replaced it is a fatal error waiting to happen. Loading them to fix the problem
	 * they are a symptom of is a chicken and egg situation we avoid entirely by using plain PHP calls.
	 *
	 * @param   string  $absolutePath  Absolute path to the file or symbolic link.
	 *
	 * @return  bool  True on success.
	 * @since   7.9.2
	 */
	private function removeFile(string $absolutePath): bool
	{
		if (@is_link($absolutePath))
		{
			return @unlink($absolutePath) || @rmdir($absolutePath);
		}

		return @unlink($absolutePath);
	}

	/**
	 * Recursively remove a directory and everything in it.
	 *
	 * As with removeFile() above, this deliberately avoids Joomla's filesystem classes.
	 *
	 * @param   string  $absolutePath  Absolute path to the directory.
	 *
	 * @return  bool  True if the directory is gone. False if it is not, whether because we ran out of time or because
	 *                something could not be removed; check isTimeout() to tell the two apart.
	 * @since   7.9.2
	 */
	private function removeDirectory(string $absolutePath): bool
	{
		// A symbolic link to a directory is removed as a link. We must never recurse through it.
		if (@is_link($absolutePath))
		{
			return @unlink($absolutePath) || @rmdir($absolutePath);
		}

		if (!@is_dir($absolutePath))
		{
			return true;
		}

		try
		{
			foreach (new DirectoryIterator($absolutePath) as $item)
			{
				if ($item->isDot())
				{
					continue;
				}

				if ($this->isTimeout())
				{
					return false;
				}

				$itemPath = $item->getPathname();

				if ($item->isLink())
				{
					if (!@unlink($itemPath) && !@rmdir($itemPath))
					{
						return false;
					}

					continue;
				}

				if ($item->isDir())
				{
					if (!$this->removeDirectory($itemPath))
					{
						return false;
					}

					continue;
				}

				if (!@unlink($itemPath))
				{
					return false;
				}
			}
		}
		catch (\Exception $e)
		{
			return false;
		}

		return @rmdir($absolutePath);
	}

	/**
	 * Restart the batch timer.
	 *
	 * @return  void
	 * @since   7.9.2
	 */
	private function restartTimer(): void
	{
		$this->startTime = microtime(true);
	}

	/**
	 * Have we used up this batch's time allowance?
	 *
	 * Starts the timer if it has not been started yet. Without that, an unstarted timer reads as "started at the
	 * epoch", i.e. always expired, and the very first thing we tried to delete would be deferred forever.
	 *
	 * @return  bool
	 * @since   7.9.2
	 */
	private function isTimeout(): bool
	{
		if ($this->startTime <= 0.0)
		{
			$this->restartTimer();
		}

		return microtime(true) - $this->startTime > $this->timeoutThreshold;
	}
}
