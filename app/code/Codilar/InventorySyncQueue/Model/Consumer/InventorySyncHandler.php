<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Model\Consumer;

use Codilar\InventorySyncQueue\Logger\StepLogger;
use Codilar\InventorySyncQueue\Model\InventorySyncService;
use Codilar\InventorySyncQueue\Model\ResourceModel\SyncStatusResource;
use Codilar\InventorySyncQueue\Model\SyncStatus;

/**
 * Consumes codilar.inventory.sync messages.
 *
 * A thin status machine around InventorySyncService: pending -> processing -> completed
 * or failed. It never rethrows, because an exception escaping a consumer handler takes
 * the message with it - the work would be lost and the row left stuck in processing until
 * the 30 minute window expired. Recording the failure instead lets the retry rule see it
 * on the very next publish run.
 */
class InventorySyncHandler
{
    public function __construct(
        private readonly SyncStatusResource $syncStatusResource,
        private readonly InventorySyncService $inventorySyncService,
        private readonly StepLogger $stepLogger
    ) {
    }

    /**
     * @param string $message Product ID, as published.
     * @return void
     */
    public function process(string $message): void
    {
        // A fresh id per message: a consumer handles many messages in one process, and
        // several consumers may run at once, so the publish run's id cannot be reused.
        $this->stepLogger->setRunId($this->stepLogger->newRunId());

        $productId = (int)trim($message);

        $this->stepLogger->step(StepLogger::STEP_RECEIVE, sprintf(
            'took message body "%s" off queue codilar_inventory_sync_queue -> product %d',
            $message,
            $productId
        ));

        if ($productId <= 0) {
            $this->stepLogger->fail(StepLogger::STEP_RESULT, sprintf(
                'discarded | message body "%s" is not a usable product id',
                $message
            ));

            return;
        }

        $before = $this->syncStatusResource->getByProductId($productId);
        $oldStatus = $before['status'] ?? '(no row)';

        if ($before === null) {
            // The row is gone - the product was deleted after publishing and the foreign
            // key cascaded. Nothing to sync and nothing to record.
            $this->stepLogger->warn(StepLogger::STEP_RESULT, sprintf(
                'product %d | skipped | no status row, the product was deleted after publishing',
                $productId
            ));

            return;
        }

        $startedAt = microtime(true);

        try {
            $this->syncStatusResource->markProcessing($productId);
            $this->stepLogger->step(StepLogger::STEP_PROCESSING, sprintf(
                'product %d | status %s -> %s | attempt %d of %d',
                $productId,
                $oldStatus,
                SyncStatus::PROCESSING,
                (int)$before['attempts'] + 1,
                \Codilar\InventorySyncQueue\Model\ProductSyncCandidates::MAX_ATTEMPTS
            ));

            $result = $this->inventorySyncService->sync($productId);

            $this->syncStatusResource->markCompleted($productId, $result);
            $this->stepLogger->step(StepLogger::STEP_RESULT, sprintf(
                'product %d | status %s -> %s | %s | took %.3fs | last_synced_at updated',
                $productId,
                SyncStatus::PROCESSING,
                SyncStatus::COMPLETED,
                $result,
                microtime(true) - $startedAt
            ));
        } catch (\Throwable $e) {
            $this->syncStatusResource->markFailed($productId, $e->getMessage());
            $this->stepLogger->fail(StepLogger::STEP_RESULT, sprintf(
                'product %d | status %s -> %s | %s | took %.3fs | the next publish run will retry it',
                $productId,
                SyncStatus::PROCESSING,
                SyncStatus::FAILED,
                $e->getMessage(),
                microtime(true) - $startedAt
            ), $e);
        }
    }
}
