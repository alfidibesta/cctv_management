<?php
/** @var array $entries */
/** @var array $logs */
/** @var string $tab */
/** @var string $level */
/** @var string $logPath */
/** @var bool $logExists */
require_once BASE_PATH . '/app/views/partials/icons.php';

$tab = $tab ?? 'activity';
$level = $level ?? '';
$logs = $logs ?? [];
$entries = $entries ?? [];
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title"><?= $tab === 'errors' ? 'Logs' : 'Activity' ?></h2>
        <p class="page-head__sub">
            <?= $tab === 'errors'
                ? 'Runtime errors and warnings from the app and PHP — newest first.'
                : 'Sign-ins, camera changes, checks and PTZ commands — the last 200 events.' ?>
        </p>
    </div>
</div>

<nav class="tabs" aria-label="Log views">
    <a class="tabs__link <?= $tab === 'activity' ? 'is-active' : '' ?>" href="<?= e(url('/activity')) ?>">
        <?= icon('activity') ?> Activity
    </a>
    <a class="tabs__link <?= $tab === 'errors' ? 'is-active' : '' ?>" href="<?= e(url('/activity?tab=errors')) ?>">
        <?= icon('info') ?> Errors
        <?php if ($logs && $tab === 'errors'): ?>
            <span class="tabs__count"><?= count($logs) ?></span>
        <?php endif; ?>
    </a>
</nav>

<?php if ($tab === 'activity'): ?>
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
<?php else: ?>
    <form class="toolbar" method="get" action="<?= e(url('/activity')) ?>">
        <input type="hidden" name="tab" value="errors">
        <select class="select toolbar__select" name="level" onchange="this.form.submit()">
            <option value="">All levels</option>
            <?php foreach (['error', 'warning', 'info', 'debug'] as $option): ?>
                <option value="<?= e($option) ?>" <?= selected($level, $option) ?>><?= e(ucfirst($option)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($level !== ''): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/activity?tab=errors')) ?>">Reset</a>
        <?php endif; ?>
        <span class="wall-bar__spacer"></span>
        <span class="field__hint">File: <code><?= e($logPath ?: 'storage/logs/app.log') ?></code></span>
    </form>

    <section class="card">
        <div class="card__body card__body--flush">
            <?php if (!$logs): ?>
                <div class="empty empty--compact">
                    <?= icon('check', 'empty__icon') ?>
                    <h3><?= $logExists ? 'Nothing at this level' : 'No errors logged' ?></h3>
                    <p>
                        <?= $logExists
                            ? 'No entries match the selected level. Pick "All levels" to see everything.'
                            : 'Errors and warnings will be collected here as soon as something goes wrong.' ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>When</th><th>Level</th><th>Message</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $row): ?>
                            <tr>
                                <td class="nowrap muted"><?= e(dt($row['time'], 'd M, H:i:s')) ?></td>
                                <td>
                                    <code class="tag <?= $row['level'] === 'error' ? 'tag--error' : ($row['level'] === 'warning' ? 'tag--warning' : '') ?>">
                                        <?= e($row['level']) ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="log-line"><?= e($row['message']) ?></span>
                                    <?php if ($row['context']): ?>
                                        <span class="log-meta">
                                            <?php
                                            $pairs = [];
                                            foreach ($row['context'] as $key => $value) {
                                                if (is_array($value)) {
                                                    $value = implode(' | ', array_map('strval', $value));
                                                }
                                                $pairs[] = $key . ': ' . (string) $value;
                                            }
                                            echo e(mb_substr(implode(' · ', $pairs), 0, 320));
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
