<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Controller\Adminhtml\Enquiry;

use Codilar\Twoxl\Model\ProductEnquiryFactory;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Codilar_Twoxl::enquiry';

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
        $id = (int)$this->getRequest()->getParam('entity_id');

        if (!$id) {
            return $redirect->setPath('*/*/index');
        }

        $enquiry = $this->enquiryFactory->create();
        $this->enquiryResource->load($enquiry, $id);

        if (!$enquiry->getId()) {
            $this->messageManager->addErrorMessage(__('This enquiry no longer exists.'));

            return $redirect->setPath('*/*/index');
        }

        try {
            $this->enquiryResource->delete($enquiry);
            $this->messageManager->addSuccessMessage(__('The enquiry has been deleted.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect->setPath('*/*/index');
    }
}
