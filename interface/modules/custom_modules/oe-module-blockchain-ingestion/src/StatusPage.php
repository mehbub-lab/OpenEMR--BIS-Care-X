<?php

/**
 * StatusPage - Simple admin view for blockchain ingestion status.
 *
 * Shows recent document anchoring activity, queue status,
 * and any failed items that need attention.
 *
 * @package   OpenEMR
 * @subpackage Modules\BlockchainIngestion
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\BlockchainIngestion;

class StatusPage
{
    /**
     * Render the status dashboard HTML.
     *
     * @return string HTML content
     */
    public static function render(): string
    {
        // Summary statistics
        $stats = self::getStats();

        // Recent queue items
        $recentItems = self::getRecentQueueItems(25);

        ob_start();
        ?>
        <html>
        <head>
            <title><?php echo xlt('Blockchain Ingestion Status'); ?></title>
            <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/public/assets/bootstrap/dist/css/bootstrap.min.css">
            <style>
                body { padding: 20px; background: #f8f9fa; }
                .stat-card { border-left: 4px solid; border-radius: 4px; }
                .stat-anchored { border-left-color: #28a745; }
                .stat-pending { border-left-color: #ffc107; }
                .stat-failed { border-left-color: #dc3545; }
                .stat-total { border-left-color: #007bff; }
                .badge-anchored { background-color: #28a745; }
                .badge-pending { background-color: #ffc107; color: #333; }
                .badge-failed { background-color: #dc3545; }
                .badge-processing { background-color: #17a2b8; }
                .badge-completed { background-color: #28a745; }
            </style>
        </head>
        <body>
            <div class="container-fluid">
                <h2 class="mb-4">
                    <i class="fa fa-link"></i>
                    <?php echo xlt('Blockchain Ingestion Status'); ?>
                </h2>

                <!-- Summary Stats -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card stat-total p-3">
                            <div class="text-muted small"><?php echo xlt('Total Documents'); ?></div>
                            <div class="h3 mb-0"><?php echo text($stats['total']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card stat-anchored p-3">
                            <div class="text-muted small"><?php echo xlt('Anchored'); ?></div>
                            <div class="h3 mb-0 text-success"><?php echo text($stats['anchored']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card stat-pending p-3">
                            <div class="text-muted small"><?php echo xlt('Pending'); ?></div>
                            <div class="h3 mb-0 text-warning"><?php echo text($stats['pending']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card stat-failed p-3">
                            <div class="text-muted small"><?php echo xlt('Failed'); ?></div>
                            <div class="h3 mb-0 text-danger"><?php echo text($stats['failed']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Queue Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo xlt('Recent Queue Activity'); ?></h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th><?php echo xlt('Doc ID'); ?></th>
                                    <th><?php echo xlt('Patient UUID'); ?></th>
                                    <th><?php echo xlt('Status'); ?></th>
                                    <th><?php echo xlt('Attempts'); ?></th>
                                    <th><?php echo xlt('Blockchain TX'); ?></th>
                                    <th><?php echo xlt('Last Attempt'); ?></th>
                                    <th><?php echo xlt('Error'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentItems)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <?php echo xlt('No queue items found'); ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($recentItems as $item): ?>
                                    <tr>
                                        <td><?php echo text($item['document_id']); ?></td>
                                        <td>
                                            <code class="small"><?php echo text(substr($item['patient_uuid'] ?? '', 0, 12)); ?>...</code>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo attr($item['status']); ?>">
                                                <?php echo text($item['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo text($item['attempts']); ?>/<?php echo text($item['max_attempts']); ?></td>
                                        <td>
                                            <?php if (!empty($item['blockchain_tx'])): ?>
                                                <code class="small"><?php echo text(substr($item['blockchain_tx'], 0, 16)); ?>...</code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?php echo text($item['last_attempt'] ?? '—'); ?></td>
                                        <td class="small text-danger">
                                            <?php echo text(substr($item['error_message'] ?? '', 0, 60)); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Config Info -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo xlt('Configuration'); ?></h5>
                    </div>
                    <div class="card-body">
                        <p><strong><?php echo xlt('BIS Endpoint'); ?>:</strong>
                            <code><?php echo text($GLOBALS[GlobalConfig::CONFIG_BIS_ENDPOINT] ?? GlobalConfig::DEFAULT_BIS_ENDPOINT); ?></code>
                        </p>
                        <p><strong><?php echo xlt('Max Retries'); ?>:</strong>
                            <?php echo text($GLOBALS[GlobalConfig::CONFIG_MAX_RETRIES] ?? GlobalConfig::DEFAULT_MAX_RETRIES); ?>
                        </p>
                        <p><strong><?php echo xlt('Timeout'); ?>:</strong>
                            <?php echo text($GLOBALS[GlobalConfig::CONFIG_BIS_TIMEOUT] ?? GlobalConfig::DEFAULT_BIS_TIMEOUT); ?>s
                        </p>
                        <p class="text-muted small mb-0">
                            <?php echo xlt('Configure settings in Administration → Globals → Blockchain Ingestion'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Get summary statistics.
     *
     * @return array
     */
    private static function getStats(): array
    {
        $total = sqlQuery("SELECT COUNT(*) AS cnt FROM documents WHERE chain_status IS NOT NULL");
        $anchored = sqlQuery("SELECT COUNT(*) AS cnt FROM documents WHERE chain_status = 'anchored'");
        $pending = sqlQuery("SELECT COUNT(*) AS cnt FROM documents WHERE chain_status = 'pending'");
        $failed = sqlQuery("SELECT COUNT(*) AS cnt FROM documents WHERE chain_status = 'failed'");

        return [
            'total'    => $total['cnt'] ?? 0,
            'anchored' => $anchored['cnt'] ?? 0,
            'pending'  => $pending['cnt'] ?? 0,
            'failed'   => $failed['cnt'] ?? 0,
        ];
    }

    /**
     * Get recent queue items.
     *
     * @param int $limit
     * @return array
     */
    private static function getRecentQueueItems(int $limit = 25): array
    {
        $sql = "SELECT document_id, patient_uuid, status, attempts, max_attempts,
                       blockchain_tx, record_hash, last_attempt, error_message, created_at
                FROM mod_blockchain_queue
                ORDER BY created_at DESC
                LIMIT ?";

        $result = sqlStatement($sql, [$limit]);
        $items = [];
        while ($row = sqlFetchArray($result)) {
            $items[] = $row;
        }
        return $items;
    }
}
