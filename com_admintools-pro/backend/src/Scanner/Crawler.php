<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Scanner;

defined('_JEXEC') || die;

use Akeeba\Component\AdminTools\Administrator\Scanner\Exception\WarningException;
use Akeeba\Component\AdminTools\Administrator\Scanner\Logger\Logger;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Configuration;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Filesystem;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Session;
use Akeeba\Component\AdminTools\Administrator\Scanner\Util\Timer;
use Akeeba\Component\AdminTools\Administrator\HealthCheck\Scanner\Collector;
use DirectoryIterator;
use Exception;
use OutOfBoundsException;
use RuntimeException;

/**
 * Filesystem crawler
 */
class Crawler extends Part
{
	/**
	 * Directories currently queued for scanning
	 *
	 * @var   array
	 */
	private $directoryQueue = [];

	/**
	 * Files queued for processing
	 *
	 * @var array
	 */
	private $fileQueue = [];

	/**
	 * True when we have finished listing all folders inside the current directory
	 *
	 * @var   boolean
	 */
	private $hasScannedFolders = false;

	/**
	 * True when we have finished listing  all files inside the current directory
	 *
	 * @var   boolean
	 */
	private $hasScannedFiles = false;

	/**
	 * Current directory being scanned
	 *
	 * @var   string
	 */
	private $currentDirectory = '';

	/**
	 * Directory and file filtering engine
	 *
	 * @var   Filter
	 */
	private $filter;

	/**
	 * The file scanner engine
	 *
	 * @var   FileScanner
	 */
	private $fileScanner;

	/**
	 * Collects information for the Site Health Check
	 *
	 * @var   Collector
	 * @since 7.10.0
	 */
	private $healthCollector;

	/**
	 * Crawler constructor.
	 *
	 * @param   Configuration  $configuration
	 * @param   Session        $session
	 * @param   Logger         $logger
	 * @param   Timer          $timer
	 */
	public function __construct(Configuration $configuration, Session $session, Logger $logger, Timer $timer)
	{
		parent::__construct($configuration, $session, $logger, $timer);

		$this->filter      = new Filter($this->configuration);
		$this->fileScanner = new FileScanner($configuration, $session, $logger);
		$this->healthCollector = new Collector($configuration, $session, $this->filter);

		// Load the state from the session
		$this->directoryQueue    = $this->session->get('directoryQueue', []);
		$this->fileQueue         = $this->session->get('fileQueue', []);
		$this->hasScannedFiles   = $this->session->get('hasScannedFiles', false);
		$this->hasScannedFolders = $this->session->get('hasScannedFolders', false);
		$this->currentDirectory  = $this->session->get('currentDirectory', '');

		$this->setState($this->session->get('crawlerState', self::STATE_INIT));
	}

	public function __destruct()
	{
		// Save the state to the session
		$this->session->set('directoryQueue', $this->directoryQueue);
		$this->session->set('fileQueue', $this->fileQueue);
		$this->session->set('hasScannedFiles', $this->hasScannedFiles);
		$this->session->set('hasScannedFolders', $this->hasScannedFolders);
		$this->session->set('currentDirectory', $this->currentDirectory);
		$this->session->set('crawlerState', $this->getState());

		$this->healthCollector->save();
	}

	/**
	 * Initializes the crawler
	 *
	 * @return  void
	 */
	protected function _prepare()
	{
		$this->logger->debug(__CLASS__ . " :: Starting _prepare()");

		// Push the site's root as the first directory to scan
		$this->directoryQueue    = [];
		$this->fileQueue         = [];
		$this->currentDirectory  = Filesystem::normalizePath(JPATH_ROOT);
		$this->currentDirectory  = empty($this->currentDirectory) ? '/' : $this->currentDirectory;
		$this->hasScannedFolders = false;
		$this->hasScannedFiles   = false;

		// Start collecting Site Health Check information afresh
		$this->healthCollector->reset();
		$this->session->set('healthCheckCollectOnly', []);

		$this->setState(self::STATE_PREPARED);

		$this->logger->debug(__CLASS__ . " :: prepared");
	}

	/**
	 * Runs a step of the crawler
	 *
	 * @return  void
	 * @throws  Exception
	 */
	protected function _run()
	{
		if ($this->getState() == self::STATE_POSTRUN)
		{
			$this->logger->debug(__CLASS__ . " :: Already finished");
			$this->setStep("-");
			$this->setSubstep("");

			return;
		}

		/**
		 * I am done scanning files and subdirectories and there are no more files to pack. I need to get the next
		 * directory to scan from my queue. If my queue is empty I am all done.
		 */
		if (empty($this->fileQueue) && $this->hasScannedFolders && $this->hasScannedFiles)
		{
			if (!$this->getNextDirectory())
			{
				$this->setState(self::STATE_POSTRUN);

				return;
			}
		}

		// I have no queued files and I haven't yet finished scanning for files. List the files.
		if (!$this->hasScannedFiles && empty($this->fileQueue))
		{
			$this->scanFiles();
		}
		/**
		 * I have queued files. Process them.
		 *
		 * Note that at this point I may have NOT finished listing all files. This is intentional. If a folder has two
		 * thousand files and my fileThreshold is 100 I need to run 20 batches of 100 files each. This lets me be very
		 * efficient in processing files without requesting too much session storage and memory for storing a massive
		 * number of files in the queue.
		 */
		elseif (!empty($this->fileQueue))
		{
			$this->processFiles();
		}
		/**
		 * I am done processing files. Find the subdirectories of the current directory and add them to my queue.
		 */
		elseif (!$this->hasScannedFolders)
		{
			$this->scanSubdirs();
		}
	}

	/**
	 * Finalization.
	 *
	 * I have currently nothing to do.
	 */
	protected function _finalize()
	{
		$this->setState(self::STATE_FINISHED);
	}

	/**
	 * Gets the next directory to scan from the queue.
	 *
	 * It also applies folder filters. Skipped folders are logged as such and are marked as having been fully scanned
	 * for files and folders. This means the next tick will call getNextDirectory once again.
	 *
	 * @return   boolean  True if we found a directory (even if it's filtered), false if the directory queue is empty.
	 */
	protected function getNextDirectory(): bool
	{
		// Reset the file / folder scanning positions
		$this->hasScannedFiles   = false;
		$this->hasScannedFolders = false;

		// Every folder is listed from its first entry, whatever happened to the previous one.
		$this->session->set('dirPosition', null);
		$this->session->set('filePosition', null);

		if (count($this->directoryQueue) == 0)
		{
			// No directories left to scan
			return false;
		}

		// Get and remove the last entry from the $directory_list array
		$this->currentDirectory = array_pop($this->directoryQueue);
		$this->setStep($this->currentDirectory);

		if ($this->filter->isExcludedFolder($this->currentDirectory))
		{
			/**
			 * The Site Health Check may still need to look inside a folder the user excluded from scanning, e.g. for
			 * PHP files in images/. Its files are then listed for the Site Health Check only, never scanned.
			 */
			if ($this->healthCollector->shouldCollectInExcludedFolder($this->currentDirectory))
			{
				$this->logger->info("Collecting Site Health Check information only in directory " . $this->currentDirectory);

				$collectOnly   = $this->session->get('healthCheckCollectOnly', []);
				$collectOnly[] = Filesystem::relativePath($this->currentDirectory);
				$this->session->set('healthCheckCollectOnly', array_values(array_unique($collectOnly)));

				return true;
			}

			$this->logger->info("Skipping directory " . $this->currentDirectory);
			$this->hasScannedFolders = true;
			$this->hasScannedFiles   = true;
		}

		return true;
	}

	/**
	 * Try to add some files from the $file_list into the archive
	 *
	 * @return   boolean   True if there were files packed, false otherwise
	 *                     (empty filelist or fatal error)
	 */
	protected function processFiles()
	{
		// Normal file backup loop; we keep on processing the file list, packing files as we go.
		if (count($this->fileQueue) == 0)
		{
			// No files left to pack. Return true and let the engine loop
			return true;
		}

		$this->logger->debug("Processing files");
		$counter = 0;

		while ((count($this->fileQueue) > 0) && ($this->timer->getTimeLeft() >= 0))
		{
			$counter++;
			$file = @array_shift($this->fileQueue);

			// Is this file skipped or simply does not exist anymore?
			if (!@file_exists($file) || $this->filter->isExcludedFile($file))
			{
				continue;
			}

			// Step break before processing a large file (unless it's the FIRST file we process in this step)
			if ($counter != 1)
			{
				$size               = @filesize($file);
				$largeFileThreshold = $this->configuration->get('largeFileThreshold');

				if ($size >= $largeFileThreshold)
				{
					$this->fileQueue[] = $file;
					$this->setBreakFlag();

					return true;
				}
			}

			// Mark another file scanned...
			$scannedFiles = $this->session->get('scannedFiles', 0);
			$this->session->set('scannedFiles', ++$scannedFiles);
			// ...and actually scan the file
			$markedSafe = $this->fileScanner->processFile($file);

			// The Site Health Check lists the non-core files the scanner reports; not those marked safe in the report.
			try
			{
				$this->healthCollector->recordScannedFile($file, $markedSafe);
			}
			catch (\Throwable $e)
			{
				$this->logger->debug('Site Health Check could not record ' . $file . ': ' . $e->getMessage());
			}
		}

		// True if we have more files, false if we're done packing
		return (count($this->fileQueue) > 0);
	}

	/**
	 * Steps the subdirectory scanning of the current directory
	 *
	 * @return  void
	 * @throws  Exception
	 */
	protected function scanSubdirs()
	{
		$dirPosition = $this->session->get('dirPosition', null);

		if (is_null($dirPosition))
		{
			$this->logger->info("Scanning directories of " . $this->currentDirectory);
		}
		else
		{
			$this->logger->info("Resuming scanning directories of " . $this->currentDirectory);
		}

		// Get subdirectories
		$exception      = null;
		$subdirectories = [];

		try
		{
			$subdirectories = $this->scanContents($this->currentDirectory, true);
		}
		catch (WarningException $e)
		{
			$this->logger->warning($e->getMessage());
		}
		catch (Exception $e)
		{
			$exception = $e;
		}

		// If the list contains "too many" items, please break this step!
		if ($this->session->get('breakFlag', false))
		{
			// Log the step break decision, for debugging reasons
			$this->logger->info(sprintf("Large directory %s while scanning for subdirectories; I will resume scanning in next step.", $this->currentDirectory));

			// Return immediately
			return;
		}

		// Error control
		if (!is_null($exception))
		{
			throw $exception;
		}

		// Make sure we do not follow directory symlinks because on some sites it could cause an infinite loop
		foreach ($subdirectories as $subdirectory)
		{
			if (is_link($subdirectory))
			{
				$this->logger->info(sprintf("Skipping directory symlink %s/%s", $this->currentDirectory, $subdirectory));

				continue;
			}

			$this->directoryQueue[] = $subdirectory;
		}

		// If the scanner nullified the next position to scan, we're done scanning for subdirectories
		$dirPosition = $this->session->get('dirPosition', null);

		if (is_null($dirPosition))
		{
			$this->hasScannedFolders = true;
		}
	}

	/**
	 * Steps the files scanning of the current directory
	 *
	 * @return  void
	 * @throws  Exception
	 */
	protected function scanFiles()
	{
		$filePosition = $this->session->get('filePosition', null);

		if (is_null($filePosition))
		{
			$this->logger->info("Scanning files of " . $this->currentDirectory);
		}
		else
		{
			$this->logger->info("Resuming scanning files of " . $this->currentDirectory);
		}

		// Get file listing
		$exception = null;
		$fileList  = false;

		try
		{
			$fileList = $this->scanContents($this->currentDirectory, false);
		}
		catch (WarningException $e)
		{
			$this->logger->warning($e->getMessage());
		}
		catch (Exception $e)
		{
			$exception = $e;
		}

		// If the list contains "too many" items, please break this step!
		if ($this->session->get('breakFlag', false))
		{
			// Log the step break decision, for debugging reasons
			$this->logger->info(sprintf("Large directory %s while scanning for files; I will resume scanning in next step.", $this->currentDirectory));

			// Return immediately, marking that we are not done yet!
			return;
		}

		// Error control
		if (!is_null($exception))
		{
			throw $exception;
		}

		// Do I have an unreadable directory?
		if ($fileList === false)
		{
			$this->logger->warning('Unreadable directory ' . $this->currentDirectory);

			$this->hasScannedFiles = true;
		}
		// Directory was readable, process the file list
		elseif (is_array($fileList) && !empty($fileList))
		{
			// Files in folders excluded from scanning are only listed for the Site Health Check; never scan them.
			if ($this->isCollectOnlyDirectory($this->currentDirectory))
			{
				$fileList = [];
			}

			// Scan all directory entries
			foreach ($fileList as $fileName)
			{
				$skipThisFile = $this->filter->isExcludedFile($fileName);

				if ($skipThisFile)
				{
					$this->logger->info(sprintf("Skipping file %s", $this->currentDirectory));

					continue;
				}

				$this->fileQueue[] = $fileName;
			}
		}

		// If the scanner engine nullified the next position we are done scanning for files
		$filePosition = $this->session->get('filePosition', null);

		if (is_null($filePosition))
		{
			$this->hasScannedFiles = true;
		}

		return;
	}

	/**
	 * Is this folder inside a folder excluded from scanning, which is only crawled for the Site Health Check?
	 *
	 * @param   string  $directory
	 *
	 * @return  bool
	 * @since   7.10.0
	 */
	private function isCollectOnlyDirectory(string $directory): bool
	{
		return Collector::isUnder(
			Filesystem::relativePath($directory), (array) $this->session->get('healthCheckCollectOnly', [])
		);
	}

	/**
	 * Scans a folder for files or folders
	 *
	 * @param   string  $scanFolder  Return the contents of this folder
	 * @param   bool    $folders     True to return contained folders, false to return contained files
	 *
	 * @return  array
	 */
	protected function scanContents($scanFolder, $folders = true)
	{
		$listing     = [];
		$collectOnly = $this->isCollectOnlyDirectory($scanFolder);

		$sessionKey  = ($folders ? 'dir' : 'file') . 'Position';

		try
		{
			// Sanity checks
			if (!is_dir($scanFolder) && !is_dir($scanFolder . '/'))
			{
				throw new WarningException('Cannot list contents of directory ' . $scanFolder . ' -- PHP reports it as not a folder.');
			}

			if (!@is_readable($scanFolder))
			{
				throw new WarningException('Cannot list contents of directory ' . $scanFolder . ' -- PHP reports it as not readable.');
			}

			try
			{
				$di = new DirectoryIterator($scanFolder);
			}
			catch (Exception $e)
			{
				throw new WarningException('Cannot list contents of directory ' . $scanFolder . ' -- PHP\'s DirectoryIterator reports the path cannot be opened.', 0, $e);
			}
		}
		catch (WarningException $e)
		{
			// The folder may have been deleted while it was being listed. Its listing is over: do not resume it.
			$this->session->set($sessionKey, null);

			throw $e;
		}

		// Do I need to resume scanning from a position other than the beginning of the list?
		$position = $this->session->get($sessionKey, null);

		if (!empty($position))
		{
			/**
			 * Entries deleted since the previous step can leave the resume position past the end of the listing, which
			 * DirectoryIterator::seek() reports with an OutOfBoundsException. Stop listing this folder instead of
			 * failing the whole scan.
			 */
			try
			{
				$di->seek($position);

				$seekFailed = $di->key() != $position;
			}
			catch (OutOfBoundsException $e)
			{
				$seekFailed = true;
			}

			if ($seekFailed)
			{
				$this->logger->warning(
					sprintf(
						'Directory %s lost entries while it was being scanned; some of its %s may not have been scanned.',
						$scanFolder, $folders ? 'subdirectories' : 'files'
					)
				);

				$this->session->set($sessionKey, null);

				return $listing;
			}
		}

		// Get the maximum number of files / folders to scan
		$maxCounter = $this->configuration->get(sprintf("%sThreshold", $folders ? 'dir' : 'file'));
		$counter    = 0;

		while ($di->valid())
		{
			/**
			 * If the directory entry is a link pointing somewhere outside the allowed directories per open_basedir we
			 * will get a RuntimeException (tested on PHP 5.3 onwards). Catching it lets us report the link as
			 * unreadable without suffering a PHP Fatal Error.
			 */
			try
			{
				if ($di->isDot())
				{
					$di->next();

					continue;
				}

				$di->isLink();
			}
			catch (RuntimeException $e)
			{
				if (!in_array($di->getFilename(), ['.', '..']))
				{
					$this->logger->warning(sprintf("Link %s is inaccessible. Check the open_basedir restrictions in your server's PHP configuration", $di->getPathname()));
				}

				$di->next();

				continue;
			}

			if ($di->isDot())
			{
				$di->next();

				continue;
			}

			if ($di->isDir() != $folders)
			{
				$di->next();

				continue;
			}

			$counter++;
			$addToListing = true;

			// Let the Site Health Check see every entry, whatever its extension.
			$entryPath = Filesystem::normalizePath($scanFolder . '/' . $di->getFilename());

			try
			{
				$this->healthCollector->inspect(
					$entryPath,
					$folders,
					$folders ? null : (int) @$di->getSize()
				);
			}
			catch (\Throwable $e)
			{
				$this->logger->debug('Site Health Check could not inspect ' . $entryPath . ': ' . $e->getMessage());
			}

			if (!$folders)
			{
				$addToListing = !$this->filter->isExcludedByExtension($di->getBasename());
			}

			if ($addToListing)
			{
				$listing[] = Filesystem::normalizePath($scanFolder . '/' . $di->getFilename());
			}

			if ($counter == $maxCounter)
			{
				break;
			}

			$di->next();
		}

		// Move past the entry listed last; the next step resumes from the entry after it.
		$di->next();

		// Update our position within the DirectoryIterator
		$this->session->set($sessionKey, $di->valid() ? $di->key() : null);

		return $listing;
	}

}
