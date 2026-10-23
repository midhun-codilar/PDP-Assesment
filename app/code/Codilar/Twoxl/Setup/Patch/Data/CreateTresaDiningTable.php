<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Menu categories and the "Tresa 8 Seater Dining table" product used to build the PDP.
 */
class CreateTresaDiningTable implements DataPatchInterface
{
    private const SKU = '130917';
    private const IMPORT_DIR = 'import/codilar_twoxl';

    /** Top level menu categories, in menu order. */
    private const MENU = ['Furniture', 'Accessories', 'Accessible Luxury', 'New Arrivals', 'Outdoor', 'Kids', 'Wall'];

    /** Furniture > Dining > Dining Tables > 8-Seater Dining */
    private const TRAIL = ['Furniture', 'Dining', 'Dining Tables', '8-Seater Dining'];

    private const DESCRIPTION = <<<'HTML'
<p>Everyday meals feel a little more special at the Tresa 8 Seater Dining Table. Its smoked glass top keeps the room feeling open and lets the sculpted wooden base show through from every angle. The two leg bases are made of interlocking pieces with soft, rounded shapes that add warmth to a modern dining room. There is room for eight around the long rectangular top.</p>
<ul>
<li>Tempered glass top in a grey tint</li>
<li>Solid wood base in a rich walnut tone</li>
<li>Measures 240cm in length, 120cm in width, and 75.9cm in height</li>
<li>Please note that colours may appear slightly different on screen due to photography and display settings</li>
</ul>
HTML;

    public function __construct(
        private readonly State $appState,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly Filesystem $filesystem
    ) {
    }

    public function apply(): self
    {
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            $this->storeManager->setCurrentStore(0);
            $leafId = $this->createCategories();
            $this->createProduct($leafId);
        });

        return $this;
    }

    /**
     * @return int id of the deepest category of the Dining Table trail
     */
    private function createCategories(): int
    {
        $rootId = (int)$this->storeManager->getStore(1)->getRootCategoryId();
        $position = 1;
        foreach (self::MENU as $name) {
            $ids[$name] = $this->ensureCategory($name, $rootId, $position++);
        }

        $parentId = $rootId;
        foreach (self::TRAIL as $depth => $name) {
            $parentId = $depth === 0 ? $ids[$name] : $this->ensureCategory($name, $parentId, 1);
        }

        return $parentId;
    }

    private function ensureCategory(string $name, int $parentId, int $position): int
    {
        $parent = $this->categoryRepository->get($parentId, 0);
        $urlKey = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

        foreach ($parent->getChildrenCategories() as $child) {
            if ($child->getName() === $name) {
                return (int)$child->getId();
            }
        }

        /** @var Category $category */
        $category = $this->categoryFactory->create();
        $category->setName($name)
            ->setUrlKey($urlKey)
            ->setIsActive(true)
            ->setIncludeInMenu(true)
            ->setIsAnchor(true)
            ->setPosition($position)
            ->setParentId($parent->getId())
            ->setAttributeSetId($category->getDefaultAttributeSetId());
        $this->categoryRepository->save($category);

        return (int)$category->getId();
    }

    private function createProduct(int $categoryId): void
    {
        try {
            $this->productRepository->get(self::SKU);
            $this->assignStock();
            return;
        } catch (NoSuchEntityException) {
            // not created yet
        }

        /** @var ProductInterface $product */
        $product = $this->productFactory->create();
        $product->setSku(self::SKU)
            ->setName('Tresa 8 Seater Dining table')
            ->setUrlKey('tresa-8-seater-dining-table-130917')
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId($product->getDefaultAttributeSetId())
            ->setStatus(Status::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([1])
            ->setPrice(4495)
            ->setSpecialPrice(3575)
            ->setWeight(60)
            ->setCategoryIds($this->categoryAncestry($categoryId))
            ->setDescription(self::DESCRIPTION)
            ->setData('news_from_date', '2026-01-01 00:00:00')
            ->setData('height_cm', '75.9')
            ->setData('width_cm', '120')
            ->setData('color', 'Grey')
            ->setData('material', 'Tempered glass &Solid wood')
            ->setData('length_cm', '240')
            ->setData('care', '<ul><li>Wipe with clean & soft cloth</li></ul>');

        $this->attachImages($product);
        $this->productRepository->save($product);
        $this->assignStock();
    }

    private function assignStock(): void
    {
        /** @var SourceItemInterface $sourceItem */
        $sourceItem = $this->sourceItemFactory->create();
        $sourceItem->setSourceCode('default');
        $sourceItem->setSku(self::SKU);
        $sourceItem->setQuantity(100);
        $sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
        $this->sourceItemsSave->execute([$sourceItem]);
    }

    /**
     * Category plus its parents, so the product is listed all the way up the trail.
     *
     * @return int[]
     */
    private function categoryAncestry(int $categoryId): array
    {
        $path = (string)$this->categoryRepository->get($categoryId, 0)->getPath();

        return array_values(array_filter(
            array_map('intval', explode('/', $path)),
            static fn (int $id) => $id > 2
        ));
    }

    /**
     * Copies the fixture images below pub/media/import and adds them to the product gallery.
     */
    private function attachImages(ProductInterface $product): void
    {
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $media->create(self::IMPORT_DIR);

        $source = __DIR__ . '/../../Fixtures/images';
        $files = glob($source . '/' . self::SKU . '_*.png') ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $index => $file) {
            $relative = $this->copyToMedia($media, $file);
            $product->addImageToMediaGallery(
                $media->getAbsolutePath($relative),
                $index === 0 ? ['image', 'small_image', 'thumbnail'] : null,
                false,
                false
            );
        }
    }

    private function copyToMedia(WriteInterface $media, string $file): string
    {
        $relative = self::IMPORT_DIR . '/' . basename($file);
        $media->getDriver()->filePutContents($media->getAbsolutePath($relative), (string)file_get_contents($file));

        return $relative;
    }

    public static function getDependencies(): array
    {
        return [AddProductAttributes::class, ConfigureStore::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
