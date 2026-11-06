<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Logger;

use Psr\Log\LoggerInterface;

/**
 * Writes the pipeline to var/log/codilar_inventory_sync.log as a numbered step trail.
 *
 * The point is that one `tail -f` shows the whole journey of a product in order, with no
 * knowledge of the code required. Every line carries the same shape:
 *
 *   [run:a1b2c3d4] [STEP 2 SCAN      ] 4 product(s) need sync -> 3, 4, 5, 6
 *
 * The run id ties lines together. Publishing gets one id for the whole batch; the
 * consumer sets a fresh id per message, so interleaved consumer output stays readable
 * even with several workers running at once.
 */
class StepLogger
{
    /** Publish side. */
    public const STEP_RUN_START = '1 RUN-START';
    public const STEP_SCAN      = '2 SCAN';
    public const STEP_CLAIM     = '3 CLAIM';
    public const STEP_PUBLISH   = '4 PUBLISH';
    public const STEP_RUN_END   = '5 RUN-END';

    /** Consumer side. */
    public const STEP_RECEIVE    = '6 RECEIVE';
    public const STEP_PROCESSING = '7 PROCESSING';
    public const STEP_SYNC       = '8 SYNC';
    public const STEP_RESULT     = '9 RESULT';

    private string $runId = '--------';

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Start a new correlated run and return its id.
     *
     * @param string $trigger What kicked this off, e.g. "cron" or "cli".
     * @return string
     */
    public function startRun(string $trigger): string
    {
        $this->runId = bin2hex(random_bytes(4));
        $this->step(self::STEP_RUN_START, sprintf('triggered by %s', $trigger));

        return $this->runId;
    }

    /**
     * Adopt an id for a unit of work that did not start a run (one consumed message).
     *
     * @param string $runId
     * @return void
     */
    public function setRunId(string $runId): void
    {
        $this->runId = $runId;
    }

    /**
     * @return string
     */
    public function newRunId(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * @param string $step One of the STEP_* constants.
     * @param string $message
     * @return void
     */
    public function step(string $step, string $message): void
    {
        $this->logger->info($this->format($step, $message));
    }

    /**
     * @param string $step
     * @param string $message
     * @return void
     */
    public function warn(string $step, string $message): void
    {
        $this->logger->warning($this->format($step, $message));
    }

    /**
     * @param string $step
     * @param string $message
     * @param \Throwable|null $e
     * @return void
     */
    public function fail(string $step, string $message, ?\Throwable $e = null): void
    {
        $this->logger->error($this->format($step, $message), $e ? ['exception' => $e] : []);
    }

    /**
     * Fixed-width columns so the file lines up when read with tail.
     *
     * @param string $step
     * @param string $message
     * @return string
     */
    private function format(string $step, string $message): string
    {
        return sprintf('[run:%s] [STEP %-12s] %s', $this->runId, $step, $message);
    }
}
