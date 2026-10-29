<?php
/** @var array $entries */
require_once BASE_PATH . '/app/views/partials/icons.php';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Activity</h2>
        <p class="page-head__sub">Sign-ins, camera changes and PTZ commands — the last 200 events.</p>
    </div>
</div>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if (!$entries): ?>
            <div class="empty empty--compact">
                <?= icon('activity', 'empty__icon') ?>
                <h3>No activity yet</h3>
                <p>Actions will appear here as soon as people start using the wall.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>When</th><th>Actor</th><th>Action</th><th>Detail</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td class="nowrap muted"><?= e(dt($entry['created_at'], 'd M, H:i:s')) ?></td>
                            <td><?= e($entry['user_name'] ?? 'System') ?></td>
                            <td><code class="tag"><?= e($entry['action']) ?></code></td>
                            <td><?= e($entry['description']) ?></td>
                            <td class="muted nowrap"><?= e($entry['ip']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
