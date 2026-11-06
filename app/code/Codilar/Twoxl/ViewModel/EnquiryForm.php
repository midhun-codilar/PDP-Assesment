<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Codilar\Twoxl\Model\Config\EnquiryConfig;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Wording and field rules for the product page enquiry form.
 *
 * Everything comes from twoxl_enquiry/* so the form can be turned off, reworded or made
 * stricter per store view from Stores > Configuration > Enquiries.
 */
class EnquiryForm implements ArgumentInterface
{
    public function __construct(private readonly EnquiryConfig $config)
    {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getHeading(): string
    {
        return $this->config->getHeading() ?: (string)__('Request a quote');
    }

    public function getIntroText(): string
    {
        return $this->config->getIntroText();
    }

    public function isPhoneRequired(): bool
    {
        return $this->config->isPhoneRequired();
    }

    public function getMessageMaxLength(): int
    {
        return $this->config->getMessageMaxLength();
    }
}
