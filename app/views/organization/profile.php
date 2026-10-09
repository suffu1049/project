<?php
/**
 * Organization profile edit form.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $profile
 * @var array<int, string> $types
 * @var array<string, array<int, string>> $errors
 */
$user = $user ?? null;
$profile = $profile ?? [];
$types = $types ?? [];
$errors = $errors ?? [];

$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};

$oldType = (string) old('type', (string) ($profile['type'] ?? 'OTHER'));

$verification = (string) ($profile['verification_status'] ?? 'PENDING');
$verificationClass = match ($verification) {
    'VERIFIED' => 'badge--ok',
    'REJECTED' => 'badge--critical',
    default    => 'badge--warn',
};
?>
<section class="page-head">
    <p class="eyebrow">Organization profile</p>
    <div class="page-head__row">
        <h1 class="page-title">Edit your organization profile</h1>
        <span class="badge <?= e($verificationClass) ?>">Verification: <?= e($verification) ?></span>
    </div>
    <p class="page-subtitle">
        Accurate details help administrators verify you quickly and operations
        coordinate with the right contact.
    </p>
</section>

<div class="card">
    <form method="post" action="<?= e(url('organization/profile')) ?>" class="form form--wide" data-loading novalidate>
        <?= csrf_field() ?>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="name">Organization name</label>
                <input
                    class="input<?= $fieldError('name') ? ' input--invalid' : '' ?>"
                    id="name"
                    name="name"
                    type="text"
                    maxlength="180"
                    required
                    value="<?= e((string) old('name', (string) ($profile['name'] ?? ''))) ?>"
                >
                <?php if ($fieldError('name')): ?>
                    <p class="field__error"><?= e($fieldError('name')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="type">Organization type</label>
                <select class="input<?= $fieldError('type') ? ' input--invalid' : '' ?>" id="type" name="type" required>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type) ?>" <?= $oldType === $type ? 'selected' : '' ?>>
                            <?= e(str_replace('_', ' ', $type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($fieldError('type')): ?>
                    <p class="field__error"><?= e($fieldError('type')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="contact">Contact number <span class="optional">(optional)</span></label>
                <input
                    class="input<?= $fieldError('contact') ? ' input--invalid' : '' ?>"
                    id="contact"
                    name="contact"
                    type="text"
                    maxlength="100"
                    value="<?= e((string) old('contact', (string) ($profile['contact'] ?? ''))) ?>"
                >
                <?php if ($fieldError('contact')): ?>
                    <p class="field__error"><?= e($fieldError('contact')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="address_text">Address <span class="optional">(optional)</span></label>
                <input
                    class="input<?= $fieldError('address_text') ? ' input--invalid' : '' ?>"
                    id="address_text"
                    name="address_text"
                    type="text"
                    maxlength="500"
                    value="<?= e((string) old('address_text', (string) ($profile['address_text'] ?? ''))) ?>"
                >
                <?php if ($fieldError('address_text')): ?>
                    <p class="field__error"><?= e($fieldError('address_text')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">(optional)</span></label>
            <textarea
                class="input<?= $fieldError('description') ? ' input--invalid' : '' ?>"
                id="description"
                name="description"
                rows="4"
                maxlength="2000"
            ><?= e((string) old('description', (string) ($profile['description'] ?? ''))) ?></textarea>
            <p class="field__help">What your organization does and how it supports emergencies.</p>
            <?php if ($fieldError('description')): ?>
                <p class="field__error"><?= e($fieldError('description')) ?></p>
            <?php endif; ?>
        </div>

        <fieldset class="field">
            <legend class="label">Location <span class="optional">(optional but recommended)</span></legend>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="latitude">Latitude</label>
                    <input
                        class="input<?= $fieldError('latitude') ? ' input--invalid' : '' ?>"
                        id="latitude"
                        name="latitude"
                        type="number"
                        step="any"
                        min="-90"
                        max="90"
                        value="<?= e((string) old('latitude', $profile['latitude'] !== null ? (string) $profile['latitude'] : '')) ?>"
                        placeholder="20.3893"
                    >
                    <?php if ($fieldError('latitude')): ?>
                        <p class="field__error"><?= e($fieldError('latitude')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label class="label" for="longitude">Longitude</label>
                    <input
                        class="input<?= $fieldError('longitude') ? ' input--invalid' : '' ?>"
                        id="longitude"
                        name="longitude"
                        type="number"
                        step="any"
                        min="-180"
                        max="180"
                        value="<?= e((string) old('longitude', $profile['longitude'] !== null ? (string) $profile['longitude'] : '')) ?>"
                        placeholder="72.9106"
                    >
                    <?php if ($fieldError('longitude')): ?>
                        <p class="field__error"><?= e($fieldError('longitude')) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <p class="field__help">Latitude and longitude must be provided together.</p>
        </fieldset>

        <div class="form-actions">
            <button class="btn btn--primary btn--block" type="submit">Save profile</button>
            <a class="btn btn--ghost btn--block" href="<?= e(url('organization/dashboard')) ?>">Back to dashboard</a>
        </div>
    </form>
</div>
