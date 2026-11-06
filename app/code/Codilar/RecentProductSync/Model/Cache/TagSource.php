<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model\Cache;

use Magento\Framework\DataObject;
use Magento\Framework\DataObject\IdentityInterface;

/**
 * Carries a list of cache tags to the core `clean_cache_by_tags` listeners.
 *
 * Those listeners (Magento\PageCache\Observer\FlushCacheByTags for the built-in page
 * cache, Magento\CacheInvalidate\Observer\InvalidateVarnishObserver for Varnish) read
 * the event's `object` and run it through Magento\Framework\App\Cache\Tag\Resolver,
 * which uses the Identifier strategy for anything implementing IdentityInterface.
 * This class is the smallest object that satisfies that contract.
 */
class TagSource extends DataObject implements IdentityInterface
{
    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return array_values(array_unique(array_filter((array)$this->getData('identities'))));
    }
}
