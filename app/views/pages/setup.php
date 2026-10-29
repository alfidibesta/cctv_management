<?php
/** @var array $checks */
/** @var array $errors */
require_once BASE_PATH . '/app/views/partials/icons.php';

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<div class="auth-card auth-card--wide">
    <div class="auth-card__brand">
        <span class="brand__mark brand__mark--lg"><?= icon('scan') ?></span>
        <h1>Install Argus</h1>
        <p>Connect MySQL and create the first administrator. Takes a minute.</p>
    </div>

    <ul class="setup-checks">
        <?php foreach ($checks as $check): ?>
            <li class="<?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
                <?= icon($check['ok'] ? 'check' : 'info') ?>
                <span><?= e($check['label']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (!empty($errors)): ?>
        <div class="alert alert--error">
            <?= e(reset($errors)) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/setup')) ?>" class="stack">
        <?= csrf_field() ?>

        <p class="section-title">Database</p>
        <div class="grid-2">
            <label class="field">
                <span class="field__label">Host</span>
                <input class="input" type="text" name="db_host" value="<?= old('db_host', '127.0.0.1') ?>" required>
            </label>
            <label class="field">
                <span class="field__label">Port</span>
                <input class="input" type="number" name="db_port" value="<?= old('db_port', '3306') ?>" required>
            </label>
            <label class="field">
                <span class="field__label">Database name</span>
                <input class="input" type="text" name="db_name" value="<?= old('db_name', 'argus') ?>" required>
                <span class="field__hint">Created automatically when the user has permission.</span>
            </label>
            <label class="field">
                <span class="field__label">MySQL user</span>
                <input class="input" type="text" name="db_user" value="<?= old('db_user', 'root') ?>" required>
            </label>
            <label class="field field--full">
                <span class="field__label">MySQL password</span>
                <input class="input" type="password" name="db_password" autocomplete="off">
            </label>
        </div>

        <p class="section-title">Application</p>
        <label class="field">
            <span class="field__label">Wall name</span>
            <input class="input" type="text" name="app_name" value="<?= old('app_name', 'Argus') ?>" maxlength="60" required>
        </label>

        <p class="section-title">Administrator</p>
        <label class="field">
            <span class="field__label">Full name</span>
            <input class="input" type="text" name="admin_name" value="<?= old('admin_name') ?>" maxlength="120" placeholder="Alex Tan">
        </label>
        <label class="field">
            <span class="field__label">Email</span>
            <input class="input" type="email" name="admin_email" value="<?= old('admin_email') ?>" required placeholder="admin@company.com">
        </label>
        <div class="grid-2">
            <label class="field">
                <span class="field__label">Password</span>
                <input class="input" type="password" name="admin_password" required autocomplete="new-password">
            </label>
            <label class="field">
                <span class="field__label">Confirm</span>
                <input class="input" type="password" name="admin_password_confirm" required autocomplete="new-password">
            </label>
        </div>

        <label class="switch">
            <input type="checkbox" name="seed_demo" value="1" checked>
            <span class="switch__track"></span>
            <span class="switch__label">
                <strong>Add demo cameras</strong>
                <small>Six placeholder feeds so you can explore the wall immediately.</small>
            </span>
        </label>

        <button class="btn btn--primary btn--block" type="submit" <?= $allOk ? '' : 'disabled' ?>>
            <?= icon('check') ?> Install Argus
        </button>

        <?php if (!$allOk): ?>
            <p class="field-error">Fix the missing requirements above before installing.</p>
        <?php endif; ?>
    </form>
</div>
