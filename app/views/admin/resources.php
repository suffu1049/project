<?php
/**
 * Admin resource overview — inventory totals, per-type summary and shortages.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $data
 */
$user = $user ?? null;
$data = $data ?? [];

$summary = (array) ($data['summary'] ?? []);
$stats = (array) ($data['stats'] ?? []);
$items = (array) ($data['items'] ?? []);
$shortages = (array) ($data['shortages'] ?? []);

$statusClass = static function (string $status): string {
    return match ($status) {
        'AVAILABLE'   => 'badge--ok',
        'LIMITED'     => 'badge--warn',
        default       => 'badge--critical',
    };
};
?>
<section class="page-head">
    <p class="eyebrow">Admin · Inventory</p>
    <div class="page-head__row">
        <h1 class="page-title">Resource overview</h1>
        <a class="btn btn--ghost btn--small" href="<?= e(url('admin')) ?>">Control center</a>
    </div>
    <p class="page-subtitle">
        Platform-wide inventory contributed by verified organizations. Highlighted
        rows are limited, unavailable or below 20% availability.
    </p>
</section>

<section class="grid grid--cards" aria-label="Inventory totals">
    <article class="card stat">
        <span class="stat__label">Resources</span>
        <span class="stat__value"><?= e((string) (int) ($stats['total_resources'] ?? 0)) ?></span>
        <span class="stat__meta muted">Registered items</span>
    </article>
    <article class="card stat">
        <span class="stat__label">Total units</span>
        <span class="stat__value"><?= e((string) (int) ($stats['total_quantity'] ?? 0)) ?></span>
        <span class="stat__meta muted">All stock</span>
    </article>
    <article class="card stat stat--ok">
        <span class="stat__label">Available units</span>
        <span class="stat__value"><?= e((string) (int) ($stats['available_quantity'] ?? 0)) ?></span>
        <span class="stat__meta muted">Ready to allocate</span>
    </article>
    <article class="card stat stat--high">
        <span class="stat__label">Low stock</span>
        <span class="stat__value"><?= e((string) (int) ($stats['low_stock'] ?? 0)) ?></span>
        <span class="stat__meta muted">≤ 20% availability</span>
    </article>
</section>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">By resource type</h2>
    </header>
    <?php if ($summary === []): ?>
        <p class="muted">No resources registered.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Total</th>
                        <th scope="col">Available</th>
                        <th scope="col">Availability</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary as $type => $totals): ?>
                        <?php
                        $typeTotal = (int) ($totals['total'] ?? 0);
                        $typeAvailable = (int) ($totals['available'] ?? 0);
                        $ratio = $typeTotal > 0 ? (int) round(($typeAvailable / $typeTotal) * 100) : 0;
                        ?>
                        <tr>
                            <td><?= e(str_replace('_', ' ', (string) $type)) ?></td>
                            <td><?= e((string) $typeTotal) ?></td>
                            <td><?= e((string) $typeAvailable) ?></td>
                            <td>
                                <span class="badge <?= $ratio <= 20 ? 'badge--critical' : ($ratio <= 50 ? 'badge--warn' : 'badge--ok') ?>">
                                    <?= e((string) $ratio) ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Shortages</h2>
        <span class="badge badge--critical"><?= e((string) count($shortages)) ?></span>
    </header>
    <?php if ($shortages === []): ?>
        <p class="muted">No shortages detected.</p>
    <?php else: ?>
        <ul class="notification-list">
            <?php foreach ($shortages as $resource): ?>
                <li class="notification-item">
                    <div class="notification-item__body">
                        <p class="notification-item__title">
                            <?= e((string) $resource['resource_name']) ?>
                            <span class="badge <?= e($statusClass((string) $resource['status'])) ?>"><?= e((string) $resource['status']) ?></span>
                        </p>
                        <p class="notification-item__message muted">
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

<section class="card">
    <header class="card__header">
        <h2 class="card__title">All resources</h2>
        <span class="muted"><?= e((string) count($items)) ?> shown</span>
    </header>
    <?php if ($items === []): ?>
        <p class="muted">No resources registered.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Resource</th>
                        <th scope="col">Organization</th>
                        <th scope="col">Type</th>
                        <th scope="col">Available / Total</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $resource): ?>
                        <tr>
                            <td><?= e((string) $resource['resource_name']) ?></td>
                            <td><?= e((string) $resource['organization_name']) ?></td>
                            <td><?= e(str_replace('_', ' ', (string) $resource['resource_type'])) ?></td>
                            <td><?= e((string) (int) $resource['available_quantity']) ?> / <?= e((string) (int) $resource['quantity']) ?></td>
                            <td><span class="badge <?= e($statusClass((string) $resource['status'])) ?>"><?= e((string) $resource['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
