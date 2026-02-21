<?php

/**
 * Blockchain Ingestion Module - Public Index (Admin Status Page)
 *
 * Entry point for the module's admin dashboard, accessible
 * from the OpenEMR menu under Modules → Blockchain Ingestion.
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Load OpenEMR globals
require_once dirname(__FILE__, 5) . '/globals.php';

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Modules\BlockchainIngestion\StatusPage;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// Ensure the namespace is loaded
$classLoader = new ModulesClassLoader($GLOBALS['fileroot']);
$classLoader->registerNamespaceIfNotExists(
    "OpenEMR\\Modules\\BlockchainIngestion\\",
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'
);

// ACL check — only super admins can access
if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Access Denied');
    exit;
}

// Render the status page
echo StatusPage::render();
