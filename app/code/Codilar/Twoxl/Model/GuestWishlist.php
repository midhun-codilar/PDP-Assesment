<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Helper\Data as WishlistHelper;
use Magento\Wishlist\Model\WishlistFactory;
use Psr\Log\LoggerInterface;

/**
 * Wish list a guest builds in the browser, kept in a cookie until they sign in.
 *
 * The cookie is written by the theme's js/guest-wishlist.js as a JSON list of
 * add-to-wishlist requests ({"product": 3, "qty": 1, ...}).
 */
class GuestWishlist
{
    public const COOKIE_NAME = 'twoxl_guest_wishlist';

    /** Keep in sync with MAX_ITEMS in js/guest-wishlist.js. */
    private const MAX_ITEMS = 50;

    /** Buy request fields the add-to-wishlist link can carry; anything else is dropped. */
    private const REQUEST_FIELDS = [
        'product', 'qty', 'super_attribute', 'options', 'bundle_option', 'bundle_option_qty', 'super_group', 'links',
    ];

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly Json $json,
        private readonly WishlistFactory $wishlistFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly WishlistHelper $wishlistHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function hasItems(): bool
    {
        return (string)$this->cookieManager->getCookie(self::COOKIE_NAME) !== '';
    }

    /**
     * @return int[] ids of the saved products, oldest first
     */
    public function getProductIds(): array
    {
        return array_map(static fn (array $request): int => (int)$request['product'], $this->readRequests());
    }

    /**
     * Add the guest items to the customer's wish list and clear the cookie.
     *
     * @return int number of products added
     */
    public function mergeInto(int $customerId): int
    {
        $requests = $this->readRequests();
        $this->clear();
        if (!$requests) {
            return 0;
        }

        $wishlist = $this->wishlistFactory->create()->loadByCustomerId($customerId, true);
        $existing = [];
        foreach ($wishlist->getItemCollection() as $item) {
            $existing[(int)$item->getProductId()] = true;
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $added = 0;
        foreach ($requests as $request) {
            $productId = (int)$request['product'];
            if (isset($existing[$productId])) {
                continue;
            }
            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if ((int)$product->getStatus() !== Status::STATUS_ENABLED || !$product->isVisibleInSiteVisibility()) {
                continue;
            }

            try {
                $result = $wishlist->addNewItem($product, new DataObject($request));
            } catch (\Exception $e) {
                $this->logger->warning('Guest wish list item not saved: ' . $e->getMessage(), ['product' => $productId]);
                continue;
            }
            if (is_string($result)) {
                continue;
            }
            $existing[$productId] = true;
            $added++;
        }

        if ($added) {
            $wishlist->save();
            $this->wishlistHelper->calculate();
        }

        return $added;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRequests(): array
    {
        try {
            $items = $this->json->unserialize((string)$this->cookieManager->getCookie(self::COOKIE_NAME));
        } catch (\InvalidArgumentException) {
            return [];
        }
        if (!is_array($items)) {
            return [];
        }

        $requests = [];
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item) || empty($item['product']) || !is_numeric($item['product'])) {
                continue;
            }
            $request = array_intersect_key($item, array_flip(self::REQUEST_FIELDS));
            $request['qty'] = max(1, (float)($request['qty'] ?? 1));
            $requests[(int)$item['product']] = $request;
        }

        return array_values($requests);
    }

    private function clear(): void
    {
        $this->cookieManager->deleteCookie(
            self::COOKIE_NAME,
            $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
        );
    }
}
