<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Console\Command;

use Codilar\RecentProductSync\Model\Synchronizer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento codilar:search:sync - the same job the cron runs, on demand.
 */
class SyncProductsCommand extends Command
{
    private const OPTION_FULL = 'full';

    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('codilar:search:sync')
            ->setDescription('Sync recently updated products into the custom search table.')
            ->addOption(
                self::OPTION_FULL,
                'f',
                InputOption::VALUE_NONE,
                'Ignore the stored cursor and re-sync the whole catalogue.'
            )
            ->addOption(
                self::OPTION_BATCH_SIZE,
                'b',
                InputOption::VALUE_REQUIRED,
                'Products per batch.',
                (string)Synchronizer::DEFAULT_BATCH_SIZE
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Store-scoped attribute values (name, short_description) are only resolved
        // correctly once an area is set; the CLI starts with none.
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Already set by another command in the same process - nothing to do.
        }

        $full = (bool)$input->getOption(self::OPTION_FULL);
        $batchSize = (int)$input->getOption(self::OPTION_BATCH_SIZE);

        $output->writeln($full
            ? '<info>Running a full re-sync...</info>'
            : '<info>Running an incremental sync...</info>');

        try {
            $stats = $this->synchronizer->run($full, $batchSize);
        } catch (\Throwable $e) {
            $output->writeln('<error>Sync failed: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        $output->writeln(sprintf('  window   : %s -> %s', $stats['from'], $stats['to']));
        $output->writeln(sprintf('  scanned  : %d product(s) in %d batch(es)', $stats['scanned'], $stats['batches']));
        $output->writeln(sprintf('  upserted : %d row(s)', $stats['upserted']));
        $output->writeln(sprintf('  deleted  : %d row(s)', $stats['deleted']));
        $output->writeln(sprintf('  expired  : %d row(s) past their TTL', $stats['expired']));
        $output->writeln(sprintf('<info>Done in %.3Fs.</info>', $stats['duration']));

        return Command::SUCCESS;
    }
}
