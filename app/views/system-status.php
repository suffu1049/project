<?php
/**
 * System status page.
 *
 * @var string $title
 * @var array<int, array{label: string, ok: bool, hint: string}> $checks
 */
?>
<section class="page-head">
    <h1 class="page-title">System Status</h1>
    <p class="page-subtitle">Foundation diagnostics for the ResQLink runtime environment.</p>
</section>

<section class="card">
    <ul class="status-list">
        <?php foreach ($checks as $check): ?>
            <li class="status-row">
                <span class="status-row__label">
                    <span class="dot <?= $check['ok'] ? 'dot--ok' : 'dot--warn' ?>" aria-hidden="true"></span>
                    <?= e($check['label']) ?>
                </span>
                <span class="status-row__value">
                    <span class="badge <?= $check['ok'] ? 'badge--ok' : 'badge--warn' ?>">
                        <?= e($check['ok'] ? 'OK' : 'Attention') ?>
                    </span>
                    <span class="muted"><?= e($check['hint']) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card muted-card">
    <h2 class="card__title">Next steps</h2>
    <p>
        A failing database check is expected until Phase 2 configures MySQL. The application
        is designed to degrade gracefully — pages still render when the database is unavailable.
    </p>
</section>
