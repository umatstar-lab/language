<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

/**
 * Describes a one-click fix a Site Health Check item can apply.
 *
 * A check lists the fixes it knows about in getFixes(). Its CheckResult then says which of them are offered for the
 * current outcome, either for the whole check or per item.
 *
 * @since  7.10.0
 */
final class Fix
{
	/** @var string */
	private $id;

	/** @var string */
	private $label;

	/** @var string */
	private $confirm;

	/** @var string */
	private $style;

	/** @var string */
	private $icon;

	/** @var bool */
	private $reloadPage;

	/**
	 * @param   string  $id       Unique (within the check) fix ID. Letters, digits, dot, dash and underscore only.
	 * @param   string  $label    Translated button label (plain text).
	 * @param   string  $confirm  Translated confirmation question (plain text); empty for no confirmation. For per-item
	 *                            fixes, `%s` in it is replaced with the item's label, so the question names the item.
	 * @param   string  $style    Bootstrap button colour: primary, danger, warning, …
	 * @param   string  $icon        FontAwesome icon class, e.g. 'fa fa-trash'.
	 * @param   bool    $reloadPage  Reload the whole page after applying the fix, instead of updating just this check?
	 *                               Needed when the fix ends the user's session, or changes what the page itself shows.
	 *
	 * @since   7.10.0
	 */
	public function __construct(
		string $id, string $label, string $confirm = '', string $style = 'primary', string $icon = 'fa fa-wrench',
		bool $reloadPage = false
	)
	{
		if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $id))
		{
			throw new \InvalidArgumentException(sprintf('Invalid Site Health Check fix ID “%s”', $id));
		}

		$this->id      = $id;
		$this->label   = $label;
		$this->confirm = $confirm;
		$this->style   = preg_replace('/[^a-z\-]/', '', $style) ?: 'primary';
		$this->icon    = preg_replace('/[^A-Za-z0-9 \-]/', '', $icon);

		$this->reloadPage = $reloadPage;
	}

	public function isReloadPage(): bool
	{
		return $this->reloadPage;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getLabel(): string
	{
		return $this->label;
	}

	public function getConfirm(): string
	{
		return $this->confirm;
	}

	public function getStyle(): string
	{
		return $this->style;
	}

	public function getIcon(): string
	{
		return $this->icon;
	}
}
