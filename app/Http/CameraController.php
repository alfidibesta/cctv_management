<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Repositories\Groups;
use Argus\Services\Snapshot;
use Argus\Services\StreamUrl;
use Argus\Support\Activity;
use Argus\Support\Auth;
use Argus\Support\Crypto;
use Argus\Support\Db;

final class CameraController extends Controller
{
    public function index(): void
    {
        $groupId = $this->int('group', 0);
        $search = (string) $this->input('q', '');

        $user = Auth::user();
        $cameras = array_values(array_filter(
            Cameras::visibleForUser($user),
            static function (array $camera) use ($groupId, $search): bool {
                if ($groupId > 0 && (int) $camera['group_id'] !== $groupId) {
                    return false;
                }
                if ($search !== '') {
                    $haystack = mb_strtolower(($camera['name'] ?? '') . ' ' . ($camera['location'] ?? '') . ' ' . ($camera['host'] ?? ''));

                    return str_contains($haystack, mb_strtolower($search));
                }

                return true;
            }
        ));

        view('pages/cameras', [
            'title'     => 'Cameras',
            'active'    => 'cameras',
            'cameras'   => $cameras,
            'groups'    => Groups::all(),
            'filter'    => ['group' => $groupId, 'q' => $search],
            'counts'    => Cameras::counts(),
            'canManage' => Auth::canManageCameras(),
            'errors'    => errors(),
        ]);
    }

    public function create(): void
    {
        Auth::requireCameraManager();

        view('pages/camera-form', [
            'title'  => 'Add camera',
            'active' => 'cameras',
            'camera' => $this->emptyCamera(),
            'groups' => Groups::all(),
            'errors' => errors(),
            'isNew'  => true,
        ]);
    }

    public function store(): void
    {
        Auth::requireCameraManager();

        [$data, $errors] = $this->validate();

        if ($errors) {
            back_with_errors('/cameras/new', $errors, $_POST);
        }

        $data['sort_order'] = Cameras::nextSortOrder();
        $id = Cameras::create($data);

        Snapshot::clearCache($id);
        Activity::log('camera.created', 'Added camera "' . $data['name'] . '"', 'camera', $id);
        flash('Camera added to the wall.');

        redirect('/cameras');
    }

    public function edit(string $id): void
    {
        Auth::requireCameraManager();
        $camera = CameraGate::resolve((int) $id, true);

        view('pages/camera-form', [
            'title'  => 'Edit camera',
            'active' => 'cameras',
            'camera' => $camera,
            'groups' => Groups::all(),
            'errors' => errors(),
            'isNew'  => false,
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireCameraManager();
        $camera = CameraGate::resolve((int) $id, true);

        [$data, $errors] = $this->validate();

        if ($errors) {
            back_with_errors('/cameras/' . (int) $id . '/edit', $errors, $_POST);
        }

        // Keep the stored password when the field is left blank.
        if (!array_key_exists('password_enc', $data)) {
            $data['password_enc'] = $camera['password_enc'];
        }

        Cameras::update((int) $id, $data);
        Snapshot::clearCache((int) $id);
        Activity::log('camera.updated', 'Updated camera "' . $data['name'] . '"', 'camera', (int) $id);
        flash('Camera updated.');

        redirect('/cameras');
    }

    public function delete(string $id): void
    {
        Auth::requireCameraManager();
        $camera = CameraGate::resolve((int) $id, true);

        Cameras::delete((int) $id);
        Snapshot::clearCache((int) $id);
        Activity::log('camera.deleted', 'Deleted camera "' . $camera['name'] . '"', 'camera', (int) $id);
        flash('Camera removed.', 'success');

        redirect('/cameras');
    }

    /** Probe a camera and return a JSON status payload. */
    public function test(string $id): void
    {
        $camera = CameraGate::resolve((int) $id);
        $result = Snapshot::probe($camera);

        $this->json([
            'ok'         => $result['ok'],
            'status'     => $result['ok'] ? 'online' : 'offline',
            'latency_ms' => $result['latency_ms'] ?? null,
            'error'      => $result['error'] ?? null,
        ]);
    }

    /** CSV bulk import: name,host,port,username,password,driver,group */
    public function bulk(): void
    {
        Auth::requireCameraManager();

        $raw = (string) ($_POST['rows'] ?? '');
        $driver = (string) $this->input('driver', 'acti');
        $groupId = $this->int('group_id', 0);
        $mode = (string) $this->input('stream_mode', 'mjpeg');
        $port = $this->int('port', 80);

        if (!isset(StreamUrl::DRIVERS[$driver])) {
            $driver = 'acti';
        }
        if (!in_array($mode, Cameras::STREAM_MODES, true)) {
            $mode = 'mjpeg';
        }

        $created = 0;
        $skipped = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = array_map('trim', str_getcsv($line));

            if (count($columns) < 2 || $columns[0] === '' || $columns[1] === '') {
                $skipped[] = 'Line ' . ($lineNumber + 1) . ': expected at least name,host';

                continue;
            }

            [$name, $host] = $columns;
            $rowPort = isset($columns[2]) && $columns[2] !== '' ? (int) $columns[2] : $port;
            $username = $columns[3] ?? '';
            $password = $columns[4] ?? '';
            $rowDriver = isset($columns[5]) && isset(StreamUrl::DRIVERS[$columns[5]]) ? $columns[5] : $driver;
            $preset = StreamUrl::preset($rowDriver);

            $id = Cameras::create([
                'group_id'      => $groupId > 0 ? $groupId : null,
                'name'          => mb_substr($name, 0, 120),
                'location'      => '',
                'driver'        => $rowDriver,
                'host'          => mb_substr($host, 0, 190),
                'port'          => $rowPort > 0 && $rowPort < 65536 ? $rowPort : 80,
                'rtsp_port'     => 554,
                'username'      => mb_substr($username, 0, 190),
                'password_enc'  => $password !== '' ? Crypto::encrypt($password) : null,
                'channel'       => 1,
                'stream_mode'   => $mode,
                'snapshot_path' => $preset['snapshot_path'],
                'mjpeg_path'    => $preset['mjpeg_path'],
                'rtsp_path'     => $preset['rtsp_path'],
                'ptz_path'      => $preset['ptz_path'],
                'ptz_enabled'   => (int) ($preset['ptz_path'] !== ''),
                'transport'     => 'tcp',
                'is_enabled'    => 1,
                'sort_order'    => Cameras::nextSortOrder(),
                'status'        => 'unknown',
            ]);

            if ($id > 0) {
                $created++;
            }
        }

        Activity::log('camera.bulk', 'Bulk imported ' . $created . ' camera(s)', 'camera', null);
        flash($created . ' camera(s) imported' . ($skipped ? ', ' . count($skipped) . ' line(s) skipped.' : '.'), $created > 0 ? 'success' : 'error');

        if ($skipped) {
            flash(implode(' ', array_slice($skipped, 0, 3)), 'error');
        }

        redirect('/cameras');
    }

    private function emptyCamera(): array
    {
        $preset = StreamUrl::preset('acti');

        return [
            'id'            => null,
            'name'          => '',
            'location'      => '',
            'group_id'      => null,
            'driver'        => 'acti',
            'host'          => '',
            'port'          => 80,
            'rtsp_port'     => 554,
            'username'      => '',
            'password_enc'  => '',
            'channel'       => 1,
            'stream_mode'   => 'mjpeg',
            'snapshot_path' => $preset['snapshot_path'],
            'mjpeg_path'    => $preset['mjpeg_path'],
            'rtsp_path'     => $preset['rtsp_path'],
            'ptz_path'      => $preset['ptz_path'],
            'ptz_enabled'   => 0,
            'transport'     => 'tcp',
            'is_enabled'    => 1,
            'sort_order'    => 0,
            'status'        => 'unknown',
        ];
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function validate(): array
    {
        $name = (string) $this->input('name', '');
        $driver = (string) $this->input('driver', 'acti');
        $host = (string) $this->input('host', '');
        $port = $this->int('port', 80);
        $rtspPort = $this->int('rtsp_port', 554);
        $mode = (string) $this->input('stream_mode', 'mjpeg');
        $groupId = $this->int('group_id', 0);
        $channel = $this->int('channel', 1);
        $password = (string) ($_POST['password'] ?? '');

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Give the camera a name.';
        }
        if (!isset(StreamUrl::DRIVERS[$driver])) {
            $errors['driver'] = 'Choose a supported camera driver.';
        }
        if ($driver !== 'demo' && $host === '') {
            $errors['host'] = 'The camera host or IP address is required.';
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'Port must be between 1 and 65535.';
        }
        if ($rtspPort < 1 || $rtspPort > 65535) {
            $errors['rtsp_port'] = 'RTSP port must be between 1 and 65535.';
        }
        if (!in_array($mode, Cameras::STREAM_MODES, true)) {
            $errors['stream_mode'] = 'Choose a stream mode.';
        }
        if ($groupId > 0 && !Groups::find($groupId)) {
            $errors['group_id'] = 'That group no longer exists.';
        }

        $data = [
            'group_id'      => $groupId > 0 ? $groupId : null,
            'name'          => mb_substr($name, 0, 120),
            'location'      => mb_substr((string) $this->input('location', ''), 0, 160),
            'driver'        => $driver,
            'host'          => mb_substr($host, 0, 190),
            'port'          => $port,
            'rtsp_port'     => $rtspPort,
            'username'      => mb_substr((string) $this->input('username', ''), 0, 190),
            'channel'       => max(1, min($channel, 64)),
            'stream_mode'   => $mode,
            'snapshot_path' => mb_substr((string) $this->input('snapshot_path', ''), 0, 255),
            'mjpeg_path'    => mb_substr((string) $this->input('mjpeg_path', ''), 0, 255),
            'rtsp_path'     => mb_substr((string) $this->input('rtsp_path', ''), 0, 255),
            'ptz_path'      => mb_substr((string) $this->input('ptz_path', ''), 0, 255),
            'ptz_enabled'   => $this->bool('ptz_enabled') ? 1 : 0,
            'transport'     => $this->input('transport', 'tcp') === 'udp' ? 'udp' : 'tcp',
            'is_enabled'    => $this->bool('is_enabled') ? 1 : 0,
        ];

        if ($password !== '') {
            $data['password_enc'] = Crypto::encrypt($password);
        }
        if ($this->bool('clear_password')) {
            $data['password_enc'] = null;
        }

        return [$data, $errors];
    }
}
