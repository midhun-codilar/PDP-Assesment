<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Data needed by the 2XL product page that Magento does not provide out of the box.
 */
class Pdp implements ArgumentInterface
{
    /** Loyalty points earned per currency unit (placeholder for the Sougha rewards API). */
    private const POINTS_RATIO = 0.7144;

    /** Total repayable factor and number of months used for the "as low as" instalment copy. */
    private const INSTALMENT_FACTOR = 1.17;
    private const INSTALMENT_MONTHS = 12;

    public function __construct(
        private readonly Registry $registry,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function getProduct(): ?ProductInterface
    {
        return $this->registry->registry('current_product');
    }

    public function getRegularPrice(Product $product): float
    {
        return (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
    }

    public function getFinalPrice(Product $product): float
    {
        return (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
    }

    public function getDiscountPercent(Product $product): int
    {
        $regular = $this->getRegularPrice($product);
        $final = $this->getFinalPrice($product);

        return $regular > 0 && $final < $regular ? (int)round((1 - $final / $regular) * 100) : 0;
    }

    public function getEarnPoints(Product $product): int
    {
        return (int)round($this->getFinalPrice($product) * self::POINTS_RATIO);
    }

    public function getMonthlyInstalment(Product $product): float
    {
        return $this->getFinalPrice($product) * self::INSTALMENT_FACTOR / self::INSTALMENT_MONTHS;
    }

    public function isNew(Product $product): bool
    {
        $from = $product->getData('news_from_date');
        $to = $product->getData('news_to_date');
        if (!$from && !$to) {
            return false;
        }
        $now = $this->timezone->date()->format('Y-m-d H:i:s');

        return (!$from || $from <= $now) && (!$to || $to >= $now);
    }

    /**
     * Deepest category of the product, used for the sub title link and the breadcrumb trail.
     */
    public function getPrimaryCategory(Product $product): ?Category
    {
        $categories = $product->getCategoryCollection()
            ->addAttributeToSelect(['name', 'url_key', 'is_active'])
            ->addIsActiveFilter()
            ->addOrder('level', 'DESC');

        /** @var Category|null $category */
        $category = $categories->getFirstItem();

        return $category && $category->getId() ? $category : null;
    }

    /**
     * Breadcrumb trail for a product: Home / parent categories / product.
     *
     * @return array<int, array{label: string, url: ?string}>
     */
    public function getBreadcrumbs(Product $product): array
    {
        $crumbs = [[
            'label' => (string)__('Home'),
            'url' => $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB)
        ]];

        $primary = $this->getPrimaryCategory($product);
        if ($primary) {
            $rootId = (int)$this->storeManager->getStore()->getRootCategoryId();
            $ids = array_filter(
                array_map('intval', explode('/', (string)$primary->getPath())),
                static fn (int $id) => $id > 1 && $id !== $rootId
            );
            $categories = $this->categoryCollectionFactory->create()
                ->addAttributeToSelect(['name', 'url_key'])
                ->addIdFilter($ids)
                ->setOrder('level', 'ASC');
            foreach ($categories as $category) {
                $crumbs[] = ['label' => (string)$category->getName(), 'url' => $category->getUrl()];
            }
        }
        $crumbs[] = ['label' => (string)$product->getName(), 'url' => null];

        return $crumbs;
    }
}
