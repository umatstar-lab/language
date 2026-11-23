/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

ALTER TABLE "#__admintools_scanalerts" ADD COLUMN IF NOT EXISTS "flag" character varying(20) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS "#__admintools_idx_flag" ON "#__admintools_scanalerts" ("flag");
ALTER TABLE "#__admintools_scans" ADD COLUMN IF NOT EXISTS "coresums_failed" smallint NOT NULL DEFAULT 0;
