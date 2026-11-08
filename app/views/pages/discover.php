<?php
/** @var array $devices */
/** @var array $results */
/** @var array $groups */
/** @var array $errors */
/** @var string $address */
/** @var string|null $scannedAt */
require_once BASE_PATH . '/app/views/partials/icons.php';

$devices = $devices ?? [];
$results = $results ?? [];
$groups = $groups ?? [];
$errors = $errors ?? [];
$address = $address ?? '';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Discover cameras</h2>
        <p class="page-head__sub">
            Find ONVIF cameras with WS-Discovery, then add the profile you need — main for detail,
            sub-stream for a large wall.
        </p>
    </div>
    <div class="page-head__actions">
        <form method="post" action="<?= e(url('/discover/scan')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--primary" type="submit"><?= icon('scan') ?> Scan network</button>
        </form>
    </div>
</div>

<section class="card">
    <header class="card__head">
        <div class="card__head__text">
            <h3>Scan results</h3>
            <p>
                <?= $scannedAt
                    ? 'Last scan ' . e(ago($scannedAt)) . ' — ' . count($devices) . ' device(s).'
                    : 'Multicast discovery only reaches this network segment.' ?>
            </p>
        </div>
        <button class="collapse-toggle" type="button" data-collapse-toggle>
            <span class="collapse-label">Hide</span>
        </button>
    </header>
    <div class="card__body card__body--flush">
        <?php if (!$devices): ?>
            <div class="empty empty--compact">
                <?= icon('scan', 'empty__icon') ?>
                <h3>No devices yet</h3>
                <p>
                    Cameras behind a router, VLAN or Docker bridge never answer multicast. Enter the
                    address below instead — discovery is only a shortcut.
                </p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Device</th><th>Host</th><th>ONVIF service</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td>
                                <strong><?= e($device['name'] ?? $device['host']) ?></strong>
                                <?php if (!empty($device['hardware'])): ?>
                                    <span class="muted"> · <?= e($device['hardware']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= e($device['host'] ?? '') ?></td>
                            <td class="muted"><code class="tag"><?= e($device['xaddr'] ?? '') ?></code></td>
                            <td class="nowrap">
                                <a class="btn btn--ghost btn--sm"
                                   href="<?= e(url('/discover?address=' . rawurlencode((string) ($device['xaddr'] ?? '')))) ?>">
                                    Use address
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <header class="card__head">
        <div class="card__head__text">
            <h3>Device address and credentials</h3>
            <p>Works for any reachable ONVIF camera; credentials are used only for this request.</p>
        </div>
    </header>
    <div class="card__body">
        <form method="post" action="<?= e(url('/discover/probe')) ?>" class="grid-2">
            <?= csrf_field() ?>
            <label class="field">
                <span class="field__label">Address</span>
                <input class="input input--mono" type="text" name="address" required
                       placeholder="10.0.0.20 or http://10.0.0.20/onvif/device_service"
                       value="<?= e($address) ?>">
                <?= error_for('address', $errors) ?>
                <span class="field__hint">Port 80 and <code>/onvif/device_service</code> are assumed when omitted.</span>
            </label>

            <label class="field">
                <span class="field__label">ONVIF username</span>
                <input class="input" type="text" name="username" autocomplete="off" placeholder="admin">
            </label>

            <label class="field">
                <span class="field__label">ONVIF password</span>
                <input class="input" type="password" name="password" autocomplete="new-password">
                <span class="field__hint">Keep the profile open: you will enter the same password again when adding.</span>
            </label>

            <div class="field" style="align-self:end">
                <button class="btn btn--primary" type="submit"><?= icon('refresh') ?> Load profiles</button>
            </div>
        </form>
    </div>
</section>

<?php foreach ($results as $result): ?>
    <?php $info = $result['info'] ?? []; ?>
    <section class="card">
        <header class="card__head">
            <div class="card__head__text">
                <h3><?= e(($info['manufacturer'] ?? '') . ' ' . ($info['model'] ?? '')) ?: e($result['xaddr'] ?? 'Device') ?></h3>
                <p>
                    <?= e($result['xaddr'] ?? '') ?>
                    <?php if (!empty($info['firmware'])): ?> · firmware <?= e($info['firmware']) ?><?php endif; ?>
                    <?php if (!empty($result['checked'])): ?> · read <?= e(ago($result['checked'])) ?><?php endif; ?>
                </p>
            </div>
        </header>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Profile</th><th>Stream</th><th>Resolution</th><th>FPS</th>
                            <th>Add as</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['profiles'] ?? []) as $profile): ?>
                        <tr>
                            <td>
                                <strong><?= e($profile['name']) ?></strong>
                                <span class="badge"><?= $profile['kind'] === 'main' ? 'Main' : 'Sub' ?></span>
                                <div class="muted"><code class="tag"><?= e($profile['token']) ?></code></div>
                            </td>
                            <td><?= e($profile['encoding'] ?: '—') ?></td>
                            <td class="nowrap">
                                <?= $profile['width'] > 0 ? (int) $profile['width'] . '×' . (int) $profile['height'] : '—' ?>
                            </td>
                            <td><?= $profile['fps'] > 0 ? (int) $profile['fps'] : '—' ?></td>
                            <td>
                                <form method="post" action="<?= e(url('/discover/add')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="rtsp_uri" value="<?= e($profile['rtsp']) ?>">
                                    <input type="hidden" name="snapshot_uri" value="<?= e($profile['snapshot']) ?>">
                                    <input type="hidden" name="username" value="<?= e($profile['user'] ?: ($result['username'] ?? '')) ?>">

                                    <div class="grid-2">
                                        <label class="field">
                                            <span class="field__label">Name</span>
                                            <input class="input" type="text" name="name" required
                                                   value="<?= e(($info['model'] ?? $info['host'] ?? 'Camera') . ' · ' . $profile['name']) ?>">
                                        </label>

                                        <label class="field">
                                            <span class="field__label">Location</span>
                                            <input class="input" type="text" name="location" value="">
                                        </label>

                                        <label class="field">
                                            <span class="field__label">Group</span>
                                            <select class="select" name="group_id">
                                                <option value="0">Ungrouped</option>
                                                <?php foreach ($groups as $group): ?>
                                                    <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>

                                        <label class="field">
                                            <span class="field__label">Stream mode</span>
                                            <select class="select" name="stream_mode">
                                                <option value="hls">HLS (RTSP via ffmpeg)</option>
                                                <?php if (!empty($profile['snapshot'])): ?>
                                                    <option value="snapshot">Snapshot (low bandwidth)</option>
                                                <?php endif; ?>
                                            </select>
                                        </label>

                                        <label class="field">
                                            <span class="field__label">Password</span>
                                            <input class="input" type="password" name="password" autocomplete="new-password">
                                        </label>

                                        <div class="field" style="align-self:end">
                                            <button class="btn btn--primary btn--sm" type="submit">
                                                <?= icon('plus') ?> Add camera
                                            </button>
                                        </div>
                                    </div>

                                    <?php if (empty($profile['rtsp'])): ?>
                                        <p class="field__hint">This profile has no RTSP URI — use Snapshot mode.</p>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endforeach; ?>
