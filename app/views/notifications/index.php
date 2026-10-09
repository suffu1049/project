<?php
/**
 * Notification centre — unread/read list with mark-read actions (Phase 8).
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $items
 * @var array<string, mixed> $meta
 * @var bool $unreadOnly
 */
$user = $user ?? null;
$items = $items ?? [];
$meta = $meta ?? [];
$unreadOnly = (bool) ($unreadOnly ?? false);

$unread = (int) ($meta['unread_count'] ?? 0);
$total = (int) ($meta['total'] ?? 0);
$page = max(1, (int) ($meta['page'] ?? 1));
$pages = (int) ($meta['pages'] ?? 0);

$typeBadge = static function (string $type): string {
    return match ($type) {
        'MATCH_FOUND'         => 'badge--medium',
        'MATCH_ACCEPTED'      => 'badge--ok',
        'REQUEST_CREATED'     => 'badge--warn',
        'STATUS_CHANGED'      => 'badge--info',
        'RESOURCE_ALLOCATED'  => 'badge--info',
        'REQUEST_RESOLVED'    => 'badge--ok',
        'ESCALATED'           => 'badge--critical',
        default               => 'badge--muted',
    };
};
?>
<section class="page-head">
    <p class="eyebrow">Stay informed</p>
    <div class="page-head__row">
        <h1 class="page-title">Notifications</h1>
        <div class="request-card__badges">
            <span class="badge <?= $unread > 0 ? 'badge--warn' : 'badge--muted' ?>">
                <?= $unread ?> unread
            </span>
            <span class="badge badge--muted"><?= $total ?> total</span>
        </div>
    </div>
    <p class="page-subtitle">
        In-app alerts for matches, status changes and resource allocations.
        This page polls every few seconds when open.
    </p>
    <div class="hero__actions" style="margin-top:0.75rem">
        <a class="btn btn--ghost btn--small <?= $unreadOnly ? '' : 'is-active' ?>" href="<?= e(url('notifications')) ?>">All</a>
        <a class="btn btn--ghost btn--small <?= $unreadOnly ? 'is-active' : '' ?>" href="<?= e(url('notifications?unread=1')) ?>">Unread only</a>
        <form method="post" action="<?= e(url('notifications/read-all')) ?>" class="nav-form" data-loading>
            <?= csrf_field() ?>
            <button class="btn btn--primary btn--small" type="submit" <?= $unread === 0 ? 'disabled' : '' ?>>
                Mark all read
            </button>
        </form>
        <a class="btn btn--ghost btn--small" href="<?= e(url('map')) ?>">Open map</a>
    </div>
</section>

<section class="card" data-notifications-root data-unread-badge-host>
    <?php if ($items === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true">🔔</div>
            <h2 class="empty-state__title">You're all caught up</h2>
            <p class="empty-state__message">No notifications to show right now.</p>
        </div>
    <?php else: ?>
        <ul class="notification-list">
            <?php foreach ($items as $item): ?>
                <li class="notification-item <?= empty($item['read_status']) ? 'is-unread' : '' ?>">
                    <div class="notification-item__body">
                        <p class="notification-item__title">
                            <span class="badge <?= e($typeBadge((string) $item['type'])) ?>"><?= e((string) $item['type']) ?></span>
                            <?= e((string) $item['title']) ?>
                            <?php if (empty($item['read_status'])): ?>
                                <span class="dot dot--warn" title="Unread" aria-label="Unread"></span>
                            <?php endif; ?>
                        </p>
                        <p class="notification-item__message"><?= e((string) $item['message']) ?></p>
                        <p class="notification-item__meta muted">
                            <?= e(date('d M Y, H:i', strtotime((string) $item['created_at']))) ?>
                            <?php if (!empty($item['related_entity_type']) && !empty($item['related_entity_id'])): ?>
                                · <?= e((string) $item['related_entity_type']) ?> #<?= e((string) (int) $item['related_entity_id']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="notification-item__actions">
                        <?php if (empty($item['read_status'])): ?>
                            <form method="post" action="<?= e(url('notifications/' . (int) $item['id'] . '/read')) ?>" data-loading>
                                <?= csrf_field() ?>
                                <button class="btn btn--ghost btn--small" type="submit">Mark read</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge--ok">Read</span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Notification pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn--ghost btn--small" href="<?= e(url('notifications?page=' . ($page - 1) . ($unreadOnly ? '&unread=1' : ''))) ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn--ghost btn--small" href="<?= e(url('notifications?page=' . ($page + 1) . ($unreadOnly ? '&unread=1' : ''))) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
