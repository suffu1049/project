<?php
/**
 * Volunteer profile edit form.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $profile
 * @var array<int, string> $skills
 * @var array<int, string> $equipment
 * @var array<string, array<int, string>> $errors
 */
$user = $user ?? null;
$profile = $profile ?? [];
$skills = $skills ?? [];
$equipment = $equipment ?? [];
$errors = $errors ?? [];

$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};

$skillOptions = \App\Services\VolunteerService::SKILLS;
$vehicleOptions = \App\Services\VolunteerService::VEHICLES;
$availabilityOptions = \App\Services\VolunteerService::AVAILABILITY;

$oldSkills = old('skills', implode(',', $skills));
if (is_array($oldSkills)) {
    $oldSkills = implode(',', $oldSkills);
}
$oldEquipment = old('equipment', implode(',', $equipment));
if (is_array($oldEquipment)) {
    $oldEquipment = implode(',', $oldEquipment);
}
$oldVehicle = (string) old('vehicle', (string) ($profile['vehicle'] ?? 'NONE'));
$oldAvailability = (string) old('availability', (string) ($profile['availability'] ?? 'OFFLINE'));
$oldRadius = (string) old('service_radius_km', (string) ($profile['service_radius_km'] ?? '10'));
$oldLat = (string) old('latitude', $profile['latitude'] !== null ? (string) $profile['latitude'] : '');
$oldLng = (string) old('longitude', $profile['longitude'] !== null ? (string) $profile['longitude'] : '');

$verification = (string) ($profile['verification_status'] ?? 'PENDING');
$verificationClass = match ($verification) {
    'VERIFIED' => 'badge--ok',
    'REJECTED' => 'badge--critical',
    default    => 'badge--warn',
};
?>
<section class="page-head">
    <p class="eyebrow">Volunteer profile</p>
    <div class="page-head__row">
        <h1 class="page-title">Edit your volunteer profile</h1>
        <span class="badge <?= e($verificationClass) ?>">Verification: <?= e($verification) ?></span>
    </div>
    <p class="page-subtitle">
        Skills drive which emergencies you see. Location and service radius control
        how far operations can reach you.
    </p>
</section>

<div class="card">
    <form method="post" action="<?= e(url('volunteer/profile')) ?>" class="form form--wide" data-loading novalidate>
        <?= csrf_field() ?>

        <fieldset class="field">
            <legend class="label">Capabilities</legend>
            <p class="field__help">Select every skill that applies to you.</p>
            <div class="form-grid">
                <?php foreach ($skillOptions as $skill): ?>
                    <label class="check-field">
                        <input
                            type="checkbox"
                            name="skills[]"
                            value="<?= e($skill) ?>"
                            <?= in_array($skill, $skills, true) || in_array($skill, array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $oldSkills)))), true) ? 'checked' : '' ?>
                        >
                        <span><?= e(str_replace('_', ' ', $skill)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($fieldError('skills')): ?>
                <p class="field__error"><?= e($fieldError('skills')) ?></p>
            <?php endif; ?>
        </fieldset>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="vehicle">Vehicle</label>
                <select class="input<?= $fieldError('vehicle') ? ' input--invalid' : '' ?>" id="vehicle" name="vehicle">
                    <?php foreach ($vehicleOptions as $vehicle): ?>
                        <option value="<?= e($vehicle) ?>" <?= $oldVehicle === $vehicle ? 'selected' : '' ?>>
                            <?= e($vehicle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($fieldError('vehicle')): ?>
                    <p class="field__error"><?= e($fieldError('vehicle')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="availability">Availability</label>
                <select class="input<?= $fieldError('availability') ? ' input--invalid' : '' ?>" id="availability" name="availability" required>
                    <?php foreach ($availabilityOptions as $option): ?>
                        <option value="<?= e($option) ?>" <?= $oldAvailability === $option ? 'selected' : '' ?>>
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($fieldError('availability')): ?>
                    <p class="field__error"><?= e($fieldError('availability')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="service_radius_km">Service radius (km)</label>
                <input
                    class="input<?= $fieldError('service_radius_km') ? ' input--invalid' : '' ?>"
                    id="service_radius_km"
                    name="service_radius_km"
                    type="number"
                    step="0.1"
                    min="0.1"
                    max="1000"
                    required
                    value="<?= e($oldRadius) ?>"
                >
                <p class="field__help">Between 0.1 and 1000 km.</p>
                <?php if ($fieldError('service_radius_km')): ?>
                    <p class="field__error"><?= e($fieldError('service_radius_km')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="equipment">Equipment <span class="optional">(optional)</span></label>
                <input
                    class="input<?= $fieldError('equipment') ? ' input--invalid' : '' ?>"
                    id="equipment"
                    name="equipment"
                    type="text"
                    maxlength="1000"
                    value="<?= e($oldEquipment) ?>"
                    placeholder="FIRST_AID_KIT,TORCH"
                >
                <p class="field__help">Comma-separated tokens, e.g. FIRST_AID_KIT, TORCH.</p>
                <?php if ($fieldError('equipment')): ?>
                    <p class="field__error"><?= e($fieldError('equipment')) ?></p>
                <?php endif; ?>
            </div>
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
                        value="<?= e($oldLat) ?>"
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
                        value="<?= e($oldLng) ?>"
                        placeholder="72.9106"
                    >
                    <?php if ($fieldError('longitude')): ?>
                        <p class="field__error"><?= e($fieldError('longitude')) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-actions" style="margin-top:0.65rem">
                <button class="btn btn--ghost btn--small" type="button" data-geolocate>
                    Use my current location
                </button>
            </div>
            <p class="field__help" data-geolocate-status>
                Latitude and longitude must be provided together. Exact coordinates stay private to you and operations.
            </p>
        </fieldset>

        <div class="form-actions">
            <button class="btn btn--primary btn--block" type="submit">Save profile</button>
            <a class="btn btn--ghost btn--block" href="<?= e(url('volunteer/dashboard')) ?>">Back to dashboard</a>
        </div>
    </form>
</div>
