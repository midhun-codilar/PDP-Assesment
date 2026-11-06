<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model\ResourceModel\ProductEnquiry;

use Codilar\Twoxl\Model\ProductEnquiry;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method ProductEnquiry[] getItems()
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(ProductEnquiry::class, ProductEnquiryResource::class);
    }

    /**
     * Enquiries still waiting on someone, newest first.
     */
    public function addOpenFilter(): self
    {
        $this->addFieldToFilter('status', ['neq' => ProductEnquiry::STATUS_CLOSED])
            ->setOrder('created_at', self::SORT_ORDER_DESC);

        return $this;
    }
}
