<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Mixin\LegacyObjectTrait;
use Akeeba\Component\AdminTools\Administrator\Mixin\ModelCopyTrait;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\AdminModel;

#[\AllowDynamicProperties]
class BadwordModel extends AdminModel
{
	use ModelCopyTrait;
	use LegacyObjectTrait;

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?FormFactoryInterface $formFactory = null)
	{
		parent::__construct($config, $factory, $formFactory);

		$this->_parent_table = '';
	}

	/**
	 * @inheritDoc
	 */
	public function getForm($data = [], $loadData = true)
	{
		$form = $this->loadForm(
			'com_admintools.badword',
			'badword',
			[
				'control'   => 'jform',
				'load_data' => $loadData,
			]
		) ?: false;

		if (empty($form))
		{
			return false;
		}

		return $form;
	}

	/**
	 * @inheritDoc
	 *
	 * @since   7.9.2
	 */
	public function save($data)
	{
		return parent::save($this->applyEscapeOption((array) $data));
	}

	/**
	 * Consume the virtual `escape` field of the edit form.
	 *
	 * The Word field holds a regular expression fragment which the WAF plugin interpolates into `#\b…\b#iu`, see
	 * BadWordsFiltering::buildBadWordRegex(). When the "Escape regular expression" option is enabled we run the
	 * submitted word through preg_quote(), turning it into an expression which matches itself literally — which
	 * is what someone entering a plain word expects to happen.
	 *
	 * The `escape` field is not a database column. It is always removed from the data before it reaches the
	 * table.
	 *
	 * @param   array  $data  The submitted form data.
	 *
	 * @return  array  The data to save.
	 * @since   7.9.2
	 */
	public function applyEscapeOption(array $data): array
	{
		$escape = !empty($data['escape'] ?? null);

		unset($data['escape']);

		if ($escape && isset($data['word']))
		{
			// '#' is the delimiter of the expression built by the WAF plugin, so it must be escaped as well.
			$data['word'] = preg_quote((string) $data['word'], '#');
		}

		return $data;
	}

	protected function loadFormData()
	{
		/** @var CMSApplication $app */
		$app  = Factory::getApplication();
		$data = $app->getUserState('com_admintools.edit.badword.data', []);
		$pk   = (int) $this->getState($this->getName() . '.id');
		$item = ($pk ? (object) $this->normalizePossibleCMSObject($this->getItem()) : false) ?: [];

		$data = $data ?: $item;

		/**
		 * Preset the virtual "Escape regular expression" field.
		 *
		 * New records default to Yes: most people enter a literal word, and escaping it is the safe thing to do.
		 * Existing records default to No: their stored word has, in all likelihood, already been escaped when it
		 * was created, and escaping it a second time would produce an expression which matches nothing.
		 *
		 * A value already present in $data comes from a failed save attempt, so it takes precedence.
		 */
		$defaultEscape = $pk ? 0 : 1;

		if (is_object($data))
		{
			$data->escape = $data->escape ?? $defaultEscape;
		}
		elseif (is_array($data))
		{
			$data['escape'] = $data['escape'] ?? $defaultEscape;
		}

		$this->preprocessData('com_admintools.badword', $data);

		return $data;
	}

}