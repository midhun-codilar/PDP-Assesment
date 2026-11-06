/**
 * Lets guests heart products without signing in: the add-to-wishlist request is kept in
 * a cookie and Codilar\Twoxl\Observer\MergeGuestWishlist moves it into the account on login.
 */
define([
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function (customerData, $t) {
    'use strict';

    var COOKIE = 'twoxl_guest_wishlist',
        MAX_ITEMS = 50,
        MAX_AGE = 30 * 24 * 60 * 60,
        TRIGGER = '[data-action="add-to-wishlist"]',
        FIELDS = ['product', 'qty', 'super_attribute', 'options', 'bundle_option', 'bundle_option_qty',
            'super_group', 'links'];

    function read() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)')),
            items;

        try {
            items = match ? JSON.parse(decodeURIComponent(match[1])) : [];
        } catch (e) {
            items = [];
        }

        return Array.isArray(items) ? items : [];
    }

    function write(items) {
        document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(items)) +
            '; path=/; SameSite=Lax; max-age=' + (items.length ? MAX_AGE : 0) +
            (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function isGuest() {
        return !customerData.get('customer')().firstname;
    }

    function requestOf(link) {
        var params, request = {};

        try {
            params = JSON.parse(link.getAttribute('data-post'));
        } catch (e) {
            return null;
        }
        if (!params || !params.data || !params.data.product) {
            return null;
        }
        FIELDS.forEach(function (field) {
            if (params.data[field] !== undefined) {
                request[field] = params.data[field];
            }
        });
        request.product = parseInt(request.product, 10);

        return request;
    }

    function indexOf(items, productId) {
        for (var i = 0; i < items.length; i++) {
            if (parseInt(items[i].product, 10) === productId) {
                return i;
            }
        }

        return -1;
    }

    // On the guest wish list page (twoxl/wishlist) an unhearted product leaves the list
    function removeCard(link) {
        var card = link.closest('[data-guest-wishlist-item]');

        if (!card) {
            return;
        }
        card.remove();
        if (!document.querySelector('[data-guest-wishlist-item]')) {
            window.location.reload();
        }
    }

    function notify(text, type) {
        customerData.set('messages', {
            messages: [{type: type || 'success', text: text}]
        });
    }

    return function (config) {
        var paint = function () {
            var items = isGuest() ? read() : [];

            document.querySelectorAll(TRIGGER).forEach(function (link) {
                var request = requestOf(link),
                    saved = !!request && indexOf(items, request.product) !== -1;

                link.classList.toggle('is-saved', saved);
                link.setAttribute('aria-pressed', saved ? 'true' : 'false');
            });
        };

        // Capture phase, so the click never reaches mage/dataPost (which would post and bounce a guest to login)
        document.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest(TRIGGER) : null,
                request, items, index, signIn;

            if (!link || !isGuest()) {
                return;
            }
            request = requestOf(link);
            if (!request) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();

            items = read();
            index = indexOf(items, request.product);
            signIn = '<a href="' + config.loginUrl + '">' + $t('Sign in') + '</a>';

            if (index !== -1) {
                items.splice(index, 1);
                write(items);
                notify($t('Removed from your wish list.'));
                removeCard(link);
            } else if (items.length >= MAX_ITEMS) {
                notify($t('Your wish list is full. %1 to save more products.').replace('%1', signIn), 'error');
            } else {
                items.push(request);
                write(items);
                notify($t('Saved to your wish list. %1 to keep it in your account.').replace('%1', signIn));
            }
            paint();
        }, true);

        customerData.get('customer').subscribe(paint);
        paint();
    };
});
