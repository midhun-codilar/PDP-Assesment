<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Model;

use Codilar\ProductInfoCache\Model\Cache\Type\ProductInfo as ProductInfoCacheType;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads product name and description through a per store view cache.
 *
 * The store id is part of the cache key, not an afterthought, which is the whole point:
 * every store view gets its own entry, so the English text can never be served to the
 * Arabic storefront no matter which store warmed the cache first.
 */
class ProductInfoCache
{
    /**
     * Seconds an entry stays valid.
     *
     * Deliberately tiny so the cache can be watched working: call the API, see
     * source=BACKEND, call again within the window for source=CACHE, wait it out and
     * watch it fall back to BACKEND.
     *
     * This is a demonstration value. For production put it back to 86400 (24 h) - entries
     * are invalidated on product save anyway, so the lifetime is only the backstop for a
     * product changed outside Magento.
     */
    public const CACHE_LIFETIME = 5;

    /**
     * Prefix for every key this service writes.
     */
    private const KEY_PREFIX = 'codilar_product_info';

    /**
     * Per product tag, so one product can be dropped across all store views at once.
     */
    private const PRODUCT_TAG_PREFIX = 'CODILAR_PRODUCT_INFO';

    public function __construct(
        private readonly ProductInfoCacheType $cache,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SerializerInterface $serializer,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Product name and description as one store view sees them.
     *
     * @param int $productId
     * @param int|null $storeId Defaults to the current store view.
     * @return array{id:int, store_id:int, name:string, description:string}
     * @throws NoSuchEntityException When no such product exists.
     */
    public function getProductInfo(int $productId, ?int $storeId = null): array
    {
        $storeId ??= (int)$this->storeManager->getStore()->getId();
        $cacheKey = $this->getCacheKey($productId, $storeId);

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false && $cached !== null) {
            $data = $this->serializer->unserialize($cached);
            if (is_array($data)) {
                return $data;
            }
            // Corrupt or stale-format entry: fall through and rebuild it.
        }

        // false = do not force a reload from a cached product instance; $storeId is
        // passed explicitly so the repository resolves store scoped attribute values
        // rather than whatever store happens to be current.
        $product = $this->productRepository->getById($productId, false, $storeId);

        $data = [
            'id'          => (int)$product->getId(),
            'store_id'    => $storeId,
            'name'        => (string)$product->getName(),
            'description' => (string)$product->getCustomAttribute('description')?->getValue(),
        ];

        $this->cache->save(
            $this->serializer->serialize($data),
            $cacheKey,
            [$this->getProductTag($productId)],
            self::CACHE_LIFETIME
        );

        return $data;
    }

    /**
     * The cache key for one product in one store view.
     *
     * @param int $productId
     * @param int $storeId
     * @return string
     */
    public function getCacheKey(int $productId, int $storeId): string
    {
        return sprintf('%s_%d_%d', self::KEY_PREFIX, $productId, $storeId);
    }

    /**
     * Drop one product from the cache in every store view.
     *
     * Cleaning by the per product tag rather than deleting keys one at a time means we
     * do not have to know how many store views exist, or which of them were ever warmed.
     *
     * @param int $productId
     * @return void
     */
    public function clean(int $productId): void
    {
        $this->cache->clean(
            CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG,
            [$this->getProductTag($productId)]
        );
    }

    /**
     * Read an entry straight back out of the cache without populating it on a miss.
     *
     * Exists so the verify command can show what is physically stored under each key,
     * rather than what the service would return - the difference between proving the
     * cache is partitioned and merely asserting it.
     *
     * @param int $productId
     * @param int $storeId
     * @return array<string, mixed>|null Null when nothing is cached under that key.
     */
    public function loadRaw(int $productId, int $storeId): ?array
    {
        $cached = $this->cache->load($this->getCacheKey($productId, $storeId));
        if ($cached === false || $cached === null) {
            return null;
        }

        $data = $this->serializer->unserialize($cached);

        return is_array($data) ? $data : null;
    }

    /**
     * Unix timestamp an entry was written, or null when nothing is cached.
     *
     * The cache frontend's test() returns the entry's last modified time, which is what
     * lets callers report an age and a countdown rather than a bare hit/miss.
     *
     * @param int $productId
     * @param int $storeId
     * @return int|null
     */
    public function getCachedAt(int $productId, int $storeId): ?int
    {
        $timestamp = $this->cache->test($this->getCacheKey($productId, $storeId));

        return is_int($timestamp) ? $timestamp : null;
    }

    /**
     * Lifetime, in seconds, applied to every entry this service writes.
     *
     * @return int
     */
    public function getCacheLifetime(): int
    {
        return self::CACHE_LIFETIME;
    }

    /**
     * Whether this product/store pair is already cached. Used by the verify command to
     * report HIT or MISS without disturbing the entry.
     *
     * @param int $productId
     * @param int $storeId
     * @return bool
     */
    public function isCached(int $productId, int $storeId): bool
    {
        return $this->cache->test($this->getCacheKey($productId, $storeId)) !== false;
    }

    private function getProductTag(int $productId): string
    {
        return sprintf('%s_%d', self::PRODUCT_TAG_PREFIX, $productId);
    }
}
