<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Specification attributes shown in the "Product Description" accordion of the PDP.
 */
class AddProductAttributes implements DataPatchInterface
{
    /** code => [label, input, sort order, html allowed] */
    private const ATTRIBUTES = [
        'height_cm' => ['Height (cm)', 'text', 10, false],
        'width_cm' => ['Width (cm)', 'text', 20, false],
        'material' => ['Material', 'text', 40, false],
        'length_cm' => ['Length (cm)', 'text', 50, false],
        'care' => ['Care', 'textarea', 60, true],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTES as $code => [$label, $input, $sortOrder, $html]) {
            if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
                continue;
            }
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type' => $input === 'textarea' ? 'text' : 'varchar',
                'label' => $label,
                'input' => $input,
                'required' => false,
                'user_defined' => true,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'visible' => true,
                'visible_on_front' => true,
                'is_html_allowed_on_front' => $html,
                'used_in_product_listing' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'group' => 'Product Details',
                'sort_order' => $sortOrder,
            ]);
        }
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
