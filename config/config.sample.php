<?php
/**
 * Argus camera wall - sample configuration.
 *
 * The installer writes config/config.php for you. If you prefer to configure
 * the app by hand, copy this file to config/config.php and adjust the values,
 * then run the SQL found in install/schema.sql against your database.
 *
 * config/config.php must never be committed to version control: it holds the
 * database password and the encryption key used for camera credentials.
 */

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'argus',
        'username' => 'argus',
        'password' => 'change-me',
        'charset'  => 'utf8mb4',
    ],

    'app' => [
        // Public name shown in the UI and the browser title.
        'name'     => 'Argus',
        // 32 random bytes, base64 encoded. Encrypts camera passwords at rest.
        // Generate with: php -r "echo base64_encode(random_bytes(32));"
        'key'      => 'REPLACE_WITH_BASE64_32_BYTES',
        // Salt used to hash session/API tokens.
        'timezone' => 'UTC',
        // Set true only while debugging; hides stack traces when false.
        'debug'    => false,
        // Optional sub-path when the app is not served from the web root,
        // e.g. '/argus'. Leave empty for automatic detection.
        'base_path' => '',
        'installed_at' => null,
    ],
];
