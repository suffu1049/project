<?php
/**
 * @var int $status
 * @var string $message
 * @var \Throwable|null $exception  Present only when APP_DEBUG is true.
 */
$status = $status ?? 500;
$message = $message ?? 'Something went wrong. Please try again.';
$exception = $exception ?? null;
?>
<section class="error-page">
    <p class="error-page__code"><?= e((string) $status) ?></p>
    <h1 class="error-page__title">Something went wrong</h1>
    <p class="error-page__message"><?= e($message) ?></p>

    <div class="error-page__actions">
        <a class="btn btn--primary" href="<?= e(url('/')) ?>">Back to home</a>
    </div>

    <?php if ($exception instanceof \Throwable): ?>
        <details class="debug-panel">
            <summary>Debug details (development only)</summary>
            <p><strong><?= e(get_class($exception)) ?></strong></p>
            <p><?= e($exception->getMessage()) ?></p>
            <p class="muted"><?= e($exception->getFile()) ?>:<?= e((string) $exception->getLine()) ?></p>
            <pre class="debug-trace"><?= e($exception->getTraceAsString()) ?></pre>
        </details>
    <?php endif; ?>
</section>
