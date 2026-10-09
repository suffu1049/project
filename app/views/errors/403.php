<?php
/**
 * Forbidden (403) page — the user is signed in but not allowed here.
 *
 * @var int $status
 * @var string $message
 */
$status = $status ?? 403;
$message = $message ?? 'You do not have permission to access this area.';
?>
<section class="error-page">
    <p class="error-page__code"><?= e((string) $status) ?></p>
    <h1 class="error-page__title">Access denied</h1>
    <p class="error-page__message"><?= e($message) ?></p>

    <div class="error-page__actions">
        <a class="btn btn--primary" href="<?= e(url('')) ?>">Back to home</a>
        <?php if (auth_user() !== null): ?>
            <a class="btn btn--ghost" href="<?= e(url(role_home((string) (auth_user()['role'] ?? '')))) ?>">My dashboard</a>
        <?php else: ?>
            <a class="btn btn--ghost" href="<?= e(url('login')) ?>">Sign in</a>
        <?php endif; ?>
    </div>
</section>
