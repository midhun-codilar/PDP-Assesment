<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Cron;

use Codilar\RecentProductSync\Model\ProductSyncService;
use Codilar\RecentProductSync\Model\RowLifetime;
use Psr\Log\LoggerInterface;

/**
 * Deletes expired rows once a minute.
 *
 * One minute is the floor Magento's scheduler can offer - the OS crontab calls cron:run
 * at that interval - so with a TTL shorter than a minute a row can outlive its window in
 * the table by up to 60 seconds. The storefront never shows those stragglers: the block
 * filters on the same TTL, so what a visitor sees is exact even between purges.
 */
class PurgeExpiredRows
{
    public function __construct(
        private readonly ProductSyncService $syncService,
        private readonly RowLifetime $rowLifetime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $deleted = $this->syncService->purgeExpiredRows();

            if ($deleted > 0) {
                $this->logger->info(sprintf(
                    'Expiry sweep: %d row(s) older than %ds removed.',
                    $deleted,
                    $this->rowLifetime->getTtlSeconds()
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Expiry sweep failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
