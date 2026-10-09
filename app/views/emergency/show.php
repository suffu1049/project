<?php
/**
 * Emergency request detail page: status, priority explanation, timeline, actions.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $item
 * @var array<int, array<string, mixed>> $events
 * @var array<int, array<string, mixed>> $attachments
 * @var bool $editable
 * @var bool $cancellable
 * @var array<int, array<string, mixed>> $mapMarkers
 */
$user = $user ?? null;
$item = $item ?? [];
$events = $events ?? [];
$attachments = $attachments ?? [];
$editable = (bool) ($editable ?? false);
$cancellable = (bool) ($cancellable ?? false);
$mapMarkers = $mapMarkers ?? [];
$errors = pull_flash('form_errors') ?? [];

$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};

$categoryLabels = [
    'MEDICAL'          => 'Medical',
    'ACCIDENT'         => 'Accident',
    'FLOOD'            => 'Flood',
    'FIRE'             => 'Fire',
    'FOOD_WATER'       => 'Food / Water',
    'SHELTER'          => 'Shelter',
    'TRANSPORT'        => 'Transport',
    'MISSING_STRANDED' => 'Missing / Stranded',
    'OTHER'            => 'Other',
];

$priorityClass = match ((string) $item['priority']) {
    'CRITICAL' => 'badge--critical',
    'HIGH'     => 'badge--high',
    'MEDIUM'   => 'badge--medium',
    default    => 'badge--low',
};

$statusClass = match ((string) $item['status']) {
    'RESOLVED'    => 'badge--ok',
    'CANCELLED'   => 'badge--muted',
    'ESCALATED'   => 'badge--critical',
    'IN_PROGRESS' => 'badge--high',
    'ACCEPTED'    => 'badge--medium',
    'MATCHING'    => 'badge--medium',
    'VERIFIED'    => 'badge--info',
    default       => 'badge--warn',
};

$priorityReason = trim((string) ($item['priority_reason'] ?? ''));
$reasonLines = $priorityReason === '' ? [] : (preg_split('/\r\n|\r|\n/', $priorityReason) ?: []);

$hasLocation = $item['latitude'] !== null && $item['longitude'] !== null;
$markersJson = json_encode(
    $mapMarkers,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($markersJson === false) {
    $markersJson = '[]';
}

$showMap = $mapMarkers !== [];
$markerPrecision = (string) ($mapMarkers[0]['precision'] ?? 'exact');

$precisionNote = '';

if ($hasLocation) {
    $precisionNote = $markerPrecision === 'approximate'
        ? 'Coordinates are shown approximately for privacy until you are matched.'
        : 'Location is visible to you and operations.';
}
?>
<section class="page-head">
    <p class="eyebrow">Request #<?= e((string) $item['id']) ?></p>
    <div class="page-head__row">
        <h1 class="page-title"><?= e($categoryLabels[(string) $item['category']] ?? (string) $item['category']) ?> emergency</h1>
        <div class="request-card__badges">
            <span class="badge <?= e($priorityClass) ?>"><?= e((string) $item['priority']) ?></span>
            <span class="badge <?= e($statusClass) ?>"><?= e((string) $item['status']) ?></span>
        </div>
    </div>
    <p class="page-subtitle">
        Created <?= e(date('d M Y, H:i', strtotime((string) $item['created_at']))) ?>
        <?= $item['resolved_at'] !== null ? ' · Resolved ' . e(date('d M Y, H:i', strtotime((string) $item['resolved_at']))) : '' ?>
    </p>
</section>

<div class="request-layout">
    <div>
        <article class="card">
            <h2 class="card__title">Situation</h2>
            <p class="request-detail__description"><?= nl2br(e((string) $item['description'])) ?></p>

            <dl class="detail-list">
                <div class="detail-list__row">
                    <dt>People affected</dt>
                    <dd><?= e((string) $item['people_affected']) ?></dd>
                </div>
                <div class="detail-list__row">
                    <dt>Priority score</dt>
                    <dd><?= e((string) $item['priority_score']) ?> / 100</dd>
                </div>
                <div class="detail-list__row">
                    <dt>Requester</dt>
                    <dd>#<?= e((string) $item['user_id']) ?></dd>
                </div>
                <?php if ($hasLocation): ?>
                    <div class="detail-list__row">
                        <dt>Location</dt>
                        <dd>
                            <?php if ($markerPrecision === 'approximate'): ?>
                                Approximate area · <?= e((string) ($mapMarkers[0]['latitude'] ?? '')) ?>, <?= e((string) ($mapMarkers[0]['longitude'] ?? '')) ?>
                            <?php else: ?>
                                <?= e((string) $item['latitude']) ?>, <?= e((string) $item['longitude']) ?>
                                <?= $item['location_accuracy_m'] !== null ? ' (±' . e((string) $item['location_accuracy_m']) . ' m)' : '' ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($item['address_text'])): ?>
                    <div class="detail-list__row">
                        <dt>Address</dt>
                        <dd><?= e((string) $item['address_text']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($precisionNote !== ''): ?>
                <p class="muted"><?= e($precisionNote) ?></p>
            <?php endif; ?>
        </article>

        <?php if ($showMap): ?>
            <article class="card">
                <header class="card__header">
                    <h2 class="card__title">Location map</h2>
                    <span class="badge <?= $markerPrecision === 'exact' ? 'badge--ok' : 'badge--warn' ?>">
                        <?= e($markerPrecision) ?>
                    </span>
                </header>
                <div
                    id="ops-map"
                    class="map-container"
                    data-map-root
                    data-map-markers="<?= e($markersJson) ?>"
                    data-map-poll="0"
                    data-map-default-lat="<?= e((string) ($mapMarkers[0]['latitude'] ?? '20.3893')) ?>"
                    data-map-default-lng="<?= e((string) ($mapMarkers[0]['longitude'] ?? '72.9106')) ?>"
                    role="application"
                    aria-label="Request location map"
                >
                    <div class="map-fallback">
                        <p class="muted">Map preview…</p>
                    </div>
                </div>
                <div class="map-legend">
                    <span class="map-legend__item">
                        <span class="map-pin map-pin--red" aria-hidden="true">!</span>
                        This request
                    </span>
                </div>
            </article>
        <?php endif; ?>

        <article class="card">
            <h2 class="card__title">Why this priority?</h2>
            <p class="muted">Deterministic rule engine — no AI override. Score must reach 70 for CRITICAL, 50 for HIGH, 30 for MEDIUM.</p>
            <?php if ($reasonLines !== []): ?>
                <ul class="reason-list">
                    <?php foreach ($reasonLines as $line): ?>
                        <li><?= e($line) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">No additional factors recorded.</p>
            <?php endif; ?>
        </article>

        <article class="card">
            <h2 class="card__title">Attachments</h2>
            <?php if ($attachments === []): ?>
                <p class="muted">No attachments yet.</p>
            <?php else: ?>
                <ul class="attachment-list">
                    <?php foreach ($attachments as $file): ?>
                        <li>
                            <span class="attachment-list__name"><?= e((string) $file['file_name']) ?></span>
                            <span class="muted"><?= e((string) $file['mime_type']) ?> · <?= e((string) (int) ($file['file_size'] / 1024)) ?> KB</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($editable || $cancellable): ?>
                <form
                    method="post"
                    action="<?= e(url('requests/' . (int) $item['id'] . '/attachment')) ?>"
                    class="form form--inline"
                    enctype="multipart/form-data"
                    data-loading
                >
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="file">Upload photo</label>
                        <input class="input" id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <button class="btn btn--ghost" type="submit">Upload</button>
                </form>
            <?php endif; ?>
        </article>
    </div>

    <aside>
        <article class="card">
            <h2 class="card__title">Timeline</h2>
            <ol class="timeline">
                <?php foreach ($events as $event): ?>
                    <li class="timeline__item">
                        <div class="timeline__dot" aria-hidden="true"></div>
                        <div class="timeline__body">
                            <p class="timeline__event">
                                <strong><?= e((string) $event['event_type']) ?></strong>
                                <?php if (!empty($event['old_status']) || !empty($event['new_status'])): ?>
                                    <span class="muted">
                                        <?= e((string) ($event['old_status'] ?? '—')) ?>
                                        →
                                        <?= e((string) ($event['new_status'] ?? '—')) ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($event['note'])): ?>
                                <p class="timeline__note"><?= e((string) $event['note']) ?></p>
                            <?php endif; ?>
                            <p class="timeline__meta muted">
                                <?= e(date('d M Y, H:i', strtotime((string) $event['created_at']))) ?>
                                <?php if (!empty($event['actor_name'])): ?>
                                    · <?= e((string) $event['actor_name']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </article>

        <?php if ($editable): ?>
            <article class="card">
                <h2 class="card__title">Update details</h2>
                <p class="muted">Editing re-scores priority automatically. Priority, status and assignment cannot be changed here.</p>
                <form
                    method="post"
                    action="<?= e(url('requests/' . (int) $item['id'] . '/update')) ?>"
                    class="form"
                    data-loading
                    novalidate
                >
                    <?= csrf_field() ?>

                    <div class="field">
                        <label class="label" for="description">Description</label>
                        <textarea
                            class="input<?= $fieldError('description') ? ' input--invalid' : '' ?>"
                            id="description"
                            name="description"
                            rows="5"
                            minlength="10"
                            maxlength="5000"
                            required
                        ><?= e((string) old('description', $item['description'])) ?></textarea>
                        <?php if ($fieldError('description')): ?>
                            <p class="field__error"><?= e($fieldError('description')) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="people_affected">People affected</label>
                        <input
                            class="input<?= $fieldError('people_affected') ? ' input--invalid' : '' ?>"
                            id="people_affected"
                            name="people_affected"
                            type="number"
                            min="1"
                            max="100000"
                            required
                            value="<?= e((string) old('people_affected', $item['people_affected'])) ?>"
                        >
                    </div>

                    <div class="field">
                        <label class="label" for="address_text">Address / landmark</label>
                        <input
                            class="input"
                            id="address_text"
                            name="address_text"
                            type="text"
                            maxlength="500"
                            value="<?= e((string) old('address_text', $item['address_text'] ?? '')) ?>"
                        >
                    </div>

                    <button class="btn btn--primary btn--block" type="submit">Save changes</button>
                </form>
            </article>
        <?php endif; ?>

        <?php if ($cancellable): ?>
            <article class="card card--danger">
                <h2 class="card__title">Cancel request</h2>
                <p class="muted">Cancellation is allowed while the request is CREATED, VERIFIED or MATCHING.</p>
                <form
                    method="post"
                    action="<?= e(url('requests/' . (int) $item['id'] . '/cancel')) ?>"
                    class="form"
                    data-loading
                    data-confirm="Cancel this emergency request? This cannot be undone."
                >
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="note">Reason (optional)</label>
                        <input class="input" id="note" name="note" type="text" maxlength="500">
                    </div>
                    <button class="btn btn--danger btn--block" type="submit">Cancel request</button>
                </form>
            </article>
        <?php endif; ?>

        <article class="card">
            <h2 class="card__title">Matching</h2>
            <p class="muted">
                Capability-first engine scores eligible volunteers and verified
                resource stocks. Suggestions stay private until acceptance.
            </p>
            <a
                class="btn btn--primary btn--small"
                href="<?= e(url('requests/' . (int) $item['id'] . '/matches')) ?>"
            >
                Open match panel
            </a>
        </article>

        <article class="card">
            <a class="btn btn--ghost btn--block" href="<?= e(url('requests')) ?>">Back to requests</a>
        </article>
    </aside>
</div>
