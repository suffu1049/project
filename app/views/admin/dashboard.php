<?php
/**
 * Admin control center dashboard (Phase 9).
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $data
 * @var array<string, mixed> $mapMeta
 */
$user = $user ?? null;
$data = $data ?? [];
$mapMeta = $mapMeta ?? [];

$kpis = (array) ($data['kpis'] ?? []);
$criticalQueue = (array) ($data['critical_queue'] ?? []);
$activity = (array) ($data['activity'] ?? []);
$resources = (array) ($data['resources'] ?? []);
$shortages = (array) ($data['resource_shortages'] ?? []);
$byStatus = (array) ($data['by_status'] ?? []);
$byPriority = (array) ($data['by_priority'] ?? []);

$priorityClass = static function (string $priority): string {
    return match ($priority) {
        'CRITICAL' => 'badge--critical',
        'HIGH'     => 'badge--high',
        'MEDIUM'   => 'badge--medium',
        default    => 'badge--low',
    };
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'RESOLVED'    => 'badge--ok',
        'CANCELLED'   => 'badge--muted',
        'ESCALATED'   => 'badge--critical',
        'IN_PROGRESS' => 'badge--high',
        'ACCEPTED'    => 'badge--medium',
        'MATCHING'    => 'badge--medium',
        'VERIFIED'    => 'badge--info',
        default       => 'badge--warn',
    };
};

$kpiCards = [
    ['label' => 'Active emergencies', 'value' => $kpis['active_emergencies'] ?? 0, 'tone' => 'critical', 'hint' => 'Open operational work'],
    ['label' => 'Critical',           'value' => $kpis['critical_requests'] ?? 0,  'tone' => 'critical', 'hint' => 'Priority CRITICAL'],
    ['label' => 'High priority',      'value' => $kpis['high_priority'] ?? 0,      'tone' => 'high',     'hint' => 'HIGH or CRITICAL'],
    ['label' => 'In progress',        'value' => $kpis['in_progress'] ?? 0,        'tone' => 'medium',   'hint' => 'Being handled'],
    ['label' => 'Resolved',           'value' => $kpis['resolved_requests'] ?? 0,  'tone' => 'ok',       'hint' => 'Completed'],
    ['label' => 'Available volunteers', 'value' => $kpis['available_volunteers'] ?? 0, 'tone' => 'ok',   'hint' => 'Verified + available'],
    ['label' => 'Available resources',  'value' => $kpis['available_resources'] ?? 0,  'tone' => 'info', 'hint' => 'Stocked depots'],
    ['label' => 'Active matches',       'value' => $kpis['active_matches'] ?? 0,       'tone' => 'info', 'hint' => 'Suggested / accepted'],
];
?>
<section class="page-head">
    <p class="eyebrow">Admin · Phase 9</p>
    <div class="page-head__row">
        <h1 class="page-title">ResQLink control center</h1>
        <div class="request-card__badges">
            <span class="badge badge--role">ADMIN</span>
            <span class="badge badge--muted"><?= e((string) ($kpis['total_requests'] ?? 0)) ?> total requests</span>
        </div>
    </div>
    <p class="page-subtitle">
        Operational overview of emergencies, responders, inventory and activity.
        Every action here is server-authorized and written to the audit trail.
    </p>
    <div class="hero__actions" style="margin-top:0.75rem">
        <a class="btn btn--primary btn--small" href="<?= e(url('admin/requests')) ?>">Request queue</a>
        <a class="btn btn--ghost btn--small" href="<?= e(url('admin/resources')) ?>">Resources</a>
        <a class="btn btn--ghost btn--small" href="<?= e(url('admin/activity')) ?>">Activity &amp; audit</a>
        <a class="btn btn--ghost btn--small" href="<?= e(url('admin/analytics')) ?>">Analytics</a>
        <a class="btn btn--ghost btn--small" href="<?= e(url('map')) ?>">Operations map</a>
    </div>
</section>

<section class="grid grid--cards" aria-label="Key performance indicators">
    <?php foreach ($kpiCards as $card): ?>
        <article class="card stat stat--<?= e((string) $card['tone']) ?>">
            <span class="stat__label"><?= e((string) $card['label']) ?></span>
            <span class="stat__value"><?= e((string) (int) $card['value']) ?></span>
            <span class="stat__meta muted"><?= e((string) $card['hint']) ?></span>
        </article>
    <?php endforeach; ?>
</section>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Live map</h2>
        <a class="btn btn--ghost btn--small" href="<?= e(url('map')) ?>">Open full map</a>
    </header>
    <p class="muted">
        The operations map shows role-aware markers. Admins see exact coordinates;
        other roles see approximate positions only.
    </p>
    <div class="map-legend" aria-label="Map legend">
        <span class="map-legend__item"><span class="map-pin map-pin--red" aria-hidden="true">!</span> Emergency</span>
        <span class="map-legend__item"><span class="map-pin map-pin--green" aria-hidden="true">V</span> Volunteer</span>
        <span class="map-legend__item"><span class="map-pin map-pin--blue" aria-hidden="true">+</span> Resource</span>
        <span class="map-legend__item"><span class="map-pin map-pin--purple" aria-hidden="true">O</span> Organization</span>
    </div>
</section>

<div class="grid grid--two">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Critical queue</h2>
            <a class="btn btn--ghost btn--small" href="<?= e(url('admin/requests?priority=CRITICAL')) ?>">All critical</a>
        </header>
        <?php if ($criticalQueue === []): ?>
            <p class="muted">No active requests right now.</p>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($criticalQueue as $item): ?>
                    <li class="notification-item">
                        <div class="notification-item__body">
                            <p class="notification-item__title">
                                <span class="badge <?= e($priorityClass((string) $item['priority'])) ?>"><?= e((string) $item['priority']) ?></span>
                                <a href="<?= e(url('requests/' . (int) $item['id'])) ?>">
                                    #<?= e((string) $item['id']) ?> · <?= e((string) $item['category']) ?>
                                </a>
                            </p>
                            <p class="notification-item__message muted">
                                <?= e(mb_strimwidth((string) $item['description'], 0, 110, '…')) ?>
                            </p>
                            <p class="notification-item__meta muted">
                                <span class="badge <?= e($statusClass((string) $item['status'])) ?>"><?= e((string) $item['status']) ?></span>
                                · <?= e((string) $item['people_affected']) ?> people
                                · score <?= e((string) $item['priority_score']) ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Recent activity</h2>
            <a class="btn btn--ghost btn--small" href="<?= e(url('admin/activity')) ?>">Full log</a>
        </header>
        <?php if ($activity === []): ?>
            <p class="muted">No recent events.</p>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($activity as $event): ?>
                    <li class="notification-item">
                        <div class="notification-item__body">
                            <p class="notification-item__title">
                                <?= e(str_replace('_', ' ', (string) $event['event_type'])) ?>
                                <span class="muted">· request #<?= e((string) $event['request_id']) ?></span>
                            </p>
                            <p class="notification-item__message muted">
                                <?= e((string) ($event['actor_name'] ?? 'System')) ?>
                                <?php if (!empty($event['old_status']) || !empty($event['new_status'])): ?>
                                    · <?= e((string) ($event['old_status'] ?? '—')) ?> → <?= e((string) ($event['new_status'] ?? '—')) ?>
                                <?php endif; ?>
                            </p>
                            <p class="notification-item__meta muted">
                                <?= e(date('d M Y, H:i', strtotime((string) $event['created_at']))) ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="grid grid--two">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Requests by status</h2>
        </header>
        <dl class="detail-list">
            <?php foreach ($byStatus as $status => $count): ?>
                <div class="detail-list__row">
                    <dt><span class="badge <?= e($statusClass((string) $status)) ?>"><?= e((string) $status) ?></span></dt>
                    <dd><?= e((string) (int) $count) ?></dd>
                </div>
            <?php endforeach; ?>
            <?php if ($byStatus === []): ?><p class="muted">No data.</p><?php endif; ?>
        </dl>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Requests by priority</h2>
        </header>
        <dl class="detail-list">
            <?php foreach ($byPriority as $priority => $count): ?>
                <div class="detail-list__row">
                    <dt><span class="badge <?= e($priorityClass((string) $priority)) ?>"><?= e((string) $priority) ?></span></dt>
                    <dd><?= e((string) (int) $count) ?></dd>
                </div>
            <?php endforeach; ?>
            <?php if ($byPriority === []): ?><p class="muted">No data.</p><?php endif; ?>
        </dl>
    </section>
</div>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Resource shortages</h2>
        <a class="btn btn--ghost btn--small" href="<?= e(url('admin/resources')) ?>">Resource overview</a>
    </header>
    <p class="muted">
        Platform totals: <?= e((string) (int) ($resources['available_quantity'] ?? 0)) ?> available of
        <?= e((string) (int) ($resources['total_quantity'] ?? 0)) ?> units
        across <?= e((string) (int) ($resources['total_resources'] ?? 0)) ?> resources.
    </p>
    <?php if ($shortages === []): ?>
        <p class="muted">No shortages detected.</p>
    <?php else: ?>
        <ul class="notification-list">
            <?php foreach ($shortages as $resource): ?>
                <li class="notification-item">
                    <div class="notification-item__body">
                        <p class="notification-item__title">
                            <?= e((string) $resource['resource_name']) ?>
                            <span class="badge <?= (string) $resource['status'] === 'UNAVAILABLE' ? 'badge--critical' : 'badge--warn' ?>">
                                <?= e((string) $resource['status']) ?>
                            </span>
                        </p>
                        <p class="notification-item__meta muted">
                            <?= e((string) $resource['organization_name']) ?>
                            · <?= e((string) (int) $resource['available_quantity']) ?> / <?= e((string) (int) $resource['quantity']) ?>
                            <?= e((string) ($resource['unit'] ?? 'units')) ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
