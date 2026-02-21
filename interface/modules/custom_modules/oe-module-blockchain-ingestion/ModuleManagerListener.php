<?php

/**
 * Class to be called from Laminas Module Manager for reporting management actions.
 * Handles module enable, disable, and unregister lifecycle events.
 *
 * @package   OpenEMR Modules
 * @subpackage Modules\BlockchainIngestion
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/*
 * Do not declare a namespace.
 * If you want Laminas manager to set namespace set it in getModuleNamespace.
 */

use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    private const BACKGROUND_SERVICE_NAME = 'Blockchain_Ingest';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param        $methodName
     * @param        $modId
     * @param string $currentActionStatus
     * @return string On method success a $currentAction status should be returned or error string.
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        } else {
            return $currentActionStatus;
        }
    }

    /**
     * Required method to return namespace.
     *
     * @return string
     */
    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\BlockchainIngestion\\';
    }

    /**
     * Required method to return this class object
     * so it will be instantiated in Laminas Manager.
     *
     * @return ModuleManagerListener
     */
    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * Handle help_requested action.
     *
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function help_requested($modId, $currentActionStatus): mixed
    {
        if (file_exists(__DIR__ . '/show_help.php')) {
            include __DIR__ . '/show_help.php';
        }
        return $currentActionStatus;
    }

    /**
     * Enable the module — activate the background service.
     *
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function enable($modId, $currentActionStatus): mixed
    {
        $sql = "UPDATE `background_services` SET `active` = '1' WHERE `name` = ?";
        sqlQuery($sql, [self::BACKGROUND_SERVICE_NAME]);
        error_log('BlockchainIngestion: Background service enabled');
        return $currentActionStatus;
    }

    /**
     * Disable the module — deactivate the background service.
     *
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function disable($modId, $currentActionStatus): mixed
    {
        $sql = "UPDATE `background_services` SET `active` = '0' WHERE `name` = ?";
        sqlQuery($sql, [self::BACKGROUND_SERVICE_NAME]);
        error_log('BlockchainIngestion: Background service disabled');
        return $currentActionStatus;
    }

    /**
     * Unregister the module — remove the background service entry.
     *
     * @param $modId
     * @param $currentActionStatus
     * @return mixed
     */
    private function unregister($modId, $currentActionStatus): mixed
    {
        $sql = "DELETE FROM `background_services` WHERE `name` = ?";
        sqlQuery($sql, [self::BACKGROUND_SERVICE_NAME]);
        error_log('BlockchainIngestion: Background service removed');
        return $currentActionStatus;
    }
}
