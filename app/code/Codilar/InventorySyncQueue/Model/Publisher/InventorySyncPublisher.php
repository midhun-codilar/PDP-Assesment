<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Model\Publisher;

use Codilar\InventorySyncQueue\Logger\StepLogger;
use Codilar\InventorySyncQueue\Model\ProductSyncCandidates;
use Codilar\InventorySyncQueue\Model\ResourceModel\SyncStatusResource;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Claims candidate products and publishes one message per product.
 *
 * Shared by the cron and the CLI so the two cannot behave differently - the CLI exists
 * precisely so you can test the cron's logic without waiting five minutes for it.
 */
class InventorySyncPublisher
{
    public const TOPIC = 'codilar.inventory.sync';

    public function __construct(
        private readonly ProductSyncCandidates $candidates,
        private readonly SyncStatusResource $syncStatusResource,
        private readonly PublisherInterface $publisher,
        private readonly StepLogger $stepLogger
    ) {
    }

    /**
     * @param string $trigger What kicked this run off, for the log trail.
     * @return array{candidates:int, published:int, failed:int}
     */
    public function publish(string $trigger = 'cron'): array
    {
        $stats = ['candidates' => 0, 'published' => 0, 'failed' => 0];
        $startedAt = microtime(true);

        $this->stepLogger->startRun($trigger);

        // Select and claim inside one transaction, with the select taking row locks.
        //
        // Two things keep concurrent runs apart. Magento's scheduler will not run the same
        // job code twice at once, and this transaction serialises anything that gets past
        // that - a second run blocks on the locked rows, then re-evaluates the criteria and
        // sees the products as freshly pending, so they no longer qualify.
        //
        // The claim happens BEFORE publishing, never after: a crash between the two leaves
        // products marked pending with nothing in the queue, which the 30 minute stuck
        // window recovers. The reverse order would leave messages in the queue that no row
        // knows about, and those get processed twice.
        $connection = $this->syncStatusResource->getConnection();
        $connection->beginTransaction();
        try {
            $productIds = $this->candidates->getCandidateIds(true);
            if ($productIds) {
                $this->syncStatusResource->markPending($productIds);
                $this->stepLogger->step(StepLogger::STEP_CLAIM, sprintf(
                    'marked %d product(s) pending in one transaction -> %s '
                    . '| they now fail every candidate rule, so no other run can pick them up',
                    count($productIds),
                    implode(', ', $productIds)
                ));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->stepLogger->fail(
                StepLogger::STEP_CLAIM,
                'transaction rolled back, nothing claimed or published: ' . $e->getMessage(),
                $e
            );
            throw $e;
        }

        $stats['candidates'] = count($productIds);
        if (!$productIds) {
            $this->stepLogger->step(StepLogger::STEP_CLAIM, 'nothing to claim');
            $this->stepLogger->step(StepLogger::STEP_RUN_END, sprintf(
                'candidates=0 published=0 failed=0 | %s | everything is already in sync',
                $this->elapsed($startedAt)
            ));

            return $stats;
        }

        foreach ($productIds as $productId) {
            try {
                // The message body is the product ID as a string; the topic declares
                // request="string" in communication.xml.
                $this->publisher->publish(self::TOPIC, (string)$productId);
                $stats['published']++;

                $this->stepLogger->step(StepLogger::STEP_PUBLISH, sprintf(
                    'product %d | status -> pending | message body "%d" sent to topic %s '
                    . '| %d of %d',
                    $productId,
                    $productId,
                    self::TOPIC,
                    $stats['published'] + $stats['failed'],
                    count($productIds)
                ));
            } catch (\Throwable $e) {
                // One unpublishable product must not strand the rest of the batch. Put it
                // straight to failed so the retry rule picks it up instead of leaving it
                // pending with no message behind it.
                $stats['failed']++;
                $this->syncStatusResource->markFailed($productId, 'Publish failed: ' . $e->getMessage());

                $this->stepLogger->fail(StepLogger::STEP_PUBLISH, sprintf(
                    'product %d | status pending -> failed | queue rejected it: %s',
                    $productId,
                    $e->getMessage()
                ), $e);
            }
        }

        $this->stepLogger->step(StepLogger::STEP_RUN_END, sprintf(
            'candidates=%d published=%d failed=%d | %s | waiting for the consumer now',
            $stats['candidates'],
            $stats['published'],
            $stats['failed'],
            $this->elapsed($startedAt)
        ));

        return $stats;
    }

    /**
     * @param float $startedAt
     * @return string
     */
    private function elapsed(float $startedAt): string
    {
        return sprintf('took %.3fs', microtime(true) - $startedAt);
    }
}
