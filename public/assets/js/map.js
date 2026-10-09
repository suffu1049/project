/* ==========================================================================
   ResQLink — Operational map (Phase 8)
   Leaflet + OpenStreetMap. Marker data is embedded server-side; polling
   refreshes via /api/v1/map/markers. Degrades gracefully when tiles fail.
   ========================================================================== */

(function () {
    'use strict';

    var COLORS = {
        red: '#dc2626',
        green: '#16a34a',
        blue: '#2563eb',
        yellow: '#d97706',
        purple: '#7c3aed'
    };

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function parseMarkers(root) {
        var raw = root.getAttribute('data-map-markers') || '[]';

        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function pinIcon(L, marker) {
        return L.divIcon({
            className: 'map-div-icon',
            html: '<span class="map-pin map-pin--' + escapeHtml(marker.color || 'blue') +
                '" title="' + escapeHtml(marker.label || marker.type) + '">' +
                escapeHtml(marker.icon || '?') + '</span>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -14]
        });
    }

    function popupHtml(marker) {
        var precision = marker.precision === 'exact' ? 'Exact' : 'Approximate (~1 km)';
        var html = '<strong>' + escapeHtml(marker.label || marker.type) + '</strong><br>' +
            escapeHtml(marker.description || '') + '<br>' +
            '<span class="muted">' + escapeHtml(marker.type) + ' · ' + escapeHtml(precision) + '</span>';

        if (marker.href) {
            html += '<br><a href="' + escapeHtml(marker.href) + '">Open details</a>';
        }

        return html;
    }

    function typeEnabled(root, type) {
        var box = root.querySelector('[data-map-filter="' + type + '"]');
        return !box || box.checked;
    }

    function showTileError() {
        var banner = document.getElementById('map-error');
        if (banner) {
            banner.hidden = false;
        }
    }

    function pullMarkers(root) {
        var url = root.getAttribute('data-map-markers-url');

        if (!url || !window.ResQLink || typeof window.ResQLink.request !== 'function') {
            return;
        }

        window.ResQLink.request(url)
            .then(function (payload) {
                var items = payload && payload.data && payload.data.items;

                if (Array.isArray(items) && typeof root.__setMarkers === 'function') {
                    root.setAttribute('data-map-markers', JSON.stringify(items));
                    root.__setMarkers(items);
                }
            })
            .catch(function () { /* offline / poll failure is fine */ });
    }

    function initRoot(root) {
        var markers = parseMarkers(root);
        root.__markers = markers;

        if (typeof window.L === 'undefined') {
            showTileError();
            return;
        }

        var defaultLat = parseFloat(root.getAttribute('data-map-default-lat') || '20.3893');
        var defaultLng = parseFloat(root.getAttribute('data-map-default-lng') || '72.9106');
        var map = L.map(root).setView([defaultLat, defaultLng], 12);

        var tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        });

        var tileFailCount = 0;

        tiles.on('tileerror', function () {
            tileFailCount += 1;
            if (tileFailCount >= 6) {
                showTileError();
            }
        });

        tiles.on('tileload', function () {
            tileFailCount = 0;
        });

        tiles.addTo(map);

        var layer = L.layerGroup().addTo(map);

        function currentMarkers() {
            return Array.isArray(root.__markers) ? root.__markers : [];
        }

        function render() {
            layer.clearLayers();
            var visible = [];

            currentMarkers().forEach(function (marker) {
                if (!marker || marker.latitude == null || marker.longitude == null) {
                    return;
                }

                if (!typeEnabled(root, marker.type)) {
                    return;
                }

                visible.push(marker);

                L.marker([parseFloat(marker.latitude), parseFloat(marker.longitude)], {
                    icon: pinIcon(L, marker),
                    title: marker.label || marker.type
                })
                    .bindPopup(popupHtml(marker))
                    .addTo(layer);
            });

            if (visible.length) {
                try {
                    map.fitBounds(
                        L.featureGroup(layer.getLayers()).getBounds().pad(0.15),
                        { maxZoom: 14 }
                    );
                } catch (error) { /* ignore empty bounds */ }
            }

            var list = document.querySelector('[data-map-list]');

            if (list) {
                list.querySelectorAll('[data-marker-type]').forEach(function (item) {
                    var type = item.getAttribute('data-marker-type');
                    item.style.display = typeEnabled(root, type) ? '' : 'none';
                });
            }
        }

        root.__setMarkers = function (next) {
            root.__markers = Array.isArray(next) ? next : [];
            render();
        };

        render();

        root.querySelectorAll('[data-map-filter]').forEach(function (box) {
            box.addEventListener('change', render);
        });

        var toolbar = root.closest('.card');

        if (toolbar) {
            var locateBtn = toolbar.querySelector('[data-map-locate]');
            var refreshBtn = toolbar.querySelector('[data-map-refresh]');

            if (locateBtn) {
                locateBtn.addEventListener('click', function () {
                    if (!navigator.geolocation) {
                        window.alert('Geolocation is not supported by this browser.');
                        return;
                    }

                    locateBtn.disabled = true;

                    navigator.geolocation.getCurrentPosition(
                        function (position) {
                            locateBtn.disabled = false;
                            var latlng = [position.coords.latitude, position.coords.longitude];
                            map.setView(latlng, 14);
                            L.circleMarker(latlng, {
                                radius: 8,
                                color: '#2563eb',
                                fillColor: '#2563eb',
                                fillOpacity: 0.35
                            }).bindPopup('Your approximate location').addTo(layer);
                        },
                        function () {
                            locateBtn.disabled = false;
                        },
                        { timeout: 10000, maximumAge: 60000 }
                    );
                });
            }

            if (refreshBtn) {
                refreshBtn.addEventListener('click', function () {
                    pullMarkers(root);
                });
            }
        }

        var pollSec = parseInt(root.getAttribute('data-map-poll') || '12', 10);

        if (pollSec > 0) {
            var pollMs = pollSec >= 5 && pollSec <= 120 ? pollSec * 1000 : 12000;

            window.setInterval(function () {
                pullMarkers(root);
            }, pollMs);
        }
    }

    function initGeolocationButtons() {
        document.querySelectorAll('[data-geolocate]').forEach(function (button) {
            button.addEventListener('click', function () {
                var latInput = document.getElementById('latitude');
                var lngInput = document.getElementById('longitude');
                var status = document.querySelector('[data-geolocate-status]');

                if (!latInput || !lngInput) {
                    return;
                }

                if (!navigator.geolocation) {
                    if (status) {
                        status.textContent = 'Geolocation is not supported in this browser. Enter coordinates manually.';
                    }
                    return;
                }

                button.disabled = true;

                if (status) {
                    status.textContent = 'Detecting location…';
                }

                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        button.disabled = false;
                        latInput.value = position.coords.latitude.toFixed(6);
                        lngInput.value = position.coords.longitude.toFixed(6);

                        if (status) {
                            status.textContent = 'Location captured (±' +
                                Math.round(position.coords.accuracy || 0) +
                                ' m). Review before saving.';
                        }
                    },
                    function (error) {
                        button.disabled = false;

                        if (status) {
                            status.textContent = error.code === 1
                                ? 'Location permission denied. Enter coordinates manually.'
                                : 'Could not detect location. Enter coordinates manually.';
                        }
                    },
                    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
                );
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-map-root]').forEach(initRoot);
        initGeolocationButtons();
    });
})();
