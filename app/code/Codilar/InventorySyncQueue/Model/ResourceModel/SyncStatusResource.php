<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Model\ResourceModel;

use Codilar\InventorySyncQueue\Model\SyncStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Every read and write against codilar_inventory_sync_status lives here.
 *
 * One place, so the status vocabulary and the timestamp handling cannot drift between
 * the publisher, the consumer and the CLI. Raw ResourceConnection rather than a model
 * per row because the publisher claims up to 500 products in a single statement.
 */
class SyncStatusResource
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Claim products for publishing by marking them pending.
     *
     * insertOnDuplicate against the unique product_id key, so a product with no row gets
     * one and a product with a row is reset to pending in the same statement. attempts is
     * deliberately NOT reset: the retry ceiling has to survive being re-queued, otherwise
     * a permanently broken product loops forever.
     *
     * @param int[] $productIds
     * @return int Rows affected.
     */
    public function markPending(array $productIds): int
    {
        if (!$productIds) {
            return 0;
        }

        $now = $this->now();
        $rows = [];
        foreach ($productIds as $productId) {
            $rows[] = [
                'product_id' => (int)$productId,
                'status'     => SyncStatus::PENDING,
                'queued_at'  => $now,
            ];
        }

        return (int)$this->getConnection()->insertOnDuplicate(
            $this->getTable(),
            $rows,
            ['status', 'queued_at']
        );
    }

    /**
     * Move one product into processing and count the attempt.
     *
     * @param int $productId
     * @return void
     */
    public function markProcessing(int $productId): void
    {
        $connection = $this->getConnection();
        $connection->update(
            $this->getTable(),
            [
                'status'   => SyncStatus::PROCESSING,
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
            ],
            ['product_id = ?' => $productId]
        );
    }

    /**
     * @param int $productId
     * @param string $message
     * @return void
     */
    public function markCompleted(int $productId, string $message): void
    {
        $now = $this->now();
        $this->getConnection()->update(
            $this->getTable(),
            [
                'status'         => SyncStatus::COMPLETED,
                'message'        => $message,
                'processed_at'   => $now,
                'last_synced_at' => $now,
            ],
            ['product_id = ?' => $productId]
        );
    }

    /**
     * @param int $productId
     * @param string $message
     * @return void
     */
    public function markFailed(int $productId, string $message): void
    {
        $this->getConnection()->update(
            $this->getTable(),
            [
                'status'       => SyncStatus::FAILED,
                'message'      => mb_substr($message, 0, 2000),
                'processed_at' => $this->now(),
            ],
            ['product_id = ?' => $productId]
        );
    }

    /**
     * Current status of one product, or null when it has never been seen.
     *
     * @param int $productId
     * @return array<string, mixed>|null
     */
    public function getByProductId(int $productId): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->where('product_id = ?', $productId)
        );

        return $row ?: null;
    }

    /**
     * Row count per status, including statuses with no rows so the report never has holes.
     *
     * @return array<string, int>
     */
    public function getCountsByStatus(): array
    {
        $connection = $this->getConnection();
        $counts = array_fill_keys(SyncStatus::all(), 0);

        $select = $connection->select()
            ->from($this->getTable(), ['status', 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->group('status');

        foreach ($connection->fetchAll($select) as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * @return AdapterInterface
     */
    public function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->resource->getTableName(SyncStatus::TABLE);
    }

    /**
     * UTC, to match what Magento stores in catalog_product_entity.updated_at. Magento
     * forces its MySQL session to +00:00, so gmdate() and the DB agree.
     *
     * @return string
     */
    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
