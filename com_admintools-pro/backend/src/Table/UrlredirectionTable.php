<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Table;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseDriver;
use Joomla\Event\DispatcherInterface;

/**
 * @property int    $id            Unique ID
 * @property string $source        When visiting this URL Path
 * @property string $dest          You are redirected to this URL or URL Path
 * @property int    $ordering      Ordering
 * @property int    $published     Is this published?
 * @property string $created           Created date and time
 * @property int    $created_by        Created by this user
 * @property string $modified          Modified date and time
 * @property int    $modified_by       Modified by this user
 * @property int    $checked_out       Checked out by this user
 * @property string $checked_out_time  Checked out date and time
 * @property int    $keepurlparams Should I keep the URL params (default) or quash them?
 */
class UrlredirectionTable extends AbstractTable
{
	/**
	 * URL schemes the "Takes you here" (source) field is allowed to redirect visitors to. A relative URL (no
	 * scheme at all) is always allowed. Anything else — most importantly script-executing pseudo-schemes such
	 * as javascript: or data: — is rejected.
	 */
	private const ALLOWED_SOURCE_SCHEMES = ['http', 'https', 'ftp', 'mailto', 'tel'];

	public function __construct(DatabaseDriver $db, ?DispatcherInterface $dispatcher = null)
	{
		parent::__construct('#__admintools_redirects', 'id', $db, $dispatcher);
	}

	protected function onBeforeCheck()
	{
		if (!$this->source)
		{
			throw new Exception(Text::_('COM_ADMINTOOLS_REDIRECTION_ERR_NEEDS_SOURCE'));
		}

		if (!$this->dest)
		{
			throw new Exception(Text::_('COM_ADMINTOOLS_REDIRECTION_ERR_NEEDS_DEST'));
		}

		$this->assertSafeSourceUrl($this->source);

		if (empty($this->published) && ($this->published !== 0))
		{
			$this->published = 0;
		}

		if (is_null($this->keepurlparams))
		{
			$this->keepurlparams = 1;
		}
	}

	/**
	 * Reject a "Takes you here" URL using a script-executing pseudo-scheme (javascript:, data:, vbscript:, ...).
	 *
	 * The field is rendered as both link text and a raw href in the backend list view, so an unescaped
	 * javascript: URL becomes stored XSS against any admin who views the list. Genuine absolute redirects
	 * (http/https/ftp/mailto/tel) and relative site-local paths must still be allowed; only the scheme is
	 * restricted.
	 *
	 * Checked two ways on purpose: a plain string match against the leading scheme (defeats a parser that might
	 * tolerate something a browser's href handling would still execute), and Joomla's own URI parser (defeats
	 * scheme obfuscation, e.g. stray whitespace or control characters, that a naive string match might miss).
	 *
	 * @param   string  $url  The "Takes you here" (source) field value to validate.
	 *
	 * @return  void
	 * @throws  Exception  When the URL uses a scheme that isn't allow-listed.
	 */
	private function assertSafeSourceUrl(string $url): void
	{
		$normalised = preg_replace('/[\x00-\x1F\x7F]/', '', $url);

		if (
			preg_match('/^\s*([a-z][a-z0-9+.\-]*):/i', $normalised, $matches)
			&& !\in_array(strtolower($matches[1]), self::ALLOWED_SOURCE_SCHEMES, true)
		)
		{
			throw new Exception(Text::_('COM_ADMINTOOLS_REDIRECTION_ERR_UNSAFE_SOURCE'));
		}

		$scheme = Uri::getInstance($url)->getScheme();

		if (!empty($scheme) && !\in_array(strtolower($scheme), self::ALLOWED_SOURCE_SCHEMES, true))
		{
			throw new Exception(Text::_('COM_ADMINTOOLS_REDIRECTION_ERR_UNSAFE_SOURCE'));
		}
	}
}