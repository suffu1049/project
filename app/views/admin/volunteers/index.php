<?php
/**
 * Admin volunteer verification queue.
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $items
 * @var string|null $filter
 */
$user = $user ?? null;
$items = $items ?? [];
$filter = $filter;

$verificationClass = static fn (string $status): string => match ($status) {
    'VERIFIED' => 'badge--ok',
    'REJECTED' => 'badge--critical',
    default    => 'badge--warn',
};

$availabilityClass = static fn (string $status): string => match ($status) {
    'AVAILABLE' => 'badge--ok',
    'BUSY'      => 'badge--warn',
    default     => 'badge--muted',
};
?>
<section class="page-head">
    <p class="eyebrow">Admin</p>
    <div class="page-head__row">
        <h1 class="page-title">Volunteer verification</h1>
        <a class="btn btn--ghost" href="<?= e(url('admin')) ?>">Control center</a>
    </div>
    <p class="page-subtitle">
        Review volunteer profiles and set verification status. Only verified,
        available volunteers are treated as match candidates.
    </p>
</section>

<div class="card">
    <form method="get" action="<?= e(url('admin/volunteers')) ?>" class="form form--inline" data-loading>
        <div class="field">
            <label class="label" for="verification_status">Verification</label>
            <select class="input" id="verification_status" name="verification_status">
                <option value="">All</option>
                <?php foreach (['PENDING', 'VERIFIED', 'REJECTED'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= ($filter ?? '') === $status ? 'selected' : '' ?>>
                        <?= e($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label">&nbsp;</label>
            <button class="btn btn--ghost" type="submit">Filter</button>
        </div>
    </form>
</div>

<?php if ($items === []): ?>
    <div class="card">
        <h2 class="card__title">No volunteers match this filter</h2>
        <p class="muted">Try a different verification status.</p>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $item): ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Profile #<?= e((string) $item['id']) ?></p>
                        <h2 class="request-card__title"><?= e((string) $item['name']) ?></h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($verificationClass((string) $item['verification_status'])) ?>">
                            <?= e((string) $item['verification_status']) ?>
                        </span>
                        <span class="badge <?= e($availabilityClass((string) $item['availability'])) ?>">
                            <?= e((string) $item['availability']) ?>
                        </span>
                    </div>
                </div>

                <p class="request-card__excerpt muted">
                    Skills: <?= ($item['skills'] ?? []) === [] ? 'None' : e(implode(', ', $item['skills'])) ?>
                    · Vehicle: <?= e((string) ($item['vehicle'] ?? 'NONE')) ?>
                    · Radius: <?= e(number_format((float) ($item['service_radius_km'] ?? 0), 1)) ?> km
                    · Location: <?= !empty($item['has_location']) ? 'On file' : 'Not set' ?>
                </p>

                <div class="form-actions">
                    <?php if ((string) $item['verification_status'] !== 'VERIFIED'): ?>
                        <form method="post" action="<?= e(url('admin/volunteers/' . (int) $item['id'] . '/verify')) ?>" data-loading data-confirm="Verify this volunteer?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="verification_status" value="VERIFIED">
                            <button class="btn btn--primary btn--small" type="submit">Verify</button>
                        </form>
                    <?php endif; ?>
                    <?php if ((string) $item['verification_status'] !== 'REJECTED'): ?>
                        <form method="post" action="<?= e(url('admin/volunteers/' . (int) $item['id'] . '/verify')) ?>" data-loading data-confirm="Reject this volunteer profile?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="verification_status" value="REJECTED">
                            <button class="btn btn--ghost btn--small" type="submit">Reject</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
