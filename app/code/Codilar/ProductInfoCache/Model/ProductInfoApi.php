<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Model;

use Codilar\ProductInfoCache\Api\Data\ProductInfoResultInterface;
use Codilar\ProductInfoCache\Api\ProductInfoApiInterface;
use Codilar\ProductInfoCache\Model\Data\ProductInfoResult;
use Codilar\ProductInfoCache\Model\Data\ProductInfoResultFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Web API implementation.
 *
 * Its job beyond the lookup is honesty about where the answer came from. `source` is
 * sampled BEFORE the read - once getProductInfo() runs, a miss has become a hit, and
 * asking afterwards would report CACHE every single time.
 */
class ProductInfoApi implements ProductInfoApiInterface
{
    public function __construct(
        private readonly ProductInfoCache $productInfoCache,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductInfoResultFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getProductInfo(int $productId, int $storeId): ProductInfoResultInterface
    {
        // Resolve the store first, so an unknown store id is a clean 404 rather than a
        // confusing "product not found in store 99".
        $store = $this->storeRepository->getById($storeId);

        if ($productId <= 0) {
            throw new NoSuchEntityException(__('Product id must be a positive integer.'));
        }

        // Sampled BEFORE the read. Afterwards every call looks like a hit, including the
        // one that just populated the entry.
        $cachedAt = $this->productInfoCache->getCachedAt($productId, $storeId);
        $wasCached = $cachedAt !== null;

        $started = microtime(true);
        $info = $this->productInfoCache->getProductInfo($productId, $storeId);
        $elapsedMs = (microtime(true) - $started) * 1000;

        $lifetime = $this->productInfoCache->getCacheLifetime();
        // On a miss the entry was written microseconds ago, so it starts a full lifetime.
        $age = $wasCached ? max(0, time() - $cachedAt) : 0;
        $expiresIn = max(0, $lifetime - $age);

        return $this->resultFactory->create(['data' => [
            ProductInfoResult::PRODUCT_ID     => (int)$info['id'],
            ProductInfoResult::STORE_ID       => (int)$info['store_id'],
            ProductInfoResult::STORE_CODE     => (string)$store->getCode(),
            ProductInfoResult::LOCALE         => (string)$this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            ProductInfoResult::NAME           => (string)$info['name'],
            ProductInfoResult::DESCRIPTION    => (string)$info['description'],
            ProductInfoResult::SOURCE         => $wasCached
                ? ProductInfoResultInterface::SOURCE_CACHE
                : ProductInfoResultInterface::SOURCE_BACKEND,
            ProductInfoResult::CACHE_KEY      => $this->productInfoCache->getCacheKey($productId, $storeId),
            ProductInfoResult::CACHE_LIFETIME => $lifetime,
            ProductInfoResult::TIME_MS        => round($elapsedMs, 3),
            ProductInfoResult::AGE_SECONDS    => $age,
            ProductInfoResult::EXPIRES_IN_SECONDS => $expiresIn,
        ]]);
    }
}
