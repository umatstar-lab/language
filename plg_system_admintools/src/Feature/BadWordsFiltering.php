<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Akeeba\Plugin\System\AdminTools\Utility\Cache;

class BadWordsFiltering extends Base
{
	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		if (!$this->app->isClient('site'))
		{
			return false;
		}

		if ($this->skipFiltering)
		{
			return false;
		}

		return ($this->wafParams->getValue('antispam', 0) == 1);
	}

	/**
	 * The simplest anti-spam solution imaginable. Just blocks a request if a prohibited word is found.
	 */
	public function onAfterRoute(): void
	{
		$badwords = Cache::getCache('badwords');

		if (empty($badwords))
		{
			return;
		}

		$hashes = ['get', 'post'];

		foreach ($hashes as $hash)
		{
			$input   = $this->input->$hash;
			$allVars = $input->getArray();

			if (empty($allVars))
			{
				continue;
			}

			foreach ($badwords as $word)
			{
				$regex = $this->buildBadWordRegex($word);

				if ($this->recursiveRegExMatch($regex, $allVars, true, [$this, 'preconditionValues']))
				{
					$extraInfo = "Hash      : $hash\n";
					$extraInfo .= "Variables :\n";
					$extraInfo .= print_r($allVars, true);
					$extraInfo .= "\n";
					$this->exceptionsHandler->blockRequest('antispam', null, $extraInfo);
				}
			}
		}
	}

	/**
	 * Build the matching regular expression for a single Bad Words list entry.
	 *
	 * Bad Words entries are, by design, regular expression fragments; they are interpolated between two word
	 * boundary anchors into a case-insensitive, Unicode-aware expression:
	 *
	 * ```
	 * #\b<ENTRY>\b#iu
	 * ```
	 *
	 * The only character this method touches is the pattern delimiter, `#`. An unescaped `#` in the entry would
	 * terminate the pattern early, making preg_match() fail with a PCRE compilation error — which would silently
	 * disable that entry, i.e. a filter bypass. A `#` which is *already* escaped in the entry is left alone, so
	 * that escaping is never applied twice.
	 *
	 * Everything else in the entry is passed through verbatim, on purpose. It is the responsibility of whoever
	 * enters the word to escape any other regular expression metacharacters they mean literally — which is what
	 * the "Escape regular expression" option of the Bad Words edit page does for them.
	 *
	 * @param   string  $word  The Bad Words list entry.
	 *
	 * @return  string  The regular expression to match request variables against.
	 * @since   7.9.2
	 */
	public function buildBadWordRegex(string $word): string
	{
		return '#\b' . $this->escapeRegexDelimiter($word) . '\b#iu';
	}

	/**
	 * Escape any unescaped occurrence of the `#` pattern delimiter in a regular expression fragment.
	 *
	 * A `#` is considered already escaped when it is preceded by an odd number of backslashes. Therefore `\#`
	 * (escaped delimiter) is left as-is, whereas `\\#` (escaped backslash, followed by a bare delimiter) becomes
	 * `\\\#`.
	 *
	 * @param   string  $word  The regular expression fragment.
	 *
	 * @return  string  The fragment, with every unescaped `#` escaped.
	 * @since   7.9.2
	 */
	private function escapeRegexDelimiter(string $word): string
	{
		// Matches a '#' preceded by an even number (zero included) of backslashes, capturing those backslashes.
		return preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)#/', '${1}\\\\#', $word);
	}

	/**
	 * Normalise input variables for this feature.
	 *
	 * This method decodes any URL-encoded values and any HTML entities back to plain UTF-8.
	 *
	 * @param   mixed  $value  The input value, which can be of any type.
	 *
	 * @return  mixed  The processed value.
	 * @since   7.8.2
	 */
	public function preconditionValues($value)
	{
		if (is_array($value))
		{
			return array_map([$this, 'preconditionValues'], $value);
		}

		if (!is_string($value))
		{
			return $value;
		}

		if (is_numeric($value))
		{
			return $value;
		}

		return html_entity_decode(urldecode($value), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
