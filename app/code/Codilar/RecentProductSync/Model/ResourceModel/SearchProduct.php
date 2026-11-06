<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SearchProduct extends AbstractDb
{
    public const TABLE_NAME = 'codilar_search_product';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'entity_id');
    }
}
