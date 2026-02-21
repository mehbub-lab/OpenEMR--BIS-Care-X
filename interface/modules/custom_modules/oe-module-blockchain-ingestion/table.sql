--
-- Blockchain Ingestion Module - SQL Migration
--
-- @package   OpenEMR
-- @link      http://www.open-emr.org
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- --------------------------------------------------------
-- Add blockchain anchoring fields to the documents table
-- --------------------------------------------------------

#IfNotColumn documents blockchain_tx
ALTER TABLE `documents` ADD COLUMN `blockchain_tx` VARCHAR(255) DEFAULT NULL COMMENT 'Blockchain transaction hash returned by BIS';
#EndIf

#IfNotColumn documents record_hash
ALTER TABLE `documents` ADD COLUMN `record_hash` VARCHAR(255) DEFAULT NULL COMMENT 'Record hash computed by BIS';
#EndIf

#IfNotColumn documents chain_status
ALTER TABLE `documents` ADD COLUMN `chain_status` VARCHAR(32) DEFAULT NULL COMMENT 'Blockchain anchoring status: NULL=new, pending, anchored, failed';
#EndIf

-- --------------------------------------------------------
-- Queue table for tracking ingestion attempts and retries
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mod_blockchain_queue` (
    `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
    `document_id` BIGINT(20) NOT NULL,
    `patient_uuid` VARCHAR(255) DEFAULT NULL,
    `payload_json` TEXT,
    `attempts` INT(11) DEFAULT 0,
    `max_attempts` INT(11) DEFAULT 5,
    `last_attempt` DATETIME DEFAULT NULL,
    `next_retry_after` DATETIME DEFAULT NULL,
    `status` VARCHAR(32) DEFAULT 'pending' COMMENT 'pending, processing, completed, failed',
    `error_message` TEXT,
    `blockchain_tx` VARCHAR(255) DEFAULT NULL,
    `record_hash` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_document_id` (`document_id`),
    INDEX `idx_next_retry` (`next_retry_after`)
);

-- --------------------------------------------------------
-- Register background service for polling & processing
-- --------------------------------------------------------

#IfNotRow background_services name Blockchain_Ingest
INSERT INTO `background_services`
    (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
VALUES
    ('Blockchain_Ingest', 'Blockchain Document Ingestion', 1, 0, NOW(), 1,
     'processBlockchainQueue',
     '/interface/modules/custom_modules/oe-module-blockchain-ingestion/src/BackgroundBlockchainService.php',
     100);
#EndIf
