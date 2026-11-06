/**
 * Switches the 2XL product gallery when a colour swatch is picked.
 *
 * The stock updateBaseImage() drives Fotorama: it looks for a gallery widget on
 * [data-gallery-role=gallery-placeholder] and, not finding one, waits for a "gallery:loaded"
 * event that never arrives. This theme renders its own static grid instead, so the images
 * are rebuilt here from the same jsonConfig data the swatch renderer already holds.
 */
define([
    'jquery',
    'underscore'
], function ($, _) {
    'use strict';

    var GALLERY = '[data-role=product-gallery]',
        ITEM = '.product-gallery__item';

    return function (widget) {
        $.widget('mage.SwatchRenderer', widget, {
            /**
             * @param {Array} images
             * @param {jQuery} context
             * @param {Boolean} isInProductView
             */
            updateBaseImage: function (images, context, isInProductView) {
                var $gallery = context.find(GALLERY),
                    alt,
                    markup;

                if (!$gallery.length) {
                    return this._super(images, context, isInProductView);
                }

                if (!_.isArray(images) || !images.length) {
                    return undefined;
                }

                // Keep whatever the server rendered as the label for this product.
                alt = $gallery.find(ITEM + ' img').first().attr('alt') || '';

                markup = images.map(function (image, index) {
                    var src = image.full || image.img;

                    return '<figure class="product-gallery__item">' +
                        '<img src="' + _.escape(src) + '"' +
                        ' alt="' + _.escape(alt) + '"' +
                        ' width="1000" height="820"' +
                        (index === 0 ? ' fetchpriority="high"' : ' loading="lazy"') +
                        '></figure>';
                }).join('');

                // Replace only the figures; the "New Arrival" label is a sibling and stays put.
                $gallery.find(ITEM).remove();
                $gallery.append(markup);

                return undefined;
            }
        });

        return $.mage.SwatchRenderer;
    };
});
