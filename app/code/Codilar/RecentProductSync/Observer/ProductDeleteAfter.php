<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Observer;

use Codilar\RecentProductSync\Model\ProductSyncService;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Drops the product's rows when it is deleted.
 *
 * The foreign key on product_id already cascades, so this is belt and braces - but it
 * is the only path that still works if the constraint is ever dropped (some hosting
 * migrations do), and it keeps the delete explicit and greppable.
 */
class ProductDeleteAfter implements ObserverInterface
{
    public function __construct(
        private readonly ProductSyncService $syncService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof ProductInterface || !$product->getId()) {
            return;
        }

        $productId = (int)$product->getId();

        try {
            $deleted = $this->syncService->deleteProducts([$productId]);

            $this->logger->debug(sprintf(
                'Delete sync for product %d: %d row(s) removed.',
                $productId,
                $deleted
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Could not remove product %d after delete: %s',
                $productId,
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }
}
