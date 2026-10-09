<?php
/**
 * Admin organization verification queue (Phase 6).
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
?>
<section class="page-head">
    <p class="eyebrow">Admin</p>
    <div class="page-head__row">
        <h1 class="page-title">Organization verification</h1>
        <a class="btn btn--ghost" href="<?= e(url('admin')) ?>">Control center</a>
    </div>
    <p class="page-subtitle">
        Verified organizations can add resources and allocate them to emergency
        requests. Rejection reasons are stored in the audit trail.
    </p>
</section>

<div class="card">
    <form method="get" action="<?= e(url('admin/organizations')) ?>" class="form form--inline" data-loading>
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
        <h2 class="card__title">No organizations match this filter</h2>
        <p class="muted">Try a different verification status.</p>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $item): ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Organization #<?= e((string) $item['id']) ?></p>
                        <h2 class="request-card__title"><?= e((string) $item['name']) ?></h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($verificationClass((string) $item['verification_status'])) ?>">
                            <?= e((string) $item['verification_status']) ?>
                        </span>
                        <span class="badge badge--info"><?= e(str_replace('_', ' ', (string) $item['type'])) ?></span>
                    </div>
                </div>

                <p class="request-card__excerpt muted">
                    Account: <?= e((string) $item['user_name']) ?>
                    · Contact: <?= e((string) ($item['contact'] ?? '—')) ?>
                    · Location: <?= !empty($item['has_location']) ? 'On file' : 'Not set' ?>
                </p>

                <dl class="request-card__meta">
                    <div>
                        <dt>Resources</dt>
                        <dd><?= e((string) (int) $item['resource_count']) ?></dd>
                    </div>
                    <div>
                        <dt>Total units</dt>
                        <dd><?= e(number_format((int) $item['total_quantity'])) ?></dd>
                    </div>
                    <div>
                        <dt>Available</dt>
                        <dd><?= e(number_format((int) $item['available_quantity'])) ?></dd>
                    </div>
                </dl>

                <div class="form-actions">
                    <?php if ((string) $item['verification_status'] !== 'VERIFIED'): ?>
                        <form method="post" action="<?= e(url('admin/organizations/' . (int) $item['id'] . '/verify')) ?>" data-loading data-confirm="Verify this organization?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="verification_status" value="VERIFIED">
                            <button class="btn btn--primary btn--small" type="submit">Verify</button>
                        </form>
                    <?php endif; ?>
                    <?php if ((string) $item['verification_status'] !== 'REJECTED'): ?>
                        <form method="post" action="<?= e(url('admin/organizations/' . (int) $item['id'] . '/verify')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <input type="hidden" name="verification_status" value="REJECTED">
                            <input
                                class="input"
                                type="text"
                                name="reason"
                                maxlength="500"
                                placeholder="Rejection reason (optional)"
                                aria-label="Rejection reason"
                            >
                            <button class="btn btn--ghost btn--small" type="submit" data-confirm="Reject this organization?">
                                Reject
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>