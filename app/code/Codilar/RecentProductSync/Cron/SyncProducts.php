<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Cron;

use Codilar\RecentProductSync\Model\Synchronizer;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: catch anything the save observer missed.
 *
 * The observer covers normal edits; this covers the rest - scheduled-update (staging)
 * flips, raw SQL imports, a save that threw, and a Magento restored from a dump.
 */
class SyncProducts
{
    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $stats = $this->synchronizer->run();

            $this->logger->info(sprintf(
                'Sync finished. Window %s -> %s | scanned: %d | upserted: %d | deleted: %d'
                . ' | expired: %d | batches: %d | %.3Fs',
                $stats['from'],
                $stats['to'],
                $stats['scanned'],
                $stats['upserted'],
                $stats['deleted'],
                $stats['expired'],
                $stats['batches'],
                $stats['duration']
            ));
        } catch (\Throwable $e) {
            // Swallowed on purpose: an exception escaping here marks the cron group's
            // job as errored and can hold up the jobs queued behind it. The cursor was
            // not advanced, so the next run retries the same window.
            $this->logger->error(
                'Sync failed, cursor left untouched: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
