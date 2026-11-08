<?php
/**
 * Maintenance task — run from cron every few minutes:
 *   php scripts/cleanup.php
 *
 * Stops ffmpeg converters that no browser has watched for a while and prunes
 * stale snapshot files. Safe to run as often as you like.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use Argus\Services\Hls;
use Argus\Support\Config;

if (!Config::installed()) {
    fwrite(STDERR, "Argus is not installed yet.\n");
    exit(1);
}

$stopped = Hls::cleanupIdle();
echo 'Stopped ' . $stopped . ' idle HLS converter(s).', PHP_EOL;

$prunedChecks = \Argus\Services\Health::prune();
echo 'Pruned ' . $prunedChecks . ' camera health sample(s).', PHP_EOL;

$cacheDir = BASE_PATH . '/storage/cache';
$pruned = 0;
$deadline = time() - 86400;

foreach (glob($cacheDir . '/camera-*.img') ?: [] as $file) {
    if (filemtime($file) < $deadline) {
        @unlink($file);
        $pruned++;
    }
}

echo 'Pruned ' . $pruned . ' stale snapshot file(s).', PHP_EOL;
