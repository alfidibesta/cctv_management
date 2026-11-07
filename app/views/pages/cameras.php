<?php
/** @var array $cameras */
/** @var array $groups */
/** @var array $filter */
/** @var array $counts */
/** @var bool $canManage */
/** @var array|null $probe */
require_once BASE_PATH . '/app/views/partials/icons.php';

$thumb = static fn (array $camera): string => url('/stream.php?camera=' . (int) $camera['id'] . '&type=snapshot');
$probe = $probe ?? null;
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Camera inventory</h2>
        <p class="page-head__sub">
            <?= (int) $counts['total'] ?> configured · <?= (int) $counts['online'] ?> online ·
            <?= (int) $counts['offline'] ?> offline
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="page-head__actions">
            <button type="button" class="btn btn--ghost" data-modal-open="bulk-modal"><?= icon('layers') ?> Bulk import</button>
            <a class="btn btn--primary" href="<?= e(url('/cameras/new')) ?>"><?= icon('plus') ?> Add camera</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($probe): ?>
    <?php
    $probeOk = !empty($probe['ok']);
    $probeCamera = $probe['camera'] ?? ['id' => 0, 'name' => 'Camera'];
    $probeSteps = is_array($probe['steps'] ?? null) ? $probe['steps'] : [];
    ?>
    <section class="check-result <?= $probeOk ? 'is-ok' : 'is-bad' ?>" data-check-result>
        <header class="check-result__head">
            <span class="check-result__mark"><?= icon($probeOk ? 'check' : 'info') ?></span>
            <div class="check-result__text">
                <h3><?= e($probeCamera['name']) ?> — <?= $probeOk ? 'connection verified' : 'needs attention' ?></h3>
                <p>
                    <?= e($probe['headline'] ?? '') ?>
                    <?php if (!empty($probe['latency_ms'])): ?>
                        <span class="muted">· <?= (int) $probe['latency_ms'] ?> ms</span>
                    <?php endif; ?>
                </p>
            </div>
            <button type="button" class="icon-btn icon-btn--sm" data-dismiss-check aria-label="Dismiss">
                <?= icon('close') ?>
            </button>
        </header>

        <p class="check-result__detail"><?= e($probe['detail'] ?? '') ?></p>

        <?php if ($probeSteps): ?>
            <ol class="check-result__steps">
                <?php foreach ($probeSteps as $index => $step): ?>
                    <li>
                        <span class="check-result__num"><?= $index + 1 ?></span>
                        <span><?= e($step) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <footer class="check-result__foot">
            <?php if ($canManage && (int) ($probeCamera['id'] ?? 0) > 0): ?>
                <button type="button" class="btn btn--ghost btn--sm"
                        data-test-camera="<?= (int) $probeCamera['id'] ?>"
                        data-test-url="<?= e(url('/cameras/' . (int) $probeCamera['id'] . '/test')) ?>">
                    <?= icon('refresh') ?> Test again
                </button>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('/cameras/' . (int) $probeCamera['id'] . '/edit')) ?>">
                    <?= icon('edit') ?> Fix settings
                </a>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('/?camera=' . (int) $probeCamera['id'])) ?>">
                    <?= icon('eye') ?> Open on wall
                </a>
            <?php endif; ?>
            <div class="test-result" data-test-result hidden></div>
        </footer>
    </section>
<?php endif; ?>

<form class="toolbar" method="get" action="<?= e(url('/cameras')) ?>">
    <span class="input-wrap toolbar__search">
        <?= icon('search', 'input-wrap__icon') ?>
        <input class="input" type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Search cameras">
    </span>
    <select class="select toolbar__select" name="group" onchange="this.form.submit()">
        <option value="0">All groups</option>
        <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group['id'] ?>" <?= selected($filter['group'], $group['id']) ?>>
                <?= e($group['name']) ?> (<?= (int) $group['camera_count'] ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn--ghost btn--sm" type="submit"><?= icon('filter') ?> Filter</button>
    <?php if ($filter['q'] !== '' || $filter['group'] > 0): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/cameras')) ?>">Reset</a>
    <?php endif; ?>
</form>

<?php if (!$cameras): ?>
    <div class="empty">
        <?= icon('camera', 'empty__icon') ?>
        <h3>No cameras found</h3>
        <p>
            <?= $filter['q'] !== '' || $filter['group'] > 0
                ? 'Nothing matches this filter. Try another group or search term.'
                : 'Add an ACTi or ONVIF camera to start building your wall.' ?>
        </p>
        <?php if ($canManage && $filter['q'] === '' && $filter['group'] === 0): ?>
            <a class="btn btn--primary" href="<?= e(url('/cameras/new')) ?>"><?= icon('plus') ?> Add camera</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="camera-grid">
        <?php foreach ($cameras as $camera): ?>
            <article class="camera-card" data-camera-card="<?= (int) $camera['id'] ?>">
                <div class="camera-card__media">
                    <img src="<?= e($thumb($camera)) ?>" alt="<?= e($camera['name']) ?>" loading="lazy">
                    <span class="camera-card__status">
                        <span class="dot dot--<?= e($camera['status'] ?: 'unknown') ?>" data-status-dot="<?= (int) $camera['id'] ?>"></span>
                        <span data-status-text><?= e(status_label((string) $camera['status'])) ?></span>
                    </span>
                    <span class="camera-card__mode"><?= e(strtoupper((string) $camera['stream_mode'])) ?></span>
                    <?php if (!(int) $camera['is_enabled']): ?>
                        <span class="camera-card__disabled">Disabled</span>
                    <?php endif; ?>
                </div>

                <div class="camera-card__body">
                    <div class="camera-card__title">
                        <h3><?= e($camera['name']) ?></h3>
                        <span class="badge"><?= e($camera['group_name'] ?? 'Ungrouped') ?></span>
                    </div>
                    <p class="camera-card__meta">
                        <?= e($camera['location'] !== '' ? $camera['location'] : 'No location set') ?>
                    </p>
                    <p class="camera-card__host">
                        <?= icon('camera') ?>
                        <span><?= e($camera['host']) ?>:<?= (int) $camera['port'] ?></span>
                        <span class="dot-dash"></span>
                        <span><?= e(ucfirst((string) $camera['driver'])) ?></span>
                    </p>

                    <?php if (!empty($camera['last_error']) && $camera['status'] === 'offline'): ?>
                        <p class="camera-card__error" title="<?= e($camera['last_error']) ?>"><?= e($camera['last_error']) ?></p>
                    <?php endif; ?>

                    <div class="camera-card__actions">
                        <button type="button" class="btn btn--ghost btn--sm" data-test-camera="<?= (int) $camera['id'] ?>"
                                data-test-url="<?= e(url('/cameras/' . (int) $camera['id'] . '/test')) ?>">
                            <?= icon('refresh') ?> Test
                        </button>
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('/?camera=' . (int) $camera['id'])) ?>">
                            <?= icon('eye') ?> View
                        </a>
                        <?php if ($canManage): ?>
                            <a class="icon-btn icon-btn--sm" href="<?= e(url('/cameras/' . (int) $camera['id'] . '/edit')) ?>"
                               title="Edit" aria-label="Edit <?= e($camera['name']) ?>"><?= icon('edit') ?></a>
                            <form method="post" action="<?= e(url('/cameras/' . (int) $camera['id'] . '/delete')) ?>"
                                  data-confirm="Remove <?= e($camera['name']) ?> from the wall?">
                                <?= csrf_field() ?>
                                <button class="icon-btn icon-btn--sm icon-btn--danger" type="submit"
                                        title="Delete" aria-label="Delete <?= e($camera['name']) ?>"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="test-result" data-card-result hidden></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal" data-modal="bulk-modal" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="bulk-title">
        <form method="post" action="<?= e(url('/cameras/bulk')) ?>">
            <?= csrf_field() ?>
            <header class="modal__head">
                <div>
                    <h2 id="bulk-title">Bulk import cameras</h2>
                    <p>One camera per line — ideal for a whole site.</p>
                </div>
                <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('close') ?></button>
            </header>

            <div class="modal__body stack">
                <label class="field">
                    <span class="field__label">Camera list</span>
                    <textarea class="textarea textarea--mono" name="rows" rows="8" required
                              placeholder="Front desk,10.0.0.21,80,admin,password,acti&#10;Warehouse,10.0.0.22,80,admin,password,acti"></textarea>
                    <span class="field__hint">
                        <code>name, host, port, username, password, driver</code> — port, credentials and driver are optional.
                        Lines starting with # are ignored.
                    </span>
                </label>

                <div class="grid-2">
                    <label class="field">
                        <span class="field__label">Default driver</span>
                        <select class="select" name="driver">
                            <?php foreach (\Argus\Services\StreamUrl::DRIVERS as $key => $driver): ?>
                                <option value="<?= e($key) ?>" <?= $key === 'acti' ? 'selected' : '' ?>>
                                    <?= e($driver['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span class="field__label">Default port</span>
                        <input class="input" type="number" name="port" value="80" min="1" max="65535">
                    </label>

                    <label class="field">
                        <span class="field__label">Stream mode</span>
                        <select class="select" name="stream_mode">
                            <option value="mjpeg">MJPEG (live in browser)</option>
                            <option value="snapshot">Snapshot refresh</option>
                            <option value="hls">HLS via ffmpeg</option>
                        </select>
                    </label>

                    <label class="field">
                        <span class="field__label">Group</span>
                        <select class="select" name="group_id">
                            <option value="0">No group</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <p class="alert alert--info">
                    <?= icon('info') ?>
                    Bulk import uses the driver's default snapshot/MJPEG/RTSP paths. You can fine-tune each camera afterwards.
                </p>
            </div>

            <footer class="modal__foot">
                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn--primary">Import cameras</button>
            </footer>
        </form>
    </div>
</div>
<?php endif; ?>

<script type="application/json" id="cameras-data"><?= json_encode([
    'statusUrl' => url('/api/status'),
    'ids'       => array_map(static fn (array $c): int => (int) $c['id'], $cameras),
], JSON_UNESCAPED_SLASHES) ?></script>
