<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Swatches\Model\Swatch;

/**
 * Turns the "color" dropdown into visual colour swatches, so the configurable sofa shows
 * clickable colour chips on the PDP instead of a select box.
 *
 * The admin form data that Magento\Swatches\Model\Plugin\EavAttribute expects is awkward to
 * build outside a form post, so the two things that actually make a swatch are written
 * directly: the swatch_input_type flag in the attribute's additional_data, and one row per
 * option in eav_attribute_option_swatch.
 */
class ConfigureColorSwatches implements DataPatchInterface
{
    /** Colour chip shown for each option label. */
    private const SWATCHES = [
        'Beige' => '#d7c9ae',
        'Cream' => '#f2e8d5',
        'Grey' => '#8c8c8c',
        'Ivory' => '#efe7d8',
        'Red' => '#d32f2f',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $attributeId = (int)$eavSetup->getAttributeId(Product::ENTITY, 'color');

        foreach (array_keys(self::SWATCHES) as $label) {
            if (!$this->optionId($attributeId, $label)) {
                $eavSetup->addAttributeOption(['attribute_id' => $attributeId, 'values' => [$label]]);
            }
        }

        // What Swatches\Model\SwatchAttributeType reads to decide the attribute renders as chips.
        $eavSetup->updateAttribute(Product::ENTITY, $attributeId, 'additional_data', $this->serializer->serialize([
            Swatch::SWATCH_INPUT_TYPE_KEY => Swatch::SWATCH_INPUT_TYPE_VISUAL,
            'update_product_preview_image' => 1,
            'use_product_image_for_swatch' => 0,
        ]));

        foreach (self::SWATCHES as $label => $hex) {
            $optionId = $this->optionId($attributeId, $label);
            if ($optionId) {
                $this->saveSwatch($optionId, $hex);
            }
        }

        $connection->endSetup();

        return $this;
    }

    private function saveSwatch(int $optionId, string $hex): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('eav_attribute_option_swatch');

        $row = [
            'option_id' => $optionId,
            'store_id' => 0,
            'type' => Swatch::SWATCH_TYPE_VISUAL_COLOR,
            'value' => $hex,
        ];

        $swatchId = (int)$connection->fetchOne(
            $connection->select()->from($table, 'swatch_id')->where('option_id = ?', $optionId)->where('store_id = ?', 0)
        );

        if ($swatchId) {
            $connection->update($table, $row, ['swatch_id = ?' => $swatchId]);
        } else {
            $connection->insert($table, $row);
        }
    }

    private function optionId(int $attributeId, string $label): int
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
            ->where('v.value = ?', $label);

        return (int)$connection->fetchOne($select);
    }

    public static function getDependencies(): array
    {
        return [ConfigureColorAttribute::class, AddSofaAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
