<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Block\Adminhtml\Enquiry\Edit\Button;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class Back extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Back'),
            'on_click' => sprintf("location.href = '%s';", $this->getUrl('*/*/index')),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }
}
