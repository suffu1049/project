/* ==========================================================================
   ResQLink — Base client behavior (Phase 1)
   Vanilla JavaScript only. No dependencies, no console noise.
   ========================================================================== */

(function () {
    'use strict';

    /**
     * Read the CSRF token injected into the page head (used from Phase 3).
     */
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Toggle the mobile navigation menu.
     */
    function initNavToggle() {
        var toggle = document.querySelector('[data-nav-toggle]');
        var nav = document.getElementById('primary-navigation');

        if (!toggle || !nav) {
            return;
        }

        toggle.addEventListener('click', function () {
            var isOpen = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    /**
     * Dismiss flash messages (button click, Escape, auto-timeout).
     */
    function initFlash() {
        var flashes = document.querySelectorAll('[data-flash]');

        flashes.forEach(function (flash) {
            var close = flash.querySelector('[data-flash-close]');

            function dismiss() {
                flash.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
                flash.style.opacity = '0';
                flash.style.transform = 'translateY(-4px)';

                window.setTimeout(function () {
                    if (flash.parentNode) {
                        flash.parentNode.removeChild(flash);
                    }
                }, 200);
            }

            if (close) {
                close.addEventListener('click', dismiss);
            }

            window.setTimeout(dismiss, 6000);
        });
    }

    /**
     * Put a button into a loading state while an async action runs.
     */
    function setLoading(button, isLoading) {
        if (!button) {
            return;
        }

        if (isLoading) {
            button.dataset.originalText = button.textContent;
            button.classList.add('is-loading');
            button.setAttribute('disabled', 'disabled');
            button.textContent = 'Working…';
        } else {
            button.classList.remove('is-loading');
            button.removeAttribute('disabled');
            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    /**
     * Minimal JSON fetch client with consistent error handling.
     * Later phases build feature clients on top of this.
     */
    function request(url, options) {
        options = options || {};

        var headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, options.headers || {});

        if (options.method && options.method.toUpperCase() !== 'GET') {
            headers['X-CSRF-Token'] = csrfToken();
        }

        return fetch(url, Object.assign({ credentials: 'same-origin' }, options, { headers: headers }))
            .then(function (response) {
                return response.json().catch(function () {
                    return { success: false, message: 'Unexpected server response.', code: 'INVALID_RESPONSE' };
                }).then(function (payload) {
                    if (!response.ok || payload.success === false) {
                        var error = new Error(payload.message || 'Request failed.');
                        error.status = response.status;
                        error.payload = payload;
                        throw error;
                    }

                    return payload;
                });
            });
    }

    /**
     * Put the submit button into a loading state for the duration of the
     * submit, so users cannot double-submit forms.
     */
    function initForms() {
        var forms = document.querySelectorAll('form[data-loading]');

        forms.forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var confirmMessage = form.getAttribute('data-confirm');
                if (confirmMessage && !window.confirm(confirmMessage)) {
                    event.preventDefault();
                    return;
                }

                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    return; // let the browser report the invalid fields
                }

                var button = form.querySelector('button[type="submit"]');
                if (!button || button.disabled) {
                    return;
                }

                event.preventDefault();
                setLoading(button, true);

                // Submit synchronously; browsers will navigate away on success.
                HTMLFormElement.prototype.submit.call(form);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNavToggle();
        initFlash();
        initForms();
    });

    // Public surface for other scripts / later phases.
    window.ResQLink = {
        csrfToken: csrfToken,
        setLoading: setLoading,
        request: request
    };
})();
