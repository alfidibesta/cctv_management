<?php
/** @var string $content */
require_once BASE_PATH . '/app/views/partials/icons.php';

$appName = \Argus\Support\Settings::get('app_name') ?: \Argus\Support\Config::appName();
$flash = flash_messages();
$bodyClass = $bodyClass ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title ?? 'Sign in') ?> · <?= e($appName) ?></title>
    <meta name="csrf-token" content="<?= e(\Argus\Support\Csrf::token()) ?>">
    <meta name="base-url" content="<?= e(url('/')) ?>">
    <meta name="color-scheme" content="dark">
    <link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest <?= e($bodyClass) ?>">
    <div class="guest__glow" aria-hidden="true"></div>
    <main class="guest__main">
        <?= $content ?>
    </main>
    <footer class="guest__footer">
        <span><?= e($appName) ?></span>
        <span class="guest__dot"></span>
        <span>IP camera wall</span>
    </footer>

    <div class="toasts" data-toasts>
        <?php foreach ($flash as $message): ?>
            <div class="toast toast--<?= e($message['type'] ?? 'success') ?>" data-toast>
                <span class="toast__icon"><?= icon(($message['type'] ?? '') === 'error' ? 'info' : 'check') ?></span>
                <p><?= e($message['message']) ?></p>
                <button type="button" class="icon-btn icon-btn--sm" data-toast-close aria-label="Dismiss"><?= icon('close') ?></button>
            </div>
        <?php endforeach; ?>
    </div>

    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
