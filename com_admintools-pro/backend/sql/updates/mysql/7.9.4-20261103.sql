/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Add the 'crawler' reason (request blocked because the IP is in a crawler IP list) to the log table's reason ENUM.
-- Appended at the end so existing ENUM ordinals are preserved. PostgreSQL stores `reason` as a varchar and needs no change.
-- This file restates the whole column; it supersedes 7.8.10-20260706.sql, which is now a comment only.
ALTER TABLE `#__admintools_log` MODIFY `reason` enum ('other','admindir','awayschedule','adminpw','ipwl','ipbl','sqlishield','antispam','tpone','tmpl','wafblacklist','template','muashield','csrfshield','badbehaviour','geoblocking','rfishield','dfishield','uploadshield','xssshield','httpbl','loginfailure','securitycode','sessionshield','external','nonewadmins','nonewfrontendadmins','configmonitor','phpshield','404shield','itemidshield','susparam','blockedusername','crawler') DEFAULT 'other';
