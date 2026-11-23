/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Which version of the file each scan alert describes. A file marked safe stays marked safe for as
-- long as it is found with the same checksum and modification date; any other version of it is
-- reported again.
ALTER TABLE `#__admintools_scanalerts` ADD COLUMN `checksum` varchar(32) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci;
ALTER TABLE `#__admintools_scanalerts` ADD COLUMN `filedate` int(11) NOT NULL DEFAULT '0';
