<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model\ResourceModel\SearchProduct;

use Codilar\RecentProductSync\Model\ResourceModel\SearchProduct as SearchProductResource;
use Codilar\RecentProductSync\Model\SearchProduct;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(SearchProduct::class, SearchProductResource::class);
    }

    /**
     * Rows belonging to one store view only.
     */
    public function addStoreFilter(int $storeId): self
    {
        $this->addFieldToFilter('store_id', $storeId);

        return $this;
    }

    /**
     * Only rows written within the TTL.
     *
     * The expiry cron deletes these eventually, but it can only run once a minute. This
     * keeps the storefront exact in the gap, so a visitor never sees a row that is past
     * its window but not yet swept.
     */
    public function addFreshnessFilter(string $threshold): self
    {
        $this->addFieldToFilter('synced_at', ['gteq' => $threshold]);

        return $this;
    }

    /**
     * Most recently touched products first.
     */
    public function orderByRecentlyUpdated(): self
    {
        $this->setOrder('product_updated_at', self::SORT_ORDER_DESC);
        $this->setOrder('entity_id', self::SORT_ORDER_DESC);

        return $this;
    }
}
