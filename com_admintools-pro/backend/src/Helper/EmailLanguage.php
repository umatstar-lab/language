<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Helper;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Decides — and applies — the language a notification email is rendered in.
 *
 * Every notification Admin Tools sends must have a *deterministic* language. Before this class existed the
 * language was whatever the request which happened to trigger the email was being displayed in: a blocked
 * front-end request on a German page produced a German email, even though the site owner never asked for
 * one. That is the bug behind support ticket 43267.
 *
 * The rules, in order:
 *
 * 1. If the recipient's email address corresponds to a Joomla user account, use that account's language
 *    preference (back-end first, then front-end), falling back to the "Preferred fallback language for
 *    email notifications" component option, then to en-GB.
 * 2. If it does not correspond to a user account, use the component option, then en-GB.
 * 3. If "Force this language for all notification emails" is on, the component option wins over everything.
 *
 * The site's current display language is never consulted.
 *
 * @since 7.9.2
 */
abstract class EmailLanguage
{
	/**
	 * The language we always fall back to. Admin Tools ships en-GB, so this is guaranteed to resolve.
	 *
	 * @since 7.9.2
	 */
	public const FALLBACK_TAG = 'en-GB';

	/**
	 * The language extensions every notification email needs.
	 *
	 * `com_admintools` provides the mail templates' subjects and bodies. `plg_system_admintools` provides the
	 * values substituted *into* them — most notably {RESCUEINFO}, which is the PLG_ADMINTOOLS_MSG_BLOCKED_RESCUEINFO
	 * string. Without the latter an otherwise correctly pinned English email arrives with a German paragraph in
	 * the middle of it.
	 *
	 * @since 7.9.2
	 */
	private const DEFAULT_EXTENSIONS = ['com_admintools', 'plg_system_admintools'];

	/**
	 * Language instances built by getLanguage(), keyed by tag and the extra extensions requested.
	 *
	 * @var   array<string, Language>
	 * @since 7.9.2
	 */
	private static $languages = [];

	/**
	 * Which language should an email to this recipient be rendered in?
	 *
	 * Deliberately pure: every input is an argument, so the decision can be unit tested without a CMS.
	 *
	 * @param   User|null  $user    The recipient. An account with an id of 0 is a bare email address which
	 *                              matched no Joomla user.
	 * @param   string     $option  The "Preferred fallback language" component option ('' when not set).
	 * @param   bool       $force   Whether that option overrides the recipient's own preference.
	 *
	 * @return  string  A language tag, e.g. 'de-DE'. Never empty.
	 *
	 * @since   7.9.2
	 */
	public static function resolveTag(?User $user, string $option, bool $force): string
	{
		$option = trim($option);

		// Rule 3: the option is forced, so nothing else gets a say.
		if ($force && $option !== '')
		{
			return $option;
		}

		// Rule 2: a bare email address which matches no user account has no preference of its own.
		if ($user === null || empty($user->id))
		{
			return $option !== '' ? $option : self::FALLBACK_TAG;
		}

		// Rule 1: the account's own preference, back-end before front-end.
		$preference = trim((string) ($user->getParam('admin_language') ?: ''))
			?: trim((string) ($user->getParam('language') ?: ''));

		return $preference ?: ($option !== '' ? $option : self::FALLBACK_TAG);
	}

	/**
	 * Resolve the language tag for a recipient, reading the two options from the component configuration.
	 *
	 * @param   User|null  $user  The recipient.
	 *
	 * @return  string  A language tag. Never empty.
	 *
	 * @since   7.9.2
	 */
	public static function resolveTagForUser(?User $user): string
	{
		try
		{
			$params = ComponentHelper::getParams('com_admintools');
			$option = (string) ($params->get('email_language', '') ?: '');
			$force  = (bool) $params->get('email_language_force', 0);
		}
		catch (Throwable $e)
		{
			$option = '';
			$force  = false;
		}

		return self::resolveTag($user, $option, $force);
	}

	/**
	 * Get a User object for an email address.
	 *
	 * If a user account with this email address exists you get that account, which is what lets rule 1 apply to
	 * addresses typed into a WAF option. Otherwise you get a throwaway User with an id of 0 carrying just the
	 * address, which is what makes rule 2 apply.
	 *
	 * @param   string  $email  The recipient's email address.
	 *
	 * @return  User
	 *
	 * @since   7.9.2
	 */
	public static function userForEmail(string $email): User
	{
		$email  = trim($email);
		$userId = null;

		try
		{
			/** @var DatabaseDriver $db */
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = DbQuery::create($db)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('email') . ' = :email')
				->bind(':email', $email, ParameterType::STRING);

			$userId = $db->setQuery($query)->loadResult();
		}
		catch (Throwable $e)
		{
			// A database failure must not stop us from sending the email; we just lose the user's preference.
			$userId = null;
		}

		if (!empty($userId))
		{
			return new User($userId);
		}

		$user            = new User();
		$user->username  = $email;
		$user->name      = $email;
		$user->email     = $email;
		$user->guest     = 0;
		$user->block     = 0;
		$user->sendEmail = 1;

		return $user;
	}

	/**
	 * Build an isolated Language instance for a tag, with Admin Tools' strings loaded.
	 *
	 * en-GB is loaded first and the requested tag on top, so a key missing from the translation falls back to
	 * English per string instead of leaking the site's display language.
	 *
	 * @param   string    $tag              The language tag, e.g. 'el-GR'.
	 * @param   string[]  $extraExtensions  Further extensions to load, e.g. ['com_config', 'com_cpanel'].
	 *
	 * @return  Language
	 *
	 * @since   7.9.2
	 */
	public static function getLanguage(string $tag, array $extraExtensions = []): Language
	{
		$tag        = trim($tag) ?: self::FALLBACK_TAG;
		$extensions = array_values(array_unique(array_merge(self::DEFAULT_EXTENSIONS, $extraExtensions)));
		$cacheKey   = $tag . '|' . implode(',', $extensions);

		if (isset(self::$languages[$cacheKey]))
		{
			return self::$languages[$cacheKey];
		}

		/**
		 * Language::getInstance() is deprecated (removal in Joomla 7) and hands out a process-wide singleton per
		 * tag, which we would then mutate under everyone else's feet. The factory gives us a private instance.
		 */
		$debug    = false;
		$language = null;

		try
		{
			$app   = Factory::getApplication();
			$debug = (bool) $app->get('debug_lang', false);
		}
		catch (Throwable $e)
		{
			// No application (CLI bootstrap, tests). Carry on with debug off.
		}

		try
		{
			$language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag, $debug);
		}
		catch (Throwable $e)
		{
			$language = null;
		}

		if (!is_object($language))
		{
			/** @noinspection PhpDeprecationInspection Last resort on a site where the container is unavailable. */
			$language = Language::getInstance($tag, $debug);
		}

		foreach ($extensions as $extension)
		{
			self::loadExtension($language, $extension, $tag);
		}

		self::$languages[$cacheKey] = $language;

		return $language;
	}

	/**
	 * Run a callback with the whole application temporarily speaking a different language.
	 *
	 * Joomla's MailTemplate renders a mail template's subject and body with Text::_(), which reads the *global*
	 * language object — there is no way to hand it an instance. So the only way to render an email in a chosen
	 * language is to swap the global object out, render, and swap it back. Anything the callback translates,
	 * including the values it substitutes into the template, comes out in the same language as a result.
	 *
	 * The previous language is restored even if the callback throws. That matters: the troubleshooting email is
	 * sent from a back-end controller which renders its UI immediately afterwards.
	 *
	 * @param   string    $tag              The language tag to switch to.
	 * @param   callable  $callback         Receives the Language instance; its return value is passed through.
	 * @param   string[]  $extraExtensions  Further extensions to load into that Language.
	 *
	 * @return  mixed  Whatever the callback returned.
	 *
	 * @since   7.9.2
	 */
	public static function withLanguage(string $tag, callable $callback, array $extraExtensions = [])
	{
		$language = self::getLanguage($tag, $extraExtensions);
		$app      = null;

		try
		{
			$app = Factory::getApplication();
		}
		catch (Throwable $e)
		{
			$app = null;
		}

		/**
		 * Text::_() reads Factory::$language, so that is the half which actually decides the rendered language.
		 * It is also the only half available under the CLI: ConsoleApplication has no loadLanguage() setter and
		 * never populates Factory::$language at all, so on a scheduled task (the PHP File Change Scanner, for
		 * one) Text::_() otherwise reads a lazily created object nobody has configured.
		 */
		$hasFactoryLanguage = property_exists(Factory::class, 'language');
		$previousFactory    = $hasFactoryLanguage ? Factory::$language : null;

		// The application's own language is swapped too, so code which reads $app->getLanguage() stays coherent.
		$previousApp = is_object($app) && method_exists($app, 'getLanguage') ? $app->getLanguage() : null;
		$swapApp     = is_object($app) && method_exists($app, 'loadLanguage') && is_object($previousApp);

		if ($hasFactoryLanguage)
		{
			Factory::$language = $language;
		}

		if ($swapApp)
		{
			$app->loadLanguage($language);
		}

		try
		{
			return $callback($language);
		}
		finally
		{
			if ($hasFactoryLanguage)
			{
				Factory::$language = $previousFactory;
			}

			if ($swapApp)
			{
				$app->loadLanguage($previousApp);
			}
		}
	}

	/**
	 * Forget the cached Language instances. Only meant for tests.
	 *
	 * @return  void
	 *
	 * @since   7.9.2
	 */
	public static function clearCache(): void
	{
		self::$languages = [];
	}

	/**
	 * Load one extension's strings for one tag, trying the same locations Joomla itself does.
	 *
	 * Language::load() only ever looks in `<basePath>/language/<tag>/`. An extension's own language folder is
	 * therefore invisible to it, which is why Joomla's ComponentDispatcher and CMSPlugin both try the shared
	 * folder first and the extension's own folder second. Doing only the first step — as this code used to —
	 * silently loads nothing on a site whose `administrator/language/<tag>/` folder has no copy of our INI, and
	 * the email comes out in whatever language was already loaded.
	 *
	 * @param   Language  $language   The instance to load into.
	 * @param   string    $extension  The extension name, e.g. 'com_admintools' or 'plg_system_admintools'.
	 * @param   string    $tag        The language tag.
	 *
	 * @return  void
	 *
	 * @since   7.9.2
	 */
	private static function loadExtension(Language $language, string $extension, string $tag): void
	{
		$basePaths = self::basePathsFor($extension);

		// en-GB first so it acts as the per-string fallback, then the requested tag on top of it.
		foreach (array_unique([self::FALLBACK_TAG, $tag]) as $loadTag)
		{
			foreach ($basePaths as $basePath)
			{
				if ($language->load($extension, $basePath, $loadTag, true))
				{
					break;
				}
			}
		}
	}

	/**
	 * The base paths to try when loading an extension's language files, in Joomla's own order.
	 *
	 * @param   string  $extension  The extension name.
	 *
	 * @return  string[]
	 *
	 * @since   7.9.2
	 */
	private static function basePathsFor(string $extension): array
	{
		// Always the administrator side: these are back-end strings even when the email is triggered by a
		// front-end request.
		$basePaths = [JPATH_ADMINISTRATOR];

		// 'com_content.sys' shares com_content's folder.
		$element = explode('.', $extension, 2)[0];

		if (strpos($element, 'com_') === 0)
		{
			$basePaths[] = JPATH_ADMINISTRATOR . '/components/' . $element;
		}
		elseif (preg_match('/^plg_([^_]+)_(.+)$/', $element, $matches))
		{
			$basePaths[] = JPATH_PLUGINS . '/' . $matches[1] . '/' . $matches[2];
		}

		return $basePaths;
	}
}
