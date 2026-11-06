<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Console\Command;

use Codilar\InventorySyncQueue\Model\ResourceModel\SyncStatusResource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento codilar:inventory-sync:status
 */
class StatusCommand extends Command
{
    public function __construct(
        private readonly SyncStatusResource $syncStatusResource,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('codilar:inventory-sync:status')
            ->setDescription('Show inventory sync row counts grouped by status.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->syncStatusResource->getCountsByStatus();

        $output->writeln('<info>Inventory sync status</info>');
        foreach ($counts as $status => $total) {
            $output->writeln(sprintf('  %-12s %d', $status, $total));
        }
        $output->writeln(sprintf('  %-12s %d', 'TOTAL', array_sum($counts)));

        return Command::SUCCESS;
    }
}
