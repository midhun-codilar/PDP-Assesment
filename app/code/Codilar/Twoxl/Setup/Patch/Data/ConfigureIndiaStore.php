<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Regional settings for the India storefront.
 *
 * Catalog price scope moves to Website, which is what lets each website carry its own base
 * currency - Catalog\Model\Product\Attribute\Backend\Price::setScope() reads that flag and
 * re-scopes the price attribute at runtime, so nothing in eav has to be rewritten.
 *
 * "Add Store Code to Urls" is global rather than per website (showInDefault only, and
 * Store::isUseStoreInUrl reads the default scope), so switching it on here is what puts both
 * /ae_en/ and /in_en/ in front of storefront URLs.
 */
class ConfigureIndiaStore implements DataPatchInterface
{
    private const WEBSITE_CODE = 'in';
    private const STORE_CODE = 'in_en';

    /** Applies to the whole installation. */
    private const GLOBAL_SETTINGS = [
        // 1 = website; each website then owns its base currency and its own prices.
        'catalog/price/scope' => '1',
        'web/url/use_store' => '1',
    ];

    /** Applies to the India website only; the UAE site keeps the AED defaults. */
    private const WEBSITE_SETTINGS = [
        'currency/options/base' => 'INR',
        'currency/options/default' => 'INR',
        'currency/options/allow' => 'INR',
        'general/country/default' => 'IN',
        'general/country/allow' => 'IN',
    ];

    /** Applies to the India store view. */
    private const STORE_SETTINGS = [
        'general/locale/code' => 'en_IN',
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function apply(): self
    {
        foreach (self::GLOBAL_SETTINGS as $path => $value) {
            $this->configWriter->save($path, $value, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }

        $websiteId = (int)$this->storeManager->getWebsite(self::WEBSITE_CODE)->getId();
        foreach (self::WEBSITE_SETTINGS as $path => $value) {
            $this->configWriter->save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        }

        $storeId = (int)$this->storeManager->getStore(self::STORE_CODE)->getId();
        foreach (self::STORE_SETTINGS as $path => $value) {
            $this->configWriter->save($path, $value, ScopeInterface::SCOPE_STORES, $storeId);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddIndiaWebsite::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
