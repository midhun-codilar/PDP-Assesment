<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Magento\Directory\Model\CountryFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The country list behind the globe icon in the header.
 *
 * Built from the active store views rather than a fixed list, so adding a region in the admin
 * is enough for it to appear. Each entry is labelled with the country configured for that
 * store's website and the currency it sells in.
 */
class RegionSwitcher implements ArgumentInterface
{
    private const COUNTRY_PATH = 'general/country/default';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CountryFactory $countryFactory
    ) {
    }

    /**
     * @return array<int, array{label: string, currency: string, url: string, isCurrent: bool}>
     */
    public function getRegions(): array
    {
        $currentId = (int)$this->storeManager->getStore()->getId();
        $regions = [];

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }

            $regions[] = [
                'label' => $this->countryLabel($store->getId(), (string)$store->getName()),
                'currency' => (string)$store->getCurrentCurrencyCode(),
                'url' => (string)$store->getBaseUrl(UrlInterface::URL_TYPE_LINK),
                'isCurrent' => (int)$store->getId() === $currentId,
            ];
        }

        return $regions;
    }

    /**
     * Country name for the store, falling back to the store's own name when no country is set.
     */
    private function countryLabel(int|string $storeId, string $fallback): string
    {
        $code = (string)$this->scopeConfig->getValue(self::COUNTRY_PATH, ScopeInterface::SCOPE_STORE, $storeId);

        if ($code === '') {
            return $fallback;
        }

        $name = (string)$this->countryFactory->create()->loadByCode($code)->getName();

        return $name !== '' ? $name : $fallback;
    }
}
