<?php
/**
 * Account page — shows only the authenticated user's own record.
 *
 * @var array<string, mixed>|null $user
 */
$user = $user ?? null;
$joined = $user['created_at'] ?? null;
?>
<section class="page-head">
    <h1 class="page-title">My account</h1>
    <p class="page-subtitle">Your profile and session details.</p>
</section>

<section class="grid grid--cards">
    <article class="card">
        <header class="card__header">
            <h2 class="card__title">Profile</h2>
            <span class="badge badge--role"><?= e($user['role'] ?? 'USER') ?></span>
        </header>

        <dl class="detail-list">
            <div class="detail-list__row">
                <dt>Name</dt>
                <dd><?= e($user['name'] ?? '—') ?></dd>
            </div>
            <div class="detail-list__row">
                <dt>Email</dt>
                <dd><?= e($user['email'] ?? '—') ?></dd>
            </div>
            <div class="detail-list__row">
                <dt>Phone</dt>
                <dd><?= e($user['phone'] ?? 'Not provided') ?></dd>
            </div>
            <div class="detail-list__row">
                <dt>Status</dt>
                <dd>
                    <span class="badge <?= ($user['status'] ?? '') === 'ACTIVE' ? 'badge--ok' : 'badge--warn' ?>">
                        <?= e($user['status'] ?? 'UNKNOWN') ?>
                    </span>
                </dd>
            </div>
            <div class="detail-list__row">
                <dt>Member since</dt>
                <dd><?= e($joined !== null ? date('d M Y', strtotime((string) $joined)) : '—') ?></dd>
            </div>
            <div class="detail-list__row">
                <dt>User ID</dt>
                <dd>#<?= e((string) ($user['id'] ?? '—')) ?></dd>
            </div>
        </dl>
    </article>

    <article class="card muted-card">
        <h2 class="card__title">Security</h2>
        <p class="muted">
            Your password is stored only as a one-way hash. Sessions are regenerated on
            sign-in and sign-out, and state-changing actions require a CSRF token.
        </p>
        <a class="btn btn--ghost" href="<?= e(url(role_home((string) ($user['role'] ?? '')))) ?>">Back to dashboard</a>
    </article>
</section>
