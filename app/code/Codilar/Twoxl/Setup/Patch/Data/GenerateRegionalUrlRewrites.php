<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\CategoryRepository;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;

/**
 * Builds the catalogue URL rewrites for every store view.
 *
 * Magento generates rewrites when a category or product is saved, so a store view added after
 * the catalogue already exists starts with none of its own and every category URL under it
 * answers 404. This fills the gap for whatever views are present.
 */
class GenerateRegionalUrlRewrites implements DataPatchInterface, NonTransactionableInterface
{
    private const ROOT_CATEGORY_ID = 2;

    public function __construct(
        private readonly State $appState,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CategoryRepository $categoryRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        private readonly ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        private readonly UrlPersistInterface $urlPersist
    ) {
    }

    public function apply(): self
    {
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            $this->rebuildCategories();
            $this->rebuildProducts();
        });

        return $this;
    }

    private function rebuildCategories(): void
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('url_key')
            ->addFieldToFilter('path', ['like' => '1/' . self::ROOT_CATEGORY_ID . '/%']);

        foreach ($collection as $item) {
            $category = $this->categoryRepository->get((int)$item->getId(), 0);
            $category->setStoreId(0);
            // true = regenerate for every store the category reaches, not just the current one.
            $this->persist($this->categoryUrlRewriteGenerator->generate($category, true));
        }
    }

    private function rebuildProducts(): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['url_key', 'visibility'])
            ->addFieldToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);

        foreach ($collection as $product) {
            $product->setStoreId(0);
            $this->persist($this->productUrlRewriteGenerator->generate($product));
        }
    }

    /**
     * @param array $urls
     */
    private function persist(array $urls): void
    {
        if (!$urls) {
            return;
        }

        try {
            $this->urlPersist->replace($urls);
        } catch (\Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException) {
            // an equivalent rewrite is already in place
        }
    }

    public static function getDependencies(): array
    {
        return [AddIndiaWebsite::class, SetIndiaCatalogPrices::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
