<?php
/**
 * Organization dashboard: verification state, inventory stats, resource
 * cards with allocation/marking actions, and the add-resource form.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $profile
 * @var array{total_resources:int, total_quantity:int, available_quantity:int, available:int, limited:int, unavailable:int} $stats
 * @var array<int, array<string, mixed>> $items
 * @var array{page:int,limit:int,total:int,pages:int} $pagination
 * @var array<int, array<string, mixed>> $targets
 * @var array<int, string> $types
 * @var array<string, array<int, string>> $errors
 */
$user = $user ?? null;
$profile = $profile ?? [];
$stats = $stats ?? ['total_resources' => 0, 'total_quantity' => 0, 'available_quantity' => 0, 'available' => 0, 'limited' => 0, 'unavailable' => 0];
$items = $items ?? [];
$pagination = $pagination ?? ['page' => 1, 'limit' => 50, 'total' => 0, 'pages' => 0];
$targets = $targets ?? [];
$types = $types ?? [];
$errors = $errors ?? [];

$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};

$verification = (string) ($profile['verification_status'] ?? 'PENDING');
$canManage = $verification === 'VERIFIED';

$verificationClass = match ($verification) {
    'VERIFIED' => 'badge--ok',
    'REJECTED' => 'badge--critical',
    default    => 'badge--warn',
};

$statusClass = static fn (string $status): string => match ($status) {
    'AVAILABLE'   => 'badge--ok',
    'LIMITED'     => 'badge--warn',
    default       => 'badge--muted',
};

$typeLabel = static fn (string $type): string => str_replace('_', ' ', $type);
?>
<section class="page-head">
    <p class="eyebrow">Organization</p>
    <div class="page-head__row">
        <h1 class="page-title">Inventory dashboard<?= $user !== null ? ', ' . e($user['name'] ?? '') : '' ?></h1>
        <a class="btn btn--primary" href="<?= e(url('organization/profile')) ?>">Edit profile</a>
    </div>
    <p class="page-subtitle">
        Manage the resources your organization contributes to emergency responses.
        Available quantity can never drop below zero.
    </p>
</section>

<?php if (!$canManage): ?>
    <div class="card">
        <h2 class="card__title">
            Verification: <span class="badge <?= e($verificationClass) ?>"><?= e($verification) ?></span>
        </h2>
        <p class="muted">
            <?= $verification === 'REJECTED'
                ? 'This organization was rejected. Update your profile and ask an administrator to review it again.'
                : 'An administrator must verify your organization before you can add, edit or allocate resources.' ?>
        </p>
    </div>
<?php endif; ?>

<section class="grid grid--cards" aria-label="Inventory summary">
    <article class="card">
        <p class="stat__label">Total resources</p>
        <p class="stat__value"><?= e((string) (int) $stats['total_resources']) ?></p>
        <p class="stat__meta">Listed inventory rows</p>
    </article>
    <article class="card">
        <p class="stat__label">Available quantity</p>
        <p class="stat__value"><?= e(number_format((int) $stats['available_quantity'])) ?></p>
        <p class="stat__meta">Of <?= e(number_format((int) $stats['total_quantity'])) ?> total units</p>
    </article>
    <article class="card">
        <p class="stat__label">Limited resources</p>
        <p class="stat__value"><?= e((string) (int) $stats['limited']) ?></p>
        <p class="stat__meta">At or below 20% stock</p>
    </article>
    <article class="card">
        <p class="stat__label">Unavailable resources</p>
        <p class="stat__value"><?= e((string) (int) $stats['unavailable']) ?></p>
        <p class="stat__meta">Marked unavailable or empty</p>
    </article>
</section>

<?php if ($canManage): ?>
    <div class="card">
        <h2 class="card__title">Add a resource</h2>
        <form method="post" action="<?= e(url('organization/resources')) ?>" class="form form--wide" data-loading novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="resource_type">Type</label>
                    <select
                        class="input<?= $fieldError('resource_type') ? ' input--invalid' : '' ?>"
                        id="resource_type"
                        name="resource_type"
                        required
                    >
                        <?php foreach ($types as $type): ?>
                            <option value="<?= e($type) ?>" <?= (string) old('resource_type', 'FOOD') === $type ? 'selected' : '' ?>>
                                <?= e($typeLabel($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($fieldError('resource_type')): ?>
                        <p class="field__error"><?= e($fieldError('resource_type')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="resource_name">Resource name</label>
                    <input
                        class="input<?= $fieldError('resource_name') ? ' input--invalid' : '' ?>"
                        id="resource_name"
                        name="resource_name"
                        type="text"
                        maxlength="150"
                        required
                        value="<?= e((string) old('resource_name', '')) ?>"
                        placeholder="Food Packets"
                    >
                    <?php if ($fieldError('resource_name')): ?>
                        <p class="field__error"><?= e($fieldError('resource_name')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="quantity">Total quantity</label>
                    <input
                        class="input<?= $fieldError('quantity') ? ' input--invalid' : '' ?>"
                        id="quantity"
                        name="quantity"
                        type="number"
                        min="0"
                        required
                        value="<?= e((string) old('quantity', '0')) ?>"
                    >
                    <?php if ($fieldError('quantity')): ?>
                        <p class="field__error"><?= e($fieldError('quantity')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="available_quantity">Available quantity</label>
                    <input
                        class="input<?= $fieldError('available_quantity') ? ' input--invalid' : '' ?>"
                        id="available_quantity"
                        name="available_quantity"
                        type="number"
                        min="0"
                        required
                        value="<?= e((string) old('available_quantity', '0')) ?>"
                    >
                    <p class="field__help">Cannot exceed the total quantity.</p>
                    <?php if ($fieldError('available_quantity')): ?>
                        <p class="field__error"><?= e($fieldError('available_quantity')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="unit">Unit <span class="optional">(optional)</span></label>
                    <input
                        class="input<?= $fieldError('unit') ? ' input--invalid' : '' ?>"
                        id="unit"
                        name="unit"
                        type="text"
                        maxlength="50"
                        value="<?= e((string) old('unit', '')) ?>"
                        placeholder="packets"
                    >
                    <?php if ($fieldError('unit')): ?>
                        <p class="field__error"><?= e($fieldError('unit')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Add resource</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($items === []): ?>
    <div class="card">
        <?php
        if ($canManage) {
            $icon = '📦';
            $title = 'No resources yet';
            $message = 'Add your first resource above so operations can see what your organization can contribute.';
            $action = null;
        } else {
            $icon = '🔐';
            $title = 'Inventory not available yet';
            $message = 'Once an administrator verifies your organization you can add resources and allocate them to requests.';
            $action = '<a class="btn btn--ghost" href="' . e(url('organization/profile')) . '">Review your profile</a>';
        }
        require view_path('components/empty-state.php');
        ?>
    </div>
<?php else: ?>
    <div class="request-list" role="list">
        <?php foreach ($items as $resource): ?>
            <?php
            $rid = (int) $resource['id'];
            $status = (string) $resource['status'];
            $available = (int) $resource['available_quantity'];
            ?>
            <article class="card request-card" role="listitem">
                <div class="request-card__top">
                    <div>
                        <p class="request-card__id">Resource #<?= e((string) $rid) ?></p>
                        <h2 class="request-card__title"><?= e((string) $resource['resource_name']) ?></h2>
                    </div>
                    <div class="request-card__badges">
                        <span class="badge <?= e($statusClass($status)) ?>"><?= e($status) ?></span>
                        <span class="badge badge--info"><?= e($typeLabel((string) $resource['resource_type'])) ?></span>
                    </div>
                </div>

                <dl class="request-card__meta">
                    <div>
                        <dt>Quantity</dt>
                        <dd><?= e(number_format((int) $resource['quantity'])) ?></dd>
                    </div>
                    <div>
                        <dt>Available</dt>
                        <dd><?= e(number_format($available)) ?></dd>
                    </div>
                    <div>
                        <dt>Unit</dt>
                        <dd><?= e((string) ($resource['unit'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Updated</dt>
                        <dd><?= e(date('d M Y, H:i', strtotime((string) $resource['updated_at']))) ?></dd>
                    </div>
                </dl>

                <div class="form-actions">
                    <a class="btn btn--ghost btn--small" href="<?= e(url('organization/resources/' . $rid . '/edit')) ?>">
                        Edit / replenish
                    </a>

                    <?php if ($canManage): ?>
                        <form method="post" action="<?= e(url('organization/resources/' . $rid)) ?>" data-loading>
                            <?= csrf_field() ?>
                            <?php if ($status === 'UNAVAILABLE'): ?>
                                <input type="hidden" name="status" value="">
                                <button class="btn btn--ghost btn--small" type="submit">Restore availability</button>
                            <?php else: ?>
                                <input type="hidden" name="status" value="UNAVAILABLE">
                                <button class="btn btn--ghost btn--small" type="submit" data-confirm="Mark this resource unavailable?">
                                    Mark unavailable
                                </button>
                            <?php endif; ?>
                        </form>

                        <?php if ($targets !== [] && $available > 0 && $status !== 'UNAVAILABLE'): ?>
                            <form method="post" action="<?= e(url('organization/resources/' . $rid . '/allocate')) ?>" class="form form--inline" data-loading>
                                <?= csrf_field() ?>
                                <div class="field">
                                    <label class="label" for="request_id_<?= e((string) $rid) ?>">Allocate to request</label>
                                    <select class="input" id="request_id_<?= e((string) $rid) ?>" name="request_id" required>
                                        <option value="">Select a request…</option>
                                        <?php foreach ($targets as $target): ?>
                                            <option value="<?= e((string) $target['id']) ?>">
                                                #<?= e((string) $target['id']) ?> · <?= e($target['category']) ?> · <?= e($target['priority']) ?><?= $target['address_text'] !== null ? ' · ' . e($target['address_text']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="label" for="allocate_qty_<?= e((string) $rid) ?>">Quantity</label>
                                    <input
                                        class="input"
                                        id="allocate_qty_<?= e((string) $rid) ?>"
                                        name="quantity"
                                        type="number"
                                        min="1"
                                        max="<?= e((string) $available) ?>"
                                        value="1"
                                        required
                                    >
                                </div>
                                <div class="field">
                                    <label class="label">&nbsp;</label>
                                    <button class="btn btn--primary btn--small" type="submit" data-confirm="Allocate these units to the selected request?">
                                        Allocate
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ((int) $pagination['pages'] > 1): ?>
        <nav class="form-actions" aria-label="Inventory pages">
            <?php if ((int) $pagination['page'] > 1): ?>
                <a class="btn btn--ghost btn--small" href="<?= e(url('organization/dashboard?page=' . ((int) $pagination['page'] - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= e((string) (int) $pagination['page']) ?> of <?= e((string) (int) $pagination['pages']) ?></span>
            <?php if ((int) $pagination['page'] < (int) $pagination['pages']): ?>
                <a class="btn btn--ghost btn--small" href="<?= e(url('organization/dashboard?page=' . ((int) $pagination['page'] + 1))) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
