<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Analytics;
use Argus\Repositories\Cameras;
use Argus\Support\Auth;

/**
 * Foot-traffic panel: hourly/daily people counts per camera, with CSV export
 * served by public/api/analytics.php.
 */
final class AnalyticsController extends Controller
{
    public function index(): void
    {
        $cameras = Cameras::visibleForUser(Auth::user());

        $requested = $this->int('camera', 0);
        $camera = null;
        foreach ($cameras as $candidate) {
            if ($requested > 0 ? (int) $candidate['id'] === $requested : $camera === null) {
                $camera = $candidate;
                if ($requested > 0) {
                    break;
                }
            }
        }

        $date = (string) $this->input('date', gmdate('Y-m-d'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = gmdate('Y-m-d');
        }

        $totals = ['in' => 0, 'out' => 0, 'inside' => 0, 'peak' => 0];
        $hourly = [];
        $daily = [];
        $recent = [];

        if ($camera !== null) {
            $id = (int) $camera['id'];
            $totals = Analytics::totals($id, $date);
            $hourly = Analytics::hourly($id, $date);
            $daily = Analytics::daily($id);
            $recent = Analytics::recent($id);
        }

        $peak = 1;
        foreach ($hourly as $bucket) {
            $peak = max($peak, $bucket['in'], $bucket['out']);
        }

        view('pages/analytics', [
            'title'    => 'Analytics',
            'active'   => 'analytics',
            'cameras'  => $cameras,
            'camera'   => $camera,
            'date'     => $date,
            'totals'   => $totals,
            'hourly'   => $hourly,
            'daily'    => $daily,
            'recent'   => $recent,
            'peak'     => $peak,
            'config'   => Analytics::config($camera['analytics_json'] ?? null),
            'exportUrl' => $camera !== null
                ? url('/api/analytics.php?camera=' . (int) $camera['id'] . '&date=' . urlencode($date) . '&format=csv')
                : null,
        ]);
    }
}
