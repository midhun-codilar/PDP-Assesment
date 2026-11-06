<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Model;

/**
 * The four states a product can be in, and the table/column names they live under.
 *
 * Kept as one class of constants so a typo in a status string is a fatal error at the
 * call site rather than a row that silently never matches any query again.
 */
class SyncStatus
{
    public const TABLE = 'codilar_inventory_sync_status';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /**
     * Statuses that mean "a consumer may still be working on this".
     */
    public const IN_FLIGHT = [self::PENDING, self::PROCESSING];

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [self::PENDING, self::PROCESSING, self::COMPLETED, self::FAILED];
    }
}
