<?php die() ?>
Admin Tools 7.10.0
================================================================================
! Server configuration makers (.htaccess, NginX, web.config): many security and bug fixes. Regenerate your file.
+ Site Health Check: finds common security problems and settings unsuitable for live sites, with one-click fixes
+ Back-end reminder to regenerate an Admin Tools-made .htaccess, nginx.conf or web.config which is out of date
+ NginX Conf Maker: optionally let PHP files in Server Protection exceptions receive path info, e.g. foobar.php/baz.png
+ web.config Maker: with frontend protection, index.php is the directory index only in Joomla! and .php-allowed folders
~ Check Temp and Log Directories can now fix the folder settings even when configuration.php is read-only
~ Portuguese (Portugal) translation now consistently uses the post-1990 spelling, like Joomla's own
~ Check Temp and Log Directories falls back to administrator/logs, Joomla 4+'s default, instead of the root's logs
~ NginX Conf Maker: removed Prevent content transformation; NginX can't add it without a second Cache-Control header
~ Server configuration makers: file injection protection no longer applies to the administrator and API folders
# [HIGH] Users with lower Admin Tools permissions could open any Admin Tools page by adding a hyphen or dot to its name
# [HIGH] .htaccess Maker: with automatic compression on, PHP files could run past Server Protection
# [HIGH] NginX Conf Maker: Restrict access by IP did not apply to most requests, e.g. index.php and static files
# [MEDIUM] Users with only Manage access could open some Admin Tools pages that need a higher permission
# [MEDIUM] Users with only Manage access could open the Temporary Super Users page
# [MEDIUM] PHP File Change Scanner waited as long as each step ran, ignoring Minimum Request Time; scans were slow
# [MEDIUM] Check Temp and Log Directories' fix could make the frontend and backend share login sessions
# [MEDIUM] Frontend and backend could keep sharing login sessions where an older version had saved configuration.php
# [MEDIUM] Security hardening: PHP File Change Scanner scans failed when a file name was not valid UTF-8
# [MEDIUM] Security hardening: PHP File Change Scanner scans failed when a file's path was over 2048 characters
# [MEDIUM] web.config Maker: Neutralise SVG script execution did nothing; scripts in SVG files could still run
# [MEDIUM] Server configuration makers: file injection protection let through https, upper case or traversal attacks
# [MEDIUM] .htaccess Maker: "Disable client-side risky behavior" skipped files served through MultiViews
# [LOW] Security hardening: a visitor whose IP could not be read passed the Administrator Exclusive Allow IP List
# [LOW] Security hardening: DFI Shield did not check every request parameter
# [LOW] Check Temp and Log Directories ignored a writable tmp folder and used or created temp instead
# [LOW] PHP File Change Scanner: site root files whose name starts with a backslash were listed under a wrong name
# [LOW] Requesting an Admin Tools page with an internal method name as the task caused a server error (HTTP 500)
# [LOW] IP lists ignored IPv4 addresses written with leading zeros (e.g. 001.002.003.004) on some PHP versions
# [LOW] Switching from Professional to Core left many Pro-only files on the site; they are now removed
# [LOW] PHP File Change Scanner scanned some files in folders with many files twice, overstating the scanned file count
# [LOW] PHP File Change Scanner failed or never finished if a folder with many files changed while it was scanned
# [LOW] DFI Shield blocked requests with a parameter whose value is just a slash, e.g. return=/
# [LOW] CLI admintools:waf:set --value on a list option emptied the list
# [LOW] CLI admintools:waf:set --remove did not remove list entries added with --add or in the back-end
# [LOW] CLI admintools:waf:set --help described --description with the help text of --add
# [LOW] The DFI Shield option's tooltip described scanning files for PHP code, which it does not do
# [LOW] Configure WAF: an invalid Away Schedule time was silently cleared, turning the Away Schedule off
# [LOW] CLI htmaker:set, nginxmaker:set and webconfigmaker:set --remove removed only the first match, or a wrong entry
# [LOW] CLI admintools:waf:set, htmaker:set, nginxmaker:set and webconfigmaker:set refused --add=0 and --remove=0
# [LOW] CLI set commands: --remove did not remove an IP address added with leading zeros, e.g. 001.002.003.004
# [LOW] CLI htmaker:set, nginxmaker:set and webconfigmaker:set saved an empty value for an empty --value=
# [LOW] Server configuration makers: saving the page dropped list entries of 0, e.g. a folder named 0
# [LOW] Configure WAF: saving the page dropped list entries of 0, e.g. a forbidden username 0
# [LOW] Security hardening: crawler IP lists accepted some IPv6 entries which covered every IPv4 address
# [LOW] NginX Conf Maker: static files got up to three Cache-Control headers with an expiration time or no-transform
# [LOW] Configure WAF: the Never block these domains and Crawlers texts said those visitors' requests always go through
# [LOW] .htaccess Maker: Neutralise SVG script execution missed .SVG and .svgz files and weakened media folders' policy
# [LOW] Security hardening: NginX Conf Maker's Block common exploits missed <script> in capitals or encoded as %3c
# [LOW] Security hardening: .htaccess and web.config back-end protection missed e.g. /ADMINISTRATOR/ and /ADMINI~1/
# [LOW] .htaccess and web.config Makers: leftover files got 403 instead of 404; .htaccess Maker served web.config.txt
# [LOW] .htaccess and web.config Makers: Remove web server and PHP version signature could leave PHP's version header
# [LOW] NginX Conf Maker: NginX refused the file if an allowed file was listed twice or named like a leftover file
# [LOW] .htaccess Maker: Protect against clickjacking sent X-Frame-Options with images, CSS, JS and other static files

Admin Tools 7.9.4
================================================================================
+ .htaccess Maker: options to let Joomla handle missing files in allowed frontend/backend directories (e.g. redirects)
+ Backend reminder while the WAF debug log file, which records request data, is enabled
+ WAF: block or never block search engine and AI crawlers by the IP ranges they publish, chosen per crawler
~ Email on Blocked Request reminder is shown to users with the Security privilege, not Configure ACL & Options
~ Server configuration makers: CloudFlare/Sucuri/BunnyCDN lists use a time limit and keep the last good copy
~ Never block these domains now checks that the host name resolves back to the visitor's IP address
~ Never block these IPs no longer ships with built-in DuckDuckBot addresses; they are removed from existing sites
~ Never block these domains no longer ships with search engine domains; they are removed from existing sites
~ Administrator Password Protection: crypt() hashing is no longer offered on Windows servers, which can't use it
# [HIGH] Temporary Super Users page: user name, username and email were not escaped (stored XSS)
# [HIGH] With "Keep a debug log file" enabled, blocked requests showed a server error and were not logged or auto-banned
# [HIGH] Malicious requests from Never block these IPs / domains were let through instead of blocked without logging
# [MEDIUM] Server configuration makers: CloudFlare IPv6 ranges were never included
# [MEDIUM] Check Temp/Log Directories never completed, stuck showing "in progress"
# [MEDIUM] Quick Setup Wizard erased the Custom Rules you had entered in the .htaccess Maker
# [MEDIUM] Quick Setup Wizard did not fully reset the configuration; leftover settings from earlier runs remained
# [MEDIUM] Logging in with a forbidden username auto-banned IPs in the IP Allow Lists or Never block these IPs
# [MEDIUM] Switching between Core and Pro left a stale update site behind; Joomla 4 kept polling the old edition
# [MEDIUM] Installing Core over Pro left the Pro-only plugins and files behind
# [LOW] Some translations had unescaped double quotes, which Joomla's language debugger flags
# [LOW] PHP File Change Scanner: core files over the oversize threshold were shown as Invalid Checksum without a check
# [LOW] Admin Tools Core could keep a Download ID in its update site
# [LOW] Administrator Password Protection: crypt() hashing was ignored, except on Windows where Apache can't use it
# [LOW] Administrator Password Protection: APR-1 password hashes used a shorter salt than Apache's own htpasswd

Admin Tools 7.9.3
================================================================================
! SEO & Migration: fixed mass assignment issue, only allowing specific keys to be written
! Fixed remaining missing anti-CSRF token checks
! Fixed open redirects on three Control Panel/Scan actions
! URL Redirections: the "Takes you here" field could store arbitrary data
! CSV scan-report exports were not neutralised against spreadsheet formula injection
! File-scanner and Critical Files Monitoring emails could contain unescaped HTML from file paths
! CSV and JSON downloads now send X-Content-Type-Options: nosniff
! PHP and Joomla minimum and maximum version compatibility is now enforced
- Removed legacy error handler
~ Scanalerts::markallsafe now requires the Security permission
~ Resetting the frontend Scanner Secret Word now requires the Options-page permission
~ Declare the core.options permission so it can be granted per user group
~ Quick Setup Wizard's Allowed Domains field is now repeatable, like Configure WAF's (gh-369)
~ Clarified that the Security ACL permission grants Super User-equivalent access
~ Raised the supported version ranges to PHP 8.6 and Joomla! 6.2
~ Errors thrown by custom upgrade handlers are now reported when Joomla's Debug Site setting is on
~ Funnelled every query through Helper\DbQuery::create() instead of duplicating the createQuery() compatibility branch
# [MEDIUM] Blocked request reasons and exception notification emails could come out blank on PHP 7.4 and 8.0
# [MEDIUM] Upgrading, or downgrading to Core, left obsolete folders behind on case-insensitive filesystems
# [MEDIUM] The layout URL parameter could make a page include a PHP file from outside the templates folder
# [LOW] Temp Super Users: the self-edit/self-delete guard checked the wrong field and never triggered
# [LOW] Scan report export endpoints required a lower permission than viewing the report itself
# [LOW] Scan alert edit page echoed the file diff and path unescaped (defence-in-depth)
# [LOW] Control Panel changelog heading line was not escaped (defence-in-depth)
# [LOW] Missing anti-CSRF token check on the scan report export endpoints
# [LOW] Blocked-request log could be forged with CR/LF in the URL, username, or User-Agent
# [LOW] Scan report list echoed each file path segment unescaped (very unlikely to be exploitable)
# [LOW] WAF Exceptions list showed a blank Query Parameter column instead of "(All)" when no query string was set
# [LOW] PHP 8.6: a deprecation notice leaving the custom administrator directory, two more saving the WAF configuration
# [LOW] When cleaning one cache failed, the remaining caches were not cleaned either

Admin Tools 7.9.2
================================================================================
+ Obsolete Core Files feature: removing leftover files after a Joomla! update
+ Bad Words: Escape Regular Expression; a plain word matches itself literally
+ Preferred fallback language option for notification emails, and an option to force it for all
+ New --exempt-ip option for the admintools:offline CLI command
~ web.config Maker: remove naming of the rule blocking access to configuration.php-dist and htaccess.txt
# [HIGH] admintools:temp:clear from the CLI never finished on sites with a lot to clean up
# [HIGH] admintools:import from the CLI failed with a fatal error on any file admintools:export had produced
# [HIGH] Emergency Off-Line Mode enabled from the CLI reported success but left the site fully accessible
# [HIGH] Users were never deactivated after too many failed logins, whatever the threshold
# [HIGH] Delete Inactive Users scheduled task always failed; its User Group filter also selected the wrong groups
# [HIGH] PHP File Change Scanner: Mark All Safe ignored every alert with a zero threat score
# [HIGH] PHP File Change Scanner: marking a file safe only worked from the most recent scan's report
# [HIGH] Temporary Super Users: a past expiration date created a permanent, untracked Super User
# [HIGH] Admin Tools' actions were not recorded in the Action Log on sites without Akeeba Backup installed
# [HIGH] Admin Password validation accepted characters outside the allowed set
# [HIGH] DFI Shield did not detect NUL bytes in the request
# [HIGH] Bad Words containing a "#" character never blocked anything
# [MEDIUM] Server configuration makers: exceptions for file / folder names with backslashes were generated wrong
# [MEDIUM] Scheduled tasks: PHP File Change Scanner could give itself an unrealistic one second time limit
# [MEDIUM] Scheduled tasks: Auto Import with invalid URL failed on every run, instead of doing nothing
# [MEDIUM] Emergency Off-Line Mode sent visitors to a broken address
# [MEDIUM] Notification emails were sent in the current site language instead of a predictable one
# [MEDIUM] The email telling you a user account was reactivated after failed logins was never actually sent
# [MEDIUM] PHP File Change Scanner: third party API application files, under api/components, were reported as Non-core
# [MEDIUM] Possible fatal error on future Joomla versions which have removed CMSObject
# [MEDIUM] Third party integration event onAdminToolsThirdpartyException wasn't handled
# [MEDIUM] Third party integration: fatal error when blocked request's extra information was passed as an array
# [MEDIUM] PHP File Change Scanner: internal warnings queue ignored its size limit and grew unbounded
# [MEDIUM] WAF Exceptions and WAF Deny List: filtering the list by two or more columns at once returned the wrong rows
# [MEDIUM] Server configuration makers: exception files list could be corrupted when upgrading
# [MEDIUM] NginX Conf Maker and web.config Maker used .htaccess Maker's list for "Restrict access by IP"
# [MEDIUM] Link Migration would rewrite rewrite look-alike domains such as example.com.evil.net
# [MEDIUM] Folder path validation checked the administrator folder instead of the site folder
# [MEDIUM] IP allow/disallow lists accepted invalid IP values, matching nothing
# [MEDIUM] Quick Setup Wizard generated wrong site-relative paths on sites whose path ends in /api
# [LOW] DFI Shield never detected Windows drive-letter paths such as “C:\Windows\system.ini”
# [LOW] CLI commands displayed relative times in the wrong unit, e.g. "24 hours" instead of "1 day"
# [LOW] Legacy Clean Temporary Files feature logged PHP warning on every pass
# [LOW] Legacy Clean Temporary Files feature hang the page until whole temp directory was cleaned
# [LOW] web.config Maker: blocking access to common server configuration files could also block unrelated files
# [LOW] Configure WAF: Forgotten Users Lockout heading used outdated wording
# [LOW] Fixed missing and malformed translations

Admin Tools 7.9.1
================================================================================
+ PHP File Change Scanner: the scan report's Export CSV button is now a split button with two new options, Export Filtered CSV and Export Paths
~ PHP File Change Scanner: the CSV export of a scan report now also includes the Type and Threat Score columns
~ Workaround for Joomla erroneously reporting our database tables as out of date
# [MEDIUM] PHP File Change Scanner: Non-core files were only reported by the scan which first saw them; they are now reported by every scan for as long as they exist on the site, unless they are Marked Safe. Test case NonCorePersistence.
# [MEDIUM] PHP File Change Scanner: when the CoreSums checksum data could not be downloaded, Joomla's own files under includes/, administrator/includes/ and administrator/help/ were reported as Non-core
# [LOW] PHP File Change Scanner: files under administrator/manifests were reported as Non-core, even though Joomla places them there itself when installing package, file, and library extensions
# [LOW] PHP File Change Scanner: the CSV export of a scan report only exported the page of results currently displayed, and silently applied the filters left over in the session
# [LOW] PHP File Change Scanner: the CSV export of a scan report escaped double quotes with a backslash instead of doubling them, per RFC 4180

Admin Tools 7.9.0
================================================================================
+ Configuration Monitoring: include a diff of the changed settings, redacting sensitive values [#334]
+ PHP File Change Scanner: verify core Joomla files against precomputed checksums
+ Machine-translated the extension into Greek, French, German, Spanish, Italian, and Portuguese
+ UploadShield reloaded: uploaded files are scanned for PHP code, PHP file extensions, multiple extensions, and .htaccess handler overrides [#346]
~ SQLiShield: Improved performance against 1-bit oracle exfiltration attacks
~ PHP File Change Scanner: using transactions to speed up the initial scan
~ Joomla! Update tools now requires global Super User (core.admin) access
~ Control Panel now available to anyone with access to Admin Tools (core.manage)
~ Inaccessible Control Panel feature buttons now hidden instead returning 403 when clicked
~ Joomla 7.0 forward compatibility: replace the removed Joomla.JText JavaScript proxy with Joomla.Text
~ Hardening: bind filter/request values as prepared parameters and use an ORDER BY column/direction allowlist across list views and the WAF [#343]
# [HIGH] .htaccess Maker: "Block access from specific user agents" failed because double quotes were used
# [HIGH] PHP warning "Undefined variable $isHttpsForced" in Admin Secret Word feature on Joomla 5 sites
# [HIGH] Allowed Domains: Quick Setup Wizard rendered the domains as the literal "Array", corrupting the list and locking sites out [#342]
# [HIGH] .htaccess/NginX/web.config Maker: Apply/Save crashed with a fatal error, instead of a warning, when the "Restrict access by IP" cloud provider (e.g. Sucuri) could not be fetched
# [MEDIUM] Block Email Domains on Signup: a specially crafted local part could allow block bypass [#340]
# [MEDIUM] PHPShield: leading whitespace bypassed anchored stream-wrapper check [#338]
# [MEDIUM] PHP File Change Scanner: double-extension scanning would flag some innocuous files
# [MEDIUM] PHP File Change Scanner CLI: warnings issued by the last step, including failures to send the scan results email, were silently discarded
# [MEDIUM] WAF Exception w/out query params had no effect.
# [MEDIUM] Non-Super User access (optional feature) didn't work due to too-tight ACL checks.
# [MEDIUM] PHP File Scanner: the error message for a failed scan was never displayed on screen.
# [MEDIUM] PHP File Change Scanner: the "Possible Threat" status filter excluded new files with a nonzero threat score [#347]
# [LOW] File-diff SequenceMatcher: comments are not rejected during matching [#339]
# [LOW] PHP File Change Scanner: the code default for "Only email when there are actionable items" disagreed with the Options page default on sites which had never saved their Options
# [LOW] Transactional emails were not rendered in the recipient's language
# [LOW] Joomla! Update: group-less plugin/module manifest nodes yielded malformed extension names [#337]
# [LOW] PHP File Change Scanner: missing anti-cache HTTP headers in AJAX endpoints
# [LOW] No logging of forbidden-username blocked logins
# [LOW] Several backend list-view search boxes and filters silently did nothing (auto-ban reason, temporary Super User e-mail search, IP list search, URL redirection published filter) [#344]
# [LOW] Temporary Super Users: the orphaned-record cleanup query was malformed and did not reliably remove orphaned rows [#345]
# [LOW] Improved CSRF checks in multiple backend views

Admin Tools 7.8.9
================================================================================
~ [LOW] Fix PHP 9 deprecations (gh-326)
+ [LOW] Immediate IP block on failed login with a forbidden username
# [HIGH] PHP File Change Scanner could fail on PostgreSQL when "Do not report files with a zero Threat Score" is enabled
# [HIGH] PHP File Change Scanner results page would crash with a fatal error on PostgreSQL sites
# [HIGH] PHP File Change Scanner list page would crash with a fatal error on PostgreSQL sites
# [HIGH] PHP File Change Scanner page would crash with a fatal error on PostgreSQL sites

Admin Tools 7.8.8
================================================================================
+ [LOW] Delete Inactive Users scheduled task: optional User Group filter
# [HIGH] .htaccess Maker outputs blocked user agents rules before RewriteEngine On
# [HIGH] Admin Secret URL Parameter override cookie wouldn't work on Joomla 6 without the backwards compatibility plugin enabled
# [HIGH] Unblock My IP in the Control Panel would fail with a fatal error

Admin Tools 7.8.7
================================================================================
# [HIGH] "Cannot use object of type stdClass as array" on some sites

Admin Tools 7.8.6
================================================================================
+ Override automatic server detection
# [HIGH] Cannot save .htaccess configuration when "Restrict access by IP" is set to Custom
# [HIGH] Cannot combine "Restrict access by IP" with "User agents to block"
# [LOW] Litespeed was incorrectly treated as Apache 2.0 when it supports Apache 2.4 syntax

Admin Tools 7.8.5
================================================================================
# [HIGH] Restrict access by IP range could fail on Joomla 6 (core b/c break)
# [HIGH] Resetting Joomla Update would fail on Joomla 6 (core b/c break)
# [HIGH] Warn About Leaked Passwords would fail on Joomla 6 (core b/c break)
# [MEDIUM] Dropdown labels were swapped in the Delete inactive users scheduled task.

Admin Tools 7.8.4
================================================================================
~ PHP 8.5 compatiblity
# [LOW] PHP warning in Web.Config Maker coming from a reference to an obsolete feature

Admin Tools 7.8.3
================================================================================
~ Support for Joomla 6
~ PHP 8.5 compatibility: setAccessible is deprecated for reflection objects
~ PHP 8.5 compatibility: implicit nullable method parameters are deprecated

Admin Tools 7.8.2
================================================================================
~ Preliminary support for Joomla 6
~ Removed wget and pycurl from the default list of blocked user agent strings
~ Improve Unicode support in Bad Words filtering
# [HIGH] Fatal error on blocked request under Joomla 5 and PostgreSQL due to internal J5 changes
# [LOW] PHP warning 'Attempt to read property "guest" on null' in some rare admin login failure cases
# [LOW] Misnamed column in the URL redirects table for PostgreSQL

Admin Tools 7.8.1
================================================================================
~ Preliminary support for Joomla 6
~ No longer report Joomla API application's NotAcceptable exception by email.
# [MEDIUM] Cannot mark a file safe from the Examine File page
# [LOW] Limit for disabled obsolete admins check was too low in the interface
# [LOW] Custom block page should never be triggered in the backend of the site
# [LOW] "Warn about use of well-known passwords" will throw a PHP error if the user groups for the feature have not been set

Admin Tools 7.8.0
================================================================================
+ Server config maker: restrict access to specific IP ranges [gh-324]
# [HIGH] The HSTS header was not output when using the .htaccess Maker
# [HIGH] A PHP warning in “Block common usernames” occurring under some circumstances may prevent the feature from working reliably
# [MEDIUM] NginX Conf Maker didn't use the X-Forwarded-Proto HTTP header when redirecting

Admin Tools 7.7.2
================================================================================
+ Support for top-level files folder introduced in Joomla! 5.3.0
# [MEDIUM] Monitor Super User accounts ignores its configured email address
# [MEDIUM] Redirect non-www to www didn't work correctly on hosts relying on X-Forwarded-Proto to detect HTTPS
# [MEDIUM] HSTS HTTP header not set on hosts relying on X-Forwarded-Proto to detect HTTPS
# [LOW] Features using SuperUsersTrait generate a deprecated notice
# [LOW] Error, or inconsistent output when deleting the last item of the last page of a list
# [LOW] Multiple email addresses required comma and space as a separator instead of just a comma

Admin Tools 7.7.1
================================================================================
+ Specific email recipients for configuration, files, and super users monitoring [gh-322]
~ Replace defunct ip-lookup.net with whatismyipaddress.com
~ Allowed Domains now returns the more appropriate HTTP status 421
# [HIGH] Using “Show errors using a customisable HTML template” with the “System - Page Cache” plugin enabled could lead to the home page showing the block error message
# [HIGH] CLI WAF set command cannot set 0 value to boolean options
# [LOW] Hiding the message about blocked requests email wouldn't work with cahcing enabled
# [LOW] Fixed PHP warnings while using the Htaccess Maker and frontend/backend protection is not enabled
# [LOW] Missing translation keys for CLI server config set commands
# [LOW] Redirection loop on HTTPS under some proxied configurations

Admin Tools 7.7.0
================================================================================
+ Notify about an increase in blocked requests [gh-319]
+ Notify when the Email on Blocked Request is enabled [gh-321]
+ `admintools:temp:clear` CLI command to clean the temporary directory
~ Make accurate PHP CLI path detection optional
~ Better IP filtering, now supports IPv4-in-IPv6
~ Simplify and improve the Clean Temp-Directory feature
# [LOW] PHP Warning when a blocked request does not have a User-Agent string
# [LOW] Unblock My IP erroneously appears in the Core version's Control Panel page
# [LOW] Typo in MailTemplateHotFix

Admin Tools 7.6.2
================================================================================
+ Backend management tables multiple row select and column hiding support
+ Automatically delete Temporary Super Users no longer linked to Joomla users
+ Accurate PHP CLI path in the PHP File Change Scanner Scheduling page
+ Added support for multiple email addresses inside WAF configuration
+ Password hashing algorithm selection for password-protect admin folder
+ Workaround for Joomla! 5.2 broken mail template layout
~ Updated list of user agents to block
~ Updated list of forbidden usernames
# [LOW] In some specific circumstances, WAF exceptions were not correctly applied
# [LOW] Do not produce a fatal error if we get an error while saving IP address on new user registration

Admin Tools 7.6.1
================================================================================
# [HIGH] Possible PHP error whilst logging a blocked request

Admin Tools 7.6.0
================================================================================
+ Reset Joomla! Update feature from the backend, the CLI, or a Scheduled Task
~ PHP File Change Scanner will now strip comments before evaluating the Threat Score
~ Support for Joomla! 5.1's createQuery method in the db object
~ Workaround for PRE element styling in Joomla! 5.1
~ Workaround for Joomla! 5.1 CSS in alert DIVs
~ Possible Threat files do not count towards modified files
~ Rewritten WAF request block to better handle concurrent attacks
~ PHP 8.4 compatibility: MD5 and SHA-1 functions are deprecated

Admin Tools 7.5.4
================================================================================
+ Improved support for Joomla! 5.1's backend colour schemes
+ You can now choose the action for an invalid administrator secret URL parameter
+ Components sidebar menu item to open the appropriate server config maker for your site
~ Remove Itemid from Suspicious Core Parameters, it has its own feature (ItemidShield)
~ Re-arrange order of execution to process IP blocks before request blocking features

Admin Tools 7.5.3
================================================================================
~ Allow empty Itemid even when Suspicious Core Parameters feature is enabled
# [MEDIUM] Server configuration maker: Fixed fatal error when web servers different than Apache are used
# [MEDIUM] Joomla does not return the plugin ID when it's disabled, leading to broken links in the UI

Admin Tools 7.5.2
================================================================================
! Joomla-recommended .htaccess code was breaking the site

Admin Tools 7.5.1
================================================================================
! Detecting PHP handlers can break if there is no .htaccess file yet
! Error in version.php breaks the control panel interface

Admin Tools 7.5.0
================================================================================
+ Improved support for Joomla! 5.1 dark mode
+ Detect and import PHP version directives into the .htaccess Maker
~ Reintroduce old value format workarounds for people being late to upgrading from Joomla! 3.x
# [LOW] Admin Tools Core showed an (unsupported) URL Redirection menu item
# [LOW] Some numeric Configure WAF options did not have their limits enforced
# [LOW] Double Gzip/Brotli compression for some core Joomla! files when both compression algorithms are supported

Admin Tools 7.4.9
================================================================================
+ Optional description field on “Never block these IPs” and “Never blocked domains”
# [MEDIUM] Suspicious Core Parameter always applied the cmd filter, leading to false positives

Admin Tools 7.4.8
================================================================================
~ Added more options to the not log and not email for the reasons options

Admin Tools 7.4.7.1
================================================================================
! CSS compilation error

Admin Tools 7.4.7
================================================================================
+ WAF Exceptions now work with the Block Suspicious URL Parameters feature
+ Updated environment stats collection code
~ Even better workaround for the Joomla! Database Maintenance page bug
# [HIGH] Running the Auto-import configuration scheduled task from CLI results in PHP error
# [MEDIUM] Could not turn off Block Suspicious URL Parameters

Admin Tools 7.4.6
================================================================================
~ Workaround for the Joomla! bug making it erroneously claim in the Maintenance: Database page that Admin Tools' database tables are not up-to-date when they actually are.

Admin Tools 7.4.5
================================================================================
+ Block Suspicious Core Parameters feature
~ The reason in emails reporting a blocked IP was always reported as blocked IP which wasn't useful
~ Move Unblock My IP into Security when not showing the Graphs panel to balance the display
# [HIGH] The “Add persistent offenders to the IP Disallow List” did not work due to a typo
# [LOW] Missing translations when using Joomla Scheduled Tasks
# [LOW] Missing language string
# [LOW] The additional context of blocked requests was not shown in the Blocked Requests Log page
# [LOW] PHP 8.3 deprecated notice in ComponentParameters service (no functional issue)
# [LOW] PHP deprecated notice about implicit float to integer conversion on PHP File Change Scanner (no functional issue)

Admin Tools 7.4.4
================================================================================
+ Support bCrypt encryption for Administrator Password Protection on Apache 2.4+
# [HIGH] NginX Conf Maker: Backend protection would make backend unavailable in newer NginX versions
# [LOW] URL Redirections appears in the Core version, even though it won't do anything; removed
# [LOW] Htaccess Maker: Fixed PHP notices when a particular combination of options was used
# [LOW] HSTS option UI wouldn't let you turn it off

Admin Tools 7.4.3
================================================================================
+ Support for Joomla! 5 installations with a custom public folder
~ Joomla 5 Dark Mode workarounds

Admin Tools 7.4.2
================================================================================
# [HIGH] PHP File Change Scanner scans all files, regardless of the configured extensions

Admin Tools 7.4.1
================================================================================
# [LOW] Possible PHP error when updating this along other extensions using the same post-installation script

Admin Tools 7.4.0
================================================================================
+ Notice about Joomla 4 End of Service
+ Improve HSTS support with an option which adds "includeSubDomains; preload" to the header (gh-303)
+ PHP File Change Scanner: options to scan double extensions and case-insensitive extensions
~ Changed the plugins' namespace
~ Joomla 5 preparation: Use DatabaseInterface instead of DatabaseDriver
~ Joomla 5 preparation: Work around backwards incompatible changes in core plugin events
~ Joomla 5 preparation: Normalise plugin event calling
~ Joomla 5 preparation: Plugin event handlers clean-up
~ Joomla 5 preparation: Loading form data MUST NOT return a Table anymore
~ Joomla 5 preparation: PHP File Change Scanner would not work (type error)
# [LOW] Fixed deleting or unpublishing an entry in the WAF Deny List

Admin Tools 7.3.4
================================================================================
~ Block uninstallation of child extensions
# [LOW] opcache_invalidate may not invalidate a file
# [LOW] Wrong documentation link in the scheduling information page
# [LOW] Cannot save the password authentication when WebAuthn preference is enabled from the regular user edit page

Admin Tools 7.3.3
================================================================================
~ Workaround for CloudAccess and other hosts with broken Apache installations that don't understand the If directive
~ Only show warning to regenerate the server configuration file if it was generated by Admin Tools to begin with
# [MEDIUM] PHP File Change Scanner fails when the MySQL packet size is too small (e.g. 1MiB) when scanning big files (>400KiB) with Calculate File Diffs enabled
# [LOW] “Disable editing user properties for these user groups” does not let you select the Super Users group

Admin Tools 7.3.2
================================================================================
# [LOW] Fixed display issue on the Scheduling Information page

Admin Tools 7.3.1
================================================================================
# [HIGH] Quick Setup Wizard will still apply the wrong site root path to the .htaccess Maker

Admin Tools 7.3.0
================================================================================
+ IP Allow List
# [HIGH] Password-protect administrator can get in the way of www to non-www redirection
# [HIGH] Empty “Force HTTPS for these URLs” will cause any HTTP access to redirect to the HTTPS site's root
# [HIGH] Quick Setup Wizard will apply the wrong site root path to the .htaccess Maker
# [MEDIUM] Plugins not enabled on clean installation
# [MEDIUM] Configuration monitor was not executing
# [LOW] .htaccess Maker accidentally prevented the use of Authorization HTTP headers with Joomla
# [LOW] Saving WAF Configuration may fail if there are empty items in subform fields

Admin Tools 7.2.3
================================================================================
+ Block Joomla API exploitation in Joomla 4.0 to 4.2
# [LOW] Wrong label for Mark Safe / Unsafe in PHP File Change Scanner reports' toolbar
# [LOW] Automatic IP blocking notification email: wrong shortcode for IP lookup

Admin Tools 7.2.2
================================================================================
# [MEDIUM] Temporary Super User could fail on some sites due to an off-by-one error
# [MEDIUM] Email throttling options were not taken into account

Admin Tools 7.2.1
================================================================================
~ Joomla 4.2 changed the togglable inline help without documenting it (b/c break); worked around.
# [HIGH] Some Joomla Tasks are broken
# [HIGH] Importing settings could reply with "No active transaction" in some cases
# [LOW] Wrong grammatical case (nominative instead of genitive) in months in some languages e.g. Greek

Admin Tools 7.2.0
================================================================================
+ You can now choose User Notes category while saving the IP used during sign-up
- Removed PHP version warnings. This is now included in Joomla itself.
- Removed the PHP Easter Eggs option from the .htaccess Maker and similar features
~ Requires Joomla 4.2 or later
~ Requires PHP 7.4.0 or later
~ Password Protect Administrator: improve compatibility with Apache 2.2 and 2.4
~ Changed all Control Panel warnings to much more compact DETAILS elements
~ The “Exclusive Allow IP List” setting in Quick Setup is now Off by default
# [LOW] In some server configurations, the IP of the user was never saved, even if requested to

Admin Tools 7.1.11
================================================================================
+ Help buttons
~ Improved component menu with relevant quick actions
~ Save and Save & Close buttons are now separate, as per Joomla 4.2 UI guidelines
# [MEDIUM] Password-protect Administrator: Fixed disabling this feature from the web interface
# [LOW] Critical Files and Custom Critical Files may result in a PHP Warning if you upgraded from Joomla 3 but never visited and saved the WAF Configuration under Joomla 4

Admin Tools 7.1.10
================================================================================
~ Updated troubleshooting URLs to point to the correct Joomla 4–specific documentation page
# [LOW] PHP 8.1 deprecated notice when checking if FOF is still installed
# [LOW] Enabling the exception handler email and disabling sending emails in Global Configuration results in the wrong error being displayed in the Joomla frontend.

Admin Tools 7.1.9
================================================================================
- Removed the Enable IP Workarounds option
# [MEDIUM] NginX Configuration Maker: frontend protection would make the backend templates' static media fail to load
# [LOW] Custom Monitored Files changed are not reported and cause a PHP notice
# [LOW] Regression: Multiple email addresses were not allowed in Configure WAF options
# [LOW] Htaccess Maker: Fixed explicitly allowing CORS
# [LOW] NginX Conf Maker: Fixed explicitly allowing CORS
# [LOW] WebConfig Maker: Fixed explicitly allowing CORS
# [LOW] Possible PHP 8 exception if you have upgraded from Admin Tools 6 or earlier, have set up no logging for specific reasons and have not clicked on Save & Close in the Configure WAF page since upgrading to Admin Tools 7.

Admin Tools 7.1.8
================================================================================
# [HIGH] Running the PHP File Change Scanner with the frontend URL doesn't work

Admin Tools 7.1.7
================================================================================
+ Added .xsl to the allowed file types in front- and backend directories in the .htaccess Maker, NginX Conf Maker and web.config Maker
+ Add Show Inline Help support in component options for Joomla 4.1
# [LOW] Fixed displaying Rescue URL info inside message shown to blocked users
# [LOW] Rare concurrency issue saving critical files into the database could cause an exception during a page load

Admin Tools 7.1.6
================================================================================
+ Better message when Allowed Domains kicks in to help you figure out what went wrong and how to fix it
+ Automatically allow TinyMCE plugins when “Disable client-side risky behavior in frontend static content” is enabled.
~ Improved handling of empty multi-selection fields in the Configure WAF page
# [MEDIUM] Upgrading from Admin Tools 6 with non-empty Allowed Domains would break the migrated site
# [MEDIUM] Defining redirections with query parameters could result in the wrong redirection taking place
# [MEDIUM] Nginx Maker: Fixed fatal error under PHP 8 and "Reverse Proxy / Load Balancer IPs" are used

Admin Tools 7.1.5
================================================================================
# [HIGH] Many emails are not sent by the plugin
# [MEDIUM] Cannot ban/unban an IP in Blocked Requests page because of backwards incompatible changes in Joomla 4.1.1
# [LOW] Fixed choosing the correct option in "Browser cookie override for the administrator secret URL parameter"

Admin Tools 7.1.4
================================================================================
# [LOW] Blocked Requests Log: Filtering by reason was not working (gh-272)
# [LOW] A “Show Inline Help” button is shown in .htaccess Maker but there never was any inline help or tooltips for it.

Admin Tools 7.1.3
================================================================================
# [HIGH] Wrong base directory created by the Quick Start (gh-271)
# [HIGH] Core edition would block IPs when third party extensions were using the third party blocked requests feature of Admin Tools even though they cannot be managed in the Core edition
# [HIGH] Delete Inactive Users may make the site inaccessible due to a database error
# [LOW] Cannot delete Auto IP Blocking History entries
# [LOW] Missing language string COM_ADMINTOOLS_WAFEMAILTEMPLATE_REASON_IPAUTOBAN

Admin Tools 7.1.2
================================================================================
+ Temporary Super User passwords respect Joomla's password options (gh-265)
+ Support for Joomla 4.1's Inline Help in several pages of the component
+ Support for Joomla 4.1's Scheduled Tasks for the PHP File Change Scanner
+ Joomla Scheduled Tasks to replace the pseudo-scheduling in the system plugin
+ Set a persistent cookie for Administrator Secret URL Parameter (gh-268)
# [HIGH] Unhandled exception when you have turned off email sending in your site's Global Configuration
# [HIGH] Sometimes you get an immediate fatal error when a request is blocked

Admin Tools 7.1.1
================================================================================
+ Export/Import the list of blocked User Agents
# [LOW] Database update might fail on some servers

Admin Tools 7.1.0
================================================================================
+ Disable password reset for specific user groups
+ Server config makers: always allow access to HTML files used in TinyMCE plugins (used by Joomla's default TinyMCE editor)
~ Improved Quick Setup Wizard default settings for the .htaccess Maker
~ Quick Setup Wizard: Allowed domains resolving to IP 127.0.0.1 or ::1 will not be applied (automatically allowed anyway)
~ Aligned default option values visible in the Configure WAF page with what the WAF does if you do not save its configuration through the Configure WAF page
# [HIGH] The Rescue URL instructions did not work throughout the 7.0 release cycle
# [MEDIUM] The “Email PHP Exceptions to this address” feature did not do anything when enabled
# [MEDIUM] PHP warning when applying the Quick Setup Wizard when the option to create a .htaccess file is checked
# [LOW] The user actions log entries for some actions was badly formatted

Admin Tools 7.0.10
================================================================================
- Removed “Convert all links to HTTPS” feature; it has stopped making sense since circa 2015.
# [HIGH] Cannot edit component Options on PHP 8 if the site had Admin Tools 5.1.0 to 5.7.2 installed in the past
# [HIGH] Emails not being sent on security exception (gh-260)
# [MEDIUM] 404Shield: PHP 8 error when legacy data is stored in the configuration
# [LOW] PHP File Change Scanner: Marked As Safe filter not applied viewing scan results (ak-36399)

Admin Tools 7.0.9
================================================================================
# [HIGH] 404Shield wouldn't work reliably
# [LOW] Database warning in some update scenarios

Admin Tools 7.0.8
================================================================================
# [MEDIUM] Missing option “IP blocking of repeat offenders” could result in no IP auto–blocking

Admin Tools 7.0.7
================================================================================
~ Ensure the correct collation of all database tables and columns used by the extension
# [HIGH] PHP 8 error in NginXConfigMaker when legacy data is stored in the configuration
# [MEDIUM] Cannot delete Temporary Super Users
# [MEDIUM] Server protection: typo in the default value for field "Backend directories where file type exceptions are allowed"
# [LOW] Bootstrap 5.1.2 included in Joomla 4.0.4 broke the CSS for Control Panel icons
# [LOW] Blocked requests log: Fixed fatal error while banning or unbanning an IP

Admin Tools 7.0.6
================================================================================
~ Backend/Frontend file types allowed in selected directories are now case–insensitive
# [HIGH] Database error on blocked request if the Do Not Log Reasons list is empty
# [HIGH] PHP error if you leave “Do not send email notifications for these reasons” empty.
# [HIGH] PHP 8 error using the Quick Setup Wizard
# [HIGH] PHP 8 error in some features when legacy data is stored in the configuration
# [MEDIUM] Emails not sent for successful/failed admin long, critical files monitoring and super users monitoring
# [MEDIUM] Exception emails were missing information
# [MEDIUM] Feature "Change administrator login directory to" was missing

Admin Tools 7.0.5
================================================================================
+ Support improved Joomla Update in Joomla 4.0.4
~ Fixed padding in some modal dialogs
# [HIGH] Temporary Super Users still had a problem with a class name having the wrong case
# [HIGH] “Neutralise SVG execution” in NginX Conf Maker would cause a server error.
# [MEDIUM] Fatal error in Quick Start wizard on first installation if you choose to create a security–tightened .htaccess file
# [MEDIUM] Fix Permissions feature was stuck in a endless loop
# [LOW] Troubleshooting emails do not include troubleshooting links due to a typo
# [LOW] Features Bad Words Filtering and Quick Start reminder were always disabled
# [LOW] NginX Conf Maker doesn't show the right path in the message telling you how to enable the custom config file.
# [LOW] Check Temp and Log modal window didn't automatically close

Admin Tools 7.0.4
================================================================================
~ Improve language string
# [MEDIUM] Fixed fatal error while trying to access Temporary Super Users page
# [MEDIUM] Main Password feature wasn't blocking unprotected access
# [LOW] Wrong email subject for blocked requests
# [LOW] Default update server URL in the XML manifest is wrong
# [LOW] Links in the Statistics box below the Control Panel graphs result in an error

Admin Tools 7.0.3
================================================================================
~ Main Password: only show the views present in the Core or Pro version you have installed
~ Prevent accidental installation attempt on Joomla 3
# [HIGH] Admin Tools Core: Control Panel fails with PHP error
# [HIGH] Installation from scratch on Joomla 4 doesn't work due to a SQL error
# [MEDIUM] The Action Log plugin does not let you use any component action logged by it when enabled
# [MEDIUM] Quick Start: Allowed Domains option did not display at all and could not be applied
# [LOW] Htaccess Maker etc preview doesn't work
# [LOW] PHP Notice in Main Password's switch fields due to change made in Joomla 4.0 stable.
# [LOW] Downgrading from Pro to Core didn't work correctly
# [LOW] PHP Warning on first run of Pro version because the .htaccess Maker is not yet configured

Admin Tools 7.0.2
================================================================================
# [HIGH] Core version does not install due to an XML manifest typo
# [HIGH] Does not work on Windows on the latest Joomla 4 RC versions

Admin Tools 7.0.1
================================================================================
# [HIGH] Impossible to save the WAF configuration on some sites upgraded from 6.x which had some options empty.
# [MEDIUM] Extensions not enabled automatically on installation.

Admin Tools 7.0.0
================================================================================
+ Rewritten with Joomla 4 core MVC and Bootstrap 5 native styling
+ Option to disable password logins when WebAuthn is enabled for a user account (gh-248)
