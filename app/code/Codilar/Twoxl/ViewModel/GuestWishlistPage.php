<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Codilar\Twoxl\Model\GuestWishlist;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Checkout\Helper\Cart as CartHelper;
use Magento\Framework\Data\Helper\PostHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Wishlist\Helper\Data as WishlistHelper;

/**
 * Products a guest saved with the wish list heart, for the twoxl/wishlist page.
 */
class GuestWishlistPage implements ArgumentInterface
{
    /** Product types that go straight to the cart; the others need options picked on the product page. */
    private const DIRECT_CART_TYPES = ['simple', 'virtual'];

    /** @var Product[]|null */
    private ?array $products = null;

    public function __construct(
        private readonly GuestWishlist $guestWishlist,
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly ImageHelper $imageHelper,
        private readonly WishlistHelper $wishlistHelper,
        private readonly CartHelper $cartHelper,
        private readonly PostHelper $postHelper
    ) {
    }

    /**
     * @return Product[] most recently saved first; disabled or hidden products are left out
     */
    public function getProducts(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }

        $ids = array_reverse($this->guestWishlist->getProductIds());
        if (!$ids) {
            return $this->products = [];
        }

        $collection = $this->collectionFactory->create()
            ->addIdFilter($ids)
            ->addAttributeToSelect(['name', 'small_image', 'price', 'special_price', 'special_from_date', 'special_to_date'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->setVisibility($this->visibility->getVisibleInSiteIds())
            ->addStoreFilter()
            ->addUrlRewrite();

        $this->products = [];
        foreach ($ids as $id) {
            $product = $collection->getItemById($id);
            if ($product) {
                $this->products[] = $product;
            }
        }

        return $this->products;
    }

    public function getImageUrl(Product $product): string
    {
        return $this->imageHelper->init($product, 'category_page_grid')->getUrl();
    }

    public function getRegularPrice(Product $product): float
    {
        return (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
    }

    public function getFinalPrice(Product $product): float
    {
        return (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
    }

    public function getDiscountPercent(Product $product): int
    {
        $regular = $this->getRegularPrice($product);
        $final = $this->getFinalPrice($product);

        return $regular > 0 && $final < $regular ? (int)round((1 - $final / $regular) * 100) : 0;
    }

    /**
     * Same data-post as the product page heart, so js/guest-wishlist.js can toggle it.
     */
    public function getHeartPostData(Product $product): string
    {
        return $this->wishlistHelper->getAddParams($product);
    }

    public function canAddToCart(Product $product): bool
    {
        return in_array($product->getTypeId(), self::DIRECT_CART_TYPES, true)
            && $product->isSaleable()
            && $product->getTypeInstance()->isPossibleBuyFromList($product);
    }

    public function getAddToCartPostData(Product $product): string
    {
        return $this->postHelper->getPostData(
            $this->cartHelper->getAddUrl($product),
            ['product' => (int)$product->getId()]
        );
    }
}
