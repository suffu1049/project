<?php
/**
 * Operational map — Leaflet + role-aware markers (Phase 8).
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $markers
 * @var array<string, mixed> $meta
 * @var string $leafletCss
 * @var string $leafletJs
 */
$user = $user ?? null;
$markers = $markers ?? [];
$meta = $meta ?? [];
$counts = (array) ($meta['counts'] ?? []);
$poll = (int) ($meta['poll_interval_seconds'] ?? 12);
$role = (string) ($meta['role'] ?? ($user['role'] ?? ''));

$markersJson = json_encode(
    $markers,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($markersJson === false) {
    $markersJson = '[]';
}
?>
<section class="page-head">
    <p class="eyebrow">Phase 8 · Location</p>
    <div class="page-head__row">
        <h1 class="page-title">Operations map</h1>
        <div class="request-card__badges">
            <span class="badge badge--role"><?= e($role) ?></span>
            <span class="badge badge--muted"><?= count($markers) ?> markers</span>
        </div>
    </div>
    <p class="page-subtitle">
        Live view of emergencies, responders and verified resources.
        Exact private coordinates stay hidden for unauthorized roles —
        approximate markers are rounded for privacy.
    </p>
</section>

<section class="card">
    <div class="map-toolbar">
        <div class="map-toolbar__filters" role="group" aria-label="Marker filters">
            <label class="check-field"><input type="checkbox" data-map-filter="EMERGENCY" checked> Emergency</label>
            <label class="check-field"><input type="checkbox" data-map-filter="VOLUNTEER" checked> Volunteer</label>
            <label class="check-field"><input type="checkbox" data-map-filter="RESOURCE" checked> Resource</label>
            <label class="check-field"><input type="checkbox" data-map-filter="ORGANIZATION" checked> Organization</label>
        </div>
        <div class="map-toolbar__actions">
            <button class="btn btn--ghost btn--small" type="button" data-map-locate>
                My location
            </button>
            <button class="btn btn--ghost btn--small" type="button" data-map-refresh>
                Refresh
            </button>
        </div>
    </div>

    <div
        id="ops-map"
        class="map-container"
        data-map-root
        data-map-markers="<?= e($markersJson) ?>"
        data-map-poll="<?= e((string) $poll) ?>"
        data-map-markers-url="<?= e(url('api/v1/map/markers')) ?>"
        data-map-default-lat="20.3893"
        data-map-default-lng="72.9106"
        role="application"
        aria-label="Emergency operations map"
    >
        <div class="map-fallback">
            <p class="muted">Interactive map loading… If tiles fail (offline), markers are listed below.</p>
        </div>
    </div>

    <div class="map-legend" aria-label="Map legend">
        <span class="map-legend__item"><span class="map-pin map-pin--red" aria-hidden="true">!</span> Emergency</span>
        <span class="map-legend__item"><span class="map-pin map-pin--green" aria-hidden="true">V</span> Volunteer</span>
        <span class="map-legend__item"><span class="map-pin map-pin--blue" aria-hidden="true">+</span> Resource</span>
        <span class="map-legend__item"><span class="map-pin map-pin--yellow" aria-hidden="true">S</span> Shelter / food</span>
        <span class="map-legend__item"><span class="map-pin map-pin--purple" aria-hidden="true">O</span> Organization</span>
        <span class="muted">Approx ≈ 1 km privacy radius</span>
    </div>

    <div id="map-error" class="empty-state" hidden>
        <div class="empty-state__icon" aria-hidden="true">🗺️</div>
        <h2 class="empty-state__title">Map tiles unavailable</h2>
        <p class="empty-state__message">
            External map tiles could not be loaded. Operational data is still available
            through the list below and the API.
        </p>
    </div>
</section>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Marker summary</h2>
        <span class="muted">Provider: <?= e((string) ($meta['provider'] ?? 'openstreetmap')) ?> · poll <?= $poll ?>s</span>
    </header>

    <?php if ($markers === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true">📍</div>
            <h2 class="empty-state__title">No markers for your role</h2>
            <p class="empty-state__message">
                <?= e((string) ($meta['precision_note'] ?? 'Nothing to display yet.')) ?>
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid--cards">
            <article class="card stat">
                <span class="stat__label">Emergencies</span>
                <span class="stat__value"><?= (int) ($counts['EMERGENCY'] ?? 0) ?></span>
            </article>
            <article class="card stat">
                <span class="stat__label">Volunteers</span>
                <span class="stat__value"><?= (int) ($counts['VOLUNTEER'] ?? 0) ?></span>
            </article>
            <article class="card stat">
                <span class="stat__label">Resources</span>
                <span class="stat__value"><?= (int) ($counts['RESOURCE'] ?? 0) ?></span>
            </article>
            <article class="card stat">
                <span class="stat__label">Organizations</span>
                <span class="stat__value"><?= (int) ($counts['ORGANIZATION'] ?? 0) ?></span>
            </article>
        </div>

        <ul class="notification-list" data-map-list>
            <?php foreach ($markers as $marker): ?>
                <li class="notification-item" data-marker-type="<?= e((string) $marker['type']) ?>">
                    <div class="notification-item__body">
                        <p class="notification-item__title">
                            <span class="map-pin map-pin--<?= e((string) $marker['color']) ?>" aria-hidden="true"><?= e((string) $marker['icon']) ?></span>
                            <?= e((string) $marker['label']) ?>
                            <span class="badge <?= $marker['precision'] === 'exact' ? 'badge--ok' : 'badge--warn' ?>">
                                <?= e((string) $marker['precision']) ?>
                            </span>
                        </p>
                        <p class="notification-item__message"><?= e((string) ($marker['description'] ?? '')) ?></p>
                        <p class="notification-item__meta muted">
                            <?= e((string) $marker['type']) ?>
                            · <?= e((string) ($marker['latitude'] ?? '')) ?>, <?= e((string) ($marker['longitude'] ?? '')) ?>
                            <?php if (!empty($marker['status'])): ?> · <?= e((string) $marker['status']) ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="notification-item__actions">
                        <?php if (!empty($marker['href'])): ?>
                            <a class="btn btn--ghost btn--small" href="<?= e((string) $marker['href']) ?>">Open</a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
