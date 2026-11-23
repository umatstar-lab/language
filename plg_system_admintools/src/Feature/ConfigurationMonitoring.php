<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\AdminTools\Feature;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Helper\Extensions;
use Akeeba\Component\AdminTools\Administrator\Helper\EmailLanguage;
use Akeeba\Plugin\System\AdminTools\Feature\Mixin\SuperUsersTrait;
use Akeeba\Plugin\System\AdminTools\Utility\RescueUrl;
use Exception;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use Throwable;

/**
 * Monitors com_config changes and emails the user
 */
class ConfigurationMonitoring extends Base
{
	use SuperUsersTrait;

	/**
	 * Core Joomla form field types whose stored value is a plain, user-recognisable scalar and can therefore be shown
	 * verbatim in the diff. Any other type — a compound core type (subform, checkboxes, rules, …) or a custom type
	 * shipped by an extension (e.g. Akeeba Backup's Secret Word, which stores an encrypted value but displays the
	 * plaintext) — is reported as merely "changed", without exposing its raw stored value.
	 *
	 * @var   string[]
	 */
	private const DISPLAYABLE_FIELD_TYPES = [
		'accesslevel', 'aliastag', 'author', 'cachehandler', 'calendar', 'category', 'checkbox', 'chromestyle',
		'color', 'combo', 'componentlayout', 'contentlanguage', 'contenttype', 'databaseconnection', 'editor',
		'email', 'file', 'filelist', 'folderlist', 'frontendlanguage', 'headertag', 'hidden', 'imagelist', 'integer',
		'language', 'limitbox', 'list', 'media', 'menu', 'menuitem', 'modulelayout', 'moduleorder', 'moduleposition',
		'number', 'ordering', 'plugins', 'pluginstatus', 'predefinedlist', 'radio', 'radiobasic', 'range',
		'redirectstatus', 'sessionhandler', 'sql', 'status', 'tag', 'telephone', 'templatestyle', 'text', 'textarea',
		'time', 'timezone', 'transition', 'url', 'user', 'useractive', 'usergrouplist', 'userstate',
		'workflowcondition', 'workflowstage',
	];

	/**
	 * Which action should I take when a change is detected? 'email' for sending a warning email, 'block' for treating
	 * the request as a security exception.
	 *
	 * @var   string
	 */
	private $action = 'email';

	/**
	 * Should we monitor changes to Component Configuration?
	 *
	 * @var   bool
	 */
	private $enabledComponents = false;

	/**
	 * Should we monitor changes to Global Configuration?
	 *
	 * @var   bool
	 */
	private $enabledGlobal = false;

	/**
	 * Is this feature enabled?
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		$this->enabledGlobal     = $this->wafParams->getValue('configmonitor_global', 0) == 1;
		$this->enabledComponents = $this->wafParams->getValue('configmonitor_components', 0) == 1;
		$this->action            = $this->wafParams->getValue('configmonitor_action', 'email');

		return $this->enabledGlobal || $this->enabledComponents;
	}

	/**
	 * Blocks monitored configuration saves when the feature is set to the 'block' action.
	 *
	 * The 'email' action is handled later, in the before-save events (onApplicationBeforeSave /
	 * onExtensionBeforeSave), where we can read both the old and new values and report exactly what changed.
	 */
	public function onAfterInitialise(): void
	{
		// Only the 'block' action is handled this early. The 'email' action diffs the configuration in the
		// before-save events so it can report what changed.
		if ($this->action !== 'block')
		{
			return;
		}

		$input  = $this->input;
		$option = $input->getCmd('option', '');
		$task   = $input->getCmd('task', '');

		if ($option != 'com_config')
		{
			return;
		}

		$block = false;

		if ($this->enabledGlobal)
		{
			$block |= in_array(
				$task, [
					'config.save.application.apply',
					'config.save.application.save',
					'application.apply',
					'application.save',
				]
			);
		}

		if ($this->enabledComponents)
		{
			$block |= in_array(
				$task,
				['config.save.component.apply', 'config.save.component.save', 'component.apply', 'component.save']
			);
		}

		if (!$block)
		{
			return;
		}

		// Get the correct reason (is this Global Configuration or component configuration)?
		$id            = $input->getInt('id', 0);
		$component     = $input->getCmd('component', '');
		$componentName = $this->getComponentName($id, $component);

		$extraInfo = empty($componentName)
			? $this->getGlobalConfigAreaName()
			: $this->getComponentAreaName($componentName);

		$this->exceptionsHandler->blockRequest('configmonitor', null, null, $extraInfo);
	}

	/**
	 * Fires when the Global Configuration is about to be saved. Computes a diff of the changed options and emails it.
	 *
	 * The event only carries the NEW configuration Registry. The OLD values are still available from the on-disk
	 * JConfig / the live application configuration, because the configuration file has not been rewritten yet.
	 *
	 * @param   Registry|mixed  $config  The new configuration Registry.
	 *
	 * @return  void
	 */
	public function onApplicationBeforeSave($config): void
	{
		if (!$this->enabledGlobal || $this->action !== 'email')
		{
			return;
		}

		if (!$config instanceof Registry)
		{
			return;
		}

		$new = $config->toArray();

		// The on-disk JConfig still holds the previous values at before-save time.
		if (class_exists('JConfig'))
		{
			$old = (array) (new \JConfig());
		}
		else
		{
			$old = $this->app->getConfig()->toArray();
		}

		$diff = $this->structuredDiff($old, $new);

		if ($this->isDiffEmpty($diff))
		{
			return;
		}

		// The form (language-independent) is built once; the diff itself is rendered per-recipient in the recipient's
		// own language inside sendDiffEmail().
		$form = $this->getGlobalConfigForm();

		$this->sendDiffEmail($diff, $form, null, 'global', null);
	}

	/**
	 * Fires when an extension's options are about to be saved. For monitored component options this computes a diff of
	 * the changed options and emails it.
	 *
	 * The $table is already bound with the NEW params; the OLD params are read from the database before it is stored.
	 *
	 * @param   string|null  $context  The save context. We only act on 'com_config.component'.
	 * @param   object|null  $table    The #__extensions table row being saved.
	 * @param   bool         $isNew    Whether this is a new record (always false for options saves).
	 *
	 * @return  void
	 */
	public function onExtensionBeforeSave($context, $table, $isNew = false): void
	{
		if (!$this->enabledComponents || $this->action !== 'email')
		{
			return;
		}

		// Only the Options screen of a component (com_config) is relevant here.
		if ($context !== 'com_config.component' || !is_object($table))
		{
			return;
		}

		$element     = $table->element ?? '';
		$type        = $table->type ?? '';
		$extensionId = (int) ($table->extension_id ?? 0);

		if ($type !== 'component' || empty($element) || empty($extensionId))
		{
			return;
		}

		$new = $this->decodeParams($table->params ?? null);
		$old = $this->loadExtensionParams($extensionId);

		$diff = $this->structuredDiff($old, $new);

		if ($this->isDiffEmpty($diff))
		{
			return;
		}

		// Component option fields live directly under <config>/<fieldset> (ungrouped) and their params are stored flat
		// by field name, so no form field group applies. The form is language-independent and built once; the diff is
		// rendered per-recipient in the recipient's own language inside sendDiffEmail().
		$form = $this->getComponentConfigForm($element);

		$this->sendDiffEmail($diff, $form, null, 'component', $element);
	}

	/**
	 * Get the component name based either on the extension ID or (preferably) the component name from the request.
	 *
	 * @param   int     $id         An extension ID passed in the request. Must belong to a component.
	 * @param   string  $component  A component name passed in the request.
	 *
	 * @return  string  The component name, or an empty string if there is no corresponding component.
	 */
	private function getComponentName($id, $component)
	{
		$component = trim(strtolower($component));

		// We have a component name
		if (!empty($component))
		{
			return $component;
		}

		// We don't have a component name or ID. Nothing to do
		if (empty($id))
		{
			return '';
		}

		// We have an ID. Try to get the component name from the #__extensions table.
		$record = Extensions::getRecordById((int) $id);

		if (empty($record) || $record->type !== 'component')
		{
			return '';
		}

		return (string) $record->element;
	}

	/**
	 * Returns the human readable name for the Global Configuration area, using Joomla's own language strings.
	 *
	 * @return  string
	 */
	private function getGlobalConfigAreaName(): string
	{
		/**
		 * This name goes into the blocked request's log entry and 403 message, both of which belong to the site
		 * rather than to any recipient — so, unlike the email, it stays in the site's current language. It is still
		 * resolved through an isolated Language instance so we do not permanently load com_cpanel and com_config
		 * strings into the global one.
		 */
		return $this->resolveAreaName($this->getSiteLanguage(['com_config', 'com_cpanel']), 'global', null);
	}

	/**
	 * A Language instance for the site's current display language, for the strings which are NOT part of an email.
	 *
	 * @param   string[]  $extraExtensions  Extensions to load in addition to Admin Tools' own.
	 *
	 * @return  object  A Joomla\CMS\Language\Language instance.
	 */
	private function getSiteLanguage(array $extraExtensions)
	{
		$appLang = $this->app->getLanguage();
		$tag     = is_object($appLang) ? $appLang->getTag() : EmailLanguage::FALLBACK_TAG;

		return EmailLanguage::getLanguage($tag, $extraExtensions);
	}

	/**
	 * Returns the human readable name for a component, using Joomla's own language strings.
	 *
	 * @param   string  $componentName  The component element, e.g. com_content.
	 *
	 * @return  string
	 */
	private function getComponentAreaName(string $componentName): string
	{
		// As above: this is a site-facing string, resolved without touching the global language object.
		return $this->resolveAreaName(
			$this->getSiteLanguage([$componentName, $componentName . '.sys']),
			'component',
			$componentName
		);
	}

	/**
	 * Computes a structured diff between two key/value configuration arrays.
	 *
	 * @param   array  $old  The previous values.
	 * @param   array  $new  The new values.
	 *
	 * @return  array  ['added' => [...], 'removed' => [...], 'changed' => ['key' => [old, new]]]
	 */
	private function structuredDiff(array $old, array $new): array
	{
		$added   = array_diff_key($new, $old);
		$removed = array_diff_key($old, $new);
		$changed = [];

		foreach (array_intersect_key($new, $old) as $key => $newValue)
		{
			$oldValue = $old[$key];

			if ($this->valuesDiffer($oldValue, $newValue))
			{
				$changed[$key] = [$oldValue, $newValue];
			}
		}

		return [
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
		];
	}

	/**
	 * Determines whether two configuration values are meaningfully different.
	 *
	 * Scalars are compared loosely (as strings) so that type-only differences — e.g. the string "2" read from
	 * configuration.php versus the integer 2 produced by a form filter — are not reported as spurious changes. Arrays
	 * and objects are compared by their canonical JSON representation.
	 *
	 * @param   mixed  $old  The previous value.
	 * @param   mixed  $new  The new value.
	 *
	 * @return  bool
	 */
	private function valuesDiffer($old, $new): bool
	{
		$oldScalar = is_scalar($old) || is_null($old);
		$newScalar = is_scalar($new) || is_null($new);

		if ($oldScalar && $newScalar)
		{
			return (string) $old !== (string) $new;
		}

		return json_encode($old) !== json_encode($new);
	}

	/**
	 * Is a structured diff empty (i.e. nothing actually changed)?
	 *
	 * @param   array  $diff  The structured diff.
	 *
	 * @return  bool
	 */
	private function isDiffEmpty(array $diff): bool
	{
		return empty($diff['added']) && empty($diff['removed']) && empty($diff['changed']);
	}

	/**
	 * Renders a structured diff into a plain text and an HTML representation, resolving each key to the label Joomla
	 * uses in its interface and redacting sensitive values.
	 *
	 * All strings are resolved through the supplied Language instance, so the diff is rendered in the recipient's own
	 * language. Every section header and every row/table fragment is produced from an overridable language string, so a
	 * site can restyle the diff (e.g. to match its own HTML email template) purely through a language override, without
	 * touching any code.
	 *
	 * @param   array        $diff   The structured diff.
	 * @param   Form|null    $form   The form providing labels and field types, or null to fall back to raw keys.
	 * @param   string|null  $group  The form field group the keys belong to (e.g. 'params' for component options).
	 * @param   object       $lang   The Joomla Language instance to translate through (recipient's language).
	 *
	 * @return  array  [0 => plain text, 1 => HTML]
	 */
	private function renderDiff(array $diff, ?Form $form, ?string $group, $lang): array
	{
		$sections = [
			'changed' => [
				'header' => 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_CHANGED',
				'text'   => 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_TEXT_CHANGED',
			],
			'added'   => [
				'header' => 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_ADDED',
				'text'   => 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_TEXT_ADDED',
			],
			'removed' => [
				'header' => 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_REMOVED',
				'text'   => 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_TEXT_REMOVED',
			],
		];

		$lines    = [];
		$htmlRows = [];
		$redacted = $lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_REDACTED');

		foreach ($sections as $type => $keys)
		{
			$entries = $diff[$type] ?? [];

			if (empty($entries))
			{
				continue;
			}

			$header     = $lang->_($keys['header']);
			$lines[]    = $this->sprintfLang($lang, 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_TEXT_SECTION', $header);
			$htmlRows[] = $this->sprintfLang(
				$lang,
				'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_HTML_SECTION',
				htmlspecialchars($header, ENT_QUOTES, 'UTF-8')
			);

			foreach ($entries as $key => $value)
			{
				$label = $this->getFieldLabel($form, (string) $key, $group, $lang);

				if ($type === 'changed')
				{
					$oldValue = $value[0];
					$newValue = $value[1];
				}
				elseif ($type === 'added')
				{
					$oldValue = null;
					$newValue = $value;
				}
				else
				{
					$oldValue = $value;
					$newValue = null;
				}

				// A value is hidden either because it is sensitive (redacted) or because it cannot be rendered in a way
				// the user would recognise (a compound value, or a custom field type). In both cases we report the
				// change without exposing the raw stored value.
				$isSensitive   = $this->isSensitive($form, (string) $key, $group);
				$isDisplayable = $this->isValueDisplayable($form, (string) $key, $group, $oldValue, $newValue);

				if ($isSensitive || !$isDisplayable)
				{
					$marker = $isSensitive
						? $redacted
						: $lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_OPAQUE');

					$lines[]    = $this->sprintfLang($lang, 'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_TEXT_OPAQUE', $label, $marker);
					$htmlRows[] = $this->sprintfLang(
						$lang,
						'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_HTML_ROW_OPAQUE',
						htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
						htmlspecialchars($marker, ENT_QUOTES, 'UTF-8')
					);

					continue;
				}

				if ($type === 'changed')
				{
					$oldDisplay = $this->formatValue($oldValue, $lang);
					$newDisplay = $this->formatValue($newValue, $lang);

					$lines[] = $this->sprintfLang($lang, $keys['text'], $label, $oldDisplay, $newDisplay);
				}
				elseif ($type === 'added')
				{
					$oldDisplay = '';
					$newDisplay = $this->formatValue($newValue, $lang);

					$lines[] = $this->sprintfLang($lang, $keys['text'], $label, $newDisplay);
				}
				else
				{
					$oldDisplay = $this->formatValue($oldValue, $lang);
					$newDisplay = '';

					$lines[] = $this->sprintfLang($lang, $keys['text'], $label, $oldDisplay);
				}

				$htmlRows[] = $this->sprintfLang(
					$lang,
					'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_HTML_ROW',
					htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($oldDisplay, ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($newDisplay, ENT_QUOTES, 'UTF-8')
				);
			}
		}

		$html = empty($htmlRows)
			? ''
			: $this->sprintfLang(
				$lang,
				'COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_HTML_TABLE',
				htmlspecialchars($lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_OPTION'), ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_OLD'), ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_NEW'), ENT_QUOTES, 'UTF-8'),
				implode('', $htmlRows)
			);

		return [implode("\n", $lines), $html];
	}

	/**
	 * Translates a language key through the given Language instance and applies sprintf() with the supplied arguments.
	 * Mirrors Text::sprintf() but against an explicit (recipient) Language rather than the global one. When the resolved
	 * string carries no placeholders (e.g. an untranslated key) the arguments are ignored, avoiding an error.
	 *
	 * @param   object  $lang  The Language instance.
	 * @param   string  $key   The language key.
	 * @param   mixed   ...$args
	 *
	 * @return  string
	 */
	private function sprintfLang($lang, string $key, ...$args): string
	{
		$format = $lang->_($key);

		if (strpos($format, '%') === false)
		{
			return $format;
		}

		return vsprintf($format, $args);
	}

	/**
	 * Resolves the human-readable label Joomla uses for a configuration key, falling back to the raw key.
	 *
	 * @param   Form|null    $form   The form providing labels, or null.
	 * @param   string       $key    The configuration key.
	 * @param   string|null  $group  The form field group.
	 * @param   object       $lang   The Language instance to translate through.
	 *
	 * @return  string
	 */
	private function getFieldLabel(?Form $form, string $key, ?string $group, $lang): string
	{
		if ($form instanceof Form)
		{
			$rawLabel = $form->getFieldAttribute($key, 'label', '', $group);

			if (!empty($rawLabel))
			{
				$translated = $lang->_($rawLabel);

				if (!empty($translated) && $translated !== $rawLabel)
				{
					return $translated;
				}
			}
		}

		return $key;
	}

	/**
	 * Determines whether a configuration key holds a sensitive value that must be redacted, using the form field type
	 * (password) and a name heuristic.
	 *
	 * @param   Form|null    $form   The form providing field types, or null.
	 * @param   string       $key    The configuration key.
	 * @param   string|null  $group  The form field group.
	 *
	 * @return  bool
	 */
	private function isSensitive(?Form $form, string $key, ?string $group): bool
	{
		if ($form instanceof Form)
		{
			$fieldType = $form->getFieldAttribute($key, 'type', '', $group);

			if (strtolower((string) $fieldType) === 'password')
			{
				return true;
			}
		}

		return (bool) preg_match('/(pass|secret|token|salt|apikey|_key\b|\bkey\b)/i', $key);
	}

	/**
	 * Determines whether a configuration value can be rendered verbatim (old → new) in the diff.
	 *
	 * We only show the raw value when it is scalar AND (when the form is available) the field is one of the well-known
	 * core Joomla field types that store a user-recognisable scalar. Compound values (arrays/objects from subforms,
	 * repeatable fields, the global Text Filters, …) and custom field types (e.g. Akeeba Backup's Secret Word, which
	 * stores an encrypted value but displays the plaintext) are reported as "changed" without their raw values, to
	 * avoid confusing the recipient. When no form is available we cannot know the type, so scalar values are allowed
	 * through on a best-effort basis.
	 *
	 * @param   Form|null    $form  The form providing field types, or null.
	 * @param   string       $key   The configuration key.
	 * @param   string|null  $group The form field group.
	 * @param   mixed        $old   The previous value.
	 * @param   mixed        $new   The new value.
	 *
	 * @return  bool
	 */
	private function isValueDisplayable(?Form $form, string $key, ?string $group, $old, $new): bool
	{
		// Compound (array/object) values are never rendered verbatim.
		foreach ([$old, $new] as $value)
		{
			if (!is_null($value) && !is_scalar($value))
			{
				return false;
			}
		}

		// Without a form we cannot determine the field type; allow scalar values through on a best-effort basis.
		if (!$form instanceof Form)
		{
			return true;
		}

		$type = strtolower((string) $form->getFieldAttribute($key, 'type', '', $group));

		// A config key with no corresponding form field (e.g. a legacy option) has no type; allow its scalar value.
		if ($type === '')
		{
			return true;
		}

		return in_array($type, self::DISPLAYABLE_FIELD_TYPES, true);
	}

	/**
	 * Formats a configuration value for display in the diff.
	 *
	 * @param   mixed   $value  The value to format.
	 * @param   object  $lang   The Language instance to translate through.
	 *
	 * @return  string
	 */
	private function formatValue($value, $lang): string
	{
		if (is_null($value))
		{
			return $lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_EMPTY');
		}

		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}

		if (is_scalar($value))
		{
			$string = (string) $value;

			if ($string === '')
			{
				return $lang->_('COM_ADMINTOOLS_EMAIL_CONFIGMONITOR_DIFF_EMPTY');
			}

			if (function_exists('mb_strlen') ? mb_strlen($string) > 200 : strlen($string) > 200)
			{
				$string = (function_exists('mb_substr') ? mb_substr($string, 0, 200) : substr($string, 0, 200)) . '…';
			}

			return $string;
		}

		return (string) json_encode($value);
	}

	/**
	 * Decodes an extension's params into an associative array.
	 *
	 * @param   Registry|string|array|object|null  $params  The params value.
	 *
	 * @return  array
	 */
	private function decodeParams($params): array
	{
		if ($params instanceof Registry)
		{
			return $params->toArray();
		}

		if (is_array($params))
		{
			return $params;
		}

		if (is_string($params) && $params !== '')
		{
			$decoded = json_decode($params, true);

			return is_array($decoded) ? $decoded : [];
		}

		if (is_object($params))
		{
			return (array) $params;
		}

		return [];
	}

	/**
	 * Loads the currently stored params of an extension from the database.
	 *
	 * @param   int  $extensionId  The extension ID.
	 *
	 * @return  array
	 */
	private function loadExtensionParams(int $extensionId): array
	{
		$record = Extensions::getRecordById($extensionId);

		return $this->decodeParams($record->params ?? null);
	}

	/**
	 * Determines the language tag an email to the given recipient should be rendered in.
	 *
	 * Delegates to the shared rules so that the diff, the field labels and the area name are rendered in exactly the
	 * language the mail template itself will be rendered in. See EmailLanguage for the rules.
	 *
	 * @param   User  $user  The recipient.
	 *
	 * @return  string
	 */
	private function recipientLanguageTag(User $user): string
	{
		return EmailLanguage::resolveTagForUser($user);
	}

	/**
	 * Returns a Joomla Language instance for the given tag with the language files needed to render the diff (Admin
	 * Tools' own strings plus the strings that provide the field labels and the area name) loaded.
	 *
	 * @param   string       $tag            The language tag, e.g. el-GR.
	 * @param   string       $areaType       'global' or 'component'.
	 * @param   string|null  $componentName  The component element for component options, e.g. com_content.
	 *
	 * @return  object  A Joomla\CMS\Language\Language instance.
	 */
	private function getLanguageForTag(string $tag, string $areaType, ?string $componentName)
	{
		$extraExtensions = ($areaType === 'component' && !empty($componentName))
			? [$componentName, $componentName . '.sys']
			: ['com_config', 'com_cpanel'];

		return EmailLanguage::getLanguage($tag, $extraExtensions);
	}

	/**
	 * Resolves the human-readable name of the configuration area being reported, in the given language.
	 *
	 * @param   object       $lang           The Language instance.
	 * @param   string       $areaType       'global' or 'component'.
	 * @param   string|null  $componentName  The component element for component options.
	 *
	 * @return  string
	 */
	private function resolveAreaName($lang, string $areaType, ?string $componentName): string
	{
		if ($areaType === 'component' && !empty($componentName))
		{
			return $lang->_($componentName);
		}

		$area = $lang->_('COM_CPANEL_LINK_GLOBAL_CONFIG');

		if ($area === 'COM_CPANEL_LINK_GLOBAL_CONFIG')
		{
			$area = $lang->_('COM_CONFIG_GLOBAL_CONFIGURATION');
		}

		return $area;
	}

	/**
	 * Builds a Joomla Form for the Global Configuration, used only to resolve field labels and types.
	 *
	 * @return  Form|null
	 */
	private function getGlobalConfigForm(): ?Form
	{
		try
		{
			Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_config/forms');

			return Form::getInstance(
				'com_admintools.configmonitor.application',
				'application',
				['control' => false, 'load_data' => false]
			);
		}
		catch (Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Builds a Joomla Form for a component's Options, used only to resolve field labels and types.
	 *
	 * @param   string  $element  The component element, e.g. com_content.
	 *
	 * @return  Form|null
	 */
	private function getComponentConfigForm(string $element): ?Form
	{
		$path = JPATH_ADMINISTRATOR . '/components/' . $element . '/config.xml';

		if (!@is_file($path))
		{
			return null;
		}

		try
		{
			Form::addFormPath(JPATH_ADMINISTRATOR . '/components/' . $element);

			return Form::getInstance(
				'com_admintools.configmonitor.' . $element,
				'config',
				['control' => false, 'load_data' => false],
				false,
				'/config'
			);
		}
		catch (Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Sends the configuration-change warning email, rendering the diff in each recipient's own language.
	 *
	 * @param   array        $diff           The structured diff.
	 * @param   Form|null    $form           The form providing labels and field types, or null.
	 * @param   string|null  $group          The form field group the keys belong to.
	 * @param   string       $areaType       'global' or 'component'.
	 * @param   string|null  $componentName  The component element for component options.
	 */
	private function sendDiffEmail(array $diff, ?Form $form, ?string $group, string $areaType, ?string $componentName)
	{
		try
		{
			/**
			 * The email recipients are taken from one of the following sources:
			 *
			 * - Email this address on monitored configuration changes (`configmonitor_email`).
			 * - Email this address after an automatic IP ban (`emailafteripautoban`).
			 * - Super Users which are not Blocked, and have Receive Email enabled.
			 *
			 * The first source to return non-zero items wins.
			 */
			$recipients = $this->userListFromConfiguredEmailList('configmonitor_email')
				?: $this->userListFromConfiguredEmailList('emailafteripautoban')
					?: array_filter($this->getSuperUserObjects(), fn(User $user) => $user->sendEmail);

			foreach ($recipients as $recipient)
			{
				if (empty($recipient) || !$recipient instanceof User)
				{
					continue;
				}

				// Render the diff and the area name in the recipient's own language, so the whole email — including the
				// field labels — is coherent with the language the mail template itself is rendered in.
				$langTag           = $this->recipientLanguageTag($recipient);
				$lang              = $this->getLanguageForTag($langTag, $areaType, $componentName);
				[$info, $infoHtml] = $this->renderDiff($diff, $form, $group, $lang);

				$substitutions = [
					'AREA'      => $this->resolveAreaName($lang, $areaType, $componentName),
					'INFO'      => $info,
					'INFO_HTML' => $infoHtml,
				];

				$data = array_merge(RescueUrl::getRescueInformation($recipient->email), $substitutions);

				$this->exceptionsHandler->sendEmail('com_admintools.configmonitor', $recipient, $data);
			}
		}
		catch (Exception $e)
		{
			// Joomla! 3.5 and later throw an exception when crap happens instead of suppressing it and returning false.
		}
	}
}
