/**
 * Keeps the buy box pinned while the gallery scrolls. When the box is taller than the
 * viewport it pins by its bottom edge instead, so its whole content is reachable.
 */
define([], function () {
    'use strict';

    return function (config, box) {
        var gap = 20,
            offset = 190,
            update = function () {
                var room = window.innerHeight - box.offsetHeight - gap;

                box.style.top = Math.min(offset, room) + 'px';
            };

        window.addEventListener('resize', update);
        if (window.ResizeObserver) {
            new ResizeObserver(update).observe(box);
        }
        update();
    };
});
