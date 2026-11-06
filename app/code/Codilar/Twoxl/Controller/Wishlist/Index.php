<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Controller\Wishlist;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Wish list page for guests (twoxl/wishlist): lists the products saved in the guest wish list cookie.
 *
 * Magento's own wish list page only works for logged-in customers, so they are sent there instead.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function execute(): ResultInterface
    {
        // Any guest items were already merged into the account by MergeGuestWishlist on predispatch
        if ($this->customerSession->isLoggedIn()) {
            return $this->redirectFactory->create()->setPath('wishlist');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Wish List'));

        return $page;
    }
}
