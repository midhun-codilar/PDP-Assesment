<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Observer;

use Codilar\Twoxl\Model\GuestWishlist;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;

/**
 * Saves the products a guest added to the wish list into their account once they are logged in.
 */
class MergeGuestWishlist implements ObserverInterface
{
    public function __construct(
        private readonly GuestWishlist $guestWishlist,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->guestWishlist->hasItems() || !$this->customerSession->isLoggedIn()) {
            return;
        }

        $added = $this->guestWishlist->mergeInto((int)$this->customerSession->getCustomerId());
        if ($added) {
            $this->messageManager->addSuccessMessage(
                $added === 1
                    ? __('The product you saved before signing in has been added to your wish list.')
                    : __('%1 products you saved before signing in have been added to your wish list.', $added)
            );
        }
    }
}
