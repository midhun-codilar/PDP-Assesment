<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a Yes/No config field as the admin switch instead of a dropdown.
 *
 * The field's real value lives in a hidden input that keeps the element's html id, because
 * that is what <depends> reads and what the form posts. The visible checkbox only drives it:
 * a checkbox always reports value="1" whether or not it is ticked, so it cannot hold the value
 * itself without breaking every field that depends on this one.
 *
 * Being a plain (non-hidden) input, the checkbox is picked up by toggleValueElements() and
 * disabled along with everything else in the cell when "Use system value" is ticked.
 */
class Toggle extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $htmlId = $element->getHtmlId();
        $switchId = $htmlId . '_switch';
        $isOn = (string)$element->getValue() === '1';
        $disabled = $element->getDisabled() ? ' disabled="disabled"' : '';

        $init = $this->escapeHtmlAttr(json_encode(
            ['Codilar_Twoxl/js/config-toggle' => ['valueId' => $htmlId]],
            JSON_UNESCAPED_SLASHES
        ));

        return sprintf(
            '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" />'
            . '<div class="admin__actions-switch" data-role="switcher" data-mage-init=\'%4$s\'>'
            . '<input type="checkbox" class="admin__actions-switch-checkbox" id="%5$s"'
            . ' data-role="toggle-switch"%6$s%7$s />'
            . '<label class="admin__actions-switch-label" for="%5$s">'
            . '<span class="admin__actions-switch-text" data-text-on="%8$s" data-text-off="%9$s"></span>'
            . '</label>'
            . '</div>',
            $this->escapeHtmlAttr($htmlId),
            // Built by Magento from the system.xml ids, and emitted raw by the core field renderer.
            $element->getName(),
            $isOn ? '1' : '0',
            $init,
            $this->escapeHtmlAttr($switchId),
            $isOn ? ' checked="checked"' : '',
            $disabled,
            $this->escapeHtmlAttr(__('Yes')),
            $this->escapeHtmlAttr(__('No'))
        );
    }
}
