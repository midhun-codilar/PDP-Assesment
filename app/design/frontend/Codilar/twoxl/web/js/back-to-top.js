/**
 * Shows the floating "Top" button once the page is scrolled and scrolls back up on click.
 */
define([], function () {
    'use strict';

    return function (config, button) {
        var threshold = 300,
            toggle = function () {
                button.hidden = (window.pageYOffset || document.documentElement.scrollTop) < threshold;
            };

        window.addEventListener('scroll', toggle, {passive: true});
        button.addEventListener('click', function () {
            window.scrollTo({top: 0, behavior: 'smooth'});
        });
        toggle();
    };
});
