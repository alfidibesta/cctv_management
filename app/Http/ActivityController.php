<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Support\Activity;
use Argus\Support\Auth;
use Argus\Support\Logger;

final class ActivityController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $tab = (string) ($_GET['tab'] ?? 'activity') === 'errors' ? 'errors' : 'activity';
        $level = (string) ($_GET['level'] ?? '');
        if (!in_array($level, ['', 'error', 'warning', 'info', 'debug'], true)) {
            $level = '';
        }

        $logFile = Logger::path();

        view('pages/activity', [
            'title'     => $tab === 'errors' ? 'Logs' : 'Activity',
            'active'    => 'activity',
            'subtitle'  => $tab === 'errors'
                ? 'Errors and warnings written by the application and PHP'
                : 'Sign-ins, camera changes and stream events',
            'tab'       => $tab,
            'level'     => $level,
            'entries'   => $tab === 'activity' ? Activity::recent(200) : [],
            'logs'      => $tab === 'errors' ? Logger::tail(300, $level) : [],
            'logPath'   => ltrim(str_replace(BASE_PATH, '', $logFile), '/'),
            'logExists' => is_file($logFile) && (int) @filesize($logFile) > 0,
        ]);
    }

    /** CSV export of the audit trail or of the JSON error log. */
    public function export(): void
    {
        Auth::requireAdmin();

        $source = (string) ($_GET['source'] ?? 'activity') === 'errors' ? 'errors' : 'activity';
        $level = (string) ($_GET['level'] ?? '');
        if (!in_array($level, ['', 'error', 'warning', 'info', 'debug'], true)) {
            $level = '';
        }

        $suffix = gmdate('Ymd-His');

        if ($source === 'errors') {
            $rows = [];
            foreach (array_reverse(Logger::tail(5000, $level)) as $entry) {
                $rows[] = [
                    $entry['time'] ?? '',
                    $entry['level'] ?? '',
                    $entry['message'] ?? '',
                    isset($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_SLASHES) : '',
                ];
            }

            Activity::log('log.export', 'Exported error log CSV (' . count($rows) . ' rows)');

            csv_response(
                'argus-errors-' . $suffix . '.csv',
                ['Time (UTC)', 'Level', 'Message', 'Context'],
                $rows
            );
        }

        $rows = [];
        foreach (array_reverse(Activity::export(20000)) as $entry) {
            $rows[] = [
                $entry['created_at'],
                $entry['user_name'],
                $entry['action'],
                $entry['description'],
                $entry['ip'],
            ];
        }

        Activity::log('log.export', 'Exported activity log CSV (' . count($rows) . ' rows)');

        csv_response(
            'argus-activity-' . $suffix . '.csv',
            ['When (UTC)', 'Actor', 'Action', 'Detail', 'IP'],
            $rows
        );
    }
}
