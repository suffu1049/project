<?php
/**
 * Volunteer nearby / eligible request list.
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $items
 * @var array<string, mixed> $meta
 */
$user = $user ?? null;
$items = $items ?? [];
$meta = $meta ?? [];

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

$priorityClass = static fn (string $priority): string => match ($priority) {
    'CRITICAL' => 'badge--critical',
    'HIGH'     => 'badge--high',
    'MEDIUM'   => 'badge--medium',
    default    => 'badge--low',
};

$statusClass = static fn (string $status): string => match ($status) {
    'RESOLVED'    => 'badge--ok',
    'CANCELLED'   => 'badge--muted',
    'ESCALATED'   => 'badge--critical',
    'IN_PROGRESS' => 'badge--high',
    'ACCEPTED'    => 'badge--medium',
    'MATCHING'    => 'badge--medium',
    'VERIFIED'    => 'badge--info',
    default       => 'badge--warn',
};

$eligible = (bool) ($meta['eligible'] ?? false);
$reason = (string) ($meta['reason'] ?? '');
?>
<section class="page-head">
    <p class="eyebrow">Volunteer requests</p>
    <div class="page-head__row">
        <h1 class="page-title">Nearby open requests</h1>
        <a class="btn btn--ghost" href="<?= e(url('volunteer/dashboard')) ?>">Dashboard</a>
    </div>
    <p class="page-subtitle">
        Open emergencies matched to your skills<?= ($meta['service_radius_km'] ?? null) !== null
            ? ' within ' . e(number_format((float) $meta['service_radius_km'], 1)) . ' km'
            : '' ?>.
        Exact request coordinates stay private until a match is accepted (Phase 7).
    </p>
</section>

<div class="card">
    <form method="get" action="<?= e(url('volunteer/requests')) ?>" class="form form--inline" data-loading>
        <div class="field">
            <label class="label" for="category">Category</label>
            <select class="input" id="category" name="category">
                <option value="">All</option>
                <?php foreach ($categoryLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= (string) ($_GET['category'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label" for="priority">Priority</label>
            <select class="input" id="priority" name="priority">
                <option value="">All</option>
                <?php foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $priority): ?>
                    <option value="<?= e($priority) ?>" <?= (string) ($_GET['priority'] ?? '') === $priority ? 'selected' : '' ?>>
                        <?= e($priority) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label">&nbsp;</label>
            <button class="btn btn--ghost" type="submit">Filter</button>
        </div>
    </form>
</div>

<?php if (!$eligible): ?>
    <div class="card">
        <h2 class="card__title">No requests to show</h2>
        <p class="muted">
            <?= e(match ($reason) {
                'NOT_VERIFIED' => 'Your profile is still awaiting admin verification. Complete your skills and location so operators can approve you faster.',
                'NOT_AVAILABLE' => 'You are not marked AVAILABLE. Update your availability on the dashboard when you can help.',
                default => 'There are no open compatible requests right now.',
            }) ?>
        </p>
        <a class="btn btn--ghost btn--small" href="<?= e(url('volunteer/dashboard')) ?>">Back to dashboard</a>
    </div>
<?php elseif ($items === []): ?>
    <div class="card">
        <h2 class="card__title">Nothing nearby</h2>
        <p class="muted">
            You are eligible, but no open requests match your skills
            <?= ($meta['has_location'] ?? false) ? 'and service radius' : '' ?> right now.
        </p>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $item): ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Request #<?= e((string) $item['id']) ?></p>
                        <h2 class="request-card__title">
                            <?= e($categoryLabels[(string) $item['category']] ?? (string) $item['category']) ?>
                        </h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($priorityClass((string) $item['priority'])) ?>">
                            <?= e((string) $item['priority']) ?>
                        </span>
                        <span class="badge <?= e($statusClass((string) $item['status'])) ?>">
                            <?= e((string) $item['status']) ?>
                        </span>
                        <?php if (($item['distance_km'] ?? null) !== null): ?>
                            <span class="badge badge--info"><?= e(number_format((float) $item['distance_km'], 1)) ?> km</span>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="request-card__excerpt muted">
                    <?= e(mb_strimwidth((string) $item['description'], 0, 160, '…')) ?>
                </p>

                <dl class="request-card__meta">
                    <div>
                        <dt>People</dt>
                        <dd><?= e((string) $item['people_affected']) ?></dd>
                    </div>
                    <div>
                        <dt>Priority score</dt>
                        <dd><?= e((string) $item['priority_score']) ?></dd>
                    </div>
                    <?php if (!empty($item['address_text'])): ?>
                        <div>
                            <dt>Landmark</dt>
                            <dd><?= e((string) $item['address_text']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Created</dt>
                        <dd><?= e(date('d M Y, H:i', strtotime((string) $item['created_at']))) ?></dd>
                    </div>
                </dl>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
