<?php
/**
 * Registration page.
 *
 * Only CITIZEN / VOLUNTEER / ORGANIZATION can be chosen — ADMIN is never
 * offered in the UI and is rejected server-side.
 *
 * @var array<string, array<int, string>> $errors
 */
$errors = $errors ?? [];
$fieldError = static function (string $field) use ($errors): ?string {
    return $errors[$field][0] ?? null;
};
$roleHint = static function (string $role): string {
    return match ($role) {
        'VOLUNTEER'    => 'Offer help to nearby emergencies.',
        'ORGANIZATION' => 'Contribute resources and coordinate relief.',
        default        => 'Create and track help requests.',
    };
};
?>
<section class="auth">
    <div class="auth__card card">
        <header class="auth__header">
            <p class="eyebrow">ResQLink</p>
            <h1 class="auth__title">Create your account</h1>
            <p class="muted">Join the emergency coordination network.</p>
        </header>

        <form method="post" action="<?= e(url('register')) ?>" class="form" data-loading novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label class="label" for="name">Full name</label>
                <input
                    class="input<?= $fieldError('name') ? ' input--invalid' : '' ?>"
                    id="name"
                    name="name"
                    type="text"
                    autocomplete="name"
                    minlength="2"
                    maxlength="120"
                    required
                    value="<?= e(old('name', '')) ?>"
                    <?= $fieldError('name') ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>
                >
                <?php if ($fieldError('name')): ?>
                    <p class="field__error" id="name-error"><?= e($fieldError('name')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="email">Email address</label>
                <input
                    class="input<?= $fieldError('email') ? ' input--invalid' : '' ?>"
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    required
                    value="<?= e(old('email', '')) ?>"
                    <?= $fieldError('email') ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                >
                <?php if ($fieldError('email')): ?>
                    <p class="field__error" id="email-error"><?= e($fieldError('email')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="phone">Phone <span class="optional">(optional)</span></label>
                <input
                    class="input<?= $fieldError('phone') ? ' input--invalid' : '' ?>"
                    id="phone"
                    name="phone"
                    type="tel"
                    autocomplete="tel"
                    maxlength="20"
                    value="<?= e(old('phone', '')) ?>"
                    <?= $fieldError('phone') ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>
                >
                <?php if ($fieldError('phone')): ?>
                    <p class="field__error" id="phone-error"><?= e($fieldError('phone')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="password">Password</label>
                <input
                    class="input<?= $fieldError('password') ? ' input--invalid' : '' ?>"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    minlength="8"
                    required
                    aria-describedby="password-help"
                    <?= $fieldError('password') ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>
                >
                <p class="field__help" id="password-help">At least 8 characters.</p>
                <?php if ($fieldError('password')): ?>
                    <p class="field__error" id="password-error"><?= e($fieldError('password')) ?></p>
                <?php endif; ?>
            </div>

            <fieldset class="field">
                <legend class="label">I am registering as</legend>
                <?php
                $selectedRole = (string) old('role', 'CITIZEN');
                $options = ['CITIZEN', 'VOLUNTEER', 'ORGANIZATION'];
                ?>
                <?php foreach ($options as $option): ?>
                    <label class="radio" for="role-<?= e(strtolower($option)) ?>">
                        <input
                            id="role-<?= e(strtolower($option)) ?>"
                            type="radio"
                            name="role"
                            value="<?= e($option) ?>"
                            <?= $selectedRole === $option ? 'checked' : '' ?>
                        >
                        <span class="radio__body">
                            <strong><?= e($option) ?></strong>
                            <small class="muted"><?= e($roleHint($option)) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>

                <?php if ($fieldError('role')): ?>
                    <p class="field__error"><?= e($fieldError('role')) ?></p>
                <?php endif; ?>
                <p class="field__help">Administrator accounts are provisioned by ResQLink staff, not self-selected.</p>
            </fieldset>

            <button class="btn btn--primary btn--block" type="submit">Create account</button>
        </form>

        <p class="auth__alt">
            Already registered? <a href="<?= e(url('login')) ?>">Sign in</a>
        </p>
    </div>
</section>
