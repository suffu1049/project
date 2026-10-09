<?php
/**
 * Top navigation component — role aware.
 *
 * Guests see Sign in / Register; signed-in users see their dashboard,
 * account, notifications, map, and a server-side logout (POST + CSRF).
 */
use App\Models\NotificationRepository;

$user = auth_user();
$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isActive = static fn (string $path): string => rtrim($current, '/') === rtrim($path, '/') ? 'is-active' : '';

$unread = 0;

if ($user !== null) {
    try {
        $unread = NotificationRepository::unreadCount((int) $user['id']);
    } catch (\Throwable) {
        $unread = 0;
    }
}
?>
<header class="navbar">
    <div class="container navbar__inner">
        <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e(config('app.name')) ?> home">
            <span class="brand__mark" aria-hidden="true">Q</span>
            <span class="brand__text">
                <strong><?= e(config('app.name')) ?></strong>
                <small>Emergency Coordination</small>
            </span>
        </a>

        <button
            class="navbar__toggle"
            type="button"
            aria-expanded="false"
            aria-controls="primary-navigation"
            aria-label="Toggle navigation"
            data-nav-toggle
        >
            <span></span><span></span><span></span>
        </button>

        <nav id="primary-navigation" class="navbar__nav" aria-label="Primary">
            <a class="nav-link <?= e($isActive('/')) ?>" href="<?= e(url('/')) ?>">Home</a>
            <a class="nav-link <?= e($isActive('/system-status')) ?>" href="<?= e(url('/system-status')) ?>">System Status</a>

            <?php if ($user !== null): ?>
                <?php $home = url(role_home((string) ($user['role'] ?? ''))); ?>
                <a class="nav-link <?= e($isActive(parse_url($home, PHP_URL_PATH) ?: '/')) ?>" href="<?= e($home) ?>">Dashboard</a>
                <?php if (in_array((string) ($user['role'] ?? ''), ['CITIZEN', 'ADMIN'], true)): ?>
                    <a class="nav-link <?= e($isActive('/requests')) ?>" href="<?= e(url('requests')) ?>">Requests</a>
                <?php endif; ?>
                <?php if ((string) ($user['role'] ?? '') === 'VOLUNTEER'): ?>
                    <a class="nav-link <?= e($isActive('/volunteer/requests')) ?>" href="<?= e(url('volunteer/requests')) ?>">Nearby</a>
                    <a class="nav-link <?= e($isActive('/volunteer/matches')) ?>" href="<?= e(url('volunteer/matches')) ?>">Matches</a>
                    <a class="nav-link <?= e($isActive('/volunteer/profile')) ?>" href="<?= e(url('volunteer/profile')) ?>">Profile</a>
                <?php endif; ?>
                <?php if ((string) ($user['role'] ?? '') === 'ORGANIZATION'): ?>
                    <a class="nav-link <?= e($isActive('/organization/dashboard')) ?>" href="<?= e(url('organization/dashboard')) ?>">Inventory</a>
                    <a class="nav-link <?= e($isActive('/organization/profile')) ?>" href="<?= e(url('organization/profile')) ?>">Organization</a>
                <?php endif; ?>
                <?php if ((string) ($user['role'] ?? '') === 'ADMIN'): ?>
                    <a class="nav-link <?= e($isActive('/admin/requests')) ?>" href="<?= e(url('admin/requests')) ?>">Queue</a>
                    <a class="nav-link <?= e($isActive('/admin/resources')) ?>" href="<?= e(url('admin/resources')) ?>">Resources</a>
                    <a class="nav-link <?= e($isActive('/admin/volunteers')) ?>" href="<?= e(url('admin/volunteers')) ?>">Volunteers</a>
                    <a class="nav-link <?= e($isActive('/admin/organizations')) ?>" href="<?= e(url('admin/organizations')) ?>">Organizations</a>
                    <a class="nav-link <?= e($isActive('/admin/activity')) ?>" href="<?= e(url('admin/activity')) ?>">Activity</a>
                    <a class="nav-link <?= e($isActive('/admin/analytics')) ?>" href="<?= e(url('admin/analytics')) ?>">Analytics</a>
                <?php endif; ?>

                <a class="nav-link <?= e($isActive('/map')) ?>" href="<?= e(url('map')) ?>">Map</a>
                <a class="nav-link nav-link--badge <?= e($isActive('/notifications')) ?>" href="<?= e(url('notifications')) ?>" data-unread-link>
                    Notifications
                    <span class="nav-badge" data-unread-badge <?= $unread === 0 ? 'hidden' : '' ?>>
                        <?= $unread > 99 ? '99+' : (string) $unread ?>
                    </span>
                </a>
                <a class="nav-link <?= e($isActive('/account')) ?>" href="<?= e(url('account')) ?>">Account</a>

                <span class="badge badge--role" title="Signed in as <?= e($user['role'] ?? 'USER') ?>">
                    <?= e($user['role'] ?? 'USER') ?>
                </span>

                <form class="nav-form" method="post" action="<?= e(url('logout')) ?>" data-loading>
                    <?= csrf_field() ?>
                    <button class="btn btn--nav" type="submit">Sign out</button>
                </form>
            <?php else: ?>
                <a class="nav-link" href="<?= e(url('login')) ?>">Sign in</a>
                <a class="btn btn--nav btn--accent" href="<?= e(url('register')) ?>">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
