<?php
/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\AdminTools\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\AdminTools\Administrator\Helper\Extensions;
use Akeeba\Component\AdminTools\Administrator\Helper\DbQuery;
use DirectoryIterator;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

#[\AllowDynamicProperties]
class UpgradeModel extends BaseModel implements DatabaseAwareInterface
{
	use DatabaseAwareTrait;

	/**
	 * Name of the package being replaced
	 *
	 * @var   string
	 */
	private const OLD_PACKAGE_NAME = '';

	/**
	 * Name of the new package this component belongs to
	 *
	 * @var   string
	 */
	private const PACKAGE_NAME = 'pkg_admintools';

	/**
	 * Files and folders to remove from both Core and Pro versions
	 *
	 * @var array[]
	 */
	private const REMOVE_FROM_ALL_VERSIONS = [
		'files'   => [
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/CliCommand/MixIt/ArgumentUtilities.php',
			// Moved to src/Helper/Complexify.php in 7.10.0
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Scanner/Complexify.php',

			// Legacy plugin files
			JPATH_PLUGINS . '/system/admintools/admintools.php',

			// Legacy usage stats collection
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Helper/usagestats.php',
		],
		'folders' => [
			// Legacy traits
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/Mixin',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Dispatcher/Mixin',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/Mixin',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/Mixin',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Mixin',

			// Legacy error handler
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/common',
		],
	];

	/**
	 * Files and folders to remove ONLY from the Core version
	 *
	 * @var array[]
	 */
	private const REMOVE_FROM_CORE = [
		'files'   => [
			// Plugin features, Pro version only
			JPATH_PLUGINS . '/system/admintools/src/Feature/AdminIPExclusiveAllow.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/AdminSecretWord.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/AllowedDomains.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/AwaySchedule.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/BadwordsFiltering.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/BlockedEmailDomainsOnSignup.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/BrowserConsoleWarning.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CacheCleaner.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CacheExpiration.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CleanTemporaryFiles.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ConfigurationMonitoring.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CriticalFilesMonitoring.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CustomAdminFolder.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CustomBlockedRequestPage.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CustomCriticalFilesMonitoring.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CustomGeneratorMeta.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/DeleteInactiveUsers.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CrawlerIPBlock.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/DFIShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/DisableObsoleteAdmins.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/DoNoCreateNewAdmins.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/EmailOnFailedAdminLogin.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/EmailOnPHPException.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/EmailOnSuccessfulAdminLogin.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/EnforceIPAutoBan.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/FixApache401.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ImportSettings.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/IPDenyList.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/MUAShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/NoFrontendSuperUserLogin.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/PHPShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ProjectHoneypot.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ProtectAgainstDeactivation.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/QuickstartReminder.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/RemoveOldLogEntries.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ResetJoomlaTFAOnPasswordReset.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/RFIShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SaveUserSignupIPAsNote.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ServerConfigReminder.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SessionCleaner.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SessionOptimiser.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SessionShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/Shield404.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SQLiShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SuperUsersList.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/TemplateSwitch.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/TemporarySuperUsers.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/TmplSwitch.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/TrackFailedLogins.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/WAFDenyList.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/WarnAboutLeakedPasswords.php',

			// Component pro features, Controller
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/AdminallowlistController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/AdminallowlistsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/AutobannedaddressController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/AutobannedaddressesController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/BadwordController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/BadwordsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/BlockedrequestslogController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ConfigurewafController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/DisallowlistController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/DisallowlistsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/EmailtemplatesController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ExportimportController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/HtaccessMakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/IPAutoBanHistoriesController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/IPAutoBanHistoryController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/NginXConfMakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/QuickstartController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScanAlertController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScanAlertsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScanController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScansController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/SchedulingInformationController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ServerConfigMakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/UnblockIPController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/UrlredirectionController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/UrlredirectionsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WAFDenyListController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WAFDenyListsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WAFExceptionController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WAFExceptionsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WebApplicationFirewallController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WebConfigMakerController.php',

			// Component pro features, Model
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/AdminallowlistModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/AdminallowlistsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/AutobannedaddressModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/AutobannedaddressesModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/BadwordModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/BadwordsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/BlockedrequestslogModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ConfigurewafModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/DisallowlistModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/DisallowlistsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ExportimportModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/HtaccessMakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/IPAutoBanHistoriesModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/IPAutoBanHistoryModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/NginXConfMakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/QuickstartModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ScanAlertModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ScanAlertsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ScanModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ScansModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/SchedulingInformationModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ServerConfigMakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/UnblockIPModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/UrlredirectionModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/UrlredirectionsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WAFDenyListModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WAFDenyListsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WAFExceptionModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WAFExceptionsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WebConfigMakerModel.php',

			// Component pro features, Table
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/AdminallowlistTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/AutobannedaddressTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/BadwordTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/BlockedrequestslogTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/DisallowlistTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/IPAutoBanHistoryTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/ScanAlertTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/ScanTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/UrlredirectionTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/WAFDenyListTable.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/WAFExceptionTable.php',

			// Crawler IP lists (Pro only): form fields
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Field/CrawlerIPHelpField.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Field/CrawlerIPStatusField.php',

			// Joomla is being stupid, telling people the DB is in the wrong state (IT IS NOT!)
			JPATH_ADMINISTRATOR . '/components/com_admintools/sql/updates/mysql/7.1.1-20220209.sql',
			JPATH_ADMINISTRATOR . '/components/com_admintools/sql/updates/mysql/7.4.5-20231214.sql',
			JPATH_ADMINISTRATOR . '/components/com_admintools/sql/updates/mysql/7.8.10-20260706.sql',

			// Site Health Check (Pro only)
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/SitehealthcheckController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/SitehealthcheckModel.php',
			JPATH_ROOT . '/media/com_admintools/js/SiteHealthCheck.js',
			JPATH_ROOT . '/media/com_admintools/js/SiteHealthCheck.min.js',

			// Pro-only component files, named exactly as on disk (paths are case sensitive)
			JPATH_ROOT . '/media/com_admintools/js/ConfigureWafCrawlers.min.js',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/HtaccessmakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/IpautobanhistoriesController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/NginxconfmakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScanalertController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ScanalertsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/SchedulinginformationController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/ServerconfigmakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/UnblockipController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WafdenylistController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WafdenylistsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WafexceptionController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WafexceptionsController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WebapplicationfirewallController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Controller/WebconfigmakerController.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/BlockedrequestslogsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/HtaccessmakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/IpautobanhistoriesModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/IpautobanhistoryModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/NginxconfmakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ScanalertModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ScanalertsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/SchedulinginformationModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/ServerconfigmakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/UnblockipModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WafdenylistModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WafdenylistsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WafexceptionModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WafexceptionsModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Model/WebconfigmakerModel.php',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Table/ScanalertTable.php',
			JPATH_ROOT . '/media/com_admintools/js/Scan.min.js',

			// System plugin features, Pro only (not part of the Core plugin)
			JPATH_PLUGINS . '/system/admintools/src/Feature/BadWordsFiltering.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/BlockForbiddenUsernameLogin.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/CustomErrorPageUncache.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/DebugLogFileReminder.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/DisablePwdReset.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/EmailOnBlockedReminder.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/ItemidShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/PWAuthOnWebAuthn.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/QuickStartReminder.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/SuspiciousCoreParams.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/UploadShield.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/URLRedirections.php',
			JPATH_PLUGINS . '/system/admintools/src/Feature/WarnAboutBlockedUsernames.php',
		],
		'folders' => [
			// Component pro features, View
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Adminallowlist',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Adminallowlists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Autobannedaddresses',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Badword',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Badwords',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Blockedrequestslog',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Configurewaf',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Disallowlist',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Disallowlists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Emailtemplates',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Exportimport',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/HtaccessMaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/IPAutoBanHistories',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/NginXConfMaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Quickstart',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Scan',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Scans',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/ScanAlert',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/ScanAlerts',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/SchedulingInformation',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/UnblockIP',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Urlredirection',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Urlredirections',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/WAFDenyList',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/WAFDenyLists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/WAFException',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/WAFExceptions',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/WebApplicationFirewall',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/WebConfigMaker',

			// Component pro features, view templates
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/adminallowlist',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/adminallowlists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/autobannedaddresses',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/badword',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/badwords',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/blockedrequestslog',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/configurewaf',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/disallowlist',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/disallowlists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/emailtemplates',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/exportimport',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/htaccessmaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/ipautobanhistories',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/nginxconfmaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/quickstart',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/scan',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/scans',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/scanalert',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/scanalerts',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/schedulinginformation',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/unblockip',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/urlredirection',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/urlredirections',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/wafdenylist',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/wafdenylists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/wafexception',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/wafexceptions',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/webapplicationfirewall',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/webconfigmaker',

			// Component pro features, other backend classes
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Scanner',

			// Crawler IP lists (Pro only): service classes and layout
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/Helper/CrawlerIP',
			JPATH_ADMINISTRATOR . '/components/com_admintools/layouts/crawlerips',

			// Site Health Check (Pro only)
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/HealthCheck',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Sitehealthcheck',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/sitehealthcheck',
			JPATH_ADMINISTRATOR . '/components/com_admintools/layouts/akeeba/admintools/sitehealthcheck',

			// Pro-only component folders, named exactly as on disk (paths are case sensitive)
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Htaccessmaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Ipautobanhistories',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Nginxconfmaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Scanalert',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Scanalerts',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Schedulinginformation',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Unblockip',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Wafdenylist',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Wafdenylists',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Wafexception',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Wafexceptions',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Webapplicationfirewall',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/View/Webconfigmaker',
			JPATH_ADMINISTRATOR . '/components/com_admintools/src/CliCommand',
			JPATH_ADMINISTRATOR . '/components/com_admintools/tmpl/scanner',
			JPATH_SITE . '/components/com_admintools/src/Controller',
			JPATH_SITE . '/components/com_admintools/src/View',
			JPATH_SITE . '/components/com_admintools/tmpl',

			// System plugin folders, Pro only (not part of the Core plugin)
			JPATH_PLUGINS . '/system/admintools/assets',
			JPATH_PLUGINS . '/system/admintools/forms',
			JPATH_PLUGINS . '/system/admintools/src/Feature/Mixin',
			JPATH_PLUGINS . '/system/admintools/tmpl',
			JPATH_ROOT . '/media/plg_system_admintools',
		],
	];

	/** @var string[] Included extensions to automatically publish on a NEW INSTALLATION */
	private const ENABLE_EXTENSIONS = [
	];

	/** @var string[] Included extensions to automatically publish on NEW INSTALLATION OR UPGRADE */
	private const ALWAYS_ENABLE_EXTENSIONS = [
		'plg_console_admintools',
		'plg_task_admintools',
		'plg_system_admintools',
	];

	/** @var string[] Extensions to always uninstall if they are still installed (runs on install and upgrade) */
	private const REMOVE_EXTENSIONS = [
		'plg_system_atoolsjupdatecheck',
		'file_admintools',
		'plg_installer_admintools',
	];

	/** @var string[] Included extensions to be uninstalled when installing the Core version */
	private const PRO_ONLY_EXTENSIONS = [
		'plg_console_admintools',
		'plg_task_admintools',
	];

	/** @var string Relative directory to the custom handlers */
	private const CUSTOM_HANDLERS_DIRECTORY = 'UpgradeHandler';

	/**
	 * List of extensions included in both old and new packages (if applicable)
	 *
	 * @var   array
	 */
	private $extensionsList;

	/**
	 * UpgradeModel custom handlers, implementing custom logic for each extension.
	 *
	 * @var object[]
	 */
	private $customHandlers = [];

	public function init()
	{
		// Find out the common extensions
		if ($this->isSamePackage())
		{
			$this->extensionsList = $this->getExtensionsFromPackage(self::PACKAGE_NAME);
		}
		else
		{
			$oldExtensions        = $this->getExtensionsFromPackage(self::OLD_PACKAGE_NAME);
			$newExtensions        = $this->getExtensionsFromPackage(self::PACKAGE_NAME);
			$this->extensionsList = array_intersect($newExtensions, $oldExtensions);
		}

		// Load extension-specific adapters
		$this->loadCustomHandlers();
	}

	/**
	 * Handles the package's post-flight routine
	 *
	 * @param   string               $type    Which action is happening (install|uninstall|discover_install|update)
	 * @param   PackageAdapter|null  $parent  The object responsible for running this script. NULL if running outside
	 *                                        of the package's script.
	 *
	 * @return  bool
	 */
	public function postflight(string $type, ?PackageAdapter $parent = null): bool
	{
		switch ($type)
		{
			// Brand new installation (regular or through Discover)
			case 'install':
			case 'discover_install':
				$this->runIsolated([
					'upgradeFromOldPackage',
					'uninstallExtensions',
					'publishExtensionsOnInstall',
					'publishExtensionsAlways',
					'removeObsoleteFiles',
					'adoptMyExtensions',
					'refreshUpdateSites',
				]);

				$this->runCustomHandlerEvent('onInstall', $type, $parent);
				break;

			// Update to a new version
			case 'update':
			default:
				$this->runIsolated([
					'upgradeFromOldPackage',
					'removeObsoleteFiles',
					'publishExtensionsAlways',
					'uninstallExtensions',
					'uninstallProExtensions',
					'adoptMyExtensions',
					'refreshUpdateSites',
				]);

				$this->runCustomHandlerEvent('onUpdate', $type, $parent);
				break;

			// Uninstallation
			case 'uninstall':
				$this->runCustomHandlerEvent('onUninstall', $type, $parent);
				break;
		}

		return true;
	}

	/**
	 * Runs an event across all custom handler objects.
	 *
	 * @param   string  $eventName     The name of the event to run
	 * @param   mixed   ...$arguments  Arguments to the event
	 *
	 * @return  array  The results of the custom handler events.
	 */
	public function runCustomHandlerEvent(string $eventName, ...$arguments): array
	{
		$result = [];

		foreach ($this->customHandlers as $adapter)
		{
			if (!method_exists($adapter, $eventName))
			{
				continue;
			}

			try
			{
				$result[] = $adapter->{$eventName}(...$arguments);
			}
			catch (Throwable $e)
			{
				if (defined('JDEBUG') && JDEBUG)
				{
					Factory::getApplication()->enqueueMessage($e->getMessage());
				}
			}
		}

		return $result;
	}

	/**
	 * Returns the extension ID for a Joomla extension given its name.
	 *
	 * This is deliberately public so that custom handlers can use it without having to reimplement it.
	 *
	 * @param   string  $extension  The extension name, e.g. `plg_system_example`.
	 *
	 * @return  int|null  The extension ID or null if no such extension exists
	 */
	public function getExtensionId(string $extension): ?int
	{
		return Extensions::getId($extension);
	}

	/**
	 * Adopt the extensions by new package.
	 *
	 * This modifies the package_id column of the #__extensions table for the records of the extensions declared in the
	 * new package's manifest. This allows you to use Discover to install new extensions without leaving them “orphan”
	 * of a package in the #__extensions table, something which could cause problems when running Joomla! Update.
	 *
	 * @return  void
	 */
	public function adoptMyExtensions(): void
	{
		// Get the extension ID of the new package
		$newPackageId = $this->getExtensionId(self::PACKAGE_NAME);

		if (empty($newPackageId))
		{
			return;
		}

		// Get the extension IDs
		$extensionIDs = array_map([$this, 'getExtensionId'], $this->getExtensionsFromPackage(self::PACKAGE_NAME));
		$extensionIDs = array_filter($extensionIDs, function ($x) {
			return !empty($x);
		});

		if (empty($extensionIDs))
		{
			return;
		}

		/**
		 * Looks stupid? This realigns the integer keys because whereIn() expects 0-based, monotonically increasing
		 * array keys. Otherwise it ends up emitting null values. GROAN!
		 */
		$extensionIDs = array_merge($extensionIDs);

		// Reassign all extensions
		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->update($db->quoteName('#__extensions'))
			->set($db->qn('package_id') . ' = :package_id')
			->whereIn($db->qn('extension_id'), $extensionIDs, ParameterType::INTEGER)
			->bind(':package_id', $newPackageId, ParameterType::INTEGER);
		$db->setQuery($query)->execute();
	}

	/**
	 * Handle the package upgrade from the old to the new package.
	 *
	 * These versions would also run on Joomla 4 but are replaced with this new package. Since the package name is
	 * different but some of the included extensions are under the same name we need to deal with them. Namely, we need
	 * to:
	 *
	 * * Change the `package_id` in the `#__extensions` table to that of the new `pkg_akeebabackup` package. This is
	 *   currently not used anywhere(?) but it might be the case that Joomla finalyl decides to prevent standalone
	 *   uninstallation of extensions which are part of a package.
	 * * Remove the extensions from the `#__akeeba_common` entries which mark them as dependent on FOF 3.x or 4.x. This
	 *   is so that FOF 3.x / 4.x can be uninstalled when the old package (`pkg_akeeba`) is being uninstalled, since
	 *   these extensions will NOT be removed with it, per the item below.
	 * * Edit the cached XML manifest file of the old `pkg_akeeba` package so that it doesn't try to uninstall the
	 *   extensions it has in common with the new `pkg_akeebabackup` package. Joomla SHOULD figure this out by means of
	 *   the recorded `package_id` in the `#__extensions` table but it currently doesn't seem to have any code to do
	 *   that. Therefore editing the cached XML manifest is the only reasonable way to do this.
	 *
	 * @return  void
	 * @noinspection PhpUnused
	 */
	protected function upgradeFromOldPackage(): void
	{
		if ($this->isSamePackage())
		{
			$this->unregisterFromFOF('3');
			$this->unregisterFromFOF('4');

			return;
		}

		if (!$this->hasOldPackage())
		{
			return;
		}

		$this->reassignExtensions();
		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$this->unregisterFromFOF('3');
		$this->unregisterFromFOF('4');
		$this->removeExtensionsFromPackageManifest();
	}

	/**
	 * Publish a list of extensions.
	 *
	 * Used to publish various plugins when you install the package.
	 *
	 * @return  void
	 */
	protected function publishExtensionsOnInstall(?array $extensionsList = null): void
	{
		$extensionsList = $extensionsList ?? self::ENABLE_EXTENSIONS;
		$extensionIDs   = array_filter(array_map([$this, 'getExtensionId'], $extensionsList));

		try
		{
			Extensions::setEnabledById($extensionIDs, true);
		}
		catch (RuntimeException $e)
		{
			return;
		}
	}

	protected function publishExtensionsAlways()
	{
		$this->publishExtensionsOnInstall(self::ALWAYS_ENABLE_EXTENSIONS);
	}

	/**
	 * Removes obsolete files and folders.
	 *
	 * This is required because Joomla's extensions installer will only check for the top-level files and directories
	 * listed in the XML manifest. Any folders and files deeper than that will not be removed automatically.
	 *
	 * @return  void
	 * @noinspection PhpUnused
	 */
	protected function removeObsoleteFiles(): void
	{
		// We will definitely remove REMOVE_FROM_ALL_VERSIONS in all versions
		$removeSource = self::REMOVE_FROM_ALL_VERSIONS;
		$isPro        = $this->isPro();

		if (!$isPro)
		{
			$removeSource['files']   = array_merge($removeSource['files'], self::REMOVE_FROM_CORE['files']);
			$removeSource['folders'] = array_merge($removeSource['folders'], self::REMOVE_FROM_CORE['folders']);
		}

		// Remove files
		foreach ($removeSource['files'] as $file)
		{
			if (!is_file($file))
			{
				continue;
			}

			try
			{
				File::delete($file);
			}
			catch (\Exception $e)
			{
				// Swallow.
			}
		}

		// Remove folders
		foreach ($removeSource['folders'] as $folder)
		{
			$this->deleteFolder($folder);
		}
	}

	private function deleteFolder(string $path): bool
	{
		// If the folder does not exist in the requested form return early.
		$hasMixedCase = is_dir($path);

		if (!$hasMixedCase)
		{
			return false;
		}

		// If the folder is all lowercase return early.
		$baseName          = basename($path);
		$lowercaseBaseName = strtolower($baseName);

		if ($baseName === $lowercaseBaseName)
		{
			try
			{
				return $hasMixedCase && Folder::delete($path);
			}
			catch (\Exception $e)
			{
				return false;
			}
		}

		// We have a mixed case folder. Further investigation necessary.
		$altPath      = dirname($path) . '/' . $lowercaseBaseName;
		$hasLowercase = is_dir($altPath);

		// If the lowercase path does not exist we have a case-sensitive filesystem. Return early.
		if (!$hasLowercase)
		{
			try
			{
				return $hasMixedCase && Folder::delete($path);
			}
			catch (\Exception $e)
			{
				return false;
			}
		}

		// Both folders exist. Are they the same?
		$testBasename      = UserHelper::genRandomPassword(8) . '.dat';
		$data              = UserHelper::genRandomPassword(32);
		$lowercaseTestFile = $altPath . '/' . $testBasename;
		$uppercaseTestFile = $path . '/' . $testBasename;

		try
		{
			File::write($lowercaseTestFile, $data);
		}
		catch (\Exception $e)
		{
			// Swallow.
		}

		$readData = file_get_contents($uppercaseTestFile);

		try
		{
			File::delete($lowercaseTestFile);
		}
		catch (\Exception $e)
		{
			// Swallow.
		}

		// The two folders are different. We have a case-sensitive filesystem. Proceed with deletion.
		if ($readData !== $data)
		{
			try
			{
				return Folder::delete($path);
			}
			catch (\Exception $e)
			{
				return false;
			}
		}

		/**
		 * The two folders are identical: we are on a case-insensitive filesystem, and $path and $altPath are two
		 * spellings of one directory. The probe above proves it — a file written through $altPath was read back
		 * through $path — so deleting it removes exactly the folder we were asked to remove, and nothing else.
		 *
		 * We cannot simply delete $path, because we do not know how the entry is actually spelled on disk, and
		 * deleting through the wrong spelling is what the original Windows breakage was about. So we first rename
		 * the folder to a name we chose ourselves, whose spelling is therefore known exactly, and delete that.
		 *
		 * The rename is two-step because renaming Foo to foo is a no-op (or an error) on a case-insensitive
		 * filesystem: the source and destination are the same entry. Going through a uniquely named intermediate
		 * avoids that collision.
		 *
		 * If the deletion fails we still land the folder on its all-lowercase name rather than leaving it under the
		 * intermediate one, so that a failure here leaves the tree tidy — and correctly cased, which matters if the
		 * site is later moved to a case-sensitive filesystem.
		 */
		$intermediateBasename = $lowercaseBaseName . '_' . UserHelper::genRandomPassword(8);
		$intermediatePath     = dirname($path) . '/' . $intermediateBasename;

		try
		{
			Folder::move($path, $intermediatePath);
		}
		catch (\Exception $e)
		{
			// Swallow; the is_dir() check below is what actually decides whether the rename landed.
		}

		/**
		 * Folder::move() reports failure by RETURNING a string ('Rename failed', 'Folder already exists', …) rather
		 * than throwing, so the catch above cannot be relied upon. Check the filesystem instead: if the folder is not
		 * where we just tried to put it, the rename did not happen and there is nothing safe to delete.
		 */
		if (!is_dir($intermediatePath))
		{
			return false;
		}

		try
		{
			return Folder::delete($intermediatePath);
		}
		catch (\Exception $e)
		{
			// Deletion failed. Fall through and at least leave the folder correctly cased.
		}

		try
		{
			Folder::move($intermediatePath, $altPath);
		}
		catch (\Exception $e)
		{
			// Swallow: nothing further we can do.
		}

		return false;
	}

	/**
	 * Uninstalls the extensions which are marked as always to be uninstalled.
	 *
	 * @return  void
	 * @noinspection PhpUnused
	 */
	protected function uninstallExtensions(): void
	{
		// Tell Joomla to uninstall the extensions always meant to be removed.
		foreach (self::REMOVE_EXTENSIONS as $extension)
		{
			$this->uninstallExtension($extension);
		}
	}

	/**
	 * Uninstalls Pro-only extensions from the Core version of the package.
	 *
	 * @return  void
	 * @noinspection PhpUnused
	 */
	protected function uninstallProExtensions(): void
	{
		// If it's the Pro version we don't uninstall anything.
		if ($this->isPro())
		{
			return;
		}

		// Tell Joomla to uninstall the Pro-only extensions.
		foreach (self::PRO_ONLY_EXTENSIONS as $extension)
		{
			$this->uninstallExtension($extension);
		}
	}

	/**
	 * Makes sure the package has exactly one update site, for the edition being installed.
	 *
	 * Joomla leaves the previous edition's update site behind (Joomla 4) or keeps its stale name (Joomla 5.4+).
	 * The edition comes from the installed version.php on disk, which during postflight is already the new edition's
	 * (see isPro()); the ADMINTOOLS_PRO constant may still describe the previous edition.
	 *
	 * @return  void
	 */
	protected function refreshUpdateSites(): void
	{
		try
		{
			$isPro = $this->isPro();
			$db    = $this->getDatabase();
			$model = new UpdatesModel(['dbo' => $db]);

			$model->setDatabase($db);
			$model->refreshUpdateSite($isPro);
		}
		catch (Throwable $e)
		{
			// Never fail the installation because of this.
		}
	}

	/**
	 * Runs a method inside a try/catch block to suppress any errors
	 *
	 * @param   string[]  $methodNames  The method name to run
	 *
	 * @return  void
	 */
	private function runIsolated(array $methodNames): void
	{
		foreach ($methodNames as $methodName)
		{
			try
			{
				$this->{$methodName}();
			}
			catch (Throwable $e)
			{
				// No problem, let's move on.
			}
		}
	}

	/**
	 * Does the old package even exist?
	 *
	 * @return   bool
	 */
	private function hasOldPackage(): bool
	{
		if (empty(self::OLD_PACKAGE_NAME))
		{
			return false;
		}

		$eid = $this->getExtensionId(self::OLD_PACKAGE_NAME);

		return !empty($eid);
	}

	/**
	 * Reassign the extensions to the new package.
	 *
	 * This modifies the package_id column of the #__extensions table for the records of the records defined in
	 * $this->extensionsList. Since these are shared between the old and new packages we need to change their package ID
	 * to the new package's ID. Otherwise Joomla might be confused as to which package "owns" them.
	 *
	 * @return  void
	 */
	private function reassignExtensions(): void
	{
		// Get the extension ID of the new package
		$newPackageId = $this->getExtensionId(self::PACKAGE_NAME);

		if (empty($newPackageId))
		{
			return;
		}

		// Get the extension IDs
		$extensionIDs = array_map([$this, 'getExtensionId'], $this->extensionsList);
		$extensionIDs = array_filter($extensionIDs, function ($x) {
			return !empty($x);
		});

		if (empty($extensionIDs))
		{
			return;
		}

		/**
		 * Looks stupid? This realigns the integer keys because whereIn() expects 0-based, monotonically increasing
		 * array keys. Otherwise it ends up emitting null values. GROAN!
		 */
		$extensionIDs = array_merge($extensionIDs);

		// Reassign all extensions
		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->update($db->quoteName('#__extensions'))
			->set($db->qn('package_id') . ' = :package_id')
			->whereIn($db->qn('extension_id'), $extensionIDs, ParameterType::INTEGER)
			->bind(':package_id', $newPackageId, ParameterType::INTEGER);
		$db->setQuery($query)->execute();
	}

	/**
	 * Unregisters a list of extensions from being marked as dependent on the specified FOF version.
	 *
	 * @param   string  $fofVersion  PHP version to unregister the extensions from
	 *
	 * @return  void
	 */
	private function unregisterFromFOF($fofVersion = '3')
	{
		// Make sure we have an extensions list and it's canonical (admin modules have mod_ prefix, not amod_).
		$extensions = $this->extensionsList;
		$extensions = array_map(function ($name) {
			if (substr($name, 0, 5) == 'amod_')
			{
				$name = 'mod_' . substr($name, 5);
			}

			return $name;
		}, $extensions);

		// Get the existing list of extensions dependent on the specified version of FOF.
		$keyName = 'fof' . $fofVersion . '0';
		$db      = $this->getDatabase();
		$query   = DbQuery::create($db)
			->select($db->quoteName('value'))
			->from($db->quoteName('#__akeeba_common'))
			->where($db->quoteName('key') . ' = :keyName')
			->bind(':keyName', $keyName);
		try
		{
			$json = $db->setQuery($query)->loadResult();
			$list = ($json === null) ? [] : json_decode($json, true);
		}
		catch (RuntimeException $e)
		{
			return;
		}

		// If the list is empty I am already done.
		if (is_null($list) || !is_array($list))
		{
			return;
		}

		// Remove the common extensions which no longer depend on FOF.
		$list = array_diff($list, $extensions);
		$json = json_encode($list);

		// Update the #__akeeba_common table.
		$query = DbQuery::create($db)
			->update($db->quoteName('#__akeeba_common'))
			->set($db->quoteName('value') . ' = :json')
			->where($db->quoteName('key') . ' = :keyName')
			->bind(':json', $json)
			->bind(':keyName', $keyName);

		try
		{
			$db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			return;
		}
	}

	/**
	 * Removes the common extensions from old package's cached manifest.
	 *
	 * This prevents Joomla from uninstalling modules, plugins etc which are nominally included in both packages when
	 * you uninstall the old package.
	 *
	 * @return  void
	 */
	private function removeExtensionsFromPackageManifest(): void
	{
		// Make sure we have an old package and a list of extensions
		$oldPackage = self::OLD_PACKAGE_NAME;
		$extensions = $this->extensionsList;

		if (empty($oldPackage) || empty($extensions))
		{
			return;
		}

		// Get the cached manifest as a SimpleXMLElement node
		$xml = $this->getPackageXMLManifest($oldPackage);

		if (is_null($xml))
		{
			return;
		}

		// Walk through all the <file> tags and remove the extensions in the $extensions list
		foreach ($xml->xpath('//files/file') as $fileField)
		{
			$extension = $this->xmlNodeToExtensionName($fileField);

			if (is_null($extension) || !in_array($extension, $extensions))
			{
				continue;
			}

			unset($fileField[0][0]);
		}

		// Save the modified manifest back to the package manifests cache.
		$filePath = $this->getCachedManifestPath($oldPackage);
		$contents = $xml->asXML();

		@file_put_contents($filePath, $contents);
	}

	/**
	 * Gets a SimpleXMLElement representation of the cached manifest of the extension.
	 *
	 * @param   string  $package
	 *
	 * @return  SimpleXMLElement|null
	 */
	private function getPackageXMLManifest(string $package): ?SimpleXMLElement
	{
		$filePath = $this->getCachedManifestPath($package);

		if (!@file_exists($filePath) || !@is_readable($filePath))
		{
			return null;
		}

		$xmlContent = @file_get_contents($filePath);

		if (empty($xmlContent))
		{
			return null;
		}

		return new SimpleXMLElement($xmlContent);
	}

	/**
	 * Get the list of extensions included in a package
	 *
	 * @param   string  $package
	 *
	 * @return  array
	 */
	private function getExtensionsFromPackage(string $package): array
	{
		$extensions = [];
		$xml        = $this->getPackageXMLManifest($package);

		if (is_null($xml))
		{
			return $extensions;
		}

		foreach ($xml->xpath('//files/file') as $fileField)
		{
			$extension = $this->xmlNodeToExtensionName($fileField);

			if (is_null($extension))
			{
				continue;
			}

			$extensions[] = $extension;
		}

		return $extensions;
	}

	/**
	 * Take a SimpleXMLElement `<file>` node of the package manifest and return the corresponding Joomla extension name
	 *
	 * @param   SimpleXMLElement  $fileField  The `<file>` node of the package manifest
	 *
	 * @return  string|null  The extension name, null if it cannot be determined.
	 */
	private function xmlNodeToExtensionName(SimpleXMLElement $fileField): ?string
	{
		$type = (string) $fileField->attributes()->type;
		$id   = (string) $fileField->attributes()->id;

		switch ($type)
		{
			case 'component':
			case 'file':
			case 'library':
				$extension = $id;
				break;

			case 'plugin':
				$group     = (string) ($fileField->attributes()->group ?? '') ?: 'system';
				$extension = 'plg_' . $group . '_' . $id;
				break;

			case 'module':
				$client    = (string) ($fileField->attributes()->client ?? '') ?: 'site';
				$extension = (($client != 'site') ? 'a' : '') . $id;
				break;

			default:
				$extension = null;
				break;
		}

		return $extension;
	}


	/**
	 * Get the absolute filesystem path
	 *
	 * @param   string  $package
	 *
	 * @return  string
	 */
	private function getCachedManifestPath(string $package): string
	{
		return JPATH_MANIFESTS . '/packages/' . $package . '.xml';
	}

	/**
	 * Path to the installed version.php of the component.
	 *
	 * @return  string
	 */
	protected function getVersionFilePath(): string
	{
		return JPATH_ADMINISTRATOR . '/components/com_admintools/version.php';
	}

	/**
	 * Is the edition being installed Pro?
	 *
	 * The answer comes from the ADMINTOOLS_PRO value written in the installed version.php. During postflight that
	 * file is already the new edition's. We must not trust the ADMINTOOLS_PRO constant first: the *previous* edition's
	 * version.php may have been loaded earlier in the same request (system plugin, dispatcher), leaving the constant
	 * describing the old edition, e.g. '1' while installing Core over Pro (gh-387). The file is read as text, not
	 * included: the constants are already defined, and OPcache could serve the stale bytecode.
	 *
	 * Fallbacks: the ADMINTOOLS_PRO constant if the file is unreadable or has no ADMINTOOLS_PRO define; otherwise Core.
	 * This method never throws.
	 *
	 * @return  bool
	 */
	private function isPro(): bool
	{
		try
		{
			$path     = $this->getVersionFilePath();
			$contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

			if (is_string($contents)
			    && preg_match(
				    '/\bdefine\s*\(\s*([\'"])ADMINTOOLS_PRO\1\s*,\s*(?:([\'"])(.*?)\2|([^\s)]+))\s*\)/',
				    $contents,
				    $matches
			    ))
			{
				$value = ($matches[4] ?? '') !== '' ? $matches[4] : ($matches[3] ?? '');

				return $this->valueIsTruthy($value);
			}

			return defined('ADMINTOOLS_PRO') ? (bool) constant('ADMINTOOLS_PRO') : false;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Treat a textual constant value the way PHP would treat the constant in a boolean context.
	 *
	 * @param   string  $value
	 *
	 * @return  bool
	 */
	private function valueIsTruthy(string $value): bool
	{
		$value = trim($value);

		if (in_array(strtolower($value), ['false', 'null'], true))
		{
			return false;
		}

		return (bool) $value;
	}

	/**
	 * Uninstall an extension by name.
	 *
	 * @param   string  $extension
	 *
	 * @return  bool
	 */
	private function uninstallExtension(string $extension): bool
	{
		// Let's get the extension ID. If it's not there we can't uninstall this extension, right..?
		$eid = $this->getExtensionId($extension);

		if (empty($eid))
		{
			return false;
		}

		// Extensions must be marked as not belonging to the package before they can be removed
		$this->removeExtensionPackageLink($eid);

		// Get an Extension table object and Installer object.
		/** @noinspection PhpParamsInspection */
		$row       = new Extension($this->getDatabase());
		$installer = Installer::getInstance();

		// Load the extension row or fail the uninstallation immediately.
		try
		{
			if (!$row->load($eid))
			{
				return false;
			}
		}
		catch (Throwable $e)
		{
			// If the database query fails or Joomla experiences an unplanned rapid deconstruction let's bail out.
			return false;
		}

		// Can't uninstalled protected extensions
		/** @noinspection PhpUndefinedFieldInspection */
		if ((int) $row->locked === 1)
		{
			return false;
		}

		// An extension row without a type? What have you done to your database, you MONSTER?!
		if (empty($row->type))
		{
			return false;
		}

		// Do the actual uninstallation. Try to trap any errors, just in case...
		try
		{
			return $installer->uninstall($row->type, $eid);
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Loads any custom handlers.
	 *
	 * @return  void
	 */
	private function loadCustomHandlers(): void
	{
		$handlerNamespace = __NAMESPACE__ . '\\' . self::CUSTOM_HANDLERS_DIRECTORY;

		$this->customHandlers = [];

		// Scan the directory and load the custom handlers
		$targetDirectory = __DIR__ . '/' . self::CUSTOM_HANDLERS_DIRECTORY;

		if (!@file_exists($targetDirectory) || !@is_dir($targetDirectory))
		{
			return;
		}

		$di = new DirectoryIterator($targetDirectory);

		/** @var DirectoryIterator $entry */
		foreach ($di as $entry)
		{
			// Ignore folders
			if ($entry->isDot() || $entry->isDir())
			{
				continue;
			}

			// Ignore non-PHP directories
			if ($entry->getExtension() != 'php')
			{
				continue;
			}

			// Get the class name
			$bareName          = basename($entry->getFilename(), '.php');
			$bareNameCanonical = preg_replace('/[^A-Z_]/i', '', $bareName);

			/**
			 * Some hosts rename files with numeric suffixes, e.g. FooBar.php is renamed to FooBar.01.php. In both cases
			 * the bare class name would be "FooBar" but the canonical would be "FooBar" vs "FooBar.01". This check
			 * makes sure that renamed files will NOT be loaded. Ever.
			 */
			if ($bareName != $bareNameCanonical)
			{
				continue;
			}

			// Have I already loaded an object this class? Yeah, sometimes hosts do weird(er) things.
			if (array_key_exists($bareNameCanonical, $this->customHandlers))
			{
				continue;
			}

			// Try to load the file
			require_once $entry->getPathname();

			// Make sure we actually loaded a class I can use
			$classFQN = $handlerNamespace . '\\' . $bareNameCanonical;

			if (!class_exists($classFQN, false))
			{
				continue;
			}

			// Add the custom handler, passing a reference to ourselves
			$this->customHandlers[$bareNameCanonical] = new $classFQN($this, $this->getDatabase());
		}
	}

	/**
	 * Are the old and new packages identical?
	 *
	 * Also returns true if no OLD_PACKAGE_NAME has been specified.
	 *
	 * @return  bool
	 */
	private function isSamePackage(): bool
	{
		return empty(self::OLD_PACKAGE_NAME) || (self::OLD_PACKAGE_NAME === self::PACKAGE_NAME);
	}

	private function removeExtensionPackageLink(int $eid): void
	{
		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('package_id') . ' = 0')
			->where($db->quoteName('extension_id') . ' = :eid')
			->bind(':eid', $eid, ParameterType::INTEGER);
		$db->setQuery($query)->execute();
	}

}