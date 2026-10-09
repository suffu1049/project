<?php
/**
 * Volunteer match inbox — Phase 7 matching engine.
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $items
 * @var string|null $filter
 */
$user = $user ?? null;
$items = $items ?? [];
$filter = $filter ?? null;

$statusClass = static fn (string $status): string => match ($status) {
    'ACCEPTED'    => 'badge--ok',
    'COMPLETED'   => 'badge--info',
    'REJECTED'    => 'badge--muted',
    'EXPIRED'     => 'badge--muted',
    'NOTIFIED'    => 'badge--medium',
    default       => 'badge--warn',
};

$priorityClass = static fn (string $priority): string => match ($priority) {
    'CRITICAL' => 'badge--critical',
    'HIGH'     => 'badge--high',
    'MEDIUM'   => 'badge--medium',
    default    => 'badge--low',
};

$canAccept = static fn (string $status): bool => in_array($status, ['SUGGESTED', 'NOTIFIED'], true);
$canComplete = static fn (string $status): bool => $status === 'ACCEPTED';
?>
<section class="page-head">
    <p class="eyebrow">Matching engine</p>
    <div class="page-head__row">
        <h1 class="page-title">My matches</h1>
        <a class="btn btn--ghost" href="<?= e(url('volunteer/requests')) ?>">Nearby requests</a>
    </div>
    <p class="page-subtitle">
        Deterministic capability-first suggestions. Accepting a match marks you
        BUSY; completing frees you and resolves the request.
    </p>
</section>

<div class="card">
    <form method="get" action="<?= e(url('volunteer/matches')) ?>" class="form form--inline" data-loading>
        <div class="field">
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['SUGGESTED', 'NOTIFIED', 'ACCEPTED', 'COMPLETED', 'REJECTED', 'EXPIRED'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
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
        <h2 class="card__title">No matches yet</h2>
        <p class="muted">
            When operators request suggestions for an open emergency, your
            profile can appear here. Keep skills, availability and location current.
        </p>
        <a class="btn btn--ghost btn--small" href="<?= e(url('volunteer/dashboard')) ?>">Back to dashboard</a>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $item): ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Match #<?= e((string) ($item['id'] ?? '')) ?></p>
                        <h2 class="request-card__title">
                            <?= e((string) (($item['request']['category'] ?? 'REQUEST'))) ?> request
                            #<?= e((string) ($item['request_id'] ?? '')) ?>
                        </h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($statusClass((string) ($item['status'] ?? ''))) ?>">
                            <?= e((string) ($item['status'] ?? '')) ?>
                        </span>
                        <?php if (($item['request']['priority'] ?? null) !== null): ?>
                            <span class="badge <?= e($priorityClass((string) $item['request']['priority'])) ?>">
                                <?= e((string) $item['request']['priority']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (($item['match_score'] ?? null) !== null): ?>
                            <span class="badge badge--info"><?= e(number_format((float) $item['match_score'], 1)) ?> score</span>
                        <?php endif; ?>
                        <?php if (($item['distance_km'] ?? null) !== null): ?>
                            <span class="badge badge--muted"><?= e(number_format((float) $item['distance_km'], 1)) ?> km</span>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="request-card__excerpt muted">
                    <?= e(mb_strimwidth((string) (($item['request']['description'] ?? '')), 0, 160, '…')) ?>
                </p>

                <dl class="request-card__meta">
                    <?php if (($item['request']['people_affected'] ?? null) !== null): ?>
                        <div>
                            <dt>People</dt>
                            <dd><?= e((string) $item['request']['people_affected']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($item['request']['address_text'] ?? null) !== null): ?>
                        <div>
                            <dt>Landmark</dt>
                            <dd><?= e((string) $item['request']['address_text']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Capability</dt>
                        <dd><?= e(number_format((float) ($item['capability_score'] ?? 0), 0)) ?>/100</dd>
                    </div>
                </dl>

                <?php if (!empty($item['reasons'])): ?>
                    <ul class="reason-list" aria-label="Why this match">
                        <?php foreach ($item['reasons'] as $reason): ?>
                            <li><?= e((string) $reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="muted">
                    <?= ($item['request']['latitude'] ?? null) === null
                        ? 'Exact request coordinates are hidden until you accept.'
                        : 'Request location is available after acceptance (shown for your accepted assignment).' ?>
                </p>

                <div class="request-card__actions">
                    <?php if ($canAccept((string) ($item['status'] ?? ''))): ?>
                        <form method="post" action="<?= e(url('volunteer/matches/' . (int) ($item['id'] ?? 0) . '/accept')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn--primary btn--small" type="submit">Accept match</button>
                        </form>
                        <form method="post" action="<?= e(url('volunteer/matches/' . (int) ($item['id'] ?? 0) . '/reject')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn--ghost btn--small" type="submit">Reject</button>
                        </form>
                    <?php elseif ($canComplete((string) ($item['status'] ?? ''))): ?>
                        <form method="post" action="<?= e(url('volunteer/matches/' . (int) ($item['id'] ?? 0) . '/complete')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button class="btn btn--primary btn--small" type="submit">Mark completed</button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn--ghost btn--small" href="<?= e(url('requests/' . (int) ($item['request_id'] ?? 0) . '/matches')) ?>">
                            View request matches
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($item['request_id'])): ?>
                        <a class="btn btn--ghost btn--small" href="<?= e(url('requests/' . (int) $item['request_id'])) ?>">
                            Open request
                        </a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
