<?php
/**
 * Shared role dashboard landing.
 *
 * @var string $heading
 * @var string $role
 * @var array<string, mixed>|null $user
 * @var array<int, array{title: string, body: string}> $cards
 */
$heading = $heading ?? 'Dashboard';
$role = $role ?? 'CITIZEN';
$user = $user ?? null;
$cards = $cards ?? [];
?>
<section class="page-head">
    <p class="eyebrow"><?= e($role) ?></p>
    <h1 class="page-title">
        <?= e($heading) ?><?= $user !== null ? ', ' . e($user['name'] ?? '') : '' ?>
    </h1>
    <p class="page-subtitle">
        You are signed in as <strong><?= e($user['role'] ?? $role) ?></strong>
        (<?= e($user['email'] ?? '') ?>).
    </p>
</section>

<section class="grid grid--cards" aria-label="Capabilities">
    <?php foreach ($cards as $card): ?>
        <article class="card">
            <h2 class="card__title"><?= e($card['title']) ?></h2>
            <p class="muted"><?= e($card['body']) ?></p>
            <?php if (!empty($card['href'])): ?>
                <a class="btn btn--ghost btn--small" href="<?= e($card['href']) ?>">
                    <?= e($card['action'] ?? 'Open') ?>
                </a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <article class="card">
        <h2 class="card__title">Your session</h2>
        <dl class="detail-list">
            <div class="detail-list__row">
                <dt>Role</dt>
                <dd><span class="badge badge--role"><?= e($role) ?></span></dd>
            </div>
            <div class="detail-list__row">
                <dt>Account status</dt>
                <dd>
                    <span class="badge <?= ($user['status'] ?? '') === 'ACTIVE' ? 'badge--ok' : 'badge--warn' ?>">
                        <?= e($user['status'] ?? 'UNKNOWN') ?>
                    </span>
                </dd>
            </div>
        </dl>
        <a class="btn btn--ghost" href="<?= e(url('account')) ?>">View account</a>
    </article>
</section>

<?php if ($role === 'ADMIN'): ?>
    <section class="card muted-card">
        <h2 class="card__title">Operational queue</h2>
        <p class="muted">
            Emergency requests, volunteer verification, organization verification
            and the smart matching engine are ready. Maps, notifications, analytics
            and the full control center arrive in later phases.
        </p>
        <a class="btn btn--ghost btn--small" href="<?= e(url('requests')) ?>">Open request queue</a>
    </section>
<?php endif; ?>
