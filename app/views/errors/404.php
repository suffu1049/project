<?php
/** @var int $status */
/** @var string $message */
$status = $status ?? 404;
$message = $message ?? 'Page not found.';
?>
<section class="error-page">
    <p class="error-page__code"><?= e((string) $status) ?></p>
    <h1 class="error-page__title">Page not found</h1>
    <p class="error-page__message"><?= e($message) ?></p>
    <div class="error-page__actions">
        <a class="btn btn--primary" href="<?= e(url('/')) ?>">Back to home</a>
        <a class="btn btn--ghost" href="<?= e(url('system-status')) ?>">System status</a>
    </div>
</section>
