<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Support\Config;
use Argus\Support\Crypto;
use Argus\Support\Db;
use Argus\Support\Settings;
use PDO;
use PDOException;

/**
 * First-run installer: verifies the server, writes config/config.php, creates
 * the schema, the first administrator and optional demo cameras.
 */
final class SetupController extends Controller
{
    public function show(): void
    {
        if (Config::installed()) {
            redirect('/login');
        }

        view('pages/setup', [
            'title'  => 'Setup',
            'checks' => $this->environment(),
            'errors' => errors(),
        ], 'layouts/guest');
    }

    public function install(): void
    {
        if (Config::installed()) {
            redirect('/login');
        }

        $db = [
            'host'     => (string) $this->input('db_host', '127.0.0.1'),
            'port'     => $this->int('db_port', 3306),
            'database' => (string) $this->input('db_name', ''),
            'username' => (string) $this->input('db_user', ''),
            'password' => (string) ($_POST['db_password'] ?? ''),
            'charset'  => 'utf8mb4',
        ];

        $appName = trim((string) $this->input('app_name', 'Argus')) ?: 'Argus';
        $adminName = trim((string) $this->input('admin_name', '')) ?: 'Administrator';
        $adminEmail = mb_strtolower(trim((string) $this->input('admin_email', '')));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $confirm = (string) ($_POST['admin_password_confirm'] ?? '');
        $seedDemo = $this->bool('seed_demo');

        $errors = [];

        if ($db['database'] === '' || !preg_match('/^[A-Za-z0-9_]+$/', $db['database'])) {
            $errors['db_name'] = 'Use letters, numbers and underscores for the database name.';
        }
        if ($db['port'] < 1 || $db['port'] > 65535) {
            $errors['db_port'] = 'Port must be between 1 and 65535.';
        }
        if ($db['username'] === '') {
            $errors['db_user'] = 'Enter the MySQL user name.';
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Enter a valid email for the administrator.';
        }
        if (mb_strlen($adminPassword) < 8) {
            $errors['admin_password'] = 'Use at least 8 characters for the password.';
        }
        if ($adminPassword !== $confirm) {
            $errors['admin_password_confirm'] = 'The passwords do not match.';
        }

        if ($errors) {
            back_with_errors('/setup', $errors, [
                'db_host'     => $db['host'],
                'db_port'     => $db['port'],
                'db_name'     => $db['database'],
                'db_user'     => $db['username'],
                'app_name'    => $appName,
                'admin_name'  => $adminName,
                'admin_email' => $adminEmail,
            ]);
        }

        // 1. Reach the database, creating it when the user is allowed to.
        $connect = $this->prepareDatabase($db);

        if (!$connect['ok']) {
            back_with_errors('/setup', ['db_host' => $connect['error']], [
                'db_host'     => $db['host'],
                'db_port'     => $db['port'],
                'db_name'     => $db['database'],
                'db_user'     => $db['username'],
                'app_name'    => $appName,
                'admin_name'  => $adminName,
                'admin_email' => $adminEmail,
            ]);
        }

        // 2. Write configuration; roll it back if the schema cannot be applied.
        try {
            Config::write([
                'db'  => $db,
                'app' => [
                    'name'         => mb_substr($appName, 0, 60),
                    'key'          => Crypto::generateKey(),
                    'timezone'     => 'UTC',
                    'debug'        => false,
                    'base_path'    => '',
                    'installed_at' => gmdate('c'),
                ],
            ]);
        } catch (\Throwable $e) {
            back_with_errors('/setup', ['db_host' => $e->getMessage()]);
        }

        try {
            $this->runSchema();

            $pdo = Db::pdo();

            // 3. First administrator (only when the users table is empty).
            $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

            if ($userCount === 0) {
                Db::insert('users', [
                    'name'          => mb_substr($adminName, 0, 120),
                    'email'         => $adminEmail,
                    'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                    'role'          => 'admin',
                    'is_active'     => 1,
                    'created_at'    => gmdate('Y-m-d H:i:s'),
                    'updated_at'    => gmdate('Y-m-d H:i:s'),
                ]);
            }

            // 4. Settings + optional demo cameras.
            Settings::flush();
            Settings::set('app_name', mb_substr($appName, 0, 60));

            if ($seedDemo && (int) $pdo->query('SELECT COUNT(*) FROM cameras')->fetchColumn() === 0) {
                $this->seedDemoCameras();
            }

            \Argus\Support\Activity::log('install', 'Installed Argus ' . ARGUS_VERSION);
        } catch (\Throwable $e) {
            @unlink(Config::path());
            back_with_errors('/setup', ['db_host' => 'Installation failed: ' . $e->getMessage()]);
        }

        $_SESSION['_flash'][] = [
            'message' => 'Argus is installed. Sign in with your administrator account.',
            'type'    => 'success',
        ];

        redirect('/login');
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function prepareDatabase(array $db): array
    {
        try {
            $pdo = Db::connect($db);
            $pdo->query('SELECT 1');

            return ['ok' => true];
        } catch (PDOException $e) {
            $unknownDatabase = str_contains($e->getMessage(), 'Unknown database')
                || str_contains($e->getMessage(), '1049');

            if (!$unknownDatabase) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // The database does not exist yet: try to create it.
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']);
            $server = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . $db['database'] . '` '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $server = null;

            $check = Db::connect($db);
            $check->query('SELECT 1');

            return ['ok' => true];
        } catch (PDOException $e) {
            return [
                'ok'    => false,
                'error' => 'Database "' . $db['database'] . '" is not reachable and could not be created: '
                    . $e->getMessage(),
            ];
        }
    }

    private function runSchema(): void
    {
        $sqlFile = BASE_PATH . '/install/schema.sql';

        if (!is_file($sqlFile)) {
            throw new \RuntimeException('install/schema.sql is missing.');
        }

        $sql = (string) file_get_contents($sqlFile);

        // Drop SQL comments before splitting so the first statement is not skipped.
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $sql = implode("\n", $lines);

        $pdo = Db::pdo();

        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            $pdo->exec($statement);
        }
    }

    private function seedDemoCameras(): void
    {
        $groupId = Db::insert('camera_groups', [
            'name'        => 'Demo cameras',
            'description' => 'Sample feeds you can rename or delete.',
            'sort_order'  => 0,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $feeds = [
            ['Reception', 'Ground floor — main entrance'],
            ['Lobby north', 'Ground floor — lifts'],
            ['Loading dock', 'Warehouse — bay 2'],
            ['Parking lot', 'Exterior — south gate'],
            ['Server room', 'Level 2 — restricted'],
            ['Rear entrance', 'Exterior — alley'],
        ];

        $order = 10;
        foreach ($feeds as [$name, $location]) {
            Db::insert('cameras', [
                'group_id'      => $groupId,
                'name'          => $name,
                'location'      => $location,
                'driver'        => 'demo',
                'host'          => 'demo',
                'port'          => 80,
                'rtsp_port'     => 554,
                'username'      => '',
                'password_enc'  => null,
                'channel'       => 1,
                'stream_mode'   => 'snapshot',
                'snapshot_path' => '',
                'mjpeg_path'    => '',
                'rtsp_path'     => '',
                'ptz_path'      => '',
                'ptz_enabled'   => 0,
                'transport'     => 'tcp',
                'is_enabled'    => 1,
                'sort_order'    => $order,
                'status'        => 'online',
                'last_seen_at'  => gmdate('Y-m-d H:i:s'),
                'created_at'    => gmdate('Y-m-d H:i:s'),
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ]);

            $order += 10;
        }
    }

    private function environment(): array
    {
        $storage = BASE_PATH . '/storage';
        $dirs = ['cache', 'hls', 'logs'];

        @mkdir($storage, 0770, true);
        foreach ($dirs as $dir) {
            @mkdir($storage . '/' . $dir, 0770, true);
        }

        return [
            ['label' => 'PHP ' . PHP_VERSION . ' (8.1+)', 'ok' => PHP_VERSION_ID >= 80100],
            ['label' => 'PDO MySQL driver', 'ok' => extension_loaded('pdo_mysql')],
            ['label' => 'cURL extension', 'ok' => function_exists('curl_init')],
            ['label' => 'OpenSSL extension', 'ok' => extension_loaded('openssl')],
            ['label' => 'Writable storage directory', 'ok' => is_writable($storage)],
            ['label' => 'Writable config directory', 'ok' => is_writable(BASE_PATH . '/config')],
        ];
    }
}
