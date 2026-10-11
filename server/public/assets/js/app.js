// Small progressive enhancements. Every form works without JavaScript.
(function () {
    'use strict';

    // Show / hide password
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-toggle-password]');
        if (!btn) return;
        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Hide' : 'Show';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    // Ask before destructive actions: <form data-confirm="Delete …?">
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Homepage slider: no automatic sliding for people who prefer reduced motion.
    var hero = document.querySelector('[data-hero-slider]');
    if (hero && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        hero.removeAttribute('data-bs-ride');
        hero.setAttribute('data-bs-interval', 'false');
    }

    // Prevent double submits
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.dataset.submitting === '1') { e.preventDefault(); return; }
        form.dataset.submitting = '1';
        var btns = form.querySelectorAll('button[type="submit"], button:not([type])');
        // Disable after the browser has read the form, so the clicked
        // button's name/value (e.g. action=draft) is still sent.
        setTimeout(function () { btns.forEach(function (b) { b.disabled = true; }); }, 0);
        // Re-enable if the user navigates back to this page
        window.addEventListener('pageshow', function () { form.dataset.submitting = ''; btns.forEach(function (b) { b.disabled = false; }); }, { once: true });
    });

    // CSRF token for fetch() calls: window.csrfFetch(url, options)
    var meta = document.querySelector('meta[name="csrf-token"]');
    window.csrfFetch = function (url, options) {
        options = options || {};
        options.headers = Object.assign({ 'X-CSRF-Token': meta ? meta.content : '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, options.headers || {});
        options.credentials = 'same-origin';
        return fetch(url, options);
    };
})();
