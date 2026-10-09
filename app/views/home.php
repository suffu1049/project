<?php
/**
 * Landing page — modern product hero + live runtime status.
 *
 * @var string $title
 * @var string $date
 * @var string $database
 * @var array<int, string> $foundation
 */
$dbOk = $database === 'available';
?>
<section class="hero">
    <p class="eyebrow">Emergency coordination platform</p>
    <h1 class="hero__title">Coordinate help, faster.</h1>
    <p class="hero__lead">
        ResQLink connects people who need emergency assistance with nearby volunteers,
        organizations and available resources — in a transparent, trackable workflow.
    </p>

    <div class="hero__actions">
        <a class="btn btn--primary" href="<?= e(url('login')) ?>">Sign in to coordinate</a>
        <a class="btn btn--ghost" href="<?= e(url('register')) ?>">Create an account</a>
        <a class="btn btn--ghost" href="<?= e(url('system-status')) ?>">System status</a>
    </div>
</section>

<section class="grid grid--cards" aria-label="Foundation status">
    <article class="card stat">
        <span class="stat__label">Runtime</span>
        <span class="stat__value">PHP <?= e(PHP_VERSION) ?></span>
        <span class="stat__meta">Requirement: 8.1+</span>
    </article>

    <article class="card stat">
        <span class="stat__label">Database</span>
        <span class="stat__value">
            <span class="dot <?= $dbOk ? 'dot--ok' : 'dot--warn' ?>" aria-hidden="true"></span>
            <?= e($dbOk ? 'Available' : 'Not connected') ?>
        </span>
        <span class="stat__meta">MySQL · migrations · seed data</span>
    </article>

    <article class="card stat">
        <span class="stat__label">Environment</span>
        <span class="stat__value"><?= e(strtoupper((string) config('app.env', 'production'))) ?></span>
        <span class="stat__meta">Debug: <?= config('app.debug') ? 'on' : 'off' ?></span>
    </article>
</section>

<section class="card">
    <header class="card__header">
        <h2 class="card__title">Foundation established</h2>
        <span class="muted">Generated <?= e($date) ?> UTC</span>
    </header>

    <ul class="checklist">
        <?php foreach ($foundation as $item): ?>
            <li class="checklist__item">
                <span class="checklist__mark" aria-hidden="true">✓</span>
                <span><?= e($item) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card muted-card">
    <h2 class="card__title">Important</h2>
    <p class="muted" style="margin-bottom:0">
        ResQLink is a coordination and information platform. It does <strong>not</strong> replace
        police, fire, ambulance or disaster-management authorities. In a real emergency,
        contact your local emergency number immediately.
    </p>
</section>
