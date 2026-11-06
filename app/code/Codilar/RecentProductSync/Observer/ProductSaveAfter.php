<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Observer;

use Codilar\RecentProductSync\Model\ProductSyncService;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps codilar_search_product current the moment a product is saved, anywhere:
 * admin form, mass action, import, REST, a data patch.
 *
 * The upsert covers every store view, so a save made in admin scope still refreshes
 * the Arabic and English rows with their own store-scoped name and description.
 */
class ProductSaveAfter implements ObserverInterface
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
            // syncProducts() removes the rows itself when the product stopped being
            // eligible (disabled, hidden, pulled off the website), so there is no
            // separate branch for that here.
            $result = $this->syncService->syncProducts([$productId]);

            $this->logger->debug(sprintf(
                'Save sync for product %d: %d row(s) upserted, %d row(s) removed.',
                $productId,
                $result['upserted'],
                $result['deleted']
            ));
        } catch (\Throwable $e) {
            // A search-index side table must never be the reason a merchant cannot save
            // a product. Log and let the save finish; the 15-minute cron repairs the row.
            $this->logger->error(sprintf(
                'Could not sync product %d after save: %s',
                $productId,
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }
}
