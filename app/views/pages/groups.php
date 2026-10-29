<?php
/** @var array $groups */
require_once BASE_PATH . '/app/views/partials/icons.php';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Camera groups</h2>
        <p class="page-head__sub">Group cameras by site or zone, then grant viewers access per group.</p>
    </div>
</div>

<div class="split">
    <section class="card">
        <header class="card__head">
            <h3>Groups</h3>
            <p><?= count($groups) ?> group<?= count($groups) === 1 ? '' : 's' ?></p>
        </header>

        <div class="card__body card__body--flush">
            <?php if (!$groups): ?>
                <div class="empty empty--compact">
                    <?= icon('groups', 'empty__icon') ?>
                    <h3>No groups yet</h3>
                    <p>Create a group to organise cameras by building or zone.</p>
                </div>
            <?php else: ?>
                <ul class="group-list">
                    <?php foreach ($groups as $group): ?>
                        <li class="group-row">
                            <form method="post" action="<?= e(url('/groups/' . (int) $group['id'])) ?>" class="group-row__form">
                                <?= csrf_field() ?>
                                <span class="group-row__icon"><?= icon('groups') ?></span>
                                <div class="group-row__fields">
                                    <input class="input input--inline" type="text" name="name"
                                           value="<?= e($group['name']) ?>" required maxlength="120" aria-label="Group name">
                                    <input class="input input--inline input--quiet" type="text" name="description"
                                           value="<?= e($group['description']) ?>" maxlength="255"
                                           placeholder="Description" aria-label="Description">
                                </div>
                                <span class="badge badge--quiet"><?= (int) $group['camera_count'] ?> cameras</span>
                                <button class="btn btn--ghost btn--sm" type="submit">Save</button>
                            </form>
                            <form method="post" action="<?= e(url('/groups/' . (int) $group['id'] . '/delete')) ?>"
                                  data-confirm="Delete <?= e($group['name']) ?>? Its cameras become ungrouped.">
                                <?= csrf_field() ?>
                                <button class="icon-btn icon-btn--sm icon-btn--danger" type="submit"
                                        title="Delete group" aria-label="Delete <?= e($group['name']) ?>"><?= icon('trash') ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <header class="card__head">
            <h3>New group</h3>
            <p>Zones, buildings or sites.</p>
        </header>
        <div class="card__body">
            <form method="post" action="<?= e(url('/groups')) ?>" class="stack">
                <?= csrf_field() ?>
                <label class="field">
                    <span class="field__label">Name <em>*</em></span>
                    <input class="input" type="text" name="name" required maxlength="120" placeholder="Head office">
                </label>
                <label class="field">
                    <span class="field__label">Description</span>
                    <input class="input" type="text" name="description" maxlength="255" placeholder="Ground floor and lobby">
                </label>
                <button class="btn btn--primary btn--block" type="submit"><?= icon('plus') ?> Create group</button>
            </form>
        </div>
    </section>
</div>
