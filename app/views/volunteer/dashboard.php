<?php
/**
 * Volunteer dashboard: profile summary, availability control, nearby count.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $profile
 * @var array{items: array<int, array<string, mixed>>, meta: array<string, mixed>} $eligible
 * @var array<int, string> $skills
 * @var array<int, string> $equipment
 */
$user = $user ?? null;
$profile = $profile ?? [];
$eligible = $eligible ?? ['items' => [], 'meta' => []];
$skills = $skills ?? [];
$equipment = $equipment ?? [];
$meta = $eligible['meta'] ?? [];
$availability = (string) ($profile['availability'] ?? 'OFFLINE');
$verification = (string) ($profile['verification_status'] ?? 'PENDING');
$radius = (float) ($profile['service_radius_km'] ?? 0);
$hasLocation = ($profile['latitude'] ?? null) !== null && ($profile['longitude'] ?? null) !== null;

$availabilityClass = match ($availability) {
    'AVAILABLE' => 'badge--ok',
    'BUSY'      => 'badge--warn',
    default     => 'badge--muted',
};

$verificationClass = match ($verification) {
    'VERIFIED' => 'badge--ok',
    'REJECTED' => 'badge--critical',
    default    => 'badge--warn',
};
?>
<section class="page-head">
    <p class="eyebrow">Volunteer</p>
    <div class="page-head__row">
        <h1 class="page-title">Volunteer dashboard<?= $user !== null ? ', ' . e($user['name'] ?? '') : '' ?></h1>
        <div>
            <a class="btn btn--ghost" href="<?= e(url('volunteer/matches')) ?>">My matches</a>
            <a class="btn btn--primary" href="<?= e(url('volunteer/requests')) ?>">Nearby requests</a>
        </div>
    </div>
    <p class="page-subtitle">
        Keep your profile current so operations can match you to the right emergencies.
    </p>
</section>

<section class="grid grid--cards" aria-label="Volunteer status">
    <article class="card">
        <h2 class="card__title">Availability</h2>
        <p class="stat__meta">Current status</p>
        <p class="stat__value">
            <span class="badge <?= e($availabilityClass) ?>"><?= e($availability) ?></span>
        </p>
        <p class="muted">Unavailable volunteers are never treated as available for matching.</p>
        <form method="post" action="<?= e(url('volunteer/availability')) ?>" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="availability" value="AVAILABLE">
            <button class="btn btn--primary btn--small" type="submit" <?= $availability === 'AVAILABLE' ? 'disabled' : '' ?>>Set available</button>
        </form>
        <form method="post" action="<?= e(url('volunteer/availability')) ?>" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="availability" value="BUSY">
            <button class="btn btn--ghost btn--small" type="submit" <?= $availability === 'BUSY' ? 'disabled' : '' ?>>Set busy</button>
        </form>
        <form method="post" action="<?= e(url('volunteer/availability')) ?>" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="availability" value="OFFLINE">
            <button class="btn btn--ghost btn--small" type="submit" <?= $availability === 'OFFLINE' ? 'disabled' : '' ?>>Go offline</button>
        </form>
    </article>

    <article class="card">
        <h2 class="card__title">Profile</h2>
        <dl class="detail-list">
            <div class="detail-list__row">
                <dt>Verification</dt>
                <dd><span class="badge <?= e($verificationClass) ?>"><?= e($verification) ?></span></dd>
            </div>
            <div class="detail-list__row">
                <dt>Service radius</dt>
                <dd><?= e(number_format($radius, 1)) ?> km</dd>
            </div>
            <div class="detail-list__row">
                <dt>Vehicle</dt>
                <dd><?= e((string) ($profile['vehicle'] ?? 'NONE')) ?></dd>
            </div>
            <div class="detail-list__row">
                <dt>Location</dt>
                <dd>
                    <?= $hasLocation
                        ? '<span class="badge badge--ok">On file</span>'
                        : '<span class="badge badge--warn">Not set</span>' ?>
                </dd>
            </div>
            <div class="detail-list__row">
                <dt>Skills</dt>
                <dd>
                    <?= $skills === []
                        ? '<span class="muted">None yet</span>'
                        : e(implode(', ', $skills)) ?>
                </dd>
            </div>
            <div class="detail-list__row">
                <dt>Equipment</dt>
                <dd>
                    <?= $equipment === []
                        ? '<span class="muted">None yet</span>'
                        : e(implode(', ', $equipment)) ?>
                </dd>
            </div>
        </dl>
        <a class="btn btn--ghost btn--small" href="<?= e(url('volunteer/profile')) ?>">Edit profile</a>
    </article>

    <article class="card">
        <h2 class="card__title">Nearby work</h2>
        <p class="stat__meta">Open compatible requests</p>
        <p class="stat__value"><?= e((string) (int) ($meta['count'] ?? count($eligible['items'] ?? []))) ?></p>
        <?php if (($meta['eligible'] ?? false) === false): ?>
            <p class="muted">
                <?= e(match ((string) ($meta['reason'] ?? '')) {
                    'NOT_VERIFIED' => 'An administrator must verify your profile before you receive eligible requests.',
                    'NOT_AVAILABLE' => 'You are not currently AVAILABLE, so no requests are listed.',
                    default => 'No eligible requests right now.',
                }) ?>
            </p>
        <?php else: ?>
            <p class="muted">
                Filtered by your skills and
                <?= ($meta['service_radius_km'] ?? null) !== null
                    ? e(number_format((float) $meta['service_radius_km'], 1)) . ' km radius.'
                    : 'capability.' ?>
            </p>
            <a class="btn btn--ghost btn--small" href="<?= e(url('volunteer/requests')) ?>">Open request list</a>
        <?php endif; ?>
    </article>

    <article class="card">
        <h2 class="card__title">Matches</h2>
        <p class="stat__meta">Phase 7 matching engine</p>
        <p class="muted">
            Accept assigned matches here. Completing a match frees your availability
            and resolves the emergency request.
        </p>
        <a class="btn btn--ghost btn--small" href="<?= e(url('volunteer/matches')) ?>">Open match inbox</a>
    </article>
</section>
