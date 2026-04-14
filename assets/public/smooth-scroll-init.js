(function () {
    'use strict';

    if (typeof window.Lenis !== 'function') {
        if (window.console && window.console.warn) {
            window.console.warn('[rh-blueprint] window.Lenis nicht verfuegbar — Smooth Scroll wird nicht initialisiert.');
        }
        return;
    }

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const lenis = new window.Lenis({
        lerp: 0.1,
        smoothWheel: true,
    });

    function raf(time) {
        lenis.raf(time);
        window.requestAnimationFrame(raf);
    }

    window.requestAnimationFrame(raf);
    window.rhbpLenis = lenis;
}());
