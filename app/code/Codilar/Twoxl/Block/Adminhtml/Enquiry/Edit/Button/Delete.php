<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Block\Adminhtml\Enquiry\Edit\Button;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class Delete extends GenericButton implements ButtonProviderInterface
{
    /**
     * Hidden on a new record, since there is nothing to delete yet.
     *
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $id = $this->getEnquiryId();

        if (!$id) {
            return [];
        }

        $message = __('Are you sure you want to delete this enquiry?');

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            // deleteConfirm() posts the request, which the POST-only Delete controller requires.
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {});",
                $message->render(),
                $this->getUrl('*/*/delete', ['entity_id' => $id])
            ),
            'sort_order' => 20,
        ];
    }
}
