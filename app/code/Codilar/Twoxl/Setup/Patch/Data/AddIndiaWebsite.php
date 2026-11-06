<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;

/**
 * Second storefront for India, sharing the UAE catalogue and theme.
 *
 * The store group points at root category 2, so both regions sell the same products, and the
 * theme stays on the default scope so India inherits the 2XL look without further setup.
 *
 * The existing view is renamed from "default" to "ae_en" at the same time: ConfigureIndiaStore
 * switches on "Add Store Code to Urls", which is a global flag, so every view gets a prefix.
 * Left alone the UAE site would answer on /default/ rather than /ae_en/.
 */

class AddIndiaWebsite implements DataPatchInterface
{
    private const WEBSITE_CODE = 'in';
    private const GROUP_CODE = 'in_store';
    private const STORE_CODE = 'in_en';

    /** Shared with the UAE storefront - one catalogue, two regions. */
    private const ROOT_CATEGORY_ID = 2;

    /** code => name for the view that shipped as "default" */
    private const RENAMED_VIEW = ['default' => ['ae_en', 'UAE English']];

    public function __construct(
        private readonly WebsiteFactory $websiteFactory,
        private readonly WebsiteResource $websiteResource,
        private readonly GroupFactory $groupFactory,
        private readonly GroupResource $groupResource,
        private readonly StoreFactory $storeFactory,
        private readonly StoreResource $storeResource
    ) {
    }

    public function apply(): self
    {
        $this->renameDefaultView();

        $website = $this->websiteFactory->create();
        $this->websiteResource->load($website, self::WEBSITE_CODE, 'code');
        if (!$website->getId()) {
            $website->setCode(self::WEBSITE_CODE)
                ->setName('2XL Home India')
                ->setSortOrder(2)
                ->setIsDefault(false);
            $this->websiteResource->save($website);
        }

        $group = $this->groupFactory->create();
        $this->groupResource->load($group, self::GROUP_CODE, 'code');
        if (!$group->getId()) {
            $group->setWebsiteId((int)$website->getId())
                ->setCode(self::GROUP_CODE)
                ->setName('India Store')
                ->setRootCategoryId(self::ROOT_CATEGORY_ID);
            $this->groupResource->save($group);
        }

        $store = $this->storeFactory->create();
        $this->storeResource->load($store, self::STORE_CODE, 'code');
        if (!$store->getId()) {
            $store->setCode(self::STORE_CODE)
                ->setName('India English')
                ->setWebsiteId((int)$website->getId())
                ->setGroupId((int)$group->getId())
                ->setSortOrder(0)
                ->setIsActive(1);
            $this->storeResource->save($store);
        }

        // Defaults can only be pointed at rows that exist, so they are set last.
        $website->setDefaultGroupId((int)$group->getId());
        $this->websiteResource->save($website);

        $group->setDefaultStoreId((int)$store->getId());
        $this->groupResource->save($group);

        return $this;
    }

    private function renameDefaultView(): void
    {
        foreach (self::RENAMED_VIEW as $oldCode => [$newCode, $newName]) {
            $store = $this->storeFactory->create();
            $this->storeResource->load($store, $oldCode, 'code');
            if ($store->getId()) {
                $store->setCode($newCode)->setName($newName);
                $this->storeResource->save($store);
            }
        }
    }

    public static function getDependencies(): array
    {
        return [ConfigureStore::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
