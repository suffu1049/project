/**
 * ResQLink — AI assistance (Phase 10).
 *
 * Suggestions only. This script NEVER writes to the request form on its own:
 * it renders what the server returned and the user explicitly chooses to
 * "Use this category". It never touches the priority field, because priority
 * is a deterministic system decision.
 *
 * Failure modes (401, 422, 5xx, offline, malformed JSON) are surfaced in the
 * status line — the form remains fully usable without AI.
 */
(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function init(root) {
        var url = root.getAttribute('data-ai-url');
        var statusEl = root.querySelector('[data-ai-status]');
        var outputEl = root.querySelector('[data-ai-output]');
        var runBtn = root.querySelector('[data-ai-run]');
        var applyBtn = root.querySelector('[data-ai-apply]');

        if (!url || !runBtn) {
            return;
        }

        var fields = {
            source:     root.querySelector('[data-ai-source]'),
            disclaimer: root.querySelector('[data-ai-disclaimer]'),
            category:   root.querySelector('[data-ai-category]'),
            priority:   root.querySelector('[data-ai-priority]'),
            indicators: root.querySelector('[data-ai-indicators]'),
            summary:    root.querySelector('[data-ai-summary]')
        };

        var categorySelect = document.getElementById('category');
        var descriptionEl = document.getElementById('description');
        var suggestedCategory = null;

        function setStatus(message, isError) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.classList.toggle('field__error', !!isError);
        }

        function render(payload) {
            var data = payload && payload.data ? payload.data : {};
            var result = data.result || {};
            var source = data.source || 'fallback';

            suggestedCategory = result.category || null;

            if (fields.source) {
                fields.source.textContent = source === 'ai'
                    ? 'AI suggestion'
                    : 'Fallback suggestion (AI unavailable)';
                fields.source.className = 'badge ' + (source === 'ai' ? 'badge--info' : 'badge--muted');
            }
            if (fields.disclaimer) fields.disclaimer.textContent = data.disclaimer || '';
            if (fields.category) fields.category.textContent = suggestedCategory || 'Not determined';
            if (fields.priority) {
                fields.priority.textContent = result.suggested_priority || '—';
            }
            if (fields.indicators) {
                fields.indicators.textContent = (result.indicators || []).join(', ') || '—';
            }
            if (fields.summary) {
                fields.summary.textContent = (result && result.summary) || '—';
            }

            if (applyBtn) {
                applyBtn.hidden = !suggestedCategory;
            }
            if (outputEl) outputEl.hidden = false;

            if (applyBtn) {
                applyBtn.disabled = false;
            }

            setStatus(
                source === 'ai'
                    ? 'AI suggestion ready. Review it below, then choose whether to use it.'
                    : 'AI is unavailable, so a deterministic fallback was used. You can still submit normally.',
                false
            );
        }

        runBtn.addEventListener('click', function () {
            var description = descriptionEl ? descriptionEl.value.trim() : '';

            if (description.length < 10) {
                setStatus('Write at least 10 characters first, then ask for a suggestion.', true);
                return;
            }

            runBtn.disabled = true;
            setStatus('Asking for a suggestion…', false);

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken()
                },
                body: JSON.stringify({ description: description, feature: 'classify' })
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { success: false, message: 'Unexpected server response.' };
                    }).then(function (payload) {
                        if (!response.ok || payload.success === false) {
                            throw new Error(payload.message || 'AI assistance is unavailable right now.');
                        }
                        return payload;
                    });
                })
                .then(render)
                .catch(function (error) {
                    // Never block the workflow: just say so and move on.
                    if (outputEl) outputEl.hidden = true;
                    setStatus(
                        (error && error.message ? error.message : 'AI assistance failed.') +
                        ' You can keep writing and submit normally.',
                        true
                    );
                })
                .then(function () {
                    runBtn.disabled = false;
                });
        });

        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                if (!suggestedCategory || !categorySelect) return;

                var option = Array.prototype.filter.call(
                    categorySelect.options,
                    function (o) { return o.value === suggestedCategory; }
                )[0];

                if (!option) {
                    setStatus('That suggestion is not a valid category, so nothing was changed.', true);
                    return;
                }

                categorySelect.value = suggestedCategory;
                setStatus('Category set to ' + suggestedCategory + '. You can change it before submitting.', false);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ai-assist]');
        if (root) init(root);
    });
})();
