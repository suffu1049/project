<?php
/**
 * Sign-in page — modern centered auth card.
 *
 * @var array<string, array<int, string>> $errors
 */
$errors = $errors ?? [];
$fieldError = static fn (string $field): ?string => $errors[$field][0] ?? null;
?>
<section class="auth">
    <div class="auth__card card">
        <header class="auth__header">
            <p class="eyebrow">ResQLink</p>
            <h1 class="auth__title">Welcome back</h1>
            <p class="muted">Sign in to the emergency coordination platform.</p>
        </header>

        <form method="post" action="<?= e(url('login')) ?>" class="form" data-loading novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label class="label" for="email">Email address</label>
                <input
                    class="input<?= $fieldError('email') ? ' input--invalid' : '' ?>"
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    required
                    value="<?= e(old('email', '')) ?>"
                    <?= $fieldError('email') ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                >
                <?php if ($fieldError('email')): ?>
                    <p class="field__error" id="email-error"><?= e($fieldError('email')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="password">Password</label>
                <input
                    class="input<?= $fieldError('password') ? ' input--invalid' : '' ?>"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required
                    <?= $fieldError('password') ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>
                >
                <?php if ($fieldError('password')): ?>
                    <p class="field__error" id="password-error"><?= e($fieldError('password')) ?></p>
                <?php endif; ?>
            </div>

            <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>

        <p class="auth__alt">
            New here? <a href="<?= e(url('register')) ?>">Create an account</a>
        </p>

        <div class="demo-hint">
            <strong>Demo accounts</strong>
            <span class="muted">All seeded accounts use password <code>Password123!</code></span>
            <ul class="demo-hint__list">
                <li><code>admin@resqlink.test</code></li>
                <li><code>citizen1@resqlink.test</code></li>
                <li><code>volunteer1@resqlink.test</code></li>
                <li><code>org1@resqlink.test</code></li>
            </ul>
        </div>
    </div>
</section>
