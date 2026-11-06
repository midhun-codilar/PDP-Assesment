<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model;

use Codilar\Twoxl\Model\Config\EnquiryConfig;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Emails the team when a storefront enquiry comes in.
 *
 * Sending is best effort: the enquiry is already stored by the time this runs, so a mail
 * problem is logged rather than shown to the shopper.
 */
class EnquiryNotifier
{
    public function __construct(
        private readonly EnquiryConfig $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notify(ProductEnquiry $enquiry): void
    {
        $storeId = (int)$enquiry->getStoreId();

        if (!$this->config->isNotificationEnabled($storeId)) {
            return;
        }

        $recipients = $this->config->getRecipients($storeId);

        if (!$recipients) {
            return;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getTemplateId($storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'enquiry_name' => (string)$enquiry->getName(),
                    'enquiry_email' => (string)$enquiry->getEmail(),
                    'enquiry_phone' => (string)$enquiry->getPhone(),
                    'enquiry_sku' => (string)$enquiry->getSku(),
                    'enquiry_message' => (string)$enquiry->getMessage(),
                    'enquiry_store' => $store->getName(),
                    // Backend URL builder, so the link points at the admin even from the frontend area.
                    'enquiry_admin_url' => $this->backendUrl->getUrl(
                        'twoxl_admin/enquiry/edit',
                        ['entity_id' => (int)$enquiry->getId()]
                    ),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($recipients)
                ->getTransport();

            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error('Could not send the product enquiry notification.', ['exception' => $e]);
        }
    }
}
