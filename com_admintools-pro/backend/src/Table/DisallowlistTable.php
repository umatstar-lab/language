<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Table;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\IpExpressionValidator;
use Exception;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\Event\DispatcherInterface;

/**
 * @property int    $id
 * @property string $ip
 * @property string $description
 *
 */
class DisallowlistTable extends AbstractTable
{
	public function __construct(DatabaseDriver $db, ?DispatcherInterface $dispatcher = null)
	{
		parent::__construct('#__admintools_ipblock', 'id', $db, $dispatcher);
	}

	protected function onBeforeCheck()
	{
		if (!$this->ip)
		{
			throw new Exception(Text::_('COM_ADMINTOOLS_DISALLOWLIST_ERR_NEEDS_IP'));
		}

		// Leading zeros in IPv4 octets are stripped (decimal, never octal).
		$this->ip = IpExpressionValidator::normalise($this->ip);

		if (!IpExpressionValidator::isValid($this->ip))
		{
			throw new Exception(Text::_('COM_ADMINTOOLS_DISALLOWLIST_ERR_INVALID_IP'));
		}
	}
}
