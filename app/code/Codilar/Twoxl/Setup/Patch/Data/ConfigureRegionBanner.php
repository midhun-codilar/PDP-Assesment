<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Gives the India storefront its own wording and accent colour.
 *
 * Only India is written here. The UAE keeps the defaults from etc/config.xml, which is the
 * point worth seeing in the admin: one storefront inherits, the other overrides, and only the
 * overridden rows exist in core_config_data.
 */
class ConfigureRegionBanner implements DataPatchInterface
{
    private const STORE_CODE = 'in_en';

    private const SETTINGS = [
        'twoxl/region/enabled' => '1',
        'twoxl/region/headline' => 'Now delivering across India',
        'twoxl/region/subline' => 'Free delivery & installation in 40+ cities',
        'twoxl/region/accent' => '#1b7a4b',
    ];

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function apply(): self
    {
        try {
            $storeId = (int)$this->storeManager->getStore(self::STORE_CODE)->getId();
        } catch (NoSuchEntityException) {
            // India not present on this install
            return $this;
        }

        foreach (self::SETTINGS as $path => $value) {
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
