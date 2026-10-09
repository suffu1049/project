<?php
/**
 * Admin request queue — filters, priority indicators and safe actions (Phase 9).
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $items
 * @var array<string, int> $pagination
 * @var array<string, mixed> $filters
 */
$user = $user ?? null;
$items = $items ?? [];
$pagination = $pagination ?? [];
$filters = $filters ?? [];

$page = max(1, (int) ($pagination['page'] ?? 1));
$pages = (int) ($pagination['pages'] ?? 0);
$total = (int) ($pagination['total'] ?? 0);

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

$statuses = ['CREATED', 'VERIFIED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS', 'RESOLVED', 'ESCALATED', 'CANCELLED'];
$priorities = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
$categories = ['MEDICAL', 'ACCIDENT', 'FLOOD', 'FIRE', 'FOOD_WATER', 'SHELTER', 'TRANSPORT', 'MISSING_STRANDED', 'OTHER'];

$queryFor = static function (int $targetPage) use ($filters): string {
    $params = array_filter([
        'status'    => $filters['status'] ?? null,
        'priority'  => $filters['priority'] ?? null,
        'category'  => $filters['category'] ?? null,
        'date_from' => $filters['date_from'] ?? null,
        'date_to'   => $filters['date_to'] ?? null,
        'search'    => $filters['search'] ?? null,
        'page'      => $targetPage > 1 ? $targetPage : null,
    ], static fn ($value): bool => $value !== null && $value !== '');

    return $params === [] ? '' : '?' . http_build_query($params);
};
?>
<section class="page-head">
    <p class="eyebrow">Admin · Operational queue</p>
    <div class="page-head__row">
        <h1 class="page-title">Request queue</h1>
        <div class="request-card__badges">
            <span class="badge badge--muted"><?= e((string) $total) ?> matching</span>
            <a class="btn btn--ghost btn--small" href="<?= e(url('admin')) ?>">Control center</a>
        </div>
    </div>
    <p class="page-subtitle">
        Filter by status, priority, category and date. Verify or escalate requests —
        each action is validated against the workflow state machine and audited.
    </p>
</section>

<div class="card">
    <form method="get" action="<?= e(url('admin/requests')) ?>" class="form form--inline" data-loading>
        <div class="field">
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label" for="priority">Priority</label>
            <select class="input" id="priority" name="priority">
                <option value="">All</option>
                <?php foreach ($priorities as $priority): ?>
                    <option value="<?= e($priority) ?>" <?= ($filters['priority'] ?? '') === $priority ? 'selected' : '' ?>><?= e($priority) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label" for="category">Category</label>
            <select class="input" id="category" name="category">
                <option value="">All</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category) ?>" <?= ($filters['category'] ?? '') === $category ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $category)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label" for="date_from">From</label>
            <input class="input" type="date" id="date_from" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
        </div>
        <div class="field">
            <label class="label" for="date_to">To</label>
            <input class="input" type="date" id="date_to" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
        </div>
        <div class="field">
            <label class="label" for="search">Search</label>
            <input class="input" type="search" id="search" name="search" placeholder="Description or #id" value="<?= e((string) ($filters['search'] ?? '')) ?>">
        </div>
        <div class="field">
            <label class="label">&nbsp;</label>
            <button class="btn btn--primary" type="submit">Apply</button>
        </div>
        <div class="field">
            <label class="label">&nbsp;</label>
            <a class="btn btn--ghost" href="<?= e(url('admin/requests')) ?>">Reset</a>
        </div>
    </form>
</div>

<?php if ($items === []): ?>
    <div class="card">
        <h2 class="card__title">No requests match these filters</h2>
        <p class="muted">Try clearing the filters.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Category</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col">People</th>
                    <th scope="col">Requester</th>
                    <th scope="col">Created</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><a href="<?= e(url('requests/' . (int) $item['id'])) ?>">#<?= e((string) $item['id']) ?></a></td>
                        <td><?= e(str_replace('_', ' ', (string) $item['category'])) ?></td>
                        <td><span class="badge <?= e($priorityClass((string) $item['priority'])) ?>"><?= e((string) $item['priority']) ?></span></td>
                        <td><span class="badge <?= e($statusClass((string) $item['status'])) ?>"><?= e((string) $item['status']) ?></span></td>
                        <td><?= e((string) (int) $item['people_affected']) ?></td>
                        <td><?= e((string) ($item['requester_name'] ?? '—')) ?></td>
                        <td><?= e(date('d M Y, H:i', strtotime((string) $item['created_at']))) ?></td>
                        <td>
                            <div class="form-actions">
                                <?php if ((string) $item['status'] === 'CREATED'): ?>
                                    <form method="post" action="<?= e(url('admin/requests/' . (int) $item['id'] . '/verify')) ?>" data-loading data-confirm="Verify request #<?= e((string) $item['id']) ?>?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--primary btn--small" type="submit">Verify</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!in_array((string) $item['status'], ['RESOLVED', 'CANCELLED', 'ESCALATED'], true)): ?>
                                    <form method="post" action="<?= e(url('admin/requests/' . (int) $item['id'] . '/escalate')) ?>" data-loading data-confirm="Escalate request #<?= e((string) $item['id']) ?>?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--ghost btn--small" type="submit">Escalate</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn--ghost btn--small" href="<?= e(url('requests/' . (int) $item['id'])) ?>">Open</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Request pages">
            <?php if ($page > 1): ?>
                <a class="btn btn--ghost btn--small" href="<?= e(url('admin/requests' . $queryFor($page - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn--ghost btn--small" href="<?= e(url('admin/requests' . $queryFor($page + 1))) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
