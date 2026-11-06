<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Seating categories and the "Niecel Sectional Sofa" product.
 */
class CreateNiecelSectionalSofa implements DataPatchInterface
{
    private const SKU = '127933';
    private const IMPORT_DIR = 'import/codilar_twoxl';

    /** Furniture > Sofa & Seating > Corner Sofa */
    private const TRAIL = ['Furniture', 'Sofa & Seating', 'Corner Sofa'];

    /** Dropdown values picked by admin label, resolved to option ids on save. */
    private const SELECTED_OPTIONS = [
        'sofa_shape' => 'Corner Sofa',
        'sofa_material' => 'Boucle',
        'frame_material' => 'Solid Wood',
        'filling_material' => 'High Resilience Foam',
        'seating_capacity' => '5 Seater',
        'color' => 'Ivory',
    ];

    private const DESCRIPTION = <<<'HTML'
<p>The Niecel Sectional Sofa turns a living room into somewhere you want to stay a while. Its curved corner shape wraps around the room, while deep rounded arms and plump scatter cushions keep the whole piece soft to look at and softer to sit on. The textured boucle cover adds warmth without weight, so a sofa this generous still feels light in the space.</p>
<ul>
<li>Textured boucle upholstery in a warm ivory tone</li>
<li>Solid wood frame with high resilience foam seating</li>
<li>Supplied with matching scatter cushions</li>
<li>Measures 330cm in length, 200cm in width, and 78cm in height</li>
<li>Please note that colours may appear slightly different on screen due to photography and display settings</li>
</ul>
HTML;

    private const CARE = <<<'HTML'
<ul><li>Vacuum regularly with an upholstery attachment</li><li>Blot spills straight away with a clean, dry cloth</li><li>Professional upholstery cleaning recommended</li></ul>
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
        private readonly Filesystem $filesystem,
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            $this->storeManager->setCurrentStore(0);
            $this->createProduct($this->createCategories());
        });

        return $this;
    }

    /**
     * @return int id of the deepest category of the seating trail
     */
    private function createCategories(): int
    {
        $parentId = (int)$this->storeManager->getStore(1)->getRootCategoryId();
        foreach (self::TRAIL as $name) {
            $parentId = $this->ensureCategory($name, $parentId);
        }

        return $parentId;
    }

    private function ensureCategory(string $name, int $parentId): int
    {
        $parent = $this->categoryRepository->get($parentId, 0);

        foreach ($parent->getChildrenCategories() as $child) {
            if ($child->getName() === $name) {
                return (int)$child->getId();
            }
        }

        /** @var Category $category */
        $category = $this->categoryFactory->create();
        $category->setName($name)
            ->setUrlKey(strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-')))
            ->setIsActive(true)
            ->setIncludeInMenu(true)
            ->setIsAnchor(true)
            ->setPosition(1)
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
            ->setName('Niecel Sectional Sofa')
            ->setUrlKey('niecel-sectional-sofa-127933')
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId($product->getDefaultAttributeSetId())
            ->setStatus(Status::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([1])
            ->setPrice(13999)
            ->setSpecialPrice(11190)
            ->setWeight(120)
            ->setCategoryIds($this->categoryAncestry($categoryId))
            ->setDescription(self::DESCRIPTION)
            ->setData('news_from_date', '2026-01-01 00:00:00')
            ->setData('length_cm', '330')
            ->setData('width_cm', '200')
            ->setData('height_cm', '78')
            ->setData('material', 'Boucle fabric & Solid wood')
            ->setData('care', self::CARE);

        foreach (self::SELECTED_OPTIONS as $code => $label) {
            $optionId = $this->optionId($code, $label);
            if ($optionId) {
                $product->setData($code, $optionId);
            }
        }

        $this->attachImages($product);
        $this->productRepository->save($product);
        $this->assignStock();
    }

    /**
     * Read straight from the option tables - the EAV source cache is still cold during setup.
     */
    private function optionId(string $attributeCode, string $label): int
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->moduleDataSetup->getTable('eav_attribute_option')], 'option_id')
            ->join(
                ['a' => $this->moduleDataSetup->getTable('eav_attribute')],
                'a.attribute_id = o.attribute_id',
                []
            )
            ->join(
                ['t' => $this->moduleDataSetup->getTable('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->join(
                ['v' => $this->moduleDataSetup->getTable('eav_attribute_option_value')],
                'v.option_id = o.option_id AND v.store_id = 0',
                []
            )
            ->where('t.entity_type_code = ?', Product::ENTITY)
            ->where('a.attribute_code = ?', $attributeCode)
            ->where('v.value = ?', $label);

        return (int)$connection->fetchOne($select);
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
        return [AddSofaAttributes::class, CreateTresaDiningTable::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
