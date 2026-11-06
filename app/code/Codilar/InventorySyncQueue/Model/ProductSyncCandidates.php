<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Model;

use Codilar\InventorySyncQueue\Logger\StepLogger;
use Codilar\InventorySyncQueue\Model\ResourceModel\SyncStatusResource;
use Magento\Framework\App\ResourceConnection;

/**
 * Decides which products need an inventory sync.
 *
 * The whole duplicate-prevention rule lives in one SQL statement, which is the point: a
 * product that is pending or processing inside the stuck window matches none of the four
 * branches below, so it simply cannot be selected again while a consumer may still hold it.
 */
class ProductSyncCandidates
{
    /**
     * Most products to claim in one run. Caps both the queue burst and the size of the
     * claim statement.
     */
    public const BATCH_LIMIT = 500;

    /**
     * After this long, a pending or processing row is assumed abandoned - a consumer that
     * was killed mid-message, say - and becomes eligible again.
     */
    public const STUCK_AFTER_SECONDS = 1800;

    /**
     * Retry ceiling for failed products, so a permanently broken SKU stops consuming
     * queue capacity.
     */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SyncStatusResource $syncStatusResource,
        private readonly StepLogger $stepLogger
    ) {
    }

    /**
     * Product IDs that need syncing, newest-stale first by entity id.
     *
     * Pass $forUpdate when calling inside the publisher's transaction: it takes row locks
     * so a second cron run blocks here rather than selecting the same products.
     *
     * @param bool $forUpdate
     * @return int[]
     */
    public function getCandidateIds(bool $forUpdate = false): array
    {
        $connection = $this->resource->getConnection();
        $productTable = $this->resource->getTableName('catalog_product_entity');
        $statusTable = $this->syncStatusResource->getTable();

        $stuckBefore = gmdate('Y-m-d H:i:s', time() - self::STUCK_AFTER_SECONDS);

        $select = $connection->select()
            ->from(['p' => $productTable], ['entity_id'])
            ->joinLeft(['s' => $statusTable], 's.product_id = p.entity_id', [])
            ->where(
                implode(' OR ', [
                    // (a) never seen before
                    's.entity_id IS NULL',
                    // (b) completed, but the product changed since
                    sprintf(
                        '(s.status = %s AND (s.last_synced_at IS NULL OR p.updated_at > s.last_synced_at))',
                        $connection->quote(SyncStatus::COMPLETED)
                    ),
                    // (c) failed, retries left
                    sprintf(
                        '(s.status = %s AND s.attempts < %d)',
                        $connection->quote(SyncStatus::FAILED),
                        self::MAX_ATTEMPTS
                    ),
                    // (d) stuck in flight past the window - re-queue it
                    sprintf(
                        '(s.status IN (%s) AND COALESCE(s.queued_at, %s) < %s)',
                        implode(',', array_map([$connection, 'quote'], SyncStatus::IN_FLIGHT)),
                        $connection->quote('1970-01-01 00:00:00'),
                        $connection->quote($stuckBefore)
                    ),
                ])
            )
            ->order('p.entity_id ASC')
            ->limit(self::BATCH_LIMIT);

        if ($forUpdate) {
            $select->forUpdate(true);
        }

        $ids = array_map('intval', $connection->fetchCol($select));

        $this->stepLogger->step(StepLogger::STEP_SCAN, sprintf(
            'matched %d product(s)%s | rules: never-synced OR changed-since-sync '
            . 'OR failed-with-attempts<%d OR stuck->%ds | batch limit %d',
            count($ids),
            $ids ? ' -> ' . implode(', ', $ids) : '',
            self::MAX_ATTEMPTS,
            self::STUCK_AFTER_SECONDS,
            self::BATCH_LIMIT
        ));

        return $ids;
    }
}
