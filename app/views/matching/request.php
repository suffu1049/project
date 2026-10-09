<?php
/**
 * Request match panel — owner / admin view of stored matches + suggestions.
 *
 * @var array<string, mixed> $user
 * @var int $request_id
 * @var array<int, array<string, mixed>> $items
 * @var array<string, mixed> $meta
 * @var array<int, array<string, mixed>> $suggestions
 * @var array<string, mixed>|null $suggest_meta
 */
$user = $user ?? null;
$request_id = (int) ($request_id ?? 0);
$items = $items ?? [];
$meta = $meta ?? [];
$suggestions = $suggestions ?? [];
$suggest_meta = $suggest_meta ?? null;

$statusClass = static fn (string $status): string => match ($status) {
    'ACCEPTED'    => 'badge--ok',
    'COMPLETED'   => 'badge--info',
    'REJECTED'    => 'badge--muted',
    'EXPIRED'     => 'badge--muted',
    'NOTIFIED'    => 'badge--medium',
    default       => 'badge--warn',
};

$requestStatus = (string) ($meta['request_status'] ?? '');
$canSuggest = in_array($requestStatus, ['CREATED', 'VERIFIED', 'MATCHING', 'ESCALATED'], true)
    && in_array((string) ($user['role'] ?? ''), ['CITIZEN', 'ADMIN'], true);
?>
<section class="page-head">
    <p class="eyebrow">Matching · Request #<?= e((string) $request_id) ?></p>
    <div class="page-head__row">
        <h1 class="page-title">Matches &amp; suggestions</h1>
        <a class="btn btn--ghost" href="<?= e(url('requests/' . $request_id)) ?>">Back to request</a>
    </div>
    <p class="page-subtitle">
        Capability-first ranking (50% capability · 20% availability · 20% distance · 10% verification).
        Acceptance is transactional; duplicate active assignments are rejected.
    </p>
</section>

<div class="card">
    <dl class="detail-list">
        <div class="detail-list__row">
            <dt>Request status</dt>
            <dd><span class="badge <?= e($statusClass($requestStatus)) ?>"><?= e($requestStatus) ?></span></dd>
        </div>
        <div class="detail-list__row">
            <dt>Stored matches</dt>
            <dd><?= e((string) (int) ($meta['count'] ?? count($items))) ?></dd>
        </div>
        <?php if ($suggest_meta !== null): ?>
            <div class="detail-list__row">
                <dt>Suggestions</dt>
                <dd>
                    <?= e((string) (int) ($suggest_meta['volunteer_candidates'] ?? 0)) ?> volunteer(s),
                    <?= e((string) (int) ($suggest_meta['resource_candidates'] ?? 0)) ?> resource(s)
                    <?= !empty($suggest_meta['has_accepted_match']) ? ' · request already accepted' : '' ?>
                </dd>
            </div>
        <?php endif; ?>
    </dl>

    <?php if ($canSuggest): ?>
        <form method="post" action="<?= e(url('requests/' . $request_id . '/matches/suggest')) ?>" data-loading>
            <?= csrf_field() ?>
            <button class="btn btn--primary btn--small" type="submit">Regenerate suggestions</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($items === []): ?>
    <div class="card">
        <h2 class="card__title">No stored matches</h2>
        <p class="muted">
            Suggestion generation creates SUGGESTED match rows for eligible
            volunteers and verified resource stocks. Nothing has been created yet.
        </p>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $item): ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Match #<?= e((string) ($item['id'] ?? '')) ?></p>
                        <h2 class="request-card__title">
                            <?= e((string) ($item['type'] ?? 'MATCH')) ?>
                            <?php if (!empty($item['provider']['name'])): ?>
                                · <?= e((string) $item['provider']['name']) ?>
                            <?php elseif (!empty($item['provider']['resource_name'])): ?>
                                · <?= e((string) $item['provider']['resource_name']) ?>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($statusClass((string) ($item['status'] ?? ''))) ?>">
                            <?= e((string) ($item['status'] ?? '')) ?>
                        </span>
                        <span class="badge badge--info"><?= e(number_format((float) ($item['match_score'] ?? 0), 1)) ?> score</span>
                        <?php if (($item['distance_km'] ?? null) !== null): ?>
                            <span class="badge badge--muted"><?= e(number_format((float) $item['distance_km'], 1)) ?> km</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($item['reasons'])): ?>
                    <ul class="reason-list" aria-label="Match reasons">
                        <?php foreach ($item['reasons'] as $reason): ?>
                            <li><?= e((string) $reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <dl class="request-card__meta">
                    <div>
                        <dt>Capability</dt>
                        <dd><?= e(number_format((float) ($item['capability_score'] ?? 0), 0)) ?>/100</dd>
                    </div>
                    <?php if (!empty($item['provider']['skills'])): ?>
                        <div>
                            <dt>Skills</dt>
                            <dd><?= e(implode(', ', (array) $item['provider']['skills'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($item['provider']['availability'])): ?>
                        <div>
                            <dt>Availability</dt>
                            <dd><?= e((string) $item['provider']['availability']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($item['provider']['available_quantity'])): ?>
                        <div>
                            <dt>Stock</dt>
                            <dd><?= e((string) $item['provider']['available_quantity']) ?> / <?= e((string) ($item['provider']['quantity'] ?? '')) ?> <?= e((string) ($item['provider']['unit'] ?? '')) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <div class="request-card__actions">
                    <?php if (in_array((string) ($item['status'] ?? ''), ['SUGGESTED', 'NOTIFIED'], true) && in_array((string) ($user['role'] ?? ''), ['CITIZEN', 'ADMIN'], true)): ?>
                        <form method="post" action="<?= e(url('requests/' . $request_id . '/matches/' . (int) ($item['id'] ?? 0) . '/accept')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn--primary btn--small" type="submit">Accept</button>
                        </form>
                        <form method="post" action="<?= e(url('requests/' . $request_id . '/matches/' . (int) ($item['id'] ?? 0) . '/reject')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn--ghost btn--small" type="submit">Reject</button>
                        </form>
                    <?php elseif ((string) ($item['status'] ?? '') === 'ACCEPTED' && in_array((string) ($user['role'] ?? ''), ['CITIZEN', 'ADMIN'], true)): ?>
                        <form method="post" action="<?= e(url('requests/' . $request_id . '/matches/' . (int) ($item['id'] ?? 0) . '/complete')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn--primary btn--small" type="submit">Complete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($suggestions !== []): ?>
    <section aria-label="Current suggestions">
        <h2 class="card__title">Current suggestions</h2>
        <p class="muted">Ranked candidates for this request. Scores are deterministic — no AI override.</p>
        <div class="request-list" role="list">
            <?php foreach ($suggestions as $item): ?>
                <article class="card request-card" role="listitem">
                    <div class="request-card__top">
                        <div>
                            <p class="request-card__id">
                                <?= e((string) ($item['type'] ?? '')) ?>
                                <?= !empty($item['id']) ? ' · match #' . e((string) $item['id']) : ' · unsaved' ?>
                            </p>
                            <h2 class="request-card__title"><?= e((string) ($item['name'] ?? '')) ?></h2>
                        </div>
                        <div class="request-card__badges">
                            <span class="badge badge--info"><?= e(number_format((float) ($item['match_score'] ?? 0), 1)) ?> score</span>
                            <?php if (($item['distance_km'] ?? null) !== null): ?>
                                <span class="badge badge--muted"><?= e(number_format((float) $item['distance_km'], 1)) ?> km</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($item['reasons'])): ?>
                        <ul class="reason-list">
                            <?php foreach ($item['reasons'] as $reason): ?>
                                <li><?= e((string) $reason) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
