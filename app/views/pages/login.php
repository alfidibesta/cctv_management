<?php
/** @var array $errors */
require_once BASE_PATH . '/app/views/partials/icons.php';
$appName = \Argus\Support\Settings::get('app_name') ?: \Argus\Support\Config::appName();
?>
<div class="auth-card">
    <div class="auth-card__brand">
        <span class="brand__mark brand__mark--lg"><?= icon('scan') ?></span>
        <h1><?= e($appName) ?></h1>
        <p>Sign in to monitor your camera wall.</p>
    </div>

    <?php if (csrf_failed()): ?>
        <div class="alert alert--error">Your session expired. Please try again.</div>
    <?php endif; ?>

    <?php if (!empty($errors['email'])): ?>
        <div class="alert alert--error"><?= e($errors['email']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" class="stack" novalidate>
        <?= csrf_field() ?>

        <label class="field">
            <span class="field__label">Email</span>
            <span class="input-wrap">
                <?= icon('mail', 'input-wrap__icon') ?>
                <input class="input" type="email" name="email" value="<?= old('email') ?>"
                       placeholder="you@company.com" autocomplete="username" required autofocus>
            </span>
        </label>

        <label class="field">
            <span class="field__label">Password</span>
            <span class="input-wrap">
                <?= icon('lock', 'input-wrap__icon') ?>
                <input class="input" type="password" name="password"
                       placeholder="••••••••" autocomplete="current-password" required>
            </span>
        </label>

        <button class="btn btn--primary btn--block" type="submit">Sign in</button>
    </form>

    <p class="auth-card__hint">
        Accounts are managed by your administrator.
    </p>
</div>
