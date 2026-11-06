<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Codilar\Twoxl\Setup\Patch\Data\AddCategoryBannerAttribute;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Hero banner of a category page, uploaded per category under Content > Category Banner.
 */
class CategoryBanner implements ArgumentInterface
{
    /**
     * Resolved media URL of the banner, or null when the category has no banner uploaded.
     */
    public function getBannerUrl(?CategoryInterface $category): ?string
    {

        if (!$category instanceof Category) {
            return null;
        }

        $url = $category->getImageUrl(AddCategoryBannerAttribute::ATTRIBUTE_CODE);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
