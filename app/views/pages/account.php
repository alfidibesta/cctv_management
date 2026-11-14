<?php
/** @var array $me */
/** @var array $errors */
require_once BASE_PATH . '/app/views/partials/icons.php';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">My account</h2>
        <p class="page-head__sub">Update your profile and password.</p>
    </div>
</div>

<div class="split">
    <section class="card">
        <header class="card__head">
            <h3>Profile</h3>
            <p>Signed in as <?= e(ucfirst($me['role'])) ?></p>
        </header>
        <div class="card__body">
            <form method="post" action="<?= e(url('/account')) ?>" class="stack">
                <?= csrf_field() ?>

                <label class="field">
                    <span class="field__label">Name</span>
                    <input class="input" type="text" name="name" required maxlength="120"
                           value="<?= e($old['name'] ?? $me['name']) ?>">
                    <?= error_for('name', $errors) ?>
                </label>

                <label class="field">
                    <span class="field__label">Email</span>
                    <input class="input" type="email" name="email" required maxlength="190"
                           value="<?= e($old['email'] ?? $me['email']) ?>">
                    <?= error_for('email', $errors) ?>
                </label>

                <hr class="rule">
                <p class="section-title">Change password</p>

                <label class="field">
                    <span class="field__label">Current password</span>
                    <input class="input" type="password" name="current_password" autocomplete="current-password">
                    <?= error_for('current_password', $errors) ?>
                </label>

                <label class="field">
                    <span class="field__label">New password</span>
                    <input class="input" type="password" name="password" autocomplete="new-password">
                    <span class="field__hint">Leave blank to keep your current password.</span>
                    <?= error_for('password', $errors) ?>
                </label>

                <button class="btn btn--primary" type="submit"><?= icon('check') ?> Save profile</button>
            </form>
        </div>
    </section>

    <section class="card card--muted">
        <div class="card__body stack-sm">
            <h4 class="card__mini-title"><?= icon('shield') ?> Access</h4>
            <ul class="list-tips">
                <li><strong>Viewer</strong> — sees cameras in the groups assigned to them.</li>
                <li><strong>Operator</strong> — all cameras, digital zoom/pan and wall layouts.</li>
                <li><strong>Administrator</strong> — full control including users and settings.</li>
            </ul>
            <p class="field__hint">Role changes are made by an administrator.</p>
        </div>
    </section>
</div>
