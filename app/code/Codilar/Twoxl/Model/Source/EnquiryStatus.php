<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model\Source;

use Codilar\Twoxl\Model\ProductEnquiry;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Status options for the admin grid filter and the edit form.
 */
class EnquiryStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (ProductEnquiry::STATUSES as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }

        return $options;
    }
}
