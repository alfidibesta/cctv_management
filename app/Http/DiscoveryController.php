<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Repositories\Groups;
use Argus\Services\Diagnostics;
use Argus\Services\Onvif;
use Argus\Services\Snapshot;
use Argus\Support\Activity;
use Argus\Support\Auth;
use Argus\Support\Crypto;
use Argus\Support\Logger;

/**
 * ONVIF discovery: scan the LAN, read a device's media profiles and add one of
 * them (main for detail, sub for large walls) as a camera.
 */
final class DiscoveryController extends Controller
{
    public function index(): void
    {
        Auth::requireCameraManager();

        $scan = $_SESSION['_onvif_scan'] ?? [];
        $results = $_SESSION['_onvif_results'] ?? [];

        view('pages/discover', [
            'title'     => 'Discover cameras',
            'active'    => 'discover',
            'subtitle'  => 'Find ONVIF cameras on the network and add the profile you need',
            'devices'   => is_array($scan['devices'] ?? null) ? $scan['devices'] : [],
            'scannedAt' => $scan['at'] ?? null,
            'results'   => is_array($results) ? $results : [],
            'address'   => (string) ($_GET['address'] ?? ''),
            'groups'    => Groups::all(),
            'errors'    => errors(),
        ]);
    }

    public function scan(): void
    {
        Auth::requireCameraManager();

        $devices = Onvif::find(3.0);
        $_SESSION['_onvif_scan'] = ['devices' => $devices, 'at' => gmdate('c')];

        Activity::log('onvif.scan', 'ONVIF scan found ' . count($devices) . ' device(s)');

        if ($devices) {
            flash(count($devices) . ' ONVIF device(s) answered. Load the profiles of one to add it.', 'success');
        } else {
            flash(
                'No ONVIF device answered the multicast probe. Cameras on another VLAN never answer — type the address below instead.',
                'error'
            );
        }

        redirect('/discover');
    }

    public function probe(): void
    {
        Auth::requireCameraManager();

        $address = (string) $this->input('address', '');
        $username = (string) $this->input('username', '');
        $password = (string) ($_POST['password'] ?? '');

        if (trim($address) === '') {
            flash('Enter the camera address, for example 10.0.0.20.', 'error');
            redirect('/discover');
        }

        $result = Onvif::profiles($address, $username !== '' ? $username : null, $password !== '' ? $password : null);

        if (!$result['ok']) {
            Logger::warning('onvif.profiles_failed', ['address' => $address, 'error' => $result['error'] ?? '']);
            flash($result['error'] ?? 'Could not read the ONVIF profiles.', 'error');
            redirect('/discover?address=' . rawurlencode($address));
        }

        // Passwords are never kept for later steps: the add form asks again.
        $_SESSION['_onvif_results'][$result['xaddr']] = [
            'xaddr'    => $result['xaddr'],
            'info'     => $result['info'] ?? [],
            'profiles' => $result['profiles'] ?? [],
            'username' => $username,
            'checked'  => gmdate('c'),
        ];

        Activity::log('onvif.probed', 'Read ONVIF profiles from ' . $result['xaddr']);

        flash(
            count($result['profiles'] ?? []) . ' profile(s) found on ' . ($result['info']['model'] ?? $address) . '.',
            'success'
        );

        redirect('/discover?address=' . rawurlencode($address));
    }

    public function add(): void
    {
        Auth::requireCameraManager();

        $name = (string) $this->input('name', '');
        $location = (string) $this->input('location', '');
        $mode = (string) $this->input('stream_mode', 'hls');
        $rtsp = trim((string) $this->input('rtsp_uri', ''));
        $snapshot = trim((string) $this->input('snapshot_uri', ''));
        $username = (string) $this->input('username', '');
        $password = (string) ($_POST['password'] ?? '');
        $groupId = $this->int('group_id', 0);

        if (!in_array($mode, ['hls', 'snapshot'], true)) {
            $mode = 'hls';
        }

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Give the camera a name.';
        }
        if ($mode === 'hls' && !preg_match('#^rtsps?://#i', $rtsp)) {
            $errors['rtsp_uri'] = 'This profile has no RTSP stream URI — pick another profile or add the camera manually.';
        }
        if ($mode === 'snapshot' && !preg_match('#^https?://#i', $snapshot)) {
            $errors['snapshot_uri'] = 'This profile has no snapshot URI. Use HLS mode instead.';
        }

        if ($errors) {
            back_with_errors('/discover', $errors, $_POST);
        }

        // Device URIs sometimes embed credentials; keep them out of the database.
        [$rtsp, $rtspUser, $rtspPass] = Onvif::splitUri($rtsp);
        [$snapshot, $snapUser, $snapPass] = Onvif::splitUri($snapshot);

        if ($username === '') {
            $username = $rtspUser !== '' ? $rtspUser : $snapUser;
        }
        if ($password === '') {
            $password = $rtspPass !== '' ? $rtspPass : $snapPass;
        }

        $source = $mode === 'hls' ? $rtsp : $snapshot;
        $parts = parse_url($source) ?: [];
        $host = (string) ($parts['host'] ?? '');
        $rtspPort = (int) (parse_url($rtsp, PHP_URL_PORT) ?: 554);

        if ($host === '') {
            flash('The selected profile did not contain a usable host.', 'error');
            redirect('/discover');
        }

        $data = [
            'group_id'      => $groupId > 0 ? $groupId : null,
            'name'          => mb_substr($name, 0, 120),
            'location'      => mb_substr($location, 0, 160),
            'driver'        => 'onvif',
            'host'          => $host,
            'port'          => (int) (parse_url($snapshot, PHP_URL_PORT) ?: 80),
            'rtsp_port'     => $rtspPort,
            'username'      => mb_substr($username, 0, 190),
            'password_enc'  => $password !== '' ? Crypto::encrypt($password) : null,
            'channel'       => 1,
            'stream_mode'   => $mode,
            'snapshot_path' => $snapshot,
            'mjpeg_path'    => '',
            'rtsp_path'     => $rtsp,
            'ptz_path'      => '',
            'ptz_enabled'   => 0,
            'transport'     => 'tcp',
            'is_enabled'    => 1,
            'sort_order'    => Cameras::nextSortOrder(),
            'status'        => 'unknown',
        ];

        $id = Cameras::create($data);
        Snapshot::clearCache($id);

        Activity::log('camera.created', 'Added ONVIF camera "' . $data['name'] . '"', 'camera', $id);
        Logger::info('camera.onvif_added', ['camera' => $id, 'host' => $host, 'mode' => $mode]);

        $camera = Cameras::find($id) ?? ($data + ['id' => $id]);
        $probe = $this->probeSafely($camera);
        $diagnosis = Diagnostics::forCamera($camera, $probe);
        $diagnosis['camera'] = ['id' => $id, 'name' => (string) $data['name']];
        $diagnosis['checked_at'] = gmdate('c');
        $_SESSION['_probe'] = $diagnosis;

        unset($_SESSION['_onvif_results']);

        flash(
            $diagnosis['ok'] ? 'ONVIF camera added and the connection test passed.' : 'ONVIF camera saved, but the connection test needs attention.',
            $diagnosis['ok'] ? 'success' : 'error'
        );

        redirect('/cameras');
    }

    /** Probe without letting a camera failure break the save. */
    private function probeSafely(array $camera): array
    {
        try {
            return Snapshot::probe($camera);
        } catch (\Throwable $e) {
            Logger::error('Camera probe failed: ' . $e->getMessage(), ['camera' => (int) ($camera['id'] ?? 0)]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
