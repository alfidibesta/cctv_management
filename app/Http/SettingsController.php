<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Layouts;
use Argus\Services\Hls;
use Argus\Services\MediaServer;
use Argus\Services\Snapshot;
use Argus\Support\Activity;
use Argus\Support\Auth;
use Argus\Support\Settings;

final class SettingsController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        view('pages/settings', [
            'title'       => 'Settings',
            'active'      => 'settings',
            'settings'    => Settings::all(),
            'timezones'   => timezone_identifiers_list(),
            'diagnostics' => $this->diagnostics(),
            'media'       => MediaServer::health(),
        ]);
    }

    public function update(): void
    {
        Auth::requireAdmin();

        $appName = trim((string) $this->input('app_name', ''));
        $timezone = (string) $this->input('timezone', 'UTC');

        if ($appName === '') {
            $appName = 'Argus';
        }
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $defaultLayout = $this->int('default_layout', 9);
        if (!in_array($defaultLayout, Layouts::COLUMNS, true)) {
            $defaultLayout = 9;
        }

        Settings::setMany([
            'app_name'         => mb_substr($appName, 0, 60),
            'timezone'         => $timezone,
            'refresh_interval' => (string) max(2, min(60, $this->int('refresh_interval', 5))),
            'status_interval'  => (string) max(10, min(300, $this->int('status_interval', 20))),
            'snapshot_cache'   => (string) max(0, min(60, $this->int('snapshot_cache', 3))),
            'default_layout'   => (string) $defaultLayout,
            'verify_tls'       => $this->bool('verify_tls') ? '1' : '0',
            'ffmpeg_path'      => mb_substr((string) $this->input('ffmpeg_path', 'ffmpeg'), 0, 255),
            'hls_idle_minutes' => (string) max(1, min(240, $this->int('hls_idle_minutes', 15))),
            'hls_segment_seconds' => (string) max(1, min(6, $this->int('hls_segment_seconds', 2))),
            'rtsp_transport'   => $this->input('rtsp_transport', 'tcp') === 'udp' ? 'udp' : 'tcp',
            'rtsp_latency_ms'  => (string) max(50, min(2000, $this->int('rtsp_latency_ms', 300))),
            'rtsp_frame_drop'  => $this->bool('rtsp_frame_drop') ? '1' : '0',
        ]);

        Snapshot::storageDir('cache');
        Activity::log('settings.updated', 'Updated application settings');
        flash('Settings saved.');

        redirect('/settings');
    }

    /** Environment facts shown on the settings page. */
    private function diagnostics(): array
    {
        $storage = BASE_PATH . '/storage';

        return [
            'php'          => PHP_VERSION,
            'curl'         => function_exists('curl_init'),
            'pdo_mysql'    => extension_loaded('pdo_mysql'),
            'ffmpeg'        => Hls::isAvailable(),
            'ffmpeg_binary' => Hls::binary(),
            'openssl'      => extension_loaded('openssl'),
            'storage'      => is_writable($storage),
            'database'     => $this->databaseVersion(),
            'config_write' => is_writable(BASE_PATH . '/config'),
        ];
    }

    private function databaseVersion(): string
    {
        try {
            $version = (string) \Argus\Support\Db::val('SELECT VERSION()');

            return $version !== '' ? $version : 'connected';
        } catch (\Throwable $e) {
            return 'unavailable';
        }
    }
}
