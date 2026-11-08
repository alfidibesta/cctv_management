<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Services\Health;
use Argus\Support\Activity;
use Argus\Support\Auth;

final class HealthController extends Controller
{
    private const RANGES = [1, 7, 14];

    public function index(): void
    {
        Auth::requireAuth();

        $days = $this->range();

        view('pages/health', [
            'title'     => 'Camera health',
            'active'    => 'health',
            'subtitle'  => 'Uptime, latency and downtime from the stored check history',
            'cameras'   => Cameras::visibleForUser(Auth::user()),
            'days'      => $days,
            'stats'     => Health::window($days),
            'stats24'   => Health::window(1),
            'bars'      => Health::bars(24, 24),
            'canManage' => Auth::canManageCameras(),
        ]);
    }

    /** CSV export: ?what=samples (default) or ?what=summary, ?days=1|7|14, ?camera=id */
    public function export(): void
    {
        Auth::requireAuth();

        $days = $this->range();
        $what = (string) ($_GET['what'] ?? 'samples') === 'summary' ? 'summary' : 'samples';
        $cameraId = $this->int('camera', 0);

        $visible = [];
        foreach (Cameras::visibleForUser(Auth::user()) as $camera) {
            $visible[(int) $camera['id']] = $camera;
        }

        if ($cameraId > 0 && !isset($visible[$cameraId])) {
            http_response_code(404);
            view('pages/error', ['code' => 404, 'message' => 'That camera is not available to you.']);

            return;
        }

        $suffix = gmdate('Ymd-His');

        if ($what === 'summary') {
            $rows = [];
            foreach (Health::window($days) as $id => $stat) {
                if (!isset($visible[$id])) {
                    continue;
                }

                $rows[] = [
                    $visible[$id]['name'],
                    $visible[$id]['host'],
                    $days,
                    $stat['samples'],
                    $stat['uptime'] ?? '',
                    $stat['downtime_minutes'],
                    $stat['avg_latency'] ?? '',
                    $stat['last_check'] ?? '',
                ];
            }

            Activity::log('health.export', 'Exported camera health summary (' . $days . ' day window)');

            csv_response(
                'argus-health-summary-' . $suffix . '.csv',
                ['Camera', 'Host', 'Window (days)', 'Samples', 'Uptime %', 'Downtime (minutes)', 'Avg latency (ms)', 'Last check (UTC)'],
                $rows
            );
        }

        $rows = [];
        foreach (Health::samples($days, $cameraId > 0 ? $cameraId : null) as $sample) {
            if (!isset($visible[(int) $sample['camera_id']])) {
                continue;
            }

            $rows[] = [
                $sample['camera'],
                $sample['host'],
                $sample['checked_at'],
                $sample['status'],
                $sample['latency_ms'] ?? '',
                $sample['error'] ?? '',
            ];
        }

        Activity::log('health.export', 'Exported camera health samples (' . $days . ' day window)');

        csv_response(
            'argus-health-' . $suffix . '.csv',
            ['Camera', 'Host', 'Checked at (UTC)', 'Status', 'Latency (ms)', 'Error'],
            $rows
        );
    }

    private function range(): int
    {
        $days = $this->int('days', 7);

        return in_array($days, self::RANGES, true) ? $days : 7;
    }
}
