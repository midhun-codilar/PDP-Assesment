<?php
declare(strict_types=1);

namespace Codilar\InventorySyncQueue\Model;

use Codilar\InventorySyncQueue\Logger\StepLogger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

/**
 * The actual inventory sync, isolated from the queue plumbing.
 *
 * Separated so the consumer handler stays a thin status machine: everything about *how*
 * inventory is synced is here, and can be changed, mocked or tested without touching the
 * queue at all.
 */
class InventorySyncService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StepLogger $stepLogger
    ) {
    }

    /**
     * Sync one product's inventory and return a short human readable result.
     *
     * @param int $productId
     * @return string Result summary, stored on the status row.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the product is gone.
     */
    public function sync(int $productId): string
    {
        $product = $this->productRepository->getById($productId);
        $sku = (string)$product->getSku();

        $this->stepLogger->step(StepLogger::STEP_SYNC, sprintf(
            'product %d | sku %s | reading inventory (this is where the WMS/ERP call goes)',
            $productId,
            $sku
        ));

        // ------------------------------------------------------------------
        // THIS IS WHERE THE EXTERNAL CALL WOULD GO.
        //
        // In a real integration you would call the WMS/ERP here - something like
        // $remote = $this->warehouseClient->getStock($sku); - and then write the result
        // back with StockRegistryInterface::updateStockItemBySku().
        //
        // For this exercise we read Magento's own stock instead, which exercises the same
        // code path (network-free) and gives the handler a real result string to store.
        // Anything that throws here is caught by the handler and recorded as failed, which
        // is exactly how a timeout or a 500 from the remote API would be handled.
        // ------------------------------------------------------------------
        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        $stockStatus = $this->stockRegistry->getStockStatusBySku($sku);

        $qty = (float)$stockItem->getQty();
        $isSalable = (bool)$stockStatus->getStockStatus();

        $result = sprintf(
            'sku=%s qty=%s salable=%s',
            $sku,
            rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') ?: '0',
            $isSalable ? 'yes' : 'no'
        );

        $this->stepLogger->step(StepLogger::STEP_SYNC, sprintf(
            'product %d | inventory read back | %s',
            $productId,
            $result
        ));

        return $result;
    }
}
