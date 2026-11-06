<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model;

use Codilar\RecentProductSync\Model\ResourceModel\SearchProduct as SearchProductResource;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * One row of codilar_search_product: a single product as one store view sees it.
 */
class SearchProduct extends AbstractModel implements IdentityInterface
{
    /**
     * Cache tag for the recently-updated list as a whole.
     *
     * It deliberately identifies the *list*, not its current members. A per-product tag
     * alone cannot work here: a product that is not in the top N today has no tag on the
     * cached page, so editing it - the very thing that should push it to the top of the
     * list - would leave the page untouched.
     */
    public const CACHE_TAG = 'codilar_search_product';

    protected function _construct(): void
    {
        $this->_init(SearchProductResource::class);
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [
            self::CACHE_TAG,
            self::CACHE_TAG . '_' . (int)$this->getData('store_id'),
        ];
    }
}
