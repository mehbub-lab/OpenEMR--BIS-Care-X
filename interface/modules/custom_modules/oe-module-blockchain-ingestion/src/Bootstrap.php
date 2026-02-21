<?php

/**
 * Bootstrap - Main module bootstrap class.
 *
 * Subscribes to OpenEMR events to register global settings
 * and menu items for the Blockchain Ingestion Module.
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\BlockchainIngestion;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "oe-module-blockchain-ingestion";

    /**
     * @var GlobalConfig Holds module global configuration values.
     */
    private GlobalConfig $globalsConfig;

    /**
     * @var string The folder name of the module.
     */
    private string $moduleDirectoryName;

    /**
     * @var SystemLogger
     */
    private SystemLogger $logger;

    /**
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher injected by OpenEMR
     * @param ?Kernel $kernel
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        ?Kernel $kernel = null
    ) {
        global $GLOBALS;

        $this->moduleDirectoryName = basename(dirname(__DIR__));
        $this->globalsConfig = new GlobalConfig($GLOBALS);
        $this->logger = new SystemLogger();
    }

    /**
     * Subscribe to all relevant OpenEMR events.
     */
    public function subscribeToEvents(): void
    {
        $this->addGlobalSettings();

        // Only register additional features if the module is fully configured
        if ($this->globalsConfig->isConfigured()) {
            $this->registerMenuItems();
        }
    }

    /**
     * @return GlobalConfig
     */
    public function getGlobalConfig(): GlobalConfig
    {
        return $this->globalsConfig;
    }

    /**
     * Register global settings listener.
     */
    public function addGlobalSettings(): void
    {
        $this->eventDispatcher->addListener(
            GlobalsInitializedEvent::EVENT_HANDLE,
            $this->addGlobalSettingsSection(...)
        );
    }

    /**
     * Add the module's global settings section to the OpenEMR Globals page.
     *
     * @param GlobalsInitializedEvent $event
     */
    public function addGlobalSettingsSection(GlobalsInitializedEvent $event): void
    {
        global $GLOBALS;

        $service = $event->getGlobalsService();
        $section = xlt("Blockchain Ingestion");
        $service->createSection($section, 'Portal');

        $settings = $this->globalsConfig->getGlobalSettingSectionConfiguration();

        foreach ($settings as $key => $config) {
            $value = $GLOBALS[$key] ?? $config['default'];
            $service->appendToSection(
                $section,
                $key,
                new GlobalSetting(
                    xlt($config['title']),
                    $config['type'],
                    $value,
                    xlt($config['description']),
                    true
                )
            );
        }
    }

    /**
     * Register a menu item under the Modules menu.
     */
    public function registerMenuItems(): void
    {
        if ($this->globalsConfig->getGlobalSetting(GlobalConfig::CONFIG_ENABLE_MENU)) {
            $this->eventDispatcher->addListener(
                MenuEvent::MENU_UPDATE,
                $this->addCustomModuleMenuItem(...)
            );
        }
    }

    /**
     * Add the Blockchain Ingestion status page menu item.
     *
     * @param MenuEvent $event
     * @return MenuEvent
     */
    public function addCustomModuleMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();

        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'mod';
        $menuItem->menu_id = 'mod0';
        $menuItem->label = xlt("Blockchain Ingestion");
        $menuItem->url = "/interface/modules/custom_modules/oe-module-blockchain-ingestion/public/index.php";
        $menuItem->children = [];
        $menuItem->acl_req = ["admin", "super"];
        $menuItem->global_req = [];

        foreach ($menu as $item) {
            if ($item->menu_id == 'modimg') {
                $item->children[] = $menuItem;
                break;
            }
        }

        $event->setMenu($menu);
        return $event;
    }
}
