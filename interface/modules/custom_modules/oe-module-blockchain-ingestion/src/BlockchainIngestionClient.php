<?php

/**
 * BlockchainIngestionClient - REST client for the Blockchain Ingestion Service (BIS).
 *
 * Sends document metadata as JSON to an external BIS endpoint.
 * Implements exponential backoff retry logic and timeout handling.
 *
 * ARCHITECTURE RULE: This client ONLY forwards metadata.
 * No encryption, hashing, or private key operations happen here.
 * All blockchain logic lives in the external BIS microservice.
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\BlockchainIngestion;

use OpenEMR\Common\Logging\SystemLogger;

class BlockchainIngestionClient
{
    private SystemLogger $logger;
    private string $endpoint;
    private int $timeout;
    private int $maxRetries;

    /**
     * @param string $endpoint  BIS endpoint URL (e.g., http://localhost:4000/ingest)
     * @param int    $timeout   HTTP timeout in seconds
     * @param int    $maxRetries Maximum retry attempts with exponential backoff
     */
    public function __construct(
        string $endpoint = 'http://localhost:4000/ingest',
        int $timeout = 10,
        int $maxRetries = 3
    ) {
        $this->endpoint = $endpoint;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->logger = new SystemLogger();
    }

    /**
     * Build the JSON payload for a document ingestion event.
     *
     * Example payload:
     * {
     *   "patient_uuid": "95f2c42e-6b28-4a61-baf0-123456789abc",
     *   "document_id": 42,
     *   "file_path": "file:///var/www/openemr/sites/default/documents/1/abc123.pdf",
     *   "file_hash": "a3f2b8c9d1e0...",
     *   "mime_type": "application/pdf",
     *   "timestamp": "2026-02-21T12:00:00+05:30",
     *   "category": "Lab Report",
     *   "source_system": "OpenEMR",
     *   "event_type": "document.created"
     * }
     *
     * @param array $documentData Associative array of document metadata
     * @return array The payload array
     */
    public static function buildPayload(array $documentData): array
    {
        return [
            'patient_uuid'  => $documentData['patient_uuid'] ?? null,
            'document_id'   => (int) ($documentData['document_id'] ?? 0),
            'file_path'     => $documentData['file_path'] ?? '',
            'file_hash'     => $documentData['file_hash'] ?? '',
            'mime_type'     => $documentData['mime_type'] ?? '',
            'timestamp'     => $documentData['timestamp'] ?? date('c'),
            'category'      => $documentData['category'] ?? '',
            'source_system' => 'OpenEMR',
            'event_type'    => 'document.created',
        ];
    }

    /**
     * Send a payload to the BIS endpoint with retry logic.
     *
     * Uses exponential backoff: attempt 1 waits 1s, attempt 2 waits 2s, attempt 3 waits 4s, etc.
     *
     * @param array $payload The JSON-serializable payload to send
     * @return array{success: bool, response: ?array, error: ?string}
     */
    public function send(array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($jsonPayload === false) {
            $error = 'Failed to encode payload to JSON: ' . json_last_error_msg();
            $this->logger->errorLogCaller($error);
            return ['success' => false, 'response' => null, 'error' => $error];
        }

        $lastError = '';

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $result = $this->doHttpPost($jsonPayload);

            if ($result['success']) {
                $this->logger->debug(
                    'BlockchainIngestionClient: Successfully sent to BIS',
                    ['document_id' => $payload['document_id'] ?? 'unknown', 'attempt' => $attempt]
                );
                return $result;
            }

            $lastError = $result['error'] ?? 'Unknown error';
            $this->logger->errorLogCaller(
                "BlockchainIngestionClient: Attempt {$attempt}/{$this->maxRetries} failed",
                ['error' => $lastError, 'document_id' => $payload['document_id'] ?? 'unknown']
            );

            // Exponential backoff: 1s, 2s, 4s, 8s, ...
            if ($attempt < $this->maxRetries) {
                $delay = pow(2, $attempt - 1);
                sleep($delay);
            }
        }

        return [
            'success'  => false,
            'response' => null,
            'error'    => "All {$this->maxRetries} attempts failed. Last error: {$lastError}",
        ];
    }

    /**
     * Execute a single HTTP POST request to the BIS endpoint.
     *
     * @param string $jsonPayload The JSON string to send
     * @return array{success: bool, response: ?array, error: ?string}
     */
    private function doHttpPost(string $jsonPayload): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Source: OpenEMR-BIM',
                'Content-Length: ' . strlen($jsonPayload),
            ],
            // For non-blocking behavior in background service context,
            // we set a reasonable timeout rather than true async.
            // True async would require a message queue (RabbitMQ, Redis, etc.)
            // which is beyond the scope of this module.
            CURLOPT_NOSIGNAL => 1,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Handle cURL-level errors (network, timeout, DNS, etc.)
        if ($curlErrno !== 0) {
            return [
                'success'  => false,
                'response' => null,
                'error'    => "cURL error ({$curlErrno}): {$curlError}",
            ];
        }

        // Decode response
        $responseData = json_decode($responseBody, true);

        // Consider 2xx as success
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'  => true,
                'response' => $responseData,
                'error'    => null,
            ];
        }

        // Non-2xx HTTP status
        $errorDetail = $responseData['error'] ?? $responseData['message'] ?? $responseBody;
        return [
            'success'  => false,
            'response' => $responseData,
            'error'    => "HTTP {$httpCode}: {$errorDetail}",
        ];
    }

    /**
     * Get the configured BIS endpoint URL.
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
