<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The stock "color" dropdown is shown in the PDP specification grid (after Width) and the
 * Tresa dining table gets its "Grey" value.
 */
class ConfigureColorAttribute implements DataPatchInterface
{
    private const SKU = '130917';
    private const COLOR = 'Grey';
    private const SORT_ORDER = 30;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly ProductAction $productAction
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributeId = (int)$eavSetup->getAttributeId(Product::ENTITY, 'color');
        if (!$attributeId) {
            $eavSetup->addAttribute(Product::ENTITY, 'color', [
                'type' => 'int',
                'label' => 'Color',
                'input' => 'select',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'required' => false,
                'user_defined' => true,
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
            ]);
            $attributeId = (int)$eavSetup->getAttributeId(Product::ENTITY, 'color');
        }

        $eavSetup->updateAttribute(Product::ENTITY, $attributeId, 'is_visible_on_front', 1);
        $eavSetup->addAttributeToGroup(Product::ENTITY, 'Default', 'Product Details', $attributeId, self::SORT_ORDER);

        $optionId = $this->findOptionId($attributeId);
        if (!$optionId) {
            $eavSetup->addAttributeOption(['attribute_id' => $attributeId, 'values' => [self::COLOR]]);
            $optionId = $this->findOptionId($attributeId);
        }

        $productId = (int)$connection->fetchOne(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('catalog_product_entity'), 'entity_id')
                ->where('sku = ?', self::SKU)
        );
        if ($productId && $optionId) {
            $this->productAction->updateAttributes([$productId], ['color' => $optionId], 0);
        }

        return $this;
    }

    private function findOptionId(int $attributeId): int
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->moduleDataSetup->getTable('eav_attribute_option')], 'option_id')
            ->join(
                ['v' => $this->moduleDataSetup->getTable('eav_attribute_option_value')],
                'v.option_id = o.option_id AND v.store_id = 0',
                []
            )
            ->where('o.attribute_id = ?', $attributeId)
            ->where('v.value = ?', self::COLOR);

        return (int)$connection->fetchOne($select);
    }

    public static function getDependencies(): array
    {
        return [AddProductAttributes::class, CreateTresaDiningTable::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
