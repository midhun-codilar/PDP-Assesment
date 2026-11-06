<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as EavAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Adds the "Table Material" dropdown to every product attribute set.
 *
 * Moved here from the throwaway Vendor_FurnitureAttributes module so that every product
 * attribute lives in Codilar_Twoxl. getAliases() carries the old class name, so installs
 * that already ran the Vendor patch record this one as applied instead of repeating it.
 */
class AddTableMaterialAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTE_CODE = 'table_material';

    private const OPTIONS = ['Sheesham Wood', 'Teak Wood', 'Engineered Wood', 'Marble', 'Glass'];

    private const SORT_ORDER = 60;

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

        if ($eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $this->moduleDataSetup->getConnection()->endSetup();

            return $this;
        }

        $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
            'type' => 'int',
            'label' => 'Table Material',
            'input' => 'select',
            'source' => Table::class,
            'option' => ['values' => self::OPTIONS],
            'global' => EavAttribute::SCOPE_GLOBAL,
            'required' => false,
            'user_defined' => true,
            'searchable' => true,
            'comparable' => true,
            'filterable' => 1,
            'visible_on_front' => true,
            'used_in_product_listing' => true,
            'group' => 'Product Details',
            'sort_order' => self::SORT_ORDER,
        ]);

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [AddProductAttributes::class];
    }

    public function getAliases(): array
    {
        return ['Vendor\FurnitureAttributes\Setup\Patch\Data\AddTableMaterialAttribute'];
    }
}
