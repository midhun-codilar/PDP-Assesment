/**
 * Keeps the "(-20% OFF)" badge correct on a configurable PDP.
 *
 * Magento\Catalog\Pricing\Render\FinalPriceBox renders the badge once for the cheapest
 * variant; after that the price box re-renders in the browser on every swatch click, so
 * the badge is recomputed here from the prices price-box publishes with `priceUpdated`.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    return function (config, element) {
        var $box = $(element),
            offLabel = config.offLabel || 'OFF';

        function render(prices) {
            var $badge = $box.find('[data-discount-badge]'),
                regular = prices && prices.oldPrice ? prices.oldPrice.amount : null,
                final = prices && prices.finalPrice ? prices.finalPrice.amount : null,
                percent;

            if (!$badge.length) {
                return;
            }

            percent = regular > 0 && final < regular ? Math.round((1 - final / regular) * 100) : 0;

            if (percent > 0) {
                $badge.text('(-' + percent + '% ' + offLabel + ')').removeClass('no-display');
            } else {
                $badge.text('').addClass('no-display');
            }
        }

        $box.on('priceUpdated', function (event, prices) {
            render(prices);
        });
    };
});
