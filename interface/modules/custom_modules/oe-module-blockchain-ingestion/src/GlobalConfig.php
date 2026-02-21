<?php

/**
 * GlobalConfig - Module global configuration values.
 *
 * Manages configurable settings for the Blockchain Ingestion Module,
 * including BIS endpoint URL, connection timeout, and retry settings.
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\BlockchainIngestion;

class GlobalConfig
{
    // Global setting keys
    const CONFIG_ENABLE_MODULE = 'blockchain_ingestion_enable';
    const CONFIG_BIS_ENDPOINT = 'blockchain_ingestion_bis_endpoint';
    const CONFIG_BIS_TIMEOUT = 'blockchain_ingestion_bis_timeout';
    const CONFIG_MAX_RETRIES = 'blockchain_ingestion_max_retries';
    const CONFIG_ENABLE_MENU = 'blockchain_ingestion_enable_menu';

    // Defaults
    const DEFAULT_BIS_ENDPOINT = 'http://localhost:4000/ingest';
    const DEFAULT_BIS_TIMEOUT = 10;
    const DEFAULT_MAX_RETRIES = 5;

    private array $globalsArray;

    public function __construct(array $globalsArray)
    {
        $this->globalsArray = $globalsArray;
    }

    /**
     * Returns the array of global setting definitions for the module.
     *
     * @return array
     */
    public function getGlobalSettingSectionConfiguration(): array
    {
        return [
            self::CONFIG_ENABLE_MODULE => [
                'title' => 'Enable Blockchain Ingestion',
                'description' => 'Enable or disable the blockchain document ingestion module',
                'type' => 'bool',
                'default' => '0',
            ],
            self::CONFIG_BIS_ENDPOINT => [
                'title' => 'BIS Endpoint URL',
                'description' => 'The URL of the Blockchain Ingestion Service (e.g., http://localhost:4000/ingest)',
                'type' => 'text',
                'default' => self::DEFAULT_BIS_ENDPOINT,
            ],
            self::CONFIG_BIS_TIMEOUT => [
                'title' => 'BIS Connection Timeout (seconds)',
                'description' => 'HTTP connection timeout in seconds for BIS requests',
                'type' => 'num',
                'default' => (string) self::DEFAULT_BIS_TIMEOUT,
            ],
            self::CONFIG_MAX_RETRIES => [
                'title' => 'Max Retry Attempts',
                'description' => 'Maximum number of retry attempts before marking a document as failed',
                'type' => 'num',
                'default' => (string) self::DEFAULT_MAX_RETRIES,
            ],
            self::CONFIG_ENABLE_MENU => [
                'title' => 'Enable Status Menu',
                'description' => 'Show Blockchain Ingestion status page in the Modules menu',
                'type' => 'bool',
                'default' => '1',
            ],
        ];
    }

    /**
     * Check if the module has been configured (at minimum, the module is enabled).
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->getGlobalSetting(self::CONFIG_ENABLE_MODULE));
    }

    /**
     * Retrieve a global setting value.
     *
     * @param string $settingKey
     * @return mixed
     */
    public function getGlobalSetting(string $settingKey): mixed
    {
        $config = $this->getGlobalSettingSectionConfiguration();
        $default = $config[$settingKey]['default'] ?? '';
        return $this->globalsArray[$settingKey] ?? $default;
    }

    /**
     * Get the BIS endpoint URL.
     *
     * @return string
     */
    public function getBisEndpoint(): string
    {
        return $this->getGlobalSetting(self::CONFIG_BIS_ENDPOINT) ?: self::DEFAULT_BIS_ENDPOINT;
    }

    /**
     * Get the BIS connection timeout.
     *
     * @return int
     */
    public function getBisTimeout(): int
    {
        return (int) ($this->getGlobalSetting(self::CONFIG_BIS_TIMEOUT) ?: self::DEFAULT_BIS_TIMEOUT);
    }

    /**
     * Get the maximum number of retry attempts.
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return (int) ($this->getGlobalSetting(self::CONFIG_MAX_RETRIES) ?: self::DEFAULT_MAX_RETRIES);
    }
}
