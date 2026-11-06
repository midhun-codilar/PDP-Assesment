<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * How long a row in codilar_search_product is allowed to live after it was written.
 *
 * A row is dead TTL_SECONDS after its synced_at, and is then deleted outright - it is
 * not archived and not merely hidden. It only comes back when its product is saved
 * again or a sync rewrites it.
 *
 * Both the writer (ProductSyncService, which deletes) and the reader (the storefront
 * block, which filters) take the window from here, so they can never disagree about
 * what counts as expired.
 */
class RowLifetime
{
    /**
     * Seconds a row stays valid after synced_at.
     *
     * To drive this from admin instead, inject ScopeConfigInterface here and read a
     * config path - every caller goes through getTtlSeconds(), so nothing else changes.
     */
    public const TTL_SECONDS = 60;

    public function __construct(
        private readonly DateTime $dateTime
    ) {
    }

    public function getTtlSeconds(): int
    {
        return self::TTL_SECONDS;
    }

    /**
     * Rows with synced_at strictly older than this are expired.
     *
     * UTC, because synced_at is written with gmdate(); comparing against a local-time
     * NOW() would expire rows hours early or late depending on the server's timezone.
     */
    public function getExpiryThreshold(): string
    {
        return gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $this->getTtlSeconds());
    }
}
