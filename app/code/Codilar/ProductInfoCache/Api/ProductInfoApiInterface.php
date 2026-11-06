<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Api;

use Codilar\ProductInfoCache\Api\Data\ProductInfoResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Web API surface for the per store view product info cache.
 */

interface ProductInfoApiInterface
{
    /**
     * Product name and description for one store view, with cache diagnostics.
     *
     * @param int $productId
     * @param int $storeId
     * @return \Codilar\ProductInfoCache\Api\Data\ProductInfoResultInterface
     * @throws NoSuchEntityException
     */
    public function getProductInfo(int $productId, int $storeId): ProductInfoResultInterface;
}
