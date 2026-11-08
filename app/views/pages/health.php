<?php
/** @var array $cameras */
/** @var array $stats */
/** @var array $stats24 */
/** @var array $bars */
/** @var int $days */
/** @var bool $canManage */
require_once BASE_PATH . '/app/views/partials/icons.php';

$days = (int) ($days ?? 7);
$stats = is_array($stats ?? null) ? $stats : [];
$stats24 = is_array($stats24 ?? null) ? $stats24 : [];
$bars = is_array($bars ?? null) ? $bars : [];
$canManage = $canManage ?? false;

$tracked = 0;
$uptimeSum = 0.0;
$uptimeCount = 0;
$downtimeTotal = 0;

foreach ($cameras as $camera) {
    $cameraId = (int) $camera['id'];
    if (!isset($stats[$cameraId])) {
        continue;
    }
    $tracked++;
    if ($stats[$cameraId]['uptime'] !== null) {
        $uptimeSum += (float) $stats[$cameraId]['uptime'];
        $uptimeCount++;
    }
    $downtimeTotal += (int) $stats[$cameraId]['downtime_minutes'];
}

$avgUptime = $uptimeCount > 0 ? round($uptimeSum / $uptimeCount, 1) : null;

$rangeButton = static function (int $value, string $label) use ($days): string {
    $class = $days === $value ? 'btn btn--subtle btn--sm' : 'btn btn--ghost btn--sm';

    return '<a class="' . $class . '" href="' . e(url('/health?days=' . $value)) . '">' . e($label) . '</a>';
};

/** 24 hourly cells: accent = online, warn = partial, danger = offline, border = no data. */
$sparkline = static function (array $series, int $buckets = 24): string {
    $cells = '';
    for ($i = 0; $i < $buckets; $i++) {
        $value = $series[$i] ?? null;

        if ($value === null) {
            $colour = 'var(--border)';
            $title = 'No data';
            $opacity = '0.45';
        } elseif ($value >= 99) {
            $colour = 'var(--accent)';
            $title = 'Online';
            $opacity = '0.85';
        } elseif ($value > 0) {
            $colour = 'var(--warn)';
            $title = round((float) $value) . '% online';
            $opacity = '0.85';
        } else {
            $colour = 'var(--danger)';
            $title = 'Offline';
            $opacity = '0.8';
        }

        $cells .= '<span title="' . e($title) . '" style="flex:1;min-width:3px;height:16px;border-radius:2px;background:'
            . $colour . ';opacity:' . $opacity . '"></span>';
    }

    return '<span style="display:flex;gap:2px;min-width:130px">' . $cells . '</span>';
};
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Camera health</h2>
        <p class="page-head__sub">
            <?= (int) $tracked ?> camera(s) with history ·
            <?= $avgUptime !== null ? e((string) $avgUptime) . '% average uptime' : 'no samples yet' ?> ·
            <?= (int) $downtimeTotal ?> min downtime in the last <?= (int) $days ?> day(s)
        </p>
    </div>
    <div class="page-head__actions">
        <?= $rangeButton(1, '24 hours') ?><?= $rangeButton(7, '7 days') ?><?= $rangeButton(14, '14 days') ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/health/export.csv?days=' . $days . '&what=summary')) ?>">
            <?= icon('download') ?> Summary CSV
        </a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/health/export.csv?days=' . $days)) ?>">
            <?= icon('download') ?> Samples CSV
        </a>
        <?php if ($canManage): ?>
            <a class="btn btn--primary btn--sm" href="<?= e(url('/discover')) ?>"><?= icon('scan') ?> Discover</a>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if (!$cameras): ?>
            <div class="empty empty--compact">
                <?= icon('camera', 'empty__icon') ?>
                <h3>No cameras to report on</h3>
                <p>Add a camera and the health history starts filling in immediately.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Camera</th>
                            <th>Status</th>
                            <th>Uptime <?= (int) $days ?>d</th>
                            <th>Uptime 24h</th>
                            <th>Avg latency</th>
                            <th>Downtime</th>
                            <th>Last 24h</th>
                            <th>Last check</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cameras as $camera): ?>
                        <?php
                        $cameraId = (int) $camera['id'];
                        $row = $stats[$cameraId] ?? null;
                        $day = $stats24[$cameraId] ?? null;
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($camera['name']) ?></strong>
                                <span class="muted"> · <?= e($camera['host']) ?></span>
                            </td>
                            <td>
                                <span class="dot dot--<?= e($camera['status']) ?>"></span>
                                <?= e(status_label((string) $camera['status'])) ?>
                            </td>
                            <td><?= $row && $row['uptime'] !== null ? e((string) $row['uptime']) . '%' : '<span class="muted">—</span>' ?></td>
                            <td><?= $day && $day['uptime'] !== null ? e((string) $day['uptime']) . '%' : '<span class="muted">—</span>' ?></td>
                            <td>
                                <?= $day && $day['avg_latency'] !== null
                                    ? e((string) $day['avg_latency']) . ' ms'
                                    : '<span class="muted">—</span>' ?>
                            </td>
                            <td>
                                <?= $row ? e((string) $row['downtime_minutes']) . ' min' : '<span class="muted">—</span>' ?>
                            </td>
                            <td><?= $sparkline($bars[$cameraId] ?? []) ?></td>
                            <td class="muted nowrap"><?= e(ago($row['last_check'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<p class="field__hint">
    One sample is stored per camera per minute (plus every online/offline change), so downtime is an
    estimate in minutes. History is kept for <?= (int) \Argus\Services\Health::RETENTION_DAYS ?> days;
    <code>php scripts/cleanup.php</code> prunes older rows.
</p>
