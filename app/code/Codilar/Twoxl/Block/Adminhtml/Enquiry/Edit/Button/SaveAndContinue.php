<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Block\Adminhtml\Enquiry\Edit\Button;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveAndContinue extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Save and Continue Edit'),
            'class' => 'save',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [
                            [
                                'targetName' => self::FORM_NAME . '.' . self::FORM_NAME,
                                'actionName' => 'save',
                                // true keeps the form open; "back" is what Save::execute() looks for.
                                'params' => [true, ['back' => 'continue']],
                            ],
                        ],
                    ],
                ],
            ],
            'sort_order' => 80,
        ];
    }
}
