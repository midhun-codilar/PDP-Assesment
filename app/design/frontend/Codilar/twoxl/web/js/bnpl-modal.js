/**
 * Opens the Tabby / Tamara instalment modals from the promo cards.
 * The modals are moved to <body> so the sticky buy box cannot clip or restack them.
 */
define([], function () {
    'use strict';

    return function (config, root) {
        var modals = {},
            active = null,
            trigger = null,
            host = root.parentNode,
            close = function () {
                if (!active) {
                    return;
                }
                active.hidden = true;
                document.body.classList.remove('bnpl-modal-open');
                if (trigger) {
                    trigger.focus();
                }
                active = trigger = null;
            },
            open = function (name, source) {
                if (!modals[name]) {
                    return;
                }
                close();
                trigger = source;
                active = modals[name];
                active.hidden = false;
                document.body.classList.add('bnpl-modal-open');
                active.querySelector('.bnpl-modal__dialog').scrollTop = 0;
                active.querySelector('.bnpl-modal__close, [data-bnpl-close]').focus();
            };

        host.querySelectorAll('[data-bnpl-modal]').forEach(function (modal) {
            modals[modal.getAttribute('data-bnpl-modal')] = modal;
            document.body.appendChild(modal);
            modal.addEventListener('click', function (event) {
                if (event.target === modal || event.target.closest('[data-bnpl-close]')) {
                    close();
                }
            });
        });

        root.addEventListener('click', function (event) {
            var el = event.target.closest('[data-bnpl-open]');

            if (el) {
                event.preventDefault();
                open(el.getAttribute('data-bnpl-open'), el);
            }
        });
        root.addEventListener('keydown', function (event) {
            var el = event.target.closest('.bnpl-card[data-bnpl-open]');

            if (el && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                open(el.getAttribute('data-bnpl-open'), el);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    };
});
