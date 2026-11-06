<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Pins each region's prices to the right scope.
 *
 * SetIndiaCatalogPrices first ran while the cached config still reported a global price scope,
 * so the rupee figures landed on the default row (store 0) and took the dirham prices with
 * them. This writes both sets back where they belong: AED on the default row, which the UAE
 * website inherits, and INR on the India view.
 */
class RestoreRegionalPrices implements DataPatchInterface, NonTransactionableInterface
{
    private const INDIA_STORE_CODE = 'in_en';

    /** Default scope - the dirham prices the UAE storefront reads. */
    private const AED_PRICES = [
        '130917' => [4495.0, 3575.0],
        '127933-IVORY' => [13999.0, 11190.0],
        '127933-RED' => [15999.0, 12799.0],
    ];

    /** India view - seeded from AED at the placeholder rate in SetIndiaCatalogPrices. */
    private const INR_PRICES = [
        '130917' => [102999.0, 81999.0],
        '127933-IVORY' => [319999.0, 255999.0],
        '127933-RED' => [364999.0, 291999.0],
    ];

    public function __construct(
        private readonly State $appState,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductAction $productAction,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    public function apply(): self
    {
        // Without this the price attribute may still report global scope and both writes
        // would end up on the same row.
        $this->reinitableConfig->reinit();

        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            $indiaStoreId = (int)$this->storeManager->getStore(self::INDIA_STORE_CODE)->getId();

            $this->writePrices(self::AED_PRICES, 0);
            $this->writePrices(self::INR_PRICES, $indiaStoreId);
        });

        return $this;
    }

    /**
     * @param array<string, array{0: float, 1: float}> $prices
     */
    private function writePrices(array $prices, int $storeId): void
    {
        foreach ($prices as $sku => [$price, $specialPrice]) {
            try {
                $id = (int)$this->productRepository->get($sku)->getId();
            } catch (NoSuchEntityException) {
                continue;
            }

            $this->productAction->updateAttributes(
                [$id],
                ['price' => $price, 'special_price' => $specialPrice],
                $storeId
            );
        }
    }

    public static function getDependencies(): array
    {
        return [SetIndiaCatalogPrices::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
