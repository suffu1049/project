<?php
/**
 * Citizen request history + admin operational queue.
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $items
 */
$user = $user ?? null;
$items = $items ?? [];
$isAdmin = (string) (($user['role'] ?? '')) === 'ADMIN';

$categoryLabels = [
    'MEDICAL'          => 'Medical',
    'ACCIDENT'         => 'Accident',
    'FLOOD'            => 'Flood',
    'FIRE'             => 'Fire',
    'FOOD_WATER'       => 'Food / Water',
    'SHELTER'          => 'Shelter',
    'TRANSPORT'        => 'Transport',
    'MISSING_STRANDED' => 'Missing / Stranded',
    'OTHER'            => 'Other',
];

$priorityClass = static function (string $priority): string {
    return match ($priority) {
        'CRITICAL' => 'badge--critical',
        'HIGH'     => 'badge--high',
        'MEDIUM'   => 'badge--medium',
        default    => 'badge--low',
    };
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'RESOLVED'    => 'badge--ok',
        'CANCELLED'   => 'badge--muted',
        'ESCALATED'   => 'badge--critical',
        'IN_PROGRESS' => 'badge--high',
        'ACCEPTED'    => 'badge--medium',
        'MATCHING'    => 'badge--medium',
        'VERIFIED'    => 'badge--info',
        default       => 'badge--warn',
    };
};
?>
<section class="page-head">
    <p class="eyebrow">Emergency requests</p>
    <div class="page-head__row">
        <h1 class="page-title"><?= $isAdmin ? 'Operational requests' : 'My requests' ?></h1>
        <?php if (!$isAdmin): ?>
            <a class="btn btn--primary" href="<?= e(url('requests/create')) ?>">Request help</a>
        <?php endif; ?>
    </div>
    <p class="page-subtitle">
        <?= $isAdmin
            ? 'All emergency requests across the platform (priority-sorted active work first).'
            : 'Track every help request you have submitted, with priority reasons and live status.' ?>
    </p>
</section>

<?php if ($items === []): ?>
    <div class="card">
        <?php
        $action = $isAdmin ? null : '<a class="btn btn--primary" href="' . e(url('requests/create')) . '">Create your first request</a>';
        ?>
        <?php require view_path('components/empty-state.php'); ?>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $item): ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Request #<?= e((string) $item['id']) ?></p>
                        <h2 class="request-card__title">
                            <a href="<?= e(url('requests/' . (int) $item['id'])) ?>">
                                <?= e($categoryLabels[(string) $item['category']] ?? (string) $item['category']) ?>
                            </a>
                        </h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($priorityClass((string) $item['priority'])) ?>">
                            <?= e((string) $item['priority']) ?>
                        </span>
                        <span class="badge <?= e($statusClass((string) $item['status'])) ?>">
                            <?= e((string) $item['status']) ?>
                        </span>
                    </div>
                </div>

                <p class="request-card__excerpt muted">
                    <?= e(mb_strimwidth((string) $item['description'], 0, 160, '…')) ?>
                </p>

                <dl class="request-card__meta">
                    <div>
                        <dt>People</dt>
                        <dd><?= e((string) $item['people_affected']) ?></dd>
                    </div>
                    <div>
                        <dt>Priority score</dt>
                        <dd><?= e((string) $item['priority_score']) ?></dd>
                    </div>
                    <div>
                        <dt>Created</dt>
                        <dd><?= e(date('d M Y, H:i', strtotime((string) $item['created_at']))) ?></dd>
                    </div>
                </dl>

                <a class="btn btn--ghost btn--small" href="<?= e(url('requests/' . (int) $item['id'])) ?>">
                    View details
                </a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
