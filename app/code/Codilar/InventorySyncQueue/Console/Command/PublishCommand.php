<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Console\Command;

use Codilar\InventorySyncQueue\Model\Publisher\InventorySyncPublisher;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento codilar:inventory-sync:publish
 *
 * Runs exactly what the cron runs, so the five minute wait is not part of the test loop.
 */
class PublishCommand extends Command
{
    public function __construct(
        private readonly InventorySyncPublisher $publisher,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('codilar:inventory-sync:publish')
            ->setDescription('Find products needing inventory sync and publish them to the queue.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException) {
            // Already set in this process.
        }

        try {
            $stats = $this->publisher->publish('cli (codilar:inventory-sync:publish)');
        } catch (\Throwable $e) {
            $output->writeln('<error>Publish failed: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        $output->writeln(sprintf('  candidates found    : %d', $stats['candidates']));
        $output->writeln(sprintf('  published           : %d', $stats['published']));
        $output->writeln(sprintf('  failed to publish   : %d', $stats['failed']));

        if ($stats['candidates'] === 0) {
            $output->writeln(
                '<info>  Nothing needed syncing - everything is either up to date or still'
                . ' in flight.</info>'
            );
        }

        return Command::SUCCESS;
    }
}
