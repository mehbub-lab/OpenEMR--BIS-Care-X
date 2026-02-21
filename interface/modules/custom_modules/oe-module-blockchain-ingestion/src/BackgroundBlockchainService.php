<?php

/**
 * BackgroundBlockchainService - Background service for polling and processing
 * new documents for blockchain anchoring.
 *
 * This file is referenced by the `background_services` table and invoked
 * by OpenEMR's background service scheduler. The function `processBlockchainQueue`
 * is the entry point.
 *
 * FLOW:
 * 1. Query `documents` where `chain_status IS NULL` (new, unprocessed)
 * 2. For each doc, build a metadata payload and insert into `mod_blockchain_queue`
 * 3. Process pending queue items: call BlockchainIngestionClient::send()
 * 4. On success: update `documents` with blockchain_tx, record_hash, chain_status='anchored'
 * 5. On failure: increment attempts, schedule retry with exponential backoff
 * 6. On max retries exceeded: set chain_status='failed'
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\BlockchainIngestion\BlockchainIngestionClient;
use OpenEMR\Modules\BlockchainIngestion\GlobalConfig;

/**
 * Entry point called by OpenEMR background service scheduler.
 * This function name matches the `function` column in `background_services`.
 */
function processBlockchainQueue(): void
{
    $logger = new SystemLogger();

    try {
        // Load global config
        global $GLOBALS;
        $config = new GlobalConfig($GLOBALS);

        // Check if module is enabled
        if (!$config->isConfigured()) {
            return;
        }

        $processor = new BackgroundBlockchainProcessor($config, $logger);
        $processor->run();
    } catch (\Throwable $e) {
        $logger->errorLogCaller(
            'BlockchainIngestion: Fatal error in background service',
            ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
        );
    }
}

/**
 * BackgroundBlockchainProcessor - Handles the actual polling and processing logic.
 */
class BackgroundBlockchainProcessor
{
    private GlobalConfig $config;
    private SystemLogger $logger;
    private BlockchainIngestionClient $client;

    /** Maximum number of documents to process per run (prevents long-running jobs) */
    private const BATCH_SIZE = 50;

    public function __construct(GlobalConfig $config, SystemLogger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->client = new BlockchainIngestionClient(
            $config->getBisEndpoint(),
            $config->getBisTimeout(),
            3 // Per-request retries (separate from queue-level retries)
        );
    }

    /**
     * Main processing loop:
     * 1. Discover new documents and enqueue them
     * 2. Process pending queue items
     */
    public function run(): void
    {
        $this->discoverNewDocuments();
        $this->processPendingQueue();
    }

    /**
     * Step 1: Find documents that have never been processed (chain_status IS NULL)
     * and create queue entries for them.
     */
    private function discoverNewDocuments(): void
    {
        $sql = "SELECT d.id AS document_id,
                       d.uuid AS doc_uuid,
                       d.foreign_id AS patient_pid,
                       d.url AS file_path,
                       d.hash AS file_hash,
                       d.mimetype AS mime_type,
                       d.date AS created_date,
                       p.uuid AS patient_uuid_bin,
                       COALESCE(c.name, '') AS category_name
                FROM documents d
                LEFT JOIN patient_data p ON p.pid = d.foreign_id
                LEFT JOIN categories_to_documents ctd ON ctd.document_id = d.id
                LEFT JOIN categories c ON c.id = ctd.category_id
                WHERE d.chain_status IS NULL
                  AND d.deleted = 0
                ORDER BY d.date ASC
                LIMIT ?";

        $result = sqlStatement($sql, [self::BATCH_SIZE]);

        $count = 0;
        while ($row = sqlFetchArray($result)) {
            $this->enqueueDocument($row);
            $count++;
        }

        // Mark discovered documents as 'pending' so they aren't re-discovered
        if ($count > 0) {
            $this->logger->debug("BlockchainIngestion: Discovered {$count} new document(s) for processing");
        }
    }

    /**
     * Create a queue entry for a document and mark it as pending.
     *
     * @param array $row Document data from the database
     */
    private function enqueueDocument(array $row): void
    {
        // Convert binary UUID to string format
        $patientUuid = '';
        if (!empty($row['patient_uuid_bin'])) {
            $patientUuid = UuidRegistry::uuidToString($row['patient_uuid_bin']);
        }

        // Build the payload
        $payload = BlockchainIngestionClient::buildPayload([
            'patient_uuid' => $patientUuid,
            'document_id'  => $row['document_id'],
            'file_path'    => $row['file_path'] ?? '',
            'file_hash'    => $row['file_hash'] ?? '',
            'mime_type'    => $row['mime_type'] ?? '',
            'timestamp'    => $row['created_date'] ?? date('c'),
            'category'     => $row['category_name'] ?? '',
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Check if already in queue (prevent duplicates)
        $existing = sqlQuery(
            "SELECT id FROM mod_blockchain_queue WHERE document_id = ? AND status IN ('pending', 'processing')",
            [$row['document_id']]
        );

        if (empty($existing)) {
            // Insert into queue
            sqlInsert(
                "INSERT INTO mod_blockchain_queue
                    (document_id, patient_uuid, payload_json, attempts, max_attempts, status, created_at)
                 VALUES (?, ?, ?, 0, ?, 'pending', NOW())",
                [
                    $row['document_id'],
                    $patientUuid,
                    $payloadJson,
                    $this->config->getMaxRetries(),
                ]
            );
        }

        // Mark the document as pending in the documents table
        sqlStatement(
            "UPDATE documents SET chain_status = 'pending' WHERE id = ? AND chain_status IS NULL",
            [$row['document_id']]
        );
    }

    /**
     * Step 2: Process all pending queue items that are ready for processing.
     */
    private function processPendingQueue(): void
    {
        $sql = "SELECT id, document_id, patient_uuid, payload_json, attempts, max_attempts
                FROM mod_blockchain_queue
                WHERE status = 'pending'
                  AND (next_retry_after IS NULL OR next_retry_after <= NOW())
                ORDER BY created_at ASC
                LIMIT ?";

        $result = sqlStatement($sql, [self::BATCH_SIZE]);

        while ($queueItem = sqlFetchArray($result)) {
            $this->processQueueItem($queueItem);
        }
    }

    /**
     * Process a single queue item: send to BIS and handle the response.
     *
     * @param array $queueItem Queue row data
     */
    private function processQueueItem(array $queueItem): void
    {
        $documentId = (int) $queueItem['document_id'];
        $queueId = (int) $queueItem['id'];
        $attempts = (int) $queueItem['attempts'];
        $maxAttempts = (int) $queueItem['max_attempts'];

        // Mark as processing
        sqlStatement(
            "UPDATE mod_blockchain_queue SET status = 'processing', last_attempt = NOW(), attempts = attempts + 1 WHERE id = ?",
            [$queueId]
        );

        // Decode the payload
        $payload = json_decode($queueItem['payload_json'], true);
        if (empty($payload)) {
            $this->markQueueFailed($queueId, $documentId, 'Invalid JSON payload in queue');
            return;
        }

        // Send to BIS
        $result = $this->client->send($payload);

        if ($result['success']) {
            $this->handleSuccess($queueId, $documentId, $result['response']);
        } else {
            $currentAttempt = $attempts + 1;
            if ($currentAttempt >= $maxAttempts) {
                $this->markQueueFailed($queueId, $documentId, $result['error']);
            } else {
                $this->scheduleRetry($queueId, $documentId, $currentAttempt, $result['error']);
            }
        }
    }

    /**
     * Handle a successful BIS response.
     * Write blockchain_tx and record_hash back to the documents table.
     *
     * @param int   $queueId    Queue row ID
     * @param int   $documentId Document ID
     * @param array|null $response  BIS response body
     */
    private function handleSuccess(int $queueId, int $documentId, ?array $response): void
    {
        $blockchainTx = $response['blockchain_tx'] ?? $response['tx_hash'] ?? $response['transaction_id'] ?? '';
        $recordHash = $response['record_hash'] ?? $response['hash'] ?? '';

        // Update the documents table with blockchain reference
        sqlStatement(
            "UPDATE documents SET blockchain_tx = ?, record_hash = ?, chain_status = 'anchored' WHERE id = ?",
            [$blockchainTx, $recordHash, $documentId]
        );

        // Update queue status
        sqlStatement(
            "UPDATE mod_blockchain_queue
             SET status = 'completed', blockchain_tx = ?, record_hash = ?, updated_at = NOW()
             WHERE id = ?",
            [$blockchainTx, $recordHash, $queueId]
        );

        $this->logger->debug(
            "BlockchainIngestion: Document {$documentId} successfully anchored",
            ['blockchain_tx' => $blockchainTx, 'record_hash' => $recordHash]
        );
    }

    /**
     * Schedule a retry with exponential backoff.
     *
     * @param int    $queueId       Queue row ID
     * @param int    $documentId    Document ID
     * @param int    $currentAttempt Current attempt number
     * @param string $error         Error message from the last attempt
     */
    private function scheduleRetry(int $queueId, int $documentId, int $currentAttempt, string $error): void
    {
        // Exponential backoff: 60s, 120s, 240s, 480s, ...
        $delaySeconds = 60 * pow(2, $currentAttempt - 1);

        sqlStatement(
            "UPDATE mod_blockchain_queue
             SET status = 'pending',
                 error_message = ?,
                 next_retry_after = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 updated_at = NOW()
             WHERE id = ?",
            [$error, $delaySeconds, $queueId]
        );

        $this->logger->debug(
            "BlockchainIngestion: Document {$documentId} retry scheduled",
            ['attempt' => $currentAttempt, 'delay_seconds' => $delaySeconds, 'error' => $error]
        );
    }

    /**
     * Mark a queue item and its document as permanently failed.
     *
     * @param int    $queueId    Queue row ID
     * @param int    $documentId Document ID
     * @param string $error      Final error message
     */
    private function markQueueFailed(int $queueId, int $documentId, string $error): void
    {
        sqlStatement(
            "UPDATE mod_blockchain_queue SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?",
            [$error, $queueId]
        );

        sqlStatement(
            "UPDATE documents SET chain_status = 'failed' WHERE id = ?",
            [$documentId]
        );

        $this->logger->errorLogCaller(
            "BlockchainIngestion: Document {$documentId} permanently failed",
            ['error' => $error]
        );
    }
}
