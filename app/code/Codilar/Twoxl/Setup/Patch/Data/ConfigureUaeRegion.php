<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Names the country of the original storefront.
 *
 * It was never set, which left the header's region list without a label for the UAE once
 * Codilar\Twoxl\ViewModel\RegionSwitcher started building that list from the stores.
 */
class ConfigureUaeRegion implements DataPatchInterface
{
    private const WEBSITE_CODE = 'base';

    private const SETTINGS = [
        'general/country/default' => 'AE',
        'general/country/allow' => 'AE',
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function apply(): self
    {
        $websiteId = (int)$this->storeManager->getWebsite(self::WEBSITE_CODE)->getId();

        foreach (self::SETTINGS as $path => $value) {
            $this->configWriter->save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId);
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
