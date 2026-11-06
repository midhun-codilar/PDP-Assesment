<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The incremental sync itself, shared by the cron job and the CLI command.
 *
 * Deliberately returns statistics instead of logging them: the cron and the console
 * command want to report the same numbers in different places.
 */


class Synchronizer
{
    public const DEFAULT_BATCH_SIZE = 500;
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductSyncService $syncService,
        private readonly SyncState $syncState,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param bool $full Ignore the stored cursor and walk the whole catalog.
     * @return array{from:string, to:string, scanned:int, upserted:int, deleted:int, batches:int, expired:int, duration:float}
     */
    public function run(bool $full = false, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $started = microtime(true);

        // (a) Take the clock reading BEFORE querying. Anything saved while this run is in
        // flight therefore has updated_at >= this value and is picked up next time, rather
        // than falling into the gap between the query and the cursor write.
        $syncStartedAt = $this->dateTime->gmtDate();

        // (b) Where the previous successful run stopped.
        $lastSyncedAt = $full ? SyncState::DEFAULT_LAST_SYNCED_AT : $this->syncState->getLastSyncedAt();

        $stats = [
            'from'     => $lastSyncedAt,
            'to'       => $syncStartedAt,
            'scanned'  => 0,
            'upserted' => 0,
            'deleted'  => 0,
            'batches'  => 0,
            'expired'  => 0,
            'duration' => 0.0,
        ];

        $batchSize = max(1, $batchSize);
        $lastId = 0;

        // (c) + (d) Walk the changed products in keyset-paginated batches and upsert each
        // batch for every store view. Keyset paging (entity_id > lastId) rather than
        // LIMIT/OFFSET, so concurrent saves cannot make a page slide past unseen.
        while (true) {
            $productIds = $this->fetchChangedProductIds($lastSyncedAt, $lastId, $batchSize);
            if (!$productIds) {
                break;
            }

            $batchResult = $this->syncService->syncProducts($productIds);

            $stats['scanned']  += count($productIds);
            $stats['upserted'] += $batchResult['upserted'];
            $stats['deleted']  += $batchResult['deleted'];
            $stats['batches']++;

            $lastId = (int)end($productIds);

            if (count($productIds) < $batchSize) {
                break;
            }
        }

        // (e) Safety net for rows whose product vanished without the FK cascade firing.
        $stats['deleted'] += $this->syncService->removeOrphanRows();

        // Expire rows that have outlived their TTL. Done after the upserts, so a product
        // touched by this very run keeps its full lifetime rather than being written and
        // deleted in the same pass.
        $stats['expired'] = $this->syncService->purgeExpiredRows();

        // (f) Only now, after every batch succeeded, does the cursor move. An exception
        // anywhere above leaves it untouched, so the next run replays the same window.
        $this->syncState->setLastSyncedAt($syncStartedAt);

        $stats['duration'] = round(microtime(true) - $started, 3);

        return $stats;
    }

    /**
     * IDs of products changed since the cursor, one page at a time.
     *
     * @return int[] ascending
     */
    private function fetchChangedProductIds(string $since, int $afterId, int $limit): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), 'entity_id')
            ->where('updated_at > ?', $since)
            ->where('entity_id > ?', $afterId)
            ->order('entity_id ASC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
