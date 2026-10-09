<?php
/**
 * Organization resource edit + replenish form.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $resource
 * @var array<int, string> $types
 * @var array<string, array<int, string>> $errors
 */
$user = $user ?? null;
$resource = $resource ?? [];
$types = $types ?? [];
$errors = $errors ?? [];

$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};

$oldType = (string) old('resource_type', (string) ($resource['resource_type'] ?? 'FOOD'));
$oldStatus = (string) old('status', (string) ($resource['status'] ?? 'AVAILABLE'));
$rid = (int) ($resource['id'] ?? 0);

$statusClass = match ($oldStatus) {
    'AVAILABLE' => 'badge--ok',
    'LIMITED'   => 'badge--warn',
    default     => 'badge--muted',
};
?>
<section class="page-head">
    <p class="eyebrow">Organization inventory</p>
    <div class="page-head__row">
        <h1 class="page-title">Edit resource #<?= e((string) $rid) ?></h1>
        <span class="badge <?= e($statusClass) ?>"><?= e($oldStatus) ?></span>
    </div>
    <p class="page-subtitle">
        Adjust quantities, restock with replenish, or mark this resource unavailable.
        Available quantity can never exceed the total quantity.
    </p>
</section>

<div class="card">
    <form method="post" action="<?= e(url('organization/resources/' . $rid)) ?>" class="form form--wide" data-loading novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="resource_type">Type</label>
                <select class="input<?= $fieldError('resource_type') ? ' input--invalid' : '' ?>" id="resource_type" name="resource_type" required>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type) ?>" <?= $oldType === $type ? 'selected' : '' ?>>
                            <?= e(str_replace('_', ' ', $type)) ?>
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
                    value="<?= e((string) old('resource_name', (string) ($resource['resource_name'] ?? ''))) ?>"
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
                    value="<?= e((string) old('quantity', (string) (int) ($resource['quantity'] ?? 0))) ?>"
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
                    value="<?= e((string) old('available_quantity', (string) (int) ($resource['available_quantity'] ?? 0))) ?>"
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
                    value="<?= e((string) old('unit', (string) ($resource['unit'] ?? ''))) ?>"
                >
                <?php if ($fieldError('unit')): ?>
                    <p class="field__error"><?= e($fieldError('unit')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="status">Status</label>
                <select class="input<?= $fieldError('status') ? ' input--invalid' : '' ?>" id="status" name="status">
                    <option value="" <?= $oldStatus === '' ? 'selected' : '' ?>>Auto (from quantities)</option>
                    <?php foreach (['AVAILABLE', 'LIMITED', 'UNAVAILABLE'] as $statusOption): ?>
                        <option value="<?= e($statusOption) ?>" <?= $oldStatus === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field__help">Choose "Auto" to derive the status from quantities.</p>
                <?php if ($fieldError('status')): ?>
                    <p class="field__error"><?= e($fieldError('status')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Save changes</button>
            <a class="btn btn--ghost" href="<?= e(url('organization/dashboard')) ?>">Back to dashboard</a>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card__title">Replenish</h2>
    <p class="muted">Adds units to both the total and the available quantity.</p>
    <form method="post" action="<?= e(url('organization/resources/' . $rid . '/replenish')) ?>" class="form form--inline" data-loading>
        <?= csrf_field() ?>
        <div class="field">
            <label class="label" for="units">Units to add</label>
            <input
                class="input<?= $fieldError('units') ? ' input--invalid' : '' ?>"
                id="units"
                name="units"
                type="number"
                min="1"
                value="<?= e((string) old('units', '10')) ?>"
                required
            >
            <?php if ($fieldError('units')): ?>
                <p class="field__error"><?= e($fieldError('units')) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label class="label">&nbsp;</label>
            <button class="btn btn--primary" type="submit">Replenish</button>
        </div>
    </form>
</div>