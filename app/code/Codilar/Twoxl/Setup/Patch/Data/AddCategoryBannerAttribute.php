<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\Attribute\Backend\Image as ImageBackend;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Hero banner shown above the product list on a category page.
 *
 * Uploads reuse the core catalog/category_image/upload controller, so the file lands in
 * pub/media/catalog/category next to the stock category image and no custom controller
 * or ImageUploader virtual type is needed.
 */

class AddCategoryBannerAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public const ATTRIBUTE_CODE = 'category_banner';

    private const SORT_ORDER = 45;

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

        if (!$eavSetup->getAttributeId(Category::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Category::ENTITY, self::ATTRIBUTE_CODE, [
                'type' => 'varchar',
                'label' => 'Category Banner',
                'input' => 'image',
                'backend' => ImageBackend::class,
                'required' => false,
                'user_defined' => true,
                // Per store view, so a banner can be swapped for a locale without touching the others.
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'visible' => true,
                'visible_on_front' => true,
                'group' => 'Content',
                'sort_order' => self::SORT_ORDER,
            ]);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Category::ENTITY, self::ATTRIBUTE_CODE);

        $this->moduleDataSetup->getConnection()->endSetup();
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
