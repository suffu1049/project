/* ==========================================================================
   ResQLink — Analytics charts (Phase 11)
   Renders the admin analytics dashboard from the JSON payload embedded by
   app/views/admin/analytics.php. Chart.js is an enhancement: if it (or the
   data) is missing, each frame shows a message and the accessible tables on
   the page remain the source of truth.
   ========================================================================== */

(function () {
    'use strict';

    var PALETTE = [
        '#2563eb', '#0ea5e9', '#16a34a', '#d97706',
        '#dc2626', '#7c3aed', '#0891b2', '#64748b'
    ];

    var GRID = 'rgba(148, 163, 184, 0.28)';
    var INK = 'rgba(100, 116, 139, 0.95)';

    function readPayload() {
        var node = document.querySelector('[data-analytics-charts]');

        if (!node) {
            return null;
        }

        try {
            var parsed = JSON.parse(node.textContent || '{}');
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function hasData(values) {
        if (!Array.isArray(values)) {
            return false;
        }

        for (var i = 0; i < values.length; i++) {
            if (Number(values[i]) > 0) {
                return true;
            }
        }

        return false;
    }

    function frameOf(canvas) {
        return canvas.closest('[data-chart-frame]') || canvas.parentNode;
    }

    function showError(canvas) {
        var frame = frameOf(canvas);
        var note = frame ? frame.querySelector('[data-chart-error]') : null;

        if (canvas) {
            canvas.hidden = true;
        }

        if (note) {
            note.hidden = false;
        } else if (frame) {
            frame.setAttribute('data-chart-failed', 'true');
        }
    }

    function showEmpty(canvas) {
        var frame = frameOf(canvas);

        if (canvas) {
            canvas.hidden = true;
        }

        if (frame && !frame.querySelector('.chart-frame__empty')) {
            var note = document.createElement('p');
            note.className = 'chart-frame__empty muted';
            note.textContent = 'No data for this period.';
            frame.appendChild(note);
        }
    }

    function axesOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { intersect: false, mode: 'index' }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: INK, maxRotation: 0, autoSkipPadding: 12 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: GRID },
                    ticks: { color: INK, precision: 0 }
                }
            }
        };
    }

    function lineChart(ctx, section) {
        return new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: section.labels,
                datasets: [{
                    label: 'Requests',
                    data: section.values,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.14)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.32,
                    pointRadius: 2,
                    pointBackgroundColor: '#2563eb'
                }]
            },
            options: axesOptions()
        });
    }

    function barChart(ctx, section, label, color) {
        return new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: section.labels,
                datasets: [{
                    label: label,
                    data: section.values,
                    backgroundColor: color,
                    borderRadius: 4,
                    maxBarThickness: 46
                }]
            },
            options: axesOptions()
        });
    }

    function doughnutChart(ctx, section) {
        return new window.Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: section.labels,
                datasets: [{
                    data: section.values,
                    backgroundColor: PALETTE,
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: INK, boxWidth: 12, boxHeight: 12 }
                    }
                }
            }
        });
    }

    function resourceChart(ctx, section) {
        return new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: section.labels,
                datasets: [
                    {
                        label: 'Available',
                        data: section.available,
                        backgroundColor: '#16a34a',
                        borderRadius: 4,
                        maxBarThickness: 34
                    },
                    {
                        label: 'Total',
                        data: section.total,
                        backgroundColor: 'rgba(148, 163, 184, 0.55)',
                        borderRadius: 4,
                        maxBarThickness: 34
                    }
                ]
            },
            options: (function () {
                var options = axesOptions();
                options.plugins.legend = {
                    display: true,
                    labels: { color: INK, boxWidth: 12, boxHeight: 12 }
                };
                return options;
            })()
        });
    }

    function render(payload) {
        var builders = {
            over_time: function (canvas, section) {
                if (!hasData(section.values)) {
                    showEmpty(canvas);
                    return;
                }
                lineChart(canvas, section);
            },
            by_category: function (canvas, section) {
                if (!hasData(section.values)) {
                    showEmpty(canvas);
                    return;
                }
                barChart(canvas, section, 'Requests', '#2563eb');
            },
            by_status: function (canvas, section) {
                if (!hasData(section.values)) {
                    showEmpty(canvas);
                    return;
                }
                doughnutChart(canvas, section);
            },
            resource_availability: function (canvas, section) {
                if (!hasData(section.available) && !hasData(section.total)) {
                    showEmpty(canvas);
                    return;
                }
                resourceChart(canvas, section);
            }
        };

        document.querySelectorAll('[data-chart]').forEach(function (canvas) {
            var key = canvas.getAttribute('data-chart');
            var section = payload[key];

            if (!section || typeof builders[key] !== 'function') {
                showEmpty(canvas);
                return;
            }

            try {
                builders[key](canvas, section);
            } catch (error) {
                showError(canvas);
            }
        });
    }

    function init() {
        var canvases = document.querySelectorAll('[data-chart]');

        if (!canvases.length) {
            return;
        }

        var payload = readPayload();

        if (!payload || typeof window.Chart === 'undefined') {
            canvases.forEach(showError);
            return;
        }

        render(payload);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
