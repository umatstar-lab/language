<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Task\AdminTools\Extension\Traits;

defined('_JEXEC') or die;

use stdClass;

/**
 * Normalisation of the parameters Joomla passes to a scheduled task routine.
 *
 * Joomla JSON-decodes the `#__scheduler_tasks`.`params` column into a stdClass and hands it to
 * `onExecuteTask` as-is. Nothing whatsoever guarantees the shape of any individual parameter: a
 * property can be missing, null, a string, a number, an array or another object, depending on what
 * ends up in the database — a hand-edited task, a migrated or restored site, a third party writing
 * tasks programmatically, or simply a form field which has changed type between versions.
 *
 * A routine reading such a parameter without a type guard therefore risks a PHP warning (an object
 * or array coerced to an integer becomes 1, which is rarely a sane value for a time limit or a
 * record count), or a thrown exception when the value is passed on to something which expects a
 * string. Read every parameter through the methods in this trait instead of reading the property
 * directly.
 *
 * @since 7.9.2
 */
trait TaskParametersTrait
{
	/**
	 * Normalise the parameters container itself into an object.
	 *
	 * Anything which is not an object — null, an array, a scalar — is treated as "no parameters at
	 * all", i.e. every parameter falls back to its default.
	 *
	 * @param   mixed  $params  The task parameters supplied by Joomla
	 *
	 * @return  stdClass
	 * @since   7.9.2
	 */
	private function normaliseTaskParameters($params): stdClass
	{
		return is_object($params) ? $params : new stdClass();
	}

	/**
	 * Return a task parameter as an integer.
	 *
	 * @param   mixed   $params   The task parameters supplied by Joomla
	 * @param   string  $key      The name of the parameter to read
	 * @param   int     $default  The value to return if the parameter is missing or unusable
	 *
	 * @return  int
	 * @since   7.9.2
	 */
	private function getTaskParameterInteger($params, string $key, int $default = 0): int
	{
		return $this->taskParameterToInteger(
			$this->normaliseTaskParameters($params)->{$key} ?? null,
			$default
		);
	}

	/**
	 * Return a task parameter as a list of unique, positive integers, e.g. a list of record IDs.
	 *
	 * A single scalar is treated as a one–element list, which is what Joomla stores for a multiple
	 * selection field with exactly one item selected in some versions.
	 *
	 * @param   mixed   $params  The task parameters supplied by Joomla
	 * @param   string  $key     The name of the parameter to read
	 *
	 * @return  int[]
	 * @since   7.9.2
	 */
	private function getTaskParameterIntegerList($params, string $key): array
	{
		$values = $this->normaliseTaskParameters($params)->{$key} ?? [];

		if (!is_array($values))
		{
			$values = (is_int($values) || is_string($values)) ? [$values] : [];
		}

		$values = array_map(
			function ($value) {
				return $this->taskParameterToInteger($value, 0);
			},
			$values
		);

		return array_values(
			array_unique(
				array_filter(
					$values,
					function (int $value): bool {
						return $value > 0;
					}
				)
			)
		);
	}

	/**
	 * Return a task parameter as a string.
	 *
	 * Numbers are stringified — Joomla's form fields habitually store numeric values as either,
	 * depending on how the task was saved. Anything else, including arrays, objects and booleans,
	 * is unusable and falls back to the default.
	 *
	 * @param   mixed   $params   The task parameters supplied by Joomla
	 * @param   string  $key      The name of the parameter to read
	 * @param   string  $default  The value to return if the parameter is missing or unusable
	 *
	 * @return  string
	 * @since   7.9.2
	 */
	private function getTaskParameterString($params, string $key, string $default = ''): string
	{
		$value = $this->normaliseTaskParameters($params)->{$key} ?? null;

		if (is_string($value))
		{
			return $value;
		}

		if (is_int($value) || is_float($value))
		{
			return (string) $value;
		}

		return $default;
	}

	/**
	 * Return a task parameter as an HTTP(S) URL.
	 *
	 * The value must be a string with an http or https scheme. Everything else — a missing or empty
	 * value, an array, an object, or a URL with a scheme we will not send an HTTP request to — falls
	 * back to the default, so that the caller can short-circuit instead of handing an unusable value
	 * to an HTTP client which will only throw over it.
	 *
	 * Note that this deliberately does not use FILTER_VALIDATE_URL. That would also reject
	 * internationalised domain names and other perfectly retrievable URLs, turning a working
	 * configuration into a silent no-op.
	 *
	 * @param   mixed   $params   The task parameters supplied by Joomla
	 * @param   string  $key      The name of the parameter to read
	 * @param   string  $default  The value to return if the parameter is missing or unusable
	 *
	 * @return  string
	 * @since   7.9.2
	 */
	private function getTaskParameterUrl($params, string $key, string $default = ''): string
	{
		$value = $this->normaliseTaskParameters($params)->{$key} ?? null;

		if (!is_string($value))
		{
			return $default;
		}

		$value = trim($value);

		if ($value === '')
		{
			return $default;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);

		if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true))
		{
			return $default;
		}

		return $value;
	}

	/**
	 * Convert a single value to an integer without PHP's array/object–to–integer warnings.
	 *
	 * Only genuine integers and strings which consist of nothing but an optionally signed run of
	 * digits are accepted. Floats, booleans, arrays and objects are not: coercing them produces a
	 * plausible-looking number out of something which was never a number, which is precisely how an
	 * object ends up being a one second time limit.
	 *
	 * @param   mixed  $value    The raw parameter value
	 * @param   int    $default  The value to return if $value is not an integer
	 *
	 * @return  int
	 * @since   7.9.2
	 */
	private function taskParameterToInteger($value, int $default = 0): int
	{
		if (is_int($value))
		{
			return $value;
		}

		if (!is_string($value) || !preg_match('/^-?\d+$/D', trim($value)))
		{
			return $default;
		}

		return (int) $value;
	}
}
