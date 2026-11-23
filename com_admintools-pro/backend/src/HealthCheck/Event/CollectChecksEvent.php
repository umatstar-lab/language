<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\HealthCheck\Event;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\HealthCheck\CheckInterface;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Runner;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Section;
use Joomla\CMS\Event\AbstractEvent;

/**
 * Event letting third-party plugins add sections and checks to the Site Health Check.
 *
 * It is triggered as `onAdminToolsSiteHealthCheckCollect`, after the built-in sections and checks are registered.
 * Plugins in the `admintools` and `system` groups are loaded before it is triggered. Example handler:
 *
 * ```php
 * public static function getSubscribedEvents(): array
 * {
 *     return [CollectChecksEvent::NAME => 'onCollect'];  // i.e. onAdminToolsSiteHealthCheckCollect
 * }
 *
 * public function onCollect(CollectChecksEvent $event): void
 * {
 *     $event->addSection(new Section('plg_system_example', 'Example', '', 500));
 *     $event->addCheck(new MyExampleCheck());  // implements CheckInterface, e.g. by extending AbstractCheck
 * }
 * ```
 *
 * A check may also be added to a built-in section: `globalconfig`, `joomla`, `files`, `scanner`, `other`. Checks whose
 * section does not exist, and checks whose ID is already taken, are ignored.
 *
 * It extends Joomla's AbstractEvent, not the framework's Event: Joomla only calls legacy (non-SubscriberInterface)
 * plugin listeners with an AbstractEvent. A legacy listener receives the Runner as its only argument, and can call
 * its addSection() and addCheck() methods.
 *
 * @since  7.10.0
 */
class CollectChecksEvent extends AbstractEvent
{
	/** The name of this event. */
	public const NAME = 'onAdminToolsSiteHealthCheckCollect';

	public function __construct(Runner $runner)
	{
		parent::__construct(self::NAME, ['subject' => $runner]);
	}

	/**
	 * Add a section.
	 *
	 * @param   Section  $section
	 *
	 * @return  $this
	 * @since   7.10.0
	 */
	public function addSection(Section $section): self
	{
		$this->getRunner()->addSection($section);

		return $this;
	}

	/**
	 * Add a check.
	 *
	 * @param   CheckInterface  $check
	 *
	 * @return  $this
	 * @since   7.10.0
	 */
	public function addCheck(CheckInterface $check): self
	{
		$this->getRunner()->addCheck($check);

		return $this;
	}

	/**
	 * The Site Health Check runner the sections and checks are added to.
	 *
	 * @return  Runner
	 * @since   7.10.0
	 */
	public function getRunner(): Runner
	{
		return $this->getArgument('subject');
	}

	/**
	 * Setter for the subject argument; it must be the Runner.
	 *
	 * @param   Runner  $value
	 *
	 * @return  Runner
	 * @since   7.10.0
	 */
	protected function onSetSubject(Runner $value): Runner
	{
		return $value;
	}
}
