<?php
/** @var array $camera */
/** @var array $groups */
/** @var array $errors */
/** @var bool $isNew */
require_once BASE_PATH . '/app/views/partials/icons.php';

$action = $isNew ? url('/cameras') : url('/cameras/' . (int) $camera['id']);
$hasPassword = !empty($camera['password_enc']);
$drivers = \Argus\Services\StreamUrl::DRIVERS;

$presets = [];
foreach ($drivers as $key => $driver) {
    $presets[$key] = [
        'snapshot' => $driver['snapshot'],
        'mjpeg'    => $driver['mjpeg'],
        'rtsp'     => $driver['rtsp'],
        'hint'     => $driver['hint'],
    ];
}
?>
<div class="page-head">
    <div>
        <p class="breadcrumb">
            <a href="<?= e(url('/cameras')) ?>">Cameras</a>
            <?= icon('chevron-right') ?>
            <span><?= $isNew ? 'New' : e($camera['name']) ?></span>
        </p>
        <h2 class="page-head__title"><?= $isNew ? 'Add camera' : 'Edit camera' ?></h2>
        <p class="page-head__sub">
            ACTi CGI, ONVIF profile, Hikvision, Dahua, Axis or any camera with a JPEG/MJPEG endpoint.
        </p>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" class="form-layout" data-camera-form>
    <?= csrf_field() ?>

    <div class="form-layout__main">
        <section class="card" data-collapse="camera-identity">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>Identity</h3>
                    <p>How this camera appears on the wall.</p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body grid-2">
                <label class="field">
                    <span class="field__label">Name <em>*</em></span>
                    <input class="input" type="text" name="name" required maxlength="120"
                           value="<?= e($camera['name']) ?>" placeholder="Reception">
                    <?= error_for('name', $errors) ?>
                </label>

                <label class="field">
                    <span class="field__label">Location</span>
                    <input class="input" type="text" name="location" maxlength="160"
                           value="<?= e($camera['location']) ?>" placeholder="Ground floor — main entrance">
                </label>

                <label class="field">
                    <span class="field__label">Group</span>
                    <select class="select" name="group_id">
                        <option value="0">No group</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= selected($camera['group_id'], $group['id']) ?>>
                                <?= e($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span class="field__label">Driver</span>
                    <select class="select" name="driver" data-driver-select>
                        <?php foreach ($drivers as $key => $driver): ?>
                            <option value="<?= e($key) ?>" <?= selected($camera['driver'], $key) ?>>
                                <?= e($driver['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint" data-driver-hint><?= e($drivers[$camera['driver']]['hint'] ?? '') ?></span>
                </label>
            </div>
        </section>

        <section class="card" data-connection-card data-collapse="camera-connection">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>Connection</h3>
                    <p>Camera address and credentials. Credentials are encrypted at rest and never sent to the browser.</p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body grid-2">
                <label class="field">
                    <span class="field__label">Host / IP <em>*</em></span>
                    <input class="input" type="text" name="host" maxlength="190"
                           value="<?= e($camera['host']) ?>" placeholder="10.0.0.21 or camera.local">
                    <?= error_for('host', $errors) ?>
                </label>

                <label class="field">
                    <span class="field__label">HTTP port</span>
                    <input class="input" type="number" name="port" min="1" max="65535"
                           value="<?= (int) $camera['port'] ?>">
                    <?= error_for('port', $errors) ?>
                </label>

                <label class="field">
                    <span class="field__label">Username</span>
                    <input class="input" type="text" name="username" maxlength="190" autocomplete="off"
                           value="<?= e($camera['username']) ?>" placeholder="admin">
                </label>

                <label class="field">
                    <span class="field__label">Password</span>
                    <input class="input" type="password" name="password" autocomplete="new-password"
                           placeholder="<?= $hasPassword ? 'Stored — leave blank to keep' : 'Camera password' ?>">
                    <?php if ($hasPassword): ?>
                        <label class="checkline">
                            <input type="checkbox" name="clear_password" value="1">
                            <span>Remove the stored password</span>
                        </label>
                    <?php endif; ?>
                </label>

                <?php if (!$isNew && $hasPassword): ?>
                    <div class="field field--wide credential" data-credential-panel
                         data-credential-url="<?= e(url('/cameras/' . (int) $camera['id'] . '/credentials')) ?>">
                        <div class="credential__head">
                            <div>
                                <span class="field__label">Credential check</span>
                                <span class="field__hint">
                                    Decrypts the stored password and shows the exact percent-encoded RTSP URI the server uses.
                                </span>
                            </div>
                            <button type="button" class="btn btn--subtle btn--sm" data-credential-check>
                                <?= icon('shield') ?> Reveal &amp; verify
                            </button>
                        </div>
                        <div class="credential__body" data-credential-body hidden>
                            <div class="grid-2">
                                <label class="field">
                                    <span class="field__label">Plaintext password</span>
                                    <span class="input-wrap">
                                        <input class="input input--mono" type="password" readonly
                                               value="" autocomplete="off" spellcheck="false" data-credential-plain>
                                        <button type="button" class="icon-btn icon-btn--sm" data-credential-eye
                                                aria-label="Reveal password" title="Reveal password"><?= icon('eye') ?></button>
                                    </span>
                                    <span class="field__hint">Percent-encoded: <code data-credential-encoded>—</code></span>
                                </label>
                                <label class="field">
                                    <span class="field__label">Sanitized RTSP URI</span>
                                    <input class="input input--mono" type="text" readonly value="" data-credential-uri>
                                    <span class="field__hint">Special characters are encoded as %21, %40, %23… — never stripped.</span>
                                </label>
                            </div>
                            <div class="credential__meta">
                                <span class="status-pill" data-credential-status>Not checked</span>
                                <span class="field__hint" data-credential-issues></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <label class="field">
                    <span class="field__label">RTSP transport</span>
                    <select class="select" name="transport">
                        <option value="udp" <?= selected($camera['transport'], 'udp') ?>>UDP — ultra-low latency</option>
                        <option value="tcp" <?= selected($camera['transport'], 'tcp') ?>>TCP — interleaved, packet-loss resistant</option>
                    </select>
                    <span class="field__hint">UDP is fastest on a clean LAN; TCP survives Wi-Fi and busy switches.</span>
                </label>

                <label class="field">
                    <span class="field__label">Channel</span>
                    <input class="input" type="number" name="channel" min="1" max="64" value="<?= (int) $camera['channel'] ?>">
                    <span class="field__hint">For NVRs and multi-sensor cameras.</span>
                </label>

                <label class="field">
                    <span class="field__label">RTSP port</span>
                    <input class="input" type="number" name="rtsp_port" min="1" max="65535"
                           value="<?= (int) $camera['rtsp_port'] ?>">
                </label>

                <label class="field">
                    <span class="field__label">Stream mode</span>
                    <select class="select" name="stream_mode">
                        <option value="mjpeg" <?= selected($camera['stream_mode'], 'mjpeg') ?>>MJPEG — live, low latency</option>
                        <option value="snapshot" <?= selected($camera['stream_mode'], 'snapshot') ?>>Snapshot — refresh loop</option>
                        <option value="hls" <?= selected($camera['stream_mode'], 'hls') ?>>HLS — RTSP via ffmpeg</option>
                    </select>
                    <span class="field__hint">MJPEG needs the least server CPU. HLS works for RTSP-only cameras.</span>
                </label>

            </div>
        </section>

        <section class="card" data-collapse="camera-endpoints">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>Endpoints</h3>
                    <p>Leave a field empty to use the driver default. Placeholders: <code>{host} {port} {channel} {channel2} {cmd} {preset}</code></p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body stack">
                <label class="field">
                    <span class="field__label">Snapshot path</span>
                    <input class="input input--mono" type="text" name="snapshot_path" maxlength="255"
                           data-path="snapshot" value="<?= e($camera['snapshot_path']) ?>"
                           placeholder="/cgi-bin/encoder?SNAPSHOT">
                </label>

                <label class="field">
                    <span class="field__label">MJPEG path</span>
                    <input class="input input--mono" type="text" name="mjpeg_path" maxlength="255"
                           data-path="mjpeg" value="<?= e($camera['mjpeg_path']) ?>"
                           placeholder="/-wvhttp-01-/stream/Mjpeg?framerate=25">
                </label>

                <label class="field">
                    <span class="field__label">RTSP path</span>
                    <input class="input input--mono" type="text" name="rtsp_path" maxlength="255"
                           data-path="rtsp" value="<?= e($camera['rtsp_path']) ?>"
                           placeholder="/onvif1">
                </label>
            </div>
        </section>

        <section class="card" data-collapse="camera-latency">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>RTSP stream latency</h3>
                    <p>Jitter buffer, transport and frame-drop policy for this camera. Zoom and pan on the wall are digital and browser-side — the camera is never moved.</p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body stack" data-latency-control>
                <label class="field">
                    <span class="field__label">Latency target / jitter buffer</span>
                    <span class="latency-row">
                        <input class="slider" type="range" name="latency_ms" min="50" max="2000" step="50"
                               value="<?= (int) $camera['latency_ms'] ?>" data-latency-slider>
                        <output class="latency-value" data-latency-value><?= (int) $camera['latency_ms'] ?> ms</output>
                    </span>
                    <span class="latency-presets" role="group" aria-label="Latency presets">
                        <button type="button" class="chip" data-latency-preset="100">Ultra-low 100 ms</button>
                        <button type="button" class="chip" data-latency-preset="300">Balanced 300 ms</button>
                        <button type="button" class="chip" data-latency-preset="1000">Stable 1000 ms</button>
                    </span>
                    <span class="field__hint">Lower values feel live; higher values smooth out a lossy camera link.</span>
                </label>

                <label class="switch">
                    <input type="checkbox" name="frame_drop" value="1" <?= checked((int) $camera['frame_drop'] === 1) ?>>
                    <span class="switch__track"></span>
                    <span class="switch__label">
                        <strong>Drop delayed frames</strong>
                        <small>Skips late frames and corrupt packets so playback stays real-time instead of drifting behind.</small>
                    </span>
                </label>
            </div>
        </section>
    </div>

    <aside class="form-layout__aside">
        <section class="card">
            <header class="card__head">
                <h3>Publish</h3>
            </header>
            <div class="card__body stack">
                <label class="switch">
                    <input type="checkbox" name="is_enabled" value="1" <?= checked((int) $camera['is_enabled'] === 1) ?>>
                    <span class="switch__track"></span>
                    <span class="switch__label">
                        <strong>Camera enabled</strong>
                        <small>Disabled cameras stay configured but are hidden from the wall.</small>
                    </span>
                </label>

                <button class="btn btn--primary btn--block" type="submit">
                    <?= icon('check') ?> <?= $isNew ? 'Add camera' : 'Save changes' ?>
                </button>
                <a class="btn btn--ghost btn--block" href="<?= e(url('/cameras')) ?>">Cancel</a>

                <?php if (!$isNew): ?>
                    <hr class="rule">
                    <button class="btn btn--subtle btn--block" type="button"
                            data-test-url="<?= e(url('/cameras/' . (int) $camera['id'] . '/test')) ?>"
                            data-test-camera="<?= (int) $camera['id'] ?>">
                        <?= icon('refresh') ?> Test connection
                    </button>
                    <div class="test-result" data-test-result hidden></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card card--muted">
            <div class="card__body stack-sm">
                <h4 class="card__mini-title"><?= icon('info') ?> Connection tips</h4>
                <ul class="list-tips">
                    <li>ACTi cameras usually answer on <code>/cgi-bin/encoder?SNAPSHOT</code> and RTSP <code>/onvif1</code>.</li>
                    <li>Use the camera's dedicated viewing account, not the administrator login.</li>
                    <li>Self-signed HTTPS certificates are accepted unless you enable strict TLS in settings.</li>
                    <li>If a camera is unreachable, the wall shows a "no signal" frame instead of breaking.</li>
                </ul>
            </div>
        </section>
    </aside>
</form>

<script type="application/json" id="driver-presets"><?= json_encode($presets, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
