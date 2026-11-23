<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck;

defined('_JEXEC') or die;

/**
 * A group of Site Health Check items.
 *
 * Built-in sections use orderings 100–400 and 9000 (Further Tools, always last). Third-party sections should use
 * orderings between 500 and 8999.
 *
 * @since  7.10.0
 */
final class Section
{
	/** @var string */
	private $id;

	/** @var string */
	private $title;

	/** @var string */
	private $description;

	/** @var int */
	private $ordering;

	/**
	 * @param   string  $id           Unique section ID. Letters, digits, dot, dash and underscore only.
	 * @param   string  $title        Translated title (plain text).
	 * @param   string  $description  Translated description (HTML, output as-is).
	 * @param   int     $ordering     Sort order; lower comes first.
	 *
	 * @since   7.10.0
	 */
	public function __construct(string $id, string $title, string $description = '', int $ordering = 500)
	{
		if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $id))
		{
			throw new \InvalidArgumentException(sprintf('Invalid Site Health Check section ID “%s”', $id));
		}

		$this->id          = $id;
		$this->title       = $title;
		$this->description = $description;
		$this->ordering    = $ordering;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getOrdering(): int
	{
		return $this->ordering;
	}
}
