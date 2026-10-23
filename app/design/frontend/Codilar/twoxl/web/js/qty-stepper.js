/**
 * "-" / "+" buttons around the product quantity input.
 */
define([], function () {
    'use strict';

    return function (config, element) {
        var input = element.querySelector('input.qty'),
            min = parseInt(config.min, 10) || 1,
            max = parseInt(config.max, 10) || 10000,
            step = function (delta) {
                var value = parseInt(input.value, 10);

                value = (isNaN(value) ? min : value) + delta;
                input.value = Math.min(max, Math.max(min, value));
                input.dispatchEvent(new Event('change', {bubbles: true}));
            };

        element.querySelectorAll('[data-qty-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                step(parseInt(button.getAttribute('data-qty-step'), 10));
            });
        });
    };
});
