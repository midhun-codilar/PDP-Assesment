<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Block;

use Codilar\RecentProductSync\Model\ResourceModel\SearchProduct\Collection;
use Codilar\RecentProductSync\Model\ResourceModel\SearchProduct\CollectionFactory;
use Codilar\RecentProductSync\Model\RowLifetime;
use Codilar\RecentProductSync\Model\SearchProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * "Recently updated products", read straight out of codilar_search_product.
 *
 * It never touches the product repository: the whole point of the side table is that a
 * listing like this is one indexed query instead of an EAV collection load. Because the
 * table already holds one row per store view, filtering on the current store is all it
 * takes for the Arabic storefront to show Arabic names and the English one English.
 */
class RecentProducts extends Template implements IdentityInterface
{
    private const DEFAULT_LIMIT = 8;

    private const MAX_LIMIT = 50;

    private const XML_PATH_PRODUCT_URL_SUFFIX = 'catalog/seo/product_url_suffix';

    /** @var SearchProduct[]|null */
    private ?array $items = null;

    public function __construct(
        Template\Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly RowLifetime $rowLifetime,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * How many products to show. Set `limit` from layout XML; clamped so a typo in a
     * layout file cannot turn the homepage into a full catalogue dump.
     */
    public function getLimit(): int
    {
        $limit = (int)$this->getData('limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    public function getHeading(): string
    {
        $heading = $this->getData('heading');

        return $heading !== null && $heading !== ''
            ? (string)$heading
            : (string)__('Recently Updated Products');
    }

    /**
     * @return SearchProduct[]
     */
    public function getItems(): array
    {
        if ($this->items === null) {
            /** @var Collection $collection */
            $collection = $this->collectionFactory->create();
            $collection->addStoreFilter((int)$this->_storeManager->getStore()->getId())
                ->addFreshnessFilter($this->rowLifetime->getExpiryThreshold())
                ->orderByRecentlyUpdated()
                ->setPageSize($this->getLimit())
                ->setCurPage(1);

            $this->items = array_values($collection->getItems());
        }

        return $this->items;
    }

    public function hasItems(): bool
    {
        return $this->getItems() !== [];
    }

    /**
     * Rebuilt from url_key rather than stored, so a change to the configured URL suffix
     * takes effect without re-running the sync.
     */
    public function getProductUrl(SearchProduct $item): string
    {
        $urlKey = (string)$item->getData('url_key');
        if ($urlKey === '') {
            // No url_key on this store view - fall back to the catalog router, which
            // resolves the product by ID.
            return $this->getUrl('catalog/product/view', ['id' => (int)$item->getData('product_id')]);
        }

        $suffix = (string)$this->_scopeConfig->getValue(
            self::XML_PATH_PRODUCT_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $this->getStore()->getId()
        );

        return $this->getStore()->getBaseUrl() . $urlKey . $suffix;
    }

    /**
     * The original upload, not a resized cache entry: the table stores the image path,
     * and generating a cached thumbnail needs a loaded product object. Size it in CSS.
     */
    public function getImageUrl(SearchProduct $item): string
    {
        $path = (string)$item->getData('image');
        if ($path === '') {
            return $this->getViewFileUrl('Magento_Catalog::images/product/placeholder/image.jpg');
        }

        return rtrim($this->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/')
            . '/catalog/product'
            . '/' . ltrim($path, '/');
    }

    /**
     * The price the customer pays. Empty string when no price is known, which the
     * template renders as "price on request".
     */
    public function getFormattedFinalPrice(SearchProduct $item): string
    {
        return $this->formatPrice($this->getFinalPrice($item));
    }

    /**
     * The list price, shown struck through - only meaningful when there is a discount.
     */
    public function getFormattedRegularPrice(SearchProduct $item): string
    {
        return $this->formatPrice($this->getRegularPrice($item));
    }

    public function getRegularPrice(SearchProduct $item): float
    {
        return (float)$item->getData('price');
    }

    /**
     * Falls back to the regular price for rows written before final_price existed, so an
     * un-resynced table shows one correct price rather than a 100% discount.
     */
    public function getFinalPrice(SearchProduct $item): float
    {
        $final = $item->getData('final_price');

        return ($final === null || $final === '')
            ? $this->getRegularPrice($item)
            : (float)$final;
    }

    /**
     * Whole-percent saving, the same calculation the product page badge uses.
     */
    public function getDiscountPercent(SearchProduct $item): int
    {
        $regular = $this->getRegularPrice($item);
        $final = $this->getFinalPrice($item);

        return $regular > 0 && $final < $regular
            ? (int)round((1 - $final / $regular) * 100)
            : 0;
    }

    public function getFormattedSaving(SearchProduct $item): string
    {
        return $this->formatPrice($this->getRegularPrice($item) - $this->getFinalPrice($item));
    }

    /**
     * A discount worth printing. Guards the rounding case where a saving of a few cents
     * would otherwise render as a shouty "-0% OFF".
     */
    public function hasDiscount(SearchProduct $item): bool
    {
        return $this->getDiscountPercent($item) > 0;
    }

    private function formatPrice(float $amount): string
    {
        if ($amount <= 0) {
            return '';
        }

        return (string)$this->priceCurrency->convertAndFormat(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $this->getStore()
        );
    }

    /**
     * Product updated_at, rendered in the store's own timezone and locale.
     */
    public function getFormattedUpdatedAt(SearchProduct $item): string
    {
        $updatedAt = $item->getData('product_updated_at');
        if (!$updatedAt) {
            return '';
        }

        return $this->formatDate($updatedAt, \IntlDateFormatter::MEDIUM, true);
    }

    /**
     * Cache tags recorded against any page that renders this block.
     *
     * Without these the full page cache has nothing tying the cached homepage to this
     * data, so an edited product keeps showing its old name until someone flushes by
     * hand - which is exactly the bug this fixes.
     *
     * The list tag is the one that matters: it fires even when the edited product was
     * not previously on the page. The per-product tags are belt and braces, so a core
     * flow that cleans cat_p_<id> (a price rule, a stock change) refreshes us too.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $identities = [
            SearchProduct::CACHE_TAG,
            SearchProduct::CACHE_TAG . '_' . (int)$this->_storeManager->getStore()->getId(),
        ];

        foreach ($this->getItems() as $item) {
            $identities[] = Product::CACHE_TAG . '_' . (int)$item->getData('product_id');
        }

        return array_values(array_unique($identities));
    }

    private function getStore(): StoreInterface
    {
        try {
            return $this->_storeManager->getStore();
        } catch (NoSuchEntityException $e) {
            return $this->_storeManager->getDefaultStoreView();
        }
    }
}
