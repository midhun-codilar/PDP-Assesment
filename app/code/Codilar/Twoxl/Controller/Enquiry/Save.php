<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Controller\Enquiry;

use Codilar\Twoxl\Model\Config\EnquiryConfig;
use Codilar\Twoxl\Model\EnquiryNotifier;
use Codilar\Twoxl\Model\ProductEnquiry;
use Codilar\Twoxl\Model\ProductEnquiryFactory;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stores an enquiry raised from a product page (twoxl/enquiry/save).
 *
 * The form can be switched off, the phone field made mandatory and the message length
 * capped from Stores > Configuration > Enquiries; all three are enforced here as well as
 * in the template, because a post can arrive without the form having been rendered.
 */
class Save implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly ManagerInterface $messageManager,
        private readonly ProductEnquiryFactory $enquiryFactory,
        private readonly ProductEnquiryResource $enquiryResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly EnquiryConfig $config,
        private readonly EnquiryNotifier $notifier
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $referer = (string)$this->request->getServer('HTTP_REFERER');

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Enquiries are not being accepted at the moment.'));

            return $referer ? $redirect->setUrl($referer) : $redirect->setPath('/');
        }

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Your session has expired, please try again.'));

            return $referer ? $redirect->setUrl($referer) : $redirect->setPath('/');
        }

        try {
            $data = $this->validatedData();
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $referer ? $redirect->setUrl($referer) : $redirect->setPath('/');
        }

        /** @var ProductEnquiry $enquiry */
        $enquiry = $this->enquiryFactory->create();
        $enquiry->addData($data + [
            'status' => ProductEnquiry::STATUS_NEW,
            'store_id' => (int)$this->storeManager->getStore()->getId(),
        ]);
        $this->enquiryResource->save($enquiry);
        $this->notifier->notify($enquiry);

        $this->messageManager->addSuccessMessage(
            $this->config->getSuccessMessage()
                ?: __('Thanks for your enquiry. Our team will get back to you shortly.')
        );

        return $referer ? $redirect->setUrl($referer) : $redirect->setPath('/');
    }

    /**
     * @return array<string, string|null>
     * @throws LocalizedException
     */
    private function validatedData(): array
    {
        $name = trim((string)$this->request->getParam('name'));
        $email = trim((string)$this->request->getParam('email'));
        $phone = trim((string)$this->request->getParam('phone'));
        $sku = trim((string)$this->request->getParam('sku'));
        $message = trim((string)$this->request->getParam('message'));

        if ($name === '' || $email === '') {
            throw new LocalizedException(__('Please provide your name and email address.'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Please provide a valid email address.'));
        }

        if ($phone === '' && $this->config->isPhoneRequired()) {
            throw new LocalizedException(__('Please provide a phone number.'));
        }

        $messageMax = $this->config->getMessageMaxLength();

        return [
            'name' => mb_substr($name, 0, 120),
            'email' => mb_substr($email, 0, 180),
            'phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null,
            'sku' => $sku !== '' ? mb_substr($sku, 0, 64) : null,
            'message' => $message !== '' ? mb_substr($message, 0, $messageMax) : null,
        ];
    }
}
