<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * The `codilar_product_info` cache type.
 *
 * TagScope stamps CACHE_TAG onto everything written through this frontend and confines
 * every clean() to that tag. That is what keeps this cache a closed world: flushing it
 * cannot touch another module's entries, and another module flushing its own type
 * cannot touch ours - even though both live in the same physical cache backend.
 */
class ProductInfo extends TagScope
{
    /**
     * Must match the type name in etc/cache.xml; it is also the key into FrontendPool.
     */
    public const TYPE_IDENTIFIER = 'codilar_product_info';

    /**
     * Tag applied to every entry of this cache type.
     */
    public const CACHE_TAG = 'CODILAR_PRODUCT_INFO';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
