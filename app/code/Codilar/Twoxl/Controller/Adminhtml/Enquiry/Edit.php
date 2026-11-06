<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Controller\Adminhtml\Enquiry;

use Codilar\Twoxl\Model\ProductEnquiryFactory;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Codilar_Twoxl::enquiry';

    /** Key the form data provider reads the record from. */
    public const REGISTRY_KEY = 'codilar_twoxl_product_enquiry';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ProductEnquiryFactory $enquiryFactory,
        private readonly ProductEnquiryResource $enquiryResource,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int)$this->getRequest()->getParam('entity_id');
        $enquiry = $this->enquiryFactory->create();

        if ($id) {
            $this->enquiryResource->load($enquiry, $id);
            if (!$enquiry->getId()) {
                $this->messageManager->addErrorMessage(__('This enquiry no longer exists.'));

                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
        }

        $this->registry->register(self::REGISTRY_KEY, $enquiry);

        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(
            $enquiry->getId() ? __('Enquiry #%1', $enquiry->getId()) : __('New Enquiry')
        );

        return $page;
    }
}
