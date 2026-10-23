/**
 * Header account dropdown: toggles on icon click and swaps guest / customer links
 * from the customer-data section (the header itself is full-page cached).
 */
define([
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function (customerData, $t) {
    'use strict';

    return function (config, root) {
        var button = root.querySelector('.header-action--account'),
            menu = root.querySelector('.header-account__menu'),
            greeting = menu.querySelector('[data-role="greeting"]'),
            setOpen = function (open) {
                menu.hidden = !open;
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            },
            render = function (customer) {
                var loggedIn = !!(customer && customer.firstname);

                menu.querySelectorAll('[data-role="guest"]').forEach(function (el) {
                    el.hidden = loggedIn;
                });
                menu.querySelectorAll('[data-role="customer"]').forEach(function (el) {
                    el.hidden = !loggedIn;
                });
                greeting.hidden = !loggedIn;
                greeting.textContent = loggedIn ? $t('Hi, %1').replace('%1', customer.firstname) : '';
            },
            section = customerData.get('customer');

        render(section());
        section.subscribe(render);

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            setOpen(menu.hidden);
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                setOpen(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !menu.hidden) {
                setOpen(false);
                button.focus();
            }
        });
    };
});
