<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use ReflectionProperty;

class DFIShield extends Base
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

		return ($this->wafParams->getValue('dfishield', 1) == 1);
	}

	/**
	 * Simple Direct Files Inclusion block.
	 */
	public function onAfterRoute(): void
	{
		$input = $this->input;

		$fallbackView = $input->getCmd('controller', '');
		$option       = $input->getCmd('option', '');
		$view         = $input->getCmd('view', $fallbackView);
		$layout       = $input->getCmd('layout', '');

		// Special case: JCE
		if (($option == 'com_jce') && ($view == 'editor') && ($layout == 'plugin'))
		{
			return;
		}

		$hashes = ['get', 'post'];

		foreach ($hashes as $hash)
		{
			$input = $this->input->$hash;
			$ref   = new ReflectionProperty($input, 'data');

			if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
			{
				$ref->setAccessible(true);
			}

			$allVars = $ref->getValue($input);

			if (empty($allVars))
			{
				continue;
			}

			if ($this->match_array_dfi($allVars))
			{
				$extraInfo = "Hash      : $hash\n";
				$extraInfo .= "Variables :\n";
				$extraInfo .= print_r($allVars, true);
				$extraInfo .= "\n";
				$this->exceptionsHandler->blockRequest('dfishield', null, $extraInfo);
			}
		}
	}

	/**
	 * Does any value in the array (nested arrays included) look like a direct file inclusion attempt?
	 *
	 * Every value is checked; the first hit ends the scan.
	 *
	 * @param   mixed  $array  The request variables
	 *
	 * @return  bool
	 */
	private function match_array_dfi($array): bool
	{
		if (!is_array($array))
		{
			return false;
		}

		foreach ($array as $key => $value)
		{
			if (!empty($this->exceptions) && in_array($key, $this->exceptions))
			{
				continue;
			}

			// A null byte in the key is a hit
			if (strstr($key, chr(0)))
			{
				return true;
			}

			// If there's no value, treat the key as a value
			if (empty($value))
			{
				$value = $key;
			}

			// Scan the value
			if (is_array($value))
			{
				if ($this->match_array_dfi($value))
				{
					return true;
				}

				continue;
			}

			if ($this->match_value_dfi($value))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Does a single (scalar) value look like a direct file inclusion attempt?
	 *
	 * @param   mixed  $value  The value to check
	 *
	 * @return  bool
	 */
	private function match_value_dfi($value): bool
	{
		// A null byte is a hit
		if (strstr($value, chr(0)))
		{
			return true;
		}

		// If the value starts with a /, ../ or [a-z]{1,2}:, check whether it names an existing file
		$value = str_replace('\\', '/', $value);

		// Only slashes, e.g. return=/, names the filesystem root which always exists. Not a hit.
		if (preg_match('#^/+$#', $value))
		{
			return false;
		}

		if (!preg_match('#^(/|\.\.|[a-z]{1,2}:/)#i', $value))
		{
			return false;
		}

		// A drive prefix cannot resolve off Windows; the path it points at is what matters.
		$bareValue = preg_replace('#^[a-z]{1,2}:/#i', '/', $value);

		// Fix 2.0.1: Check that the file exists
		if (@file_exists($value) || @file_exists($bareValue))
		{
			return true;
		}

		$sillyParts = explode('../', $bareValue);
		$realParts  = [];

		foreach ($sillyParts as $p)
		{
			if (!empty($p))
			{
				$realParts[] = $p;
			}
		}

		$path = implode('/', $realParts);

		return @file_exists($path);
	}
}
