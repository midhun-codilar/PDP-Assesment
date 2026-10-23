/**
 * Keeps the 2XL price look when Magento re-renders prices in the browser:
 * dirham glyph instead of the "AED" prefix, and no ".00" on whole amounts.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var template = '<% var f = data.formatted; if (/^AED/.test(f)) { %>' +
        '<span class="price"><i class="aed-symbol" role="img" aria-label="AED"></i>' +
        '<%- f.replace(/^AED\\s*/, "").replace(/\\.00$/, "") %></span>' +
        '<% } else { %><span class="price"><%- f %></span><% } %>';

    return function (targetWidget) {
        $.widget('mage.priceBox', targetWidget, {
            options: {
                priceTemplate: template
            }
        });

        return $.mage.priceBox;
    };
});
