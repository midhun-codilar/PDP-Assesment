/**
 * Keeps the hidden field behind an admin switch in step with the checkbox.
 *
 * The change event matters as much as the value: Magento's field dependence controller
 * listens on the hidden input, so fields that <depends> on this one only show and hide
 * when it is fired.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $checkbox = $(element).find('[data-role="toggle-switch"]'),
            $value = $('#' + config.valueId);

        $checkbox.on('change', function () {
            $value.val(this.checked ? '1' : '0').trigger('change');
        });
    };
});
