/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Paths up to the longest a PHP File Change Scanner run can store: under PHP_MAXPATHLEN (4096) bytes, or 5465
-- characters once a path which is not valid UTF-8 is Base64-encoded. Longer paths made every scan fail.
ALTER TABLE "#__admintools_filescache" ALTER COLUMN "path" TYPE character varying(8192);
ALTER TABLE "#__admintools_scanalerts" ALTER COLUMN "path" TYPE character varying(8192);
