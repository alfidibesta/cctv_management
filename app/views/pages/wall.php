<?php
/** @var array $cameras */
/** @var array $groups */
/** @var array $layout */
/** @var array $settings */
/** @var array $stats */
/** @var bool $canManage */
require_once BASE_PATH . '/app/views/partials/icons.php';

$slots = (int) ($layout['columns'] ?? 9);
$mode = $layout['mode'] ?? 'auto';
$online = (int) ($stats['online'] ?? 0);
$total = (int) ($stats['total'] ?? 0);

$wallData = [
    'cameras'   => $cameras,
    'groups'    => $groups,
    'layout'    => ['slots' => $slots, 'tiles' => $layout['tiles'] ?? [], 'mode' => $mode],
    'refresh'   => (int) $settings['refresh'],
    'status'    => (int) $settings['status'],
    'canManage' => $canManage,
];
?>
<div class="wall" data-wall>
    <div class="wall-bar">
        <div class="wall-bar__group">
            <span class="wall-bar__label">Grid</span>
            <div class="segmented" role="group" aria-label="Grid size">
                <?php foreach ([1 => 'single', 4 => 'grid4', 9 => 'grid9', 16 => 'grid16'] as $value => $ico): ?>
                    <button type="button" class="segmented__btn <?= $slots === $value ? 'is-active' : '' ?>"
                            data-grid="<?= $value ?>" title="<?= $value ?> at a time">
                        <?= icon($ico) ?><span><?= $value === 1 ? '1×1' : ($value === 4 ? '2×2' : ($value === 9 ? '3×3' : '4×4')) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="wall-bar__group">
            <span class="wall-bar__label">Mode</span>
            <div class="segmented" role="group" aria-label="Wall mode">
                <button type="button" class="segmented__btn <?= $mode === 'auto' ? 'is-active' : '' ?>"
                        data-mode="auto" title="Page through every camera">Auto</button>
                <button type="button" class="segmented__btn <?= $mode === 'custom' ? 'is-active' : '' ?>"
                        data-mode="custom" title="Hand-picked cameras, saved per user">Custom</button>
            </div>
        </div>

        <div class="pager" data-pager hidden>
            <button type="button" class="icon-btn icon-btn--sm" data-page="prev" aria-label="Previous page">
                <?= icon('chevron-left') ?>
            </button>
            <span class="pager__label" data-page-label>1 / 1</span>
            <button type="button" class="icon-btn icon-btn--sm" data-page="next" aria-label="Next page">
                <?= icon('chevron-right') ?>
            </button>
        </div>

        <div class="wall-bar__spacer"></div>

        <button type="button" class="btn btn--ghost btn--sm" data-night-vision aria-pressed="false"
                title="Client-side IR night vision">
            <?= icon('moon') ?> <span>Night vision</span>
        </button>

        <div class="wall-stat" title="Cameras online">
            <span class="dot dot--online"></span>
            <span><strong data-online-count><?= $online ?></strong> / <?= $total ?> online</span>
        </div>

        <button type="button" class="btn btn--ghost btn--sm" data-drawer-open>
            <?= icon('camera') ?> <span>Cameras</span>
        </button>
        <button type="button" class="btn btn--ghost btn--sm" data-wall-fullscreen title="Fullscreen wall">
            <?= icon('expand') ?> <span class="hide-sm">Fullscreen</span>
        </button>
    </div>

    <div class="wall-grid" data-wall-grid aria-live="polite"></div>

    <p class="wall-empty" data-wall-empty hidden>
        No cameras yet.
        <?php if ($canManage): ?>
            <a href="<?= e(url('/cameras/new')) ?>">Add your first camera</a> to start monitoring.
        <?php endif; ?>
    </p>
</div>

<!-- Camera picker -->
<aside class="drawer" data-drawer aria-hidden="true" aria-label="Camera picker">
    <header class="drawer__head">
        <div>
            <h2>Cameras</h2>
            <p data-drawer-hint>Pick a camera for the selected tile</p>
        </div>
        <button type="button" class="icon-btn" data-drawer-close aria-label="Close"><?= icon('close') ?></button>
    </header>

    <div class="drawer__search">
        <span class="input-wrap">
            <?= icon('search', 'input-wrap__icon') ?>
            <input class="input" type="search" placeholder="Search name, location, group" data-camera-search>
        </span>
    </div>

    <div class="chips" data-group-chips>
        <button type="button" class="chip is-active" data-group="all">All <?= count($cameras) ?></button>
        <?php foreach ($groups as $group): ?>
            <button type="button" class="chip" data-group="<?= (int) $group['id'] ?>">
                <?= e($group['name']) ?> <span><?= (int) $group['count'] ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="drawer__list" data-camera-list>
        <?php foreach ($cameras as $camera): ?>
            <button type="button"
                    class="camera-row"
                    draggable="true"
                    data-camera-id="<?= (int) $camera['id'] ?>"
                    data-group-id="<?= (int) ($camera['group_id'] ?? 0) ?>"
                    data-search="<?= e(mb_strtolower($camera['name'] . ' ' . $camera['location'] . ' ' . $camera['group'])) ?>">
                <span class="camera-row__thumb">
                    <img src="<?= e($camera['snapshot_url']) ?>" alt="" loading="lazy">
                </span>
                <span class="camera-row__body">
                    <strong><?= e($camera['name']) ?></strong>
                    <span><?= e($camera['location'] !== '' ? $camera['location'] : $camera['group']) ?></span>
                </span>
                <span class="dot dot--<?= e($camera['status']) ?>" data-status-dot="<?= (int) $camera['id'] ?>"></span>
            </button>
        <?php endforeach; ?>
        <p class="drawer__empty" data-list-empty hidden>No cameras match your filter.</p>
    </div>

    <?php if ($canManage): ?>
        <footer class="drawer__foot">
            <a class="btn btn--subtle btn--block" href="<?= e(url('/cameras')) ?>"><?= icon('settings') ?> Manage cameras</a>
        </footer>
    <?php endif; ?>
</aside>
<div class="scrim" data-scrim hidden></div>

<!-- Expanded camera view: digital zoom, pan, PiP and latency HUD -->
<div class="modal" data-camera-modal hidden>
    <div class="modal__panel modal__panel--wide" role="dialog" aria-modal="true" aria-labelledby="camera-modal-title">
        <header class="modal__head">
            <div>
                <h2 id="camera-modal-title" data-modal-title>Camera</h2>
                <p data-modal-subtitle></p>
            </div>
            <div class="modal__head-actions">
                <span class="status-pill" data-modal-status><span class="dot"></span> Unknown</span>
                <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('close') ?></button>
            </div>
        </header>
        <div class="modal__media" data-modal-media></div>
        <div class="modal__body" data-modal-body></div>
    </div>
</div>

<script type="application/json" id="wall-data"><?= json_encode($wallData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
