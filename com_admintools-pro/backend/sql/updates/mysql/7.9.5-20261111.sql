/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Paths up to the longest a PHP File Change Scanner run can store: under PHP_MAXPATHLEN (4096) bytes, or 5465
-- characters once a path which is not valid UTF-8 is Base64-encoded. Longer paths made every scan fail.
ALTER TABLE `#__admintools_filescache` MODIFY `path` varchar(8192) NOT NULL COLLATE utf8mb4_unicode_ci;
ALTER TABLE `#__admintools_scanalerts` MODIFY `path` varchar(8192) NOT NULL COLLATE utf8mb4_unicode_ci;
