<?php
/** @var array $users */
/** @var array $groups */
require_once BASE_PATH . '/app/views/partials/icons.php';

$roleLabel = static fn (string $role): string => match ($role) {
    'admin'    => 'Administrator',
    'operator' => 'Operator',
    default    => 'Viewer',
};
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Users &amp; roles</h2>
        <p class="page-head__sub">
            Administrators configure everything, operators can control cameras and PTZ, viewers only see their groups.
        </p>
    </div>
    <div class="page-head__actions">
        <button type="button" class="btn btn--primary" data-user-new><?= icon('plus') ?> New user</button>
    </div>
</div>

<section class="card">
    <div class="card__body card__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Groups</th>
                        <th>Status</th>
                        <th>Last sign-in</th>
                        <th class="table__actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $userGroups = [];
                    foreach ($groups as $group) {
                        if (in_array((int) $group['id'], $user['groups'] ?? [], true)) {
                            $userGroups[] = $group['name'];
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <div class="person">
                                <span class="person__avatar"><?= e(user_initials($user['name'])) ?></span>
                                <span>
                                    <strong><?= e($user['name']) ?></strong>
                                    <small><?= e($user['email']) ?></small>
                                </span>
                            </div>
                        </td>
                        <td><span class="badge badge--<?= e($user['role']) ?>"><?= e($roleLabel($user['role'])) ?></span></td>
                        <td>
                            <?php if ($user['role'] !== 'viewer'): ?>
                                <span class="muted">All cameras</span>
                            <?php elseif ($userGroups): ?>
                                <span class="muted"><?= e(implode(', ', $userGroups)) ?></span>
                            <?php else: ?>
                                <span class="muted muted--warn">No groups</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-pill <?= (int) $user['is_active'] === 1 ? 'status-pill--on' : 'status-pill--off' ?>">
                                <span class="dot"></span><?= (int) $user['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td class="muted"><?= e(ago($user['last_login_at'])) ?></td>
                        <td>
                            <div class="table__buttons">
                                <button type="button" class="icon-btn icon-btn--sm" title="Edit"
                                        data-user-edit="<?= e(json_encode([
                                            'id'     => (int) $user['id'],
                                            'name'   => $user['name'],
                                            'email'  => $user['email'],
                                            'role'   => $user['role'],
                                            'active' => (int) $user['is_active'],
                                            'groups' => array_map('intval', $user['groups'] ?? []),
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG)) ?>">
                                    <?= icon('edit') ?>
                                </button>
                                <?php if ((int) $user['id'] !== (int) current_user()['id']): ?>
                                    <form method="post" action="<?= e(url('/users/' . (int) $user['id'] . '/delete')) ?>"
                                          data-confirm="Delete <?= e($user['name']) ?>?">
                                        <?= csrf_field() ?>
                                        <button class="icon-btn icon-btn--sm icon-btn--danger" type="submit"
                                                title="Delete" aria-label="Delete <?= e($user['name']) ?>"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal" data-modal="user-modal" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
        <form method="post" action="<?= e(url('/users')) ?>" data-user-form>
            <?= csrf_field() ?>
            <header class="modal__head">
                <div>
                    <h2 id="user-modal-title" data-user-title>New user</h2>
                    <p data-user-subtitle>Create an account for a colleague.</p>
                </div>
                <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('close') ?></button>
            </header>

            <div class="modal__body stack">
                <div class="grid-2">
                    <label class="field">
                        <span class="field__label">Name <em>*</em></span>
                        <input class="input" type="text" name="name" required maxlength="120" data-user-field="name">
                    </label>
                    <label class="field">
                        <span class="field__label">Email <em>*</em></span>
                        <input class="input" type="email" name="email" required maxlength="190" data-user-field="email">
                    </label>
                </div>

                <div class="grid-2">
                    <label class="field">
                        <span class="field__label">Role</span>
                        <select class="select" name="role" data-user-field="role">
                            <option value="viewer">Viewer — watch assigned groups</option>
                            <option value="operator">Operator — all cameras + PTZ</option>
                            <option value="admin">Administrator — full access</option>
                        </select>
                    </label>
                    <label class="field">
                        <span class="field__label">Password <span data-password-hint></span></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" data-user-field="password">
                        <span class="field__hint">Minimum 8 characters. Leave blank to keep the current password.</span>
                    </label>
                </div>

                <div class="field">
                    <span class="field__label">Group access <span class="muted">(viewers only)</span></span>
                    <div class="checkgrid" data-user-groups>
                        <?php foreach ($groups as $group): ?>
                            <label class="checkbox">
                                <input type="checkbox" name="groups[]" value="<?= (int) $group['id'] ?>">
                                <span><?= e($group['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$groups): ?>
                            <p class="muted">No groups yet — create one under Groups.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="switch">
                    <input type="checkbox" name="is_active" value="1" checked data-user-field="active">
                    <span class="switch__track"></span>
                    <span class="switch__label">
                        <strong>Account active</strong>
                        <small>Disable to block sign-in without deleting history.</small>
                    </span>
                </label>
            </div>

            <footer class="modal__foot">
                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn--primary">Save user</button>
            </footer>
        </form>
    </div>
</div>
