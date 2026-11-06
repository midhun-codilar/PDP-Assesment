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
 * Puts the catalogue on the India website and gives it rupee prices.
 *
 * Prices are seeded from the AED figures at a flat placeholder rate (see RATE) and rounded to
 * a trailing 999, keeping the same 20% discount the UAE site shows. They are real website
 * scoped values, so editing one region never touches the other - replace them with the
 * commercial prices when those are agreed.
 *
 * Non transactional because this updates existing products: Product::getIdentities() reads the
 * area code, and PatchApplier defers commit callbacks past the emulateAreaCode() block.
 *
 * The config is reinitialised first. ConfigureIndiaStore writes catalog/price/scope in this same
 * process, and until the cached config is dropped the price attribute still reports global scope -
 * a store scoped write would then collapse onto the default row and overwrite the AED prices.
 */
class SetIndiaCatalogPrices implements DataPatchInterface, NonTransactionableInterface
{
    private const WEBSITE_CODE = 'in';
    private const STORE_CODE = 'in_en';

    /** Placeholder AED -> INR rate used to seed the prices. */
    private const RATE = 23;

    /** Everything that should be sellable in India. */
    private const SKUS = ['130917', '127933', '127933-IVORY', '127933-RED'];

    /** sku => [price, special price] in INR. A configurable carries no price of its own. */
    private const PRICES = [
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
        $this->reinitableConfig->reinit();

        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            $websiteId = (int)$this->storeManager->getWebsite(self::WEBSITE_CODE)->getId();
            $storeId = (int)$this->storeManager->getStore(self::STORE_CODE)->getId();

            $ids = [];
            foreach (self::SKUS as $sku) {
                try {
                    $ids[$sku] = (int)$this->productRepository->get($sku)->getId();
                } catch (NoSuchEntityException) {
                    // product not present on this install
                }
            }

            if (!$ids) {
                return;
            }

            $this->productAction->updateWebsites(array_values($ids), [$websiteId], 'add');

            foreach (self::PRICES as $sku => [$price, $specialPrice]) {
                if (isset($ids[$sku])) {
                    // India has a single view, so a store level write is the website value.
                    $this->productAction->updateAttributes(
                        [$ids[$sku]],
                        ['price' => $price, 'special_price' => $specialPrice],
                        $storeId
                    );
                }
            }
        });

        return $this;
    }

    public static function getDependencies(): array
    {
        return [ConfigureIndiaStore::class, FixSofaVariantImages::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
