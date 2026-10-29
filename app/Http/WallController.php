<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Repositories\Groups;
use Argus\Repositories\Layouts;
use Argus\Services\StreamUrl;
use Argus\Support\Auth;
use Argus\Support\Settings;

final class WallController extends Controller
{
    public function index(): void
    {
        $user = Auth::user();
        $cameras = Cameras::visibleForUser($user);
        $groups = Groups::all();

        $layout = Layouts::forUser((int) $user['id']) ?? [
            'columns' => in_array(Settings::int('default_layout', 9), Layouts::COLUMNS, true)
                ? Settings::int('default_layout', 9)
                : 9,
            'tiles'   => [],
            'mode'    => 'auto',
        ];

        $decorated = array_map(static function (array $camera): array {
            $id = (int) $camera['id'];
            $mode = (string) $camera['stream_mode'];

            return [
                'id'           => $id,
                'name'         => $camera['name'],
                'location'     => $camera['location'] ?? '',
                'group'        => $camera['group_name'] ?? 'Ungrouped',
                'group_id'     => $camera['group_id'] !== null ? (int) $camera['group_id'] : null,
                'driver'       => $camera['driver'],
                'driver_label' => StreamUrl::driverLabel((string) $camera['driver']),
                'status'       => $camera['status'] ?: 'unknown',
                'mode'         => $mode,
                'enabled'      => (int) $camera['is_enabled'] === 1,
                'ptz'          => (int) $camera['ptz_enabled'] === 1,
                'last_seen'    => $camera['last_seen_at'],
                'last_error'   => $camera['last_error'],
                'snapshot_url' => StreamUrl::viewerUrl($camera, 'snapshot'),
                'mjpeg_url'    => StreamUrl::viewerUrl($camera, 'mjpeg'),
                'hls_url'      => StreamUrl::viewerUrl($camera, 'hls'),
                'download_url' => StreamUrl::viewerUrl($camera, 'snapshot') . '&download=1',
            ];
        }, $cameras);

        $groupsPayload = array_map(static fn (array $g): array => [
            'id'     => (int) $g['id'],
            'name'   => $g['name'],
            'count'  => (int) $g['camera_count'],
        ], $groups);

        view('pages/wall', [
            'title'     => 'Live wall',
            'active'    => 'wall',
            'bodyClass' => 'page-wall',
            'scripts'   => ['wall.js'],
            'cameras'  => $decorated,
            'groups'   => $groupsPayload,
            'layout'   => $layout,
            'settings' => [
                'refresh' => max(2, Settings::int('refresh_interval', 5)),
                'status'  => max(10, Settings::int('status_interval', 20)),
            ],
            'canManage' => Auth::canManageCameras(),
            'stats'     => Cameras::counts(),
        ]);
    }

    /** Persist the wall layout (grid size and up to 9 camera slots). */
    public function saveLayout(): void
    {
        $columns = $this->int('columns', 9);
        $mode = (string) $this->input('mode', 'custom');
        $rawTiles = $_POST['tiles'] ?? [];
        $tiles = is_array($rawTiles) ? $rawTiles : [];

        if (!in_array($columns, Layouts::COLUMNS, true)) {
            $this->json(['error' => 'Unsupported grid size.'], 422);
        }

        $user = Auth::user();
        $allowed = [];
        foreach (Cameras::visibleForUser($user) as $camera) {
            $allowed[(int) $camera['id']] = true;
        }

        // Never store a camera the user cannot see (deleted or revoked access).
        $clean = [];
        foreach ($tiles as $tile) {
            $id = is_numeric($tile) ? (int) $tile : 0;
            $clean[] = ($id > 0 && isset($allowed[$id])) ? $id : null;
        }

        $saved = Layouts::save((int) $user['id'], $columns, $clean, $mode);

        $this->json(['ok' => true, 'layout' => $saved]);
    }
}
