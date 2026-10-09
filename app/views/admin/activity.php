<?php
/**
 * Admin activity feed + audit trail (Phase 9).
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $events
 * @var array{items: array<int, array>, pagination: array<string, int>} $audit
 */
$user = $user ?? null;
$events = $events ?? [];
$audit = $audit ?? ['items' => [], 'pagination' => []];
$auditItems = (array) ($audit['items'] ?? []);
$pagination = (array) ($audit['pagination'] ?? []);
$page = max(1, (int) ($pagination['page'] ?? 1));
$pages = (int) ($pagination['pages'] ?? 0);

$actionClass = static function (string $action): string {
    return match (true) {
        str_contains($action, 'REJECT') || str_contains($action, 'ESCALATE') => 'badge--critical',
        str_contains($action, 'VERIFY')  => 'badge--ok',
        str_contains($action, 'ALLOCATE') || str_contains($action, 'REPLENISH') => 'badge--info',
        str_contains($action, 'ACCEPT')  => 'badge--medium',
        default                          => 'badge--muted',
    };
};
?>
<section class="page-head">
    <p class="eyebrow">Admin · Traceability</p>
    <div class="page-head__row">
        <h1 class="page-title">Activity &amp; audit</h1>
        <a class="btn btn--ghost btn--small" href="<?= e(url('admin')) ?>">Control center</a>
    </div>
    <p class="page-subtitle">
        Operational timeline events across every request, plus the immutable audit
        trail of important administrative actions.
    </p>
</section>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Recent request activity</h2>
        <span class="badge badge--muted"><?= e((string) count($events)) ?> events</span>
    </header>
    <?php if ($events === []): ?>
        <p class="muted">No recent events.</p>
    <?php else: ?>
        <ul class="notification-list">
            <?php foreach ($events as $event): ?>
                <li class="notification-item">
                    <div class="notification-item__body">
                        <p class="notification-item__title">
                            <?= e(str_replace('_', ' ', (string) $event['event_type'])) ?>
                            <span class="muted">· request #<?= e((string) $event['request_id']) ?></span>
                            <span class="badge badge--muted"><?= e((string) ($event['request_category'] ?? '')) ?></span>
                        </p>
                        <p class="notification-item__message muted">
                            <?= e((string) ($event['actor_name'] ?? 'System')) ?>
                            <?php if (!empty($event['old_status']) || !empty($event['new_status'])): ?>
                                · <?= e((string) ($event['old_status'] ?? '—')) ?> → <?= e((string) ($event['new_status'] ?? '—')) ?>
                            <?php endif; ?>
                            <?php if (!empty($event['note'])): ?>
                                · <?= e(mb_strimwidth((string) $event['note'], 0, 90, '…')) ?>
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

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Audit trail</h2>
        <span class="badge badge--muted"><?= e((string) (int) ($pagination['total'] ?? 0)) ?> entries</span>
    </header>
    <?php if ($auditItems === []): ?>
        <p class="muted">No audit entries.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Entity</th>
                        <th scope="col">Metadata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditItems as $entry): ?>
                        <tr>
                            <td><?= e(date('d M Y, H:i', strtotime((string) $entry['created_at']))) ?></td>
                            <td><?= e((string) ($entry['actor_name'] ?? 'System')) ?></td>
                            <td><span class="badge <?= e($actionClass((string) $entry['action'])) ?>"><?= e((string) $entry['action']) ?></span></td>
                            <td>
                                <?= e((string) ($entry['entity_type'] ?? '—')) ?>
                                <?php if (!empty($entry['entity_id'])): ?>#<?= e((string) (int) $entry['entity_id']) ?><?php endif; ?>
                            </td>
                            <td class="muted"><?= e(mb_strimwidth((string) ($entry['metadata'] ?? ''), 0, 80, '…')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Audit pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn--ghost btn--small" href="<?= e(url('admin/activity?page=' . ($page - 1))) ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn--ghost btn--small" href="<?= e(url('admin/activity?page=' . ($page + 1))) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
