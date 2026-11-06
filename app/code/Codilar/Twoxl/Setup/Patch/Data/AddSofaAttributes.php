<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Specification dropdowns for upholstered seating, shown in the "Product Description"
 * accordion of the PDP next to the dining table ones.
 */

class AddSofaAttributes implements DataPatchInterface
{
    /** code => [label, sort order, options] - created when missing, topped up when already there */
    private const ATTRIBUTES = [
        'sofa_material' => ['Sofa Material', 70, [
            'Boucle', 'Fabric', 'Velvet', 'Chenille', 'Linen', 'Leather', 'Faux Leather',
        ]],
        'frame_material' => ['Frame Material', 80, [
            'Solid Wood', 'Engineered Wood', 'Plywood', 'Metal',
        ]],
        'filling_material' => ['Filling Material', 90, [
            'High Resilience Foam', 'Memory Foam', 'Polyester Fibre', 'Feather Blend', 'Foam & Fibre',
        ]],
        'sofa_shape' => ['Sofa Shape', 100, [
            'Corner Sofa', 'L-Shaped', 'U-Shaped', 'Curved', 'Modular', 'Chaise Sofa', 'Straight',
        ]],
        'seating_capacity' => ['Seating Capacity', 50, [
            '2 Seater', '3 Seater', '4 Seater', '5 Seater', '6 Seater', '7 Seater', '8 Seater',
        ]],
    ];

    /** Dropdowns owned by another patch - only the missing values are appended. */
    private const APPENDED_OPTIONS = [
        'color' => ['Beige', 'Ivory', 'Cream'],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTES as $code => [$label, $sortOrder, $options]) {
            $attributeId = (int)$eavSetup->getAttributeId(Product::ENTITY, $code);

            if (!$attributeId) {
                $eavSetup->addAttribute(Product::ENTITY, $code, [
                    'type' => 'int',
                    'label' => $label,
                    'input' => 'select',
                    'source' => Table::class,
                    'option' => ['values' => $options],
                    'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'required' => false,
                    'user_defined' => true,
                    'searchable' => true,
                    'comparable' => true,
                    'filterable' => 1,
                    'visible' => true,
                    'visible_on_front' => true,
                    'used_in_product_listing' => true,
                    'group' => 'Product Details',
                    'sort_order' => $sortOrder,
                ]);
                continue;
            }

            // Created by hand in the admin before this patch existed - keep it, just top it up.
            $eavSetup->updateAttribute(Product::ENTITY, $attributeId, 'is_visible_on_front', 1);
            $eavSetup->addAttributeToGroup(Product::ENTITY, 'Default', 'Product Details', $attributeId, $sortOrder);
            $this->appendOptions($eavSetup, $attributeId, $options);
        }

        foreach (self::APPENDED_OPTIONS as $code => $options) {
            $attributeId = (int)$eavSetup->getAttributeId(Product::ENTITY, $code);
            if ($attributeId) {
                $this->appendOptions($eavSetup, $attributeId, $options);
            }
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @param string[] $options
     */
    private function appendOptions(EavSetup $eavSetup, int $attributeId, array $options): void
    {
        $existing = $this->existingLabels($attributeId);

        foreach ($options as $label) {
            if (!in_array($label, $existing, true)) {
                $eavSetup->addAttributeOption(['attribute_id' => $attributeId, 'values' => [$label]]);
            }
        }
    }

    /**
     * @return string[] admin labels already stored for the dropdown
     */
    private function existingLabels(int $attributeId): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->moduleDataSetup->getTable('eav_attribute_option')], [])
            ->join(
                ['v' => $this->moduleDataSetup->getTable('eav_attribute_option_value')],
                'v.option_id = o.option_id AND v.store_id = 0',
                ['value']
            )
            ->where('o.attribute_id = ?', $attributeId);

        return $connection->fetchCol($select);
    }

    public static function getDependencies(): array
    {
        return [AddProductAttributes::class, ConfigureColorAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
