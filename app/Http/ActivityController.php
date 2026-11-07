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
}
