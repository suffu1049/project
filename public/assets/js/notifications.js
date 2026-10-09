/* ==========================================================================
   ResQLink — Notification polling (Phase 8)
   Polls the unread count every 10–15s and updates the navbar badge.
   Fails silently on network errors (never spams the console).
   ========================================================================== */

(function () {
    'use strict';

    var DEFAULT_POLL_MS = 12000;

    function csrfToken() {
        return window.ResQLink && window.ResQLink.csrfToken
            ? window.ResQLink.csrfToken()
            : '';
    }

    function pollMs() {
        var host = document.querySelector('[data-unread-badge-host]');
        var configured = host && host.getAttribute('data-poll-ms');
        var n = parseInt(configured || '', 10);

        return n >= 5000 && n <= 60000 ? n : DEFAULT_POLL_MS;
    }

    function updateBadge(count) {
        var badges = document.querySelectorAll('[data-unread-badge]');

        badges.forEach(function (badge) {
            if (count > 0) {
                badge.hidden = false;
                badge.textContent = count > 99 ? '99+' : String(count);
            } else {
                badge.hidden = true;
                badge.textContent = '0';
            }
        });
    }

    function fetchUnread() {
        if (!window.ResQLink || typeof window.ResQLink.request !== 'function') {
            return;
        }

        // Prefer the lightweight API; fall back to a head request marker.
        window.ResQLink.request('/api/v1/notifications?unread=1&limit=1')
            .then(function (payload) {
                var meta = (payload && payload.data && payload.data.meta) || {};
                var unread = typeof meta.unread_count === 'number'
                    ? meta.unread_count
                    : (payload && payload.data && Array.isArray(payload.data.items)
                        ? payload.data.items.length
                        : 0);

                updateBadge(unread);
            })
            .catch(function () {
                // Polling failure is expected offline — ignore.
            });
    }

    function initPolling() {
        // Only poll for signed-in users (badge present in the navbar).
        if (!document.querySelector('[data-unread-badge]')) {
            return;
        }

        fetchUnread();
        window.setInterval(fetchUnread, pollMs());
    }

    function initMarkAllReadForms() {
        // Progressive enhancement only — forms still work without JS.
        var forms = document.querySelectorAll('form[action*="read-all"]');

        forms.forEach(function (form) {
            form.addEventListener('submit', function () {
                window.setTimeout(fetchUnread, 400);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPolling();
        initMarkAllReadForms();
    });
})();
