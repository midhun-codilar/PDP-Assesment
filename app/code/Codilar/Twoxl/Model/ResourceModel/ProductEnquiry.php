<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Storage for product enquiries.
 */
class ProductEnquiry extends AbstractDb
{
    public const TABLE = 'codilar_twoxl_product_enquiry';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'entity_id');
    }
}
