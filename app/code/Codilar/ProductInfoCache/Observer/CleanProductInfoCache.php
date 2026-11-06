<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Observer;

use Codilar\ProductInfoCache\Model\ProductInfoCache;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;


/**
 * Drops a product's cached info whenever it is saved or deleted.
 *
 * Bound to both events because either one invalidates the entry: a save changes the
 * text, a delete means there is nothing left to describe. One clean() call covers every
 * store view, so editing the Arabic name alone still clears the English entry too -
 * slightly wasteful, and far safer than trying to guess which scope actually changed.
 */

class CleanProductInfoCache implements ObserverInterface
{
    public function __construct(
        private readonly ProductInfoCache $productInfoCache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof ProductInterface || !$product->getId()) {
            return;
        }

        $productId = (int)$product->getId();

        try {
            $this->productInfoCache->clean($productId);
        } catch (\Throwable $e) {
            // A cache that will not clear must never block a merchant from saving.
            // The entry expires on its own within CACHE_LIFETIME regardless.
            $this->logger->error(
                sprintf('Could not clean product info cache for product %d: %s', $productId, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }
}
