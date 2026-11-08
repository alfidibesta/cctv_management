<?php
/** @var array $settings */
/** @var array $timezones */
/** @var array $diagnostics */
require_once BASE_PATH . '/app/views/partials/icons.php';

$check = static function (bool $ok): string {
    return $ok
        ? '<span class="diag diag--ok">' . icon('check') . ' Ready</span>'
        : '<span class="diag diag--bad">' . icon('info') . ' Missing</span>';
};
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Settings</h2>
        <p class="page-head__sub">Wall behaviour, streaming and server diagnostics.</p>
    </div>
</div>

<form method="post" action="<?= e(url('/settings')) ?>" class="form-layout">
    <?= csrf_field() ?>

    <div class="form-layout__main">
        <section class="card" data-collapse="settings-general">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>General</h3>
                    <p>Branding and timezone for timestamps.</p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body grid-2">
                <label class="field">
                    <span class="field__label">Application name</span>
                    <input class="input" type="text" name="app_name" maxlength="60"
                           value="<?= e($settings['app_name']) ?>" required>
                </label>

                <label class="field">
                    <span class="field__label">Timezone</span>
                    <select class="select" name="timezone">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= e($tz) ?>" <?= selected($settings['timezone'], $tz) ?>><?= e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </section>

        <section class="card" data-collapse="settings-wall">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>Live wall</h3>
                    <p>How often tiles refresh and how status is polled.</p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body grid-2">
                <label class="field">
                    <span class="field__label">Default grid</span>
                    <select class="select" name="default_layout">
                        <option value="1" <?= selected($settings['default_layout'], 1) ?>>1 camera</option>
                        <option value="4" <?= selected($settings['default_layout'], 4) ?>>4 cameras</option>
                        <option value="9" <?= selected($settings['default_layout'], 9) ?>>9 cameras</option>
                    </select>
                    <span class="field__hint">Applies to new users. Maximum is 9 per page.</span>
                </label>

                <label class="field">
                    <span class="field__label">Snapshot refresh (seconds)</span>
                    <input class="input" type="number" name="refresh_interval" min="2" max="60"
                           value="<?= (int) $settings['refresh_interval'] ?>">
                    <span class="field__hint">Applies to snapshot-mode cameras only.</span>
                </label>

                <label class="field">
                    <span class="field__label">Status poll (seconds)</span>
                    <input class="input" type="number" name="status_interval" min="10" max="300"
                           value="<?= (int) $settings['status_interval'] ?>">
                </label>

                <label class="field">
                    <span class="field__label">Snapshot cache (seconds)</span>
                    <input class="input" type="number" name="snapshot_cache" min="0" max="60"
                           value="<?= (int) $settings['snapshot_cache'] ?>">
                    <span class="field__hint">Higher values reduce load on cameras at the cost of latency.</span>
                </label>
            </div>
        </section>

        <section class="card" data-collapse="settings-streaming">
            <header class="card__head">
                <div class="card__head__text">
                    <h3>Streaming</h3>
                    <p>HLS conversion and TLS behaviour for camera requests.</p>
                </div>
                <button class="collapse-toggle" type="button" data-collapse-toggle>
                    <span class="collapse-label">Hide</span>
                </button>
            </header>
            <div class="card__body grid-2">
                <label class="field">
                    <span class="field__label">ffmpeg path</span>
                    <input class="input input--mono" type="text" name="ffmpeg_path" maxlength="255"
                           value="<?= e($settings['ffmpeg_path']) ?>">
                    <span class="field__hint">
                        Only needed for cameras in HLS mode. Leave as <code>ffmpeg</code> to use
                        <code>bin/ffmpeg.exe</code> (Windows) or <code>bin/ffmpeg</code> inside the project,
                        otherwise it falls back to the system PATH.
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Stop idle HLS streams after (minutes)</span>
                    <input class="input" type="number" name="hls_idle_minutes" min="1" max="240"
                           value="<?= (int) $settings['hls_idle_minutes'] ?>">
                </label>

                <label class="field">
                    <span class="field__label">HLS segment length (seconds)</span>
                    <input class="input" type="number" name="hls_segment_seconds" min="1" max="6"
                           value="<?= (int) $settings['hls_segment_seconds'] ?>">
                    <span class="field__hint">
                        Short segments cut delay: 1–2 s feels live, 4–6 s is gentler on bandwidth.
                        Keep the camera I-frame interval at 1–2 s so short segments stay playable.
                    </span>
                </label>

                <label class="switch switch--wide">
                    <input type="checkbox" name="verify_tls" value="1" <?= checked($settings['verify_tls'] === '1') ?>>
                    <span class="switch__track"></span>
                    <span class="switch__label">
                        <strong>Verify camera TLS certificates</strong>
                        <small>Leave off for self-signed certificates on IP cameras.</small>
                    </span>
                </label>
            </div>
        </section>
    </div>

    <aside class="form-layout__aside">
        <section class="card">
            <div class="card__body stack">
                <button class="btn btn--primary btn--block" type="submit"><?= icon('check') ?> Save settings</button>
                <p class="field__hint">Changes take effect on the next page load or refresh cycle.</p>
            </div>
        </section>

        <section class="card">
            <header class="card__head">
                <h3>Server diagnostics</h3>
            </header>
            <div class="card__body">
                <ul class="diag-list">
                    <li><span>PHP version</span><span class="diag"><?= e($diagnostics['php']) ?></span></li>
                    <li><span>MySQL version</span><span class="diag"><?= e($diagnostics['database']) ?></span></li>
                    <li><span>PDO MySQL</span><?= $check($diagnostics['pdo_mysql']) ?></li>
                    <li><span>cURL</span><?= $check($diagnostics['curl']) ?></li>
                    <li><span>OpenSSL</span><?= $check($diagnostics['openssl']) ?></li>
                    <li><span>ffmpeg (HLS)</span><?= $check($diagnostics['ffmpeg']) ?></li>
                    <?php if (!empty($diagnostics['ffmpeg_binary'])): ?>
                        <li class="diag-path"><span></span><span class="diag"><?= e(str_replace(BASE_PATH, '', (string) $diagnostics['ffmpeg_binary'])) ?></span></li>
                    <?php endif; ?>
                    <li><span>Storage writable</span><?= $check($diagnostics['storage']) ?></li>
                    <li><span>Config writable</span><?= $check($diagnostics['config_write']) ?></li>
                </ul>
            </div>
        </section>

        <section class="card card--muted">
            <div class="card__body stack-sm">
                <h4 class="card__mini-title"><?= icon('info') ?> Maintenance</h4>
                <p class="field__hint">
                    Run <code>php scripts/cleanup.php</code> from cron every few minutes to stop idle HLS
                    converters. Stored snapshots live in <code>storage/cache</code>.
                </p>
            </div>
        </section>
    </aside>
</form>
