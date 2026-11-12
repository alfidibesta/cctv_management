<?php
/**
 * Create an administrator, or reset the password of an existing account, from
 * the command line — no bcrypt hash to copy by hand.
 *
 *   php scripts/create-admin.php
 *   php scripts/create-admin.php --email=admin@example.com --password='S3cret-pass' --name="Admin"
 *   php scripts/create-admin.php --email=ops@example.com --role=operator --password='...'
 *
 * Every option can also be typed interactively; the password is hidden while
 * typing. Nothing is echoed back and the hash never leaves the database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use Argus\Support\Activity;
use Argus\Support\Config;
use Argus\Support\Db;

const ARGUS_CLI_ROLES = ['admin', 'operator', 'viewer'];

$options = argus_cli_options($argv);

if (isset($options['help'])) {
    argus_cli_usage();
    exit(0);
}

if (!Config::installed()) {
    fwrite(STDERR, "Argus is not installed yet: config/config.php is missing.\n" .
        "Open the app in a browser and complete the setup wizard first.\n");
    exit(1);
}

$email = mb_strtolower(trim((string) ($options['email'] ?? '')));
if ($email === '') {
    if (!argus_cli_is_tty()) {
        fwrite(STDERR, "No email given. Pass --email=you@example.com when running non-interactively.\n");
        exit(1);
    }

    $email = mb_strtolower(trim(argus_cli_prompt('Email: ')));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That does not look like a valid email address: \"$email\".\n");
    exit(1);
}

$role = strtolower(trim((string) ($options['role'] ?? 'admin')));
if (!in_array($role, ARGUS_CLI_ROLES, true)) {
    fwrite(STDERR, "Unknown role \"$role\". Use one of: " . implode(', ', ARGUS_CLI_ROLES) . ".\n");
    exit(1);
}

$password = (string) ($options['password'] ?? '');
if ($password === '') {
    if (!argus_cli_is_tty()) {
        fwrite(STDERR, "No password given. Pass --password='...' when running non-interactively.\n");
        exit(1);
    }

    $password = argus_cli_prompt('Password (min 8 characters): ', true);
    $confirm = argus_cli_prompt('Repeat password: ', true);

    if ($password !== $confirm) {
        fwrite(STDERR, "The passwords do not match.\n");
        exit(1);
    }
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Use at least 8 characters for the password.\n");
    exit(1);
}

try {
    $existing = Db::one('SELECT id, name, role FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

    $name = trim((string) ($options['name'] ?? ''));
    if ($name === '') {
        $name = $existing !== null
            ? (string) $existing['name']
            : (ucfirst((string) strstr($email, '@', true)) ?: 'Administrator');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing !== null) {
        Db::update('users', [
            'name'          => mb_substr($name, 0, 120),
            'password_hash' => $hash,
            'role'          => $role,
            'is_active'     => 1,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $existing['id']]);

        $id = (int) $existing['id'];
        Activity::log('user.password_reset', 'Password reset from the command line for ' . $email, 'user', $id);

        echo 'Updated ', $role, ' "', $name, ' <', $email, '>" (id ', $id, ').', PHP_EOL;
        echo 'The previous password no longer works.', PHP_EOL;
    } else {
        $id = Db::insert('users', [
            'name'          => mb_substr($name, 0, 120),
            'email'         => $email,
            'password_hash' => $hash,
            'role'          => $role,
            'is_active'     => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        Activity::log('user.created', 'Created ' . $role . ' ' . $email . ' from the command line', 'user', $id);

        echo 'Created ', $role, ' "', $name, ' <', $email, '>" (id ', $id, ').', PHP_EOL;
    }

    if ($role === 'viewer') {
        echo 'A viewer only sees cameras in its groups. Assign one with:', PHP_EOL;
        echo '  INSERT INTO user_groups (user_id, group_id) VALUES (', $id, ', <group_id>);', PHP_EOL;
    }

    echo 'Sign in at /login with this email and the password you just entered.', PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not save the account: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Check the database settings in config/config.php.' . PHP_EOL);
    exit(1);
}

/** Parse --name=value pairs; anything else is ignored. */
function argus_cli_options(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$key, $value] = explode('=', substr($argument, 2), 2);
            $options[strtolower(trim($key))] = $value;
        }
    }

    return $options;
}

function argus_cli_usage(): void
{
    echo <<<'TXT'
Usage: php scripts/create-admin.php [options]

Creates an account, or resets the password and role of an existing email.
Missing values are asked interactively; the password is hidden while typing.

Options:
  --email=you@example.com   Account email (required, unique)
  --name="Your name"        Display name (default: existing name or the local part)
  --password='...'          Password, at least 8 characters (asked if omitted)
  --role=admin              admin | operator | viewer (default: admin)
  --help                    Show this help

Examples:
  php scripts/create-admin.php
  php scripts/create-admin.php --email=admin@example.com --password='S3cret-pass'
  php scripts/create-admin.php --email=ops@example.com --role=operator --password='...'

TXT;
}

function argus_cli_is_tty(): bool
{
    return function_exists('stream_isatty') ? @stream_isatty(STDIN) : false;
}

function argus_cli_prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);

    $canHide = $hidden && DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');

    if ($canHide) {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $value = (string) fgets(STDIN);

    if ($canHide) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    return trim($value);
}
