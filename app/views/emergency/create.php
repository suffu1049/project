<?php
/**
 * Create emergency request form.
 *
 * @var array<string, array<int, string>> $errors
 */
$errors = $errors ?? [];
$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};

$categories = [
    'MEDICAL'          => 'Medical emergency',
    'ACCIDENT'         => 'Accident',
    'FLOOD'            => 'Flood',
    'FIRE'             => 'Fire',
    'FOOD_WATER'       => 'Food or water shortage',
    'SHELTER'          => 'Shelter needed',
    'TRANSPORT'        => 'Transport needed',
    'MISSING_STRANDED' => 'Missing or stranded person',
    'OTHER'            => 'Other emergency',
];
?>
<section class="page-head">
    <p class="eyebrow">Citizen workflow</p>
    <h1 class="page-title">Request emergency help</h1>
    <p class="page-subtitle">
        Describe the situation clearly. Priority is calculated automatically from
        category, people affected and urgency indicators — you can review the
        reason on the request page.
    </p>
</section>

<div class="card">
    <form
        method="post"
        action="<?= e(url('requests')) ?>"
        class="form form--wide"
        data-loading
        enctype="multipart/form-data"
        novalidate
    >
        <?= csrf_field() ?>

        <div class="field">
            <label class="label" for="category">Emergency category</label>
            <select
                class="input<?= $fieldError('category') ? ' input--invalid' : '' ?>"
                id="category"
                name="category"
                required
                <?= $fieldError('category') ? 'aria-invalid="true" aria-describedby="category-error"' : '' ?>
            >
                <option value="">Select a category…</option>
                <?php
                $oldCategory = (string) old('category', '');
                foreach ($categories as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $oldCategory === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($fieldError('category')): ?>
                <p class="field__error" id="category-error"><?= e($fieldError('category')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">What is happening?</label>
            <textarea
                class="input<?= $fieldError('description') ? ' input--invalid' : '' ?>"
                id="description"
                name="description"
                rows="6"
                minlength="10"
                maxlength="5000"
                required
                placeholder="Example: Two elderly residents are trapped on the second floor near the river. One has difficulty breathing and needs medical assistance immediately."
                <?= $fieldError('description') ? 'aria-invalid="true" aria-describedby="description-error"' : '' ?>
            ><?= e((string) old('description', '')) ?></textarea>
            <p class="field__help">Mention urgency words (immediate, urgent, bleeding, children) so the priority engine can score the situation fairly.</p>
            <?php if ($fieldError('description')): ?>
                <p class="field__error" id="description-error"><?= e($fieldError('description')) ?></p>
            <?php endif; ?>
        </div>

        <?php // Phase 10 — AI assistance. Suggestions only: nothing here is
              // written to the request. Source is always shown as AI vs fallback
              // vs the system decision (priority) vs your own selection. ?>
        <div class="card ai-assist" data-ai-assist
             data-ai-url="<?= e(url('requests/ai-assist')) ?>"
             data-ai-classify-url="<?= e(url('api/v1/ai/classify')) ?>"
             data-ai-summarize-url="<?= e(url('api/v1/ai/summarize')) ?>">
            <header class="card__header">
                <h2 class="card__title">AI assistance <span class="badge badge--info">Optional</span></h2>
                <button class="btn btn--ghost btn--small" type="button" data-ai-run>Suggest from my description</button>
            </header>
            <p class="field__help">
                <span class="ai-tag ai-tag--ai">AI suggestion</span>
                for category and summary ·
                <span class="ai-tag ai-tag--system">System decision</span>
                for priority (rule-based, always authoritative) ·
                <span class="ai-tag ai-tag--human">Your decision</span>
                for what gets submitted.
            </p>
            <p class="field__help" data-ai-status role="status">AI is optional. Everything below is a suggestion — you still choose the final category.</p>
            <div class="ai-assist__body" data-ai-output hidden>
                <div class="ai-assist__row">
                    <span class="badge badge--info" data-ai-source>SOURCE</span>
                    <span class="muted" data-ai-disclaimer></span>
                </div>
                <div class="ai-assist__row">
                    <strong>Suggested category:</strong>
                    <span data-ai-category>—</span>
                    <button class="btn btn--ghost btn--small" type="button" data-ai-apply>Use this category</button>
                </div>
                <div class="ai-assist__row">
                    <strong>Suggested priority:</strong>
                    <span data-ai-priority>—</span>
                    <span class="muted">(advisory — the stored priority is calculated by the rule engine)</span>
                </div>
                <div class="ai-assist__row">
                    <strong>Indicators:</strong>
                    <span data-ai-indicators>—</span>
                </div>
                <div class="ai-assist__row">
                    <strong>Summary:</strong>
                    <span data-ai-summary>—</span>
                </div>
            </div>
        </div>

        <div class="field">
            <label class="label" for="people_affected">People affected</label>
            <input
                class="input<?= $fieldError('people_affected') ? ' input--invalid' : '' ?>"
                id="people_affected"
                name="people_affected"
                type="number"
                min="1"
                max="100000"
                step="1"
                required
                value="<?= e((string) old('people_affected', '1')) ?>"
                <?= $fieldError('people_affected') ? 'aria-invalid="true" aria-describedby="people_affected-error"' : '' ?>
            >
            <?php if ($fieldError('people_affected')): ?>
                <p class="field__error" id="people_affected-error"><?= e($fieldError('people_affected')) ?></p>
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
                        value="<?= e((string) old('latitude', '')) ?>"
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
                        value="<?= e((string) old('longitude', '')) ?>"
                        placeholder="72.9106"
                    >
                    <?php if ($fieldError('longitude')): ?>
                        <p class="field__error"><?= e($fieldError('longitude')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label class="label" for="location_accuracy_m">GPS accuracy (m)</label>
                    <input
                        class="input<?= $fieldError('location_accuracy_m') ? ' input--invalid' : '' ?>"
                        id="location_accuracy_m"
                        name="location_accuracy_m"
                        type="number"
                        step="any"
                        min="0"
                        value="<?= e((string) old('location_accuracy_m', '')) ?>"
                        placeholder="15.5"
                    >
                </div>
<div class="field">
                    <label class="label" for="address_text">Address / landmark</label>
                    <input
                        class="input"
                        id="address_text"
                        name="address_text"
                        type="text"
                        maxlength="500"
                        value="<?= e((string) old('address_text', '')) ?>"
                        placeholder="Near the old bridge, Sector 4"
                    >
                </div>
            </div>
            <div class="form-actions" style="margin-top:0.65rem">
                <button class="btn btn--ghost btn--small" type="button" data-geolocate>
                    Use my current location
                </button>
            </div>
            <p class="field__help" data-geolocate-status>
                Provide coordinates manually or capture them from your browser. Accuracy helps responders find you faster.
            </p>
        </fieldset>

        <div class="field">
            <label class="label" for="file">Photo attachment <span class="optional">(optional)</span></label>
            <input
                class="input"
                id="file"
                name="file"
                type="file"
                accept="image/jpeg,image/png,image/webp"
            >
            <p class="field__help">JPG, PNG or WebP up to 5 MB. Files are stored outside the public web root.</p>
        </div>

        <div class="form-actions">
            <button class="btn btn--primary btn--block" type="submit">Submit request</button>
            <a class="btn btn--ghost btn--block" href="<?= e(url('requests')) ?>">Back to my requests</a>
        </div>
    </form>
</div>
