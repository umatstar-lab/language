/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

ALTER TABLE `#__admintools_scanalerts` ADD COLUMN `flag` varchar(20) DEFAULT NULL;
ALTER TABLE `#__admintools_scanalerts` ADD INDEX `idx_flag` (`flag`);
ALTER TABLE `#__admintools_scans` ADD COLUMN `coresums_failed` tinyint NOT NULL DEFAULT 0;
