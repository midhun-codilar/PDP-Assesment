<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Block\Adminhtml\Enquiry\Edit\Button;

use Magento\Framework\View\Element\UiComponent\Context;

/**
 * Shared plumbing for the buttons on the enquiry edit form.
 */
class GenericButton
{
    /** Matches <namespace> in codilar_twoxl_enquiry_form.xml. */
    protected const FORM_NAME = 'codilar_twoxl_enquiry_form';

    public function __construct(protected readonly Context $context)
    {
    }

    /**
     * Id of the enquiry being edited, or null when the form is a new record.
     */
    public function getEnquiryId(): ?int
    {
        $id = (int)$this->context->getRequestParam('entity_id');

        return $id ?: null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrl($route, $params);
    }
}
