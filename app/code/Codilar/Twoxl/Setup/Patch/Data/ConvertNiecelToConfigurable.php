<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

/**
 * Turns the Niecel sofa into a colour configurable: the existing 127933 becomes the parent
 * and keeps its url key and categories, while each colour is a hidden simple child carrying
 * its own price and gallery.
 */
class ConvertNiecelToConfigurable implements DataPatchInterface
{
    private const PARENT_SKU = '127933';
    private const IMPORT_DIR = 'import/codilar_twoxl';

    /**
     * child sku => [colour label, image fixture glob, price, special price]
     *
     * Ivory reuses the gallery the product already had; Red uses the new photos.
     */
    private const VARIANTS = [
        '127933-IVORY' => ['Ivory', '127933_*.png', 13999.0, 11190.0],
        '127933-RED' => ['Red', '127933-RED_*.jpg', 15999.0, 12799.0],
    ];

    public function __construct(
        private readonly State $appState,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly ConfigurableOptionsFactory $optionsFactory,
        private readonly Filesystem $filesystem,
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            $childIds = [];
            $valueIndexes = [];

            foreach (self::VARIANTS as $sku => [$colour, $glob, $price, $specialPrice]) {
                $optionId = $this->colorOptionId($colour);
                $childIds[] = $this->ensureChild($sku, $colour, $optionId, $glob, $price, $specialPrice);
                $valueIndexes[] = $optionId;
            }

            $this->convertParent($childIds, $valueIndexes);
        });

        return $this;
    }

    /**
     * @return int entity id of the colour child
     */
    private function ensureChild(
        string $sku,
        string $colour,
        int $optionId,
        string $glob,
        float $price,
        float $specialPrice
    ): int {
        try {
            $existing = $this->productRepository->get($sku);
            $this->assignStock($sku);

            return (int)$existing->getId();
        } catch (NoSuchEntityException) {
            // not created yet
        }

        /** @var ProductInterface|Product $child */
        $child = $this->productFactory->create();
        $child->setSku($sku)
            ->setName('Niecel Sectional Sofa - ' . $colour)
            ->setUrlKey('niecel-sectional-sofa-127933-' . strtolower($colour))
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId($child->getDefaultAttributeSetId())
            ->setStatus(Status::STATUS_ENABLED)
            // Children are reached through the parent, never listed on their own.
            ->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE)
            ->setWebsiteIds([1])
            ->setPrice($price)
            ->setSpecialPrice($specialPrice)
            ->setWeight(120)
            ->setData('color', $optionId);

        $this->attachImages($child, $glob);
        $this->productRepository->save($child);
        $this->assignStock($sku);

        return (int)$this->productRepository->get($sku)->getId();
    }

    /**
     * @param int[] $childIds
     * @param int[] $valueIndexes
     */
    private function convertParent(array $childIds, array $valueIndexes): void
    {
        $parent = $this->productRepository->get(self::PARENT_SKU);
        if ($parent->getTypeId() === Configurable::TYPE_CODE) {
            return;
        }

        $attributeId = $this->colorAttributeId();

        $options = $this->optionsFactory->create([
            [
                'attribute_id' => $attributeId,
                'code' => 'color',
                'label' => 'Color',
                'position' => '0',
                'values' => array_map(
                    static fn (int $valueIndex) => ['value_index' => $valueIndex],
                    $valueIndexes
                ),
            ],
        ]);

        $parent->setTypeId(Configurable::TYPE_CODE)
            // Price comes from whichever child is selected; the colour lives on the children.
            ->setData('price', null)
            ->setData('special_price', null)
            ->setData('color', null);

        $extension = $parent->getExtensionAttributes();
        $extension->setConfigurableProductOptions($options);
        $extension->setConfigurableProductLinks($childIds);
        $parent->setExtensionAttributes($extension);

        $this->productRepository->save($parent);
    }

    private function assignStock(string $sku): void
    {
        /** @var SourceItemInterface $sourceItem */
        $sourceItem = $this->sourceItemFactory->create();
        $sourceItem->setSourceCode('default');
        $sourceItem->setSku($sku);
        $sourceItem->setQuantity(100);
        $sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
        $this->sourceItemsSave->execute([$sourceItem]);
    }

    private function attachImages(ProductInterface $product, string $glob): void
    {
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $media->create(self::IMPORT_DIR);

        $files = glob(__DIR__ . '/../../Fixtures/images/' . $glob) ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $index => $file) {
            $relative = self::IMPORT_DIR . '/' . basename($file);
            $media->getDriver()->filePutContents(
                $media->getAbsolutePath($relative),
                (string)file_get_contents($file)
            );
            $product->addImageToMediaGallery(
                $media->getAbsolutePath($relative),
                $index === 0 ? ['image', 'small_image', 'thumbnail'] : null,
                false,
                false
            );
        }
    }

    private function colorAttributeId(): int
    {
        $connection = $this->moduleDataSetup->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->moduleDataSetup->getTable('eav_attribute')], 'attribute_id')
                ->join(
                    ['t' => $this->moduleDataSetup->getTable('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', Product::ENTITY)
                ->where('a.attribute_code = ?', 'color')
        );
    }

    private function colorOptionId(string $label): int
    {
        $connection = $this->moduleDataSetup->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()
                ->from(['o' => $this->moduleDataSetup->getTable('eav_attribute_option')], 'option_id')
                ->join(
                    ['v' => $this->moduleDataSetup->getTable('eav_attribute_option_value')],
                    'v.option_id = o.option_id AND v.store_id = 0',
                    []
                )
                ->where('o.attribute_id = ?', $this->colorAttributeId())
                ->where('v.value = ?', $label)
        );
    }

    public static function getDependencies(): array
    {
        return [CreateNiecelSectionalSofa::class, ConfigureColorSwatches::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
