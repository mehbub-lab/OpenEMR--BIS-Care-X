<?php

/**
 * Blockchain Ingestion Module - Bootstrap Entry Point
 *
 * This file is loaded by the OpenEMR module loader when the module is active.
 * It registers the module namespace and subscribes to system events.
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\BlockchainIngestion;

/**
 * @global OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\BlockchainIngestion\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

/**
 * @global \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
 * Injected by the OpenEMR module loader
 */
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
