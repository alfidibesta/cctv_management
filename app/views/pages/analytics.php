<?php
/** @var array $cameras */
/** @var array|null $camera */
/** @var string $date */
/** @var array $totals */
/** @var array $hourly */
/** @var array $daily */
/** @var array $recent */
/** @var int $peak */
/** @var array $config */
/** @var string|null $exportUrl */
require_once BASE_PATH . '/app/views/partials/icons.php';

$peak = max(1, (int) ($peak ?? 1));
$hourLabel = static fn (int $hour): string => sprintf('%02d:00', $hour);
$modeLabel = [
    'off'      => 'Counting off',
    'tripwire' => 'Virtual tripwire',
    'zone'     => 'Zone occupancy',
    'both'     => 'Tripwire + zone',
][$config['mode'] ?? 'off'] ?? 'Counting off';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">People counting</h2>
        <p class="page-head__sub">
            Edge-inferred foot traffic, stored per camera. The browser runs detection; PHP only stores the counts.
        </p>
    </div>
    <div class="page-head__actions">
        <?php if ($exportUrl !== null): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($exportUrl) ?>"><?= icon('download') ?> Hourly CSV</a>
        <?php endif; ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/')) ?>"><?= icon('wall') ?> Live wall</a>
    </div>
</div>

<form class="traffic-filter card" method="get" action="<?= e(url('/analytics')) ?>">
    <div class="card__body traffic-filter__row">
        <label class="field">
            <span class="field__label">Camera</span>
            <select class="select" name="camera" onchange="this.form.submit()">
                <?php foreach ($cameras as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= selected($camera['id'] ?? 0, $option['id']) ?>>
                        <?= e($option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span class="field__label">Day</span>
            <input class="input" type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
        </label>

        <div class="traffic-filter__meta">
            <span class="status-pill"><?= icon('people') ?> <?= e($modeLabel) ?></span>
            <span class="field__hint">
                Confidence <?= (int) round(((float) ($config['confidence'] ?? 0.55)) * 100) ?>% ·
                Zone limit <?= (int) ($config['zone_threshold'] ?? 5) ?>
            </span>
        </div>
    </div>
</form>

<?php if ($camera === null): ?>
    <section class="card">
        <div class="empty empty--compact">
            <?= icon('people', 'empty__icon') ?>
            <h3>No cameras to analyse</h3>
            <p>Add a camera first, then enable person detection on its wall tile.</p>
        </div>
    </section>
<?php else: ?>
    <div class="traffic-stats">
        <div class="stat-card">
            <span class="stat-card__label">IN</span>
            <strong class="stat-card__value"><?= (int) $totals['in'] ?></strong>
            <span class="stat-card__hint">crossings in on <?= e($date) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-card__label">OUT</span>
            <strong class="stat-card__value"><?= (int) $totals['out'] ?></strong>
            <span class="stat-card__hint">crossings out</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__label">NET</span>
            <strong class="stat-card__value"><?= (int) $totals['in'] - (int) $totals['out'] ?></strong>
            <span class="stat-card__hint">in minus out</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__label">INSIDE</span>
            <strong class="stat-card__value"><?= (int) $totals['inside'] ?></strong>
            <span class="stat-card__hint">latest occupancy · peak <?= (int) $totals['peak'] ?></span>
        </div>
    </div>

    <section class="card">
        <header class="card__head">
            <div class="card__head__text">
                <h3>Hourly foot traffic</h3>
                <p><?= e($camera['name']) ?> · <?= e($date) ?> · UTC</p>
            </div>
            <div class="traffic-legend">
                <span><i class="traffic-dot traffic-dot--in"></i> IN</span>
                <span><i class="traffic-dot traffic-dot--out"></i> OUT</span>
            </div>
        </header>
        <div class="card__body">
            <div class="traffic-chart" role="img" aria-label="Hourly people in and out">
                <?php foreach ($hourly as $bucket): ?>
                    <?php
                    $in = (int) $bucket['in'];
                    $out = (int) $bucket['out'];
                    $inHeight = max($in > 0 ? 2 : 0, (int) round($in / $peak * 100));
                    $outHeight = max($out > 0 ? 2 : 0, (int) round($out / $peak * 100));
                    ?>
                    <div class="traffic-chart__col" title="<?= e($hourLabel((int) $bucket['hour'])) ?> — IN <?= $in ?>, OUT <?= $out ?>, peak <?= (int) $bucket['peak'] ?>">
                        <div class="traffic-chart__bars">
                            <span class="traffic-bar traffic-bar--in" style="height:<?= $inHeight ?>%"></span>
                            <span class="traffic-bar traffic-bar--out" style="height:<?= $outHeight ?>%"></span>
                        </div>
                        <span class="traffic-chart__label"><?= (int) $bucket['hour'] % 3 === 0 ? e($hourLabel((int) $bucket['hour'])) : '' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card__head">
            <div class="card__head__text">
                <h3>Daily totals</h3>
                <p>Last 14 days, all samples for this camera.</p>
            </div>
        </header>
        <div class="card__body">
            <?php if (!$daily): ?>
                <p class="field__hint">No samples yet. Open the live wall with detection enabled to start counting.</p>
            <?php else: ?>
                <div class="traffic-daily">
                    <?php foreach ($daily as $row): ?>
                        <div class="traffic-daily__row">
                            <span class="traffic-daily__day"><?= e($row['day']) ?></span>
                            <span class="traffic-daily__values">
                                <b><?= (int) $row['in'] ?></b> in · <b><?= (int) $row['out'] ?></b> out ·
                                peak <b><?= (int) $row['peak'] ?></b>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <header class="card__head">
            <div class="card__head__text">
                <h3>Latest samples</h3>
                <p>One row per counting event, newest first.</p>
            </div>
        </header>
        <div class="card__body card__body--flush">
            <?php if (!$recent): ?>
                <div class="empty empty--compact">
                    <?= icon('activity', 'empty__icon') ?>
                    <h3>Nothing counted yet</h3>
                    <p>Enable person detection on the wall and draw a tripwire or zone.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Time (UTC)</th><th>Direction</th><th>Occupancy</th><th>Confidence</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td><?= e($row['occurred_at']) ?></td>
                                <td><?= $row['direction'] ? e($row['direction']) : '—' ?></td>
                                <td><?= (int) $row['occupancy'] ?></td>
                                <td><?= (int) round(((float) $row['confidence']) * 100) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
