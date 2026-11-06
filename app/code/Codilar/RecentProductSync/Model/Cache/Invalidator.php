<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model\Cache;

use Codilar\RecentProductSync\Model\SearchProduct;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;

/**
 * Invalidates the cached pages that render the recently-updated list.
 *
 * Rows in codilar_search_product are written with ResourceConnection, not with a model
 * save, so the framework's FlushCacheByTags plugin on AbstractResource never fires and
 * nothing would otherwise evict the cached homepage. Both halves below are needed:
 *
 *  - CacheInterface::clean()  covers the default pool (block_html, collections).
 *  - the `clean_cache_by_tags` event covers the page cache - built-in *and* Varnish -
 *    which live in their own pools and are only reachable through those observers.
 */
class Invalidator
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly EventManager $eventManager,
        private readonly TagSourceFactory $tagSourceFactory
    ) {
    }

    /**
     * @param int[] $storeIds Store views whose rows changed. Empty means "every store".
     */
    public function invalidate(array $storeIds = []): void
    {
        $tags = $this->buildTags($storeIds);

        $this->cache->clean($tags);

        $this->eventManager->dispatch(
            'clean_cache_by_tags',
            ['object' => $this->tagSourceFactory->create(['data' => ['identities' => $tags]])]
        );
    }

    /**
     * Narrow to the store views that actually changed when we know them, so editing a
     * product on the English store does not evict the Arabic storefront's homepage.
     *
     * @param int[] $storeIds
     * @return string[]
     */
    private function buildTags(array $storeIds): array
    {
        if (!$storeIds) {
            // No store scope given (a delete spans every store view) - the generic tag
            // matches the identities of every store's block.
            return [SearchProduct::CACHE_TAG];
        }

        $tags = [];
        foreach ($storeIds as $storeId) {
            $tags[] = SearchProduct::CACHE_TAG . '_' . (int)$storeId;
        }

        return $tags;
    }
}
