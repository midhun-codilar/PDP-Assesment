<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Tiny key/value accessor for codilar_search_sync_state.
 *
 * Only one key is used today, but the table is generic so later syncs
 * (price feed, stock feed, ...) can keep their own cursor beside it.
 */
class SyncState
{
    public const TABLE = 'codilar_search_sync_state';

    public const KEY_LAST_SYNCED_AT = 'last_synced_at';

    /**
     * Cursor used the very first time the cron runs: epoch, so everything is picked up.
     */
    public const DEFAULT_LAST_SYNCED_AT = '1970-01-01 00:00:00';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getLastSyncedAt(): string
    {
        $value = $this->get(self::KEY_LAST_SYNCED_AT);

        return $value !== null && $value !== '' ? $value : self::DEFAULT_LAST_SYNCED_AT;
    }

    public function setLastSyncedAt(string $timestamp): void
    {
        $this->set(self::KEY_LAST_SYNCED_AT, $timestamp);
    }

    public function get(string $key): ?string
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), 'state_value')
            ->where('state_key = ?', $key);

        $value = $connection->fetchOne($select);

        return $value === false ? null : (string)$value;
    }

    public function set(string $key, ?string $value): void
    {
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['state_key' => $key, 'state_value' => $value],
            ['state_value']
        );
    }
}
