<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Cron;

use Codilar\InventorySyncQueue\Logger\StepLogger;
use Codilar\InventorySyncQueue\Model\Publisher\InventorySyncPublisher;

/**
 * Every 5 minutes: find products needing an inventory sync and queue them.
 *
 * It deliberately does no syncing itself. The cron's only job is to decide *what* needs
 * doing and hand it off, which keeps a slow or failing external API off the cron thread
 * entirely - the consumer absorbs that, and can be scaled independently.
 */
class PublishInventorySync
{
    public function __construct(
        private readonly InventorySyncPublisher $publisher,
        private readonly StepLogger $stepLogger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->publisher->publish('cron (*/5 * * * *)');
        } catch (\Throwable $e) {
            // Swallowed on purpose: an exception escaping here marks the cron group's job
            // errored and can hold up everything queued behind it. Nothing was claimed,
            // because the claim is transactional, so the next run simply retries.
            $this->stepLogger->fail(
                StepLogger::STEP_RUN_END,
                'publish run aborted: ' . $e->getMessage(),
                $e
            );
        }
    }
}
