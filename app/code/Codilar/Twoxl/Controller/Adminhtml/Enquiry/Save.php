<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Controller\Adminhtml\Enquiry;

use Codilar\Twoxl\Model\ProductEnquiryFactory;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Codilar_Twoxl::enquiry';

    /** Editable in the admin; everything else is whatever the storefront captured. */
    private const EDITABLE = ['name', 'email', 'phone', 'sku', 'message', 'status'];

    public function __construct(
        Action\Context $context,
        private readonly ProductEnquiryFactory $enquiryFactory,
        private readonly ProductEnquiryResource $enquiryResource
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = (array)$this->getRequest()->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/index');
        }

        $id = (int)($data['entity_id'] ?? 0);
        $enquiry = $this->enquiryFactory->create();

        if ($id) {
            $this->enquiryResource->load($enquiry, $id);
            if (!$enquiry->getId()) {
                $this->messageManager->addErrorMessage(__('This enquiry no longer exists.'));

                return $redirect->setPath('*/*/index');
            }
        }

        $enquiry->addData(array_intersect_key($data, array_flip(self::EDITABLE)));

        try {
            $this->enquiryResource->save($enquiry);
            $this->messageManager->addSuccessMessage(__('The enquiry has been saved.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('*/*/edit', ['entity_id' => $enquiry->getId()]);
        }

        if ($this->getRequest()->getParam('back')) {
            return $redirect->setPath('*/*/edit', ['entity_id' => $enquiry->getId()]);
        }

        return $redirect->setPath('*/*/index');
    }
}
