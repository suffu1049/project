<?php
/**
 * Admin analytics dashboard (Phase 11).
 *
 * Charts are rendered client-side by public/assets/js/charts.js from the JSON
 * payload embedded below. Chart.js is a progressive enhancement: the same data
 * is always available in the accessible tables at the bottom, so the page is
 * fully readable with JavaScript disabled or when the CDN is unreachable.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $data
 */

$user = $user ?? null;
$data = $data ?? [];

$period = (string) ($data['period'] ?? 'week');
$periods = (array) ($data['periods'] ?? ['week', 'day', 'month', 'year']);
$window = (array) ($data['window'] ?? []);
$requests = (array) ($data['requests'] ?? []);
$resolution = (array) ($requests['resolution'] ?? []);
$resources = (array) ($data['resources'] ?? []);
$resourceStats = (array) ($resources['stats'] ?? []);
$availability = (array) ($resources['availability'] ?? []);
$volunteers = (array) ($data['volunteers'] ?? []);
$allTime = (array) ($data['all_time'] ?? []);

$overTime = (array) ($requests['over_time'] ?? []);
$byCategory = (array) ($requests['by_category'] ?? []);
$byPriority = (array) ($requests['by_priority'] ?? []);
$byStatus = (array) ($requests['by_status'] ?? []);

$pretty = static fn (string $value): string => ucwords(str_replace('_', ' ', strtolower($value)));

$chartPayload = [
    'over_time' => [
        'labels' => array_values((array) ($overTime['labels'] ?? [])),
        'values' => array_values((array) ($overTime['values'] ?? [])),
    ],
    'by_category' => [
        'labels' => array_map($pretty, array_keys($byCategory)),
        'values' => array_values($byCategory),
    ],
    'by_status' => [
        'labels' => array_map($pretty, array_keys($byStatus)),
        'values' => array_values($byStatus),
    ],
    'resources' => [
        'labels'    => array_values((array) ($availability['labels'] ?? [])),
        'available' => array_values((array) ($availability['available'] ?? [])),
        'total'     => array_values((array) ($availability['total'] ?? [])),
    ],
];

$chartJson = json_encode(
    $chartPayload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$formatHours = static function (mixed $hours): string {
    return $hours === null || $hours === '' ? '—' : number_format((float) $hours, 1) . ' h';
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'RESOLVED'    => 'badge--ok',
        'CANCELLED'   => 'badge--muted',
        'ESCALATED'   => 'badge--critical',
        'IN_PROGRESS' => 'badge--high',
        'ACCEPTED', 'MATCHING' => 'badge--medium',
        'VERIFIED'    => 'badge--info',
        default       => 'badge--warn',
    };
};

$priorityClass = static function (string $priority): string {
    return match ($priority) {
        'CRITICAL' => 'badge--critical',
        'HIGH'     => 'badge--high',
        'MEDIUM'   => 'badge--medium',
        default    => 'badge--low',
    };
};

$kpiCards = [
    ['label' => 'Requests (period)', 'value' => (string) (int) ($requests['total'] ?? 0), 'hint' => ucfirst($period) . ' window'],
    ['label' => 'Active in period',  'value' => (string) (int) ($requests['active'] ?? 0), 'hint' => 'Open work created in window'],
    ['label' => 'Resolved (all time)', 'value' => (string) (int) ($allTime['resolved'] ?? 0), 'hint' => 'Completed requests'],
    ['label' => 'Avg resolution',    'value' => $formatHours($resolution['average_hours'] ?? null), 'hint' => ((int) ($resolution['tracked'] ?? 0)) . ' timed resolutions'],
    ['label' => 'Available volunteers', 'value' => (string) (int) ($volunteers['available'] ?? 0), 'hint' => 'Verified + available'],
    ['label' => 'Accepted tasks',    'value' => (string) (int) ($volunteers['accepted'] ?? 0), 'hint' => 'Volunteer matches accepted'],
    ['label' => 'Completed tasks',   'value' => (string) (int) ($volunteers['completed'] ?? 0), 'hint' => 'Volunteer matches completed'],
    ['label' => 'Resources available', 'value' => (string) (int) ($resourceStats['available_quantity'] ?? 0), 'hint' => 'of ' . (int) ($resourceStats['total_quantity'] ?? 0) . ' units · ' . (int) ($resources['allocated'] ?? 0) . ' allocated'],
];
?>

<script type="application/json" data-analytics-charts><?= $chartJson ?></script>

<section class="page-head">
    <p class="eyebrow">Admin · Phase 11</p>
    <div class="page-head__row">
        <h1 class="page-title">Operational analytics</h1>
        <div class="request-card__badges">
            <a class="btn btn--ghost btn--small" href="<?= e(url('admin')) ?>">Control center</a>
            <a class="btn btn--ghost btn--small" href="<?= e(url('admin/activity')) ?>">Audit trail</a>
        </div>
    </div>
    <p class="page-subtitle">
        Request flow, responder activity and inventory health for the
        <strong><?= e($period) ?></strong> window
        <?php if (!empty($window['from_label']) && $window['from_label'] !== '—'): ?>
            (since <?= e((string) $window['from_label']) ?>)
        <?php endif; ?>.
        Charts are an enhancement — the numbers are also listed in the tables below.
    </p>
</section>

<section class="card" aria-label="Analytics period">
    <div class="chart-toolbar">
        <span class="muted">Period</span>
        <?php foreach ($periods as $option): ?>
            <?php $option = (string) $option; ?>
            <a
                class="btn btn--small <?= $option === $period ? 'btn--primary' : 'btn--ghost' ?>"
                href="<?= e(url('admin/analytics?period=' . $option)) ?>"
                <?= $option === $period ? 'aria-current="true"' : '' ?>
            ><?= e(ucfirst($option)) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<section class="grid grid--cards" aria-label="Key metrics">
    <?php foreach ($kpiCards as $card): ?>
        <article class="card stat">
            <span class="stat__label"><?= e((string) $card['label']) ?></span>
            <span class="stat__value"><?= e((string) $card['value']) ?></span>
            <span class="stat__meta muted"><?= e((string) $card['hint']) ?></span>
        </article>
    <?php endforeach; ?>
</section>

<div class="grid grid--two">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Requests over time</h2>
            <span class="badge badge--muted"><?= e((string) (int) ($overTime['total'] ?? 0)) ?> in window</span>
        </header>
        <div class="chart-frame" data-chart-frame>
            <canvas data-chart="over_time" role="img" aria-label="Line chart of requests over time"></canvas>
            <p class="chart-frame__error muted" data-chart-error hidden>Chart.js could not be loaded — see the data table below.</p>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Status distribution</h2>
            <span class="badge badge--muted"><?= e((string) count($byStatus)) ?> statuses</span>
        </header>
        <div class="chart-frame" data-chart-frame>
            <canvas data-chart="by_status" role="img" aria-label="Doughnut chart of requests by status"></canvas>
            <p class="chart-frame__error muted" data-chart-error hidden>Chart.js could not be loaded — see the data table below.</p>
        </div>
    </section>
</div>

<div class="grid grid--two">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Requests by category</h2>
        </header>
        <div class="chart-frame" data-chart-frame>
            <canvas data-chart="by_category" role="img" aria-label="Bar chart of requests by category"></canvas>
            <p class="chart-frame__error muted" data-chart-error hidden>Chart.js could not be loaded — see the data table below.</p>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Resource availability</h2>
            <span class="badge badge--muted"><?= e((string) (int) ($resourceStats['available'] ?? 0)) ?> available</span>
        </header>
        <div class="chart-frame" data-chart-frame>
            <canvas data-chart="resource_availability" role="img" aria-label="Bar chart of resource availability by type"></canvas>
            <p class="chart-frame__error muted" data-chart-error hidden>Chart.js could not be loaded — see the data table below.</p>
        </div>
    </section>
</div>

<div class="grid grid--two">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Resolution performance</h2>
        </header>
        <dl class="detail-list">
            <div class="detail-list__row"><dt>Resolved requests</dt><dd><?= e((string) (int) ($resolution['resolved'] ?? 0)) ?></dd></div>
            <div class="detail-list__row"><dt>Average resolution time</dt><dd><?= e($formatHours($resolution['average_hours'] ?? null)) ?></dd></div>
            <div class="detail-list__row"><dt>Fastest resolution</dt><dd><?= e($formatHours($resolution['fastest_hours'] ?? null)) ?></dd></div>
            <div class="detail-list__row"><dt>Slowest resolution</dt><dd><?= e($formatHours($resolution['slowest_hours'] ?? null)) ?></dd></div>
        </dl>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title">Responder &amp; inventory summary</h2>
        </header>
        <dl class="detail-list">
            <div class="detail-list__row"><dt>Volunteers available</dt><dd><?= e((string) (int) ($volunteers['available'] ?? 0)) ?></dd></div>
            <div class="detail-list__row"><dt>Volunteers pending verification</dt><dd><?= e((string) (int) ($volunteers['pending'] ?? 0)) ?></dd></div>
            <div class="detail-list__row"><dt>Accepted tasks</dt><dd><?= e((string) (int) ($volunteers['accepted'] ?? 0)) ?></dd></div>
            <div class="detail-list__row"><dt>Completed tasks</dt><dd><?= e((string) (int) ($volunteers['completed'] ?? 0)) ?></dd></div>
            <div class="detail-list__row"><dt>Allocated resource units</dt><dd><?= e((string) (int) ($resources['allocated'] ?? 0)) ?></dd></div>
            <div class="detail-list__row"><dt>Low-stock resources</dt><dd><?= e((string) (int) ($resourceStats['low_stock'] ?? 0)) ?></dd></div>
        </dl>
    </section>
</div>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Data</h2>
        <span class="badge badge--muted">Accessible fallback</span>
    </header>

    <div class="grid grid--two">
        <div>
            <h3 class="card__title">By category</h3>
            <?php if ($byCategory === []): ?>
                <p class="muted">No requests in this window.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th scope="col">Category</th><th scope="col">Requests</th></tr></thead>
                        <tbody>
                            <?php foreach ($byCategory as $category => $count): ?>
                                <tr><td><?= e($pretty((string) $category)) ?></td><td><?= e((string) (int) $count) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h3 class="card__title">By priority</h3>
            <?php if ($byPriority === []): ?>
                <p class="muted">No requests in this window.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th scope="col">Priority</th><th scope="col">Requests</th></tr></thead>
                        <tbody>
                            <?php foreach ($byPriority as $priority => $count): ?>
                                <tr>
                                    <td><span class="badge <?= e($priorityClass((string) $priority)) ?>"><?= e((string) $priority) ?></span></td>
                                    <td><?= e((string) (int) $count) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid--two" style="margin-top:1rem">
        <div>
            <h3 class="card__title">By status</h3>
            <?php if ($byStatus === []): ?>
                <p class="muted">No requests in this window.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th scope="col">Status</th><th scope="col">Requests</th></tr></thead>
                        <tbody>
                            <?php foreach ($byStatus as $status => $count): ?>
                                <tr>
                                    <td><span class="badge <?= e($statusClass((string) $status)) ?>"><?= e((string) $status) ?></span></td>
                                    <td><?= e((string) (int) $count) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h3 class="card__title">Resource availability by type</h3>
            <?php if ($availability['labels'] === []): ?>
                <p class="muted">No resources recorded.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th scope="col">Type</th><th scope="col">Available</th><th scope="col">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ((array) $availability['labels'] as $index => $label): ?>
                                <tr>
                                    <td><?= e((string) $label) ?></td>
                                    <td><?= e((string) (int) ($availability['available'][$index] ?? 0)) ?></td>
                                    <td><?= e((string) (int) ($availability['total'][$index] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
