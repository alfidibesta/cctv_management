<?php
/** @var string $content */
/** @var array|null $user */
require_once BASE_PATH . '/app/views/partials/icons.php';

$me = $user ?? current_user();
$appName = \Argus\Support\Settings::get('app_name') ?: \Argus\Support\Config::appName();
$isAdmin = ($me['role'] ?? '') === 'admin';
$canManage = in_array($me['role'] ?? '', ['admin', 'operator'], true);
$bodyClass = $bodyClass ?? '';
$scripts = $scripts ?? [];
$flash = flash_messages();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title ?? 'Live wall') ?> · <?= e($appName) ?></title>
    <meta name="csrf-token" content="<?= e(\Argus\Support\Csrf::token()) ?>">
    <meta name="base-url" content="<?= e(url('/')) ?>">
    <meta name="color-scheme" content="dark">
    <link rel="icon" href="<?= e(url('/favicon.svg')) ?>" type="image/svg+xml">
    <script>
        // Apply saved appearance before first paint to avoid a theme flash.
        (function () {
            try {
                var theme = localStorage.getItem('argus.theme');
                var density = localStorage.getItem('argus.density');
                var rail = localStorage.getItem('argus.rail');
                if (theme === 'light') document.documentElement.dataset.theme = 'light';
                if (density === 'compact') document.documentElement.dataset.density = 'compact';
                if (rail === '1') document.documentElement.dataset.rail = '1';
            } catch (e) { /* storage blocked */ }
        })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand__mark"><?= icon('scan') ?></span>
            <span class="brand__text">
                <strong><?= e($appName) ?></strong>
                <span>Camera wall</span>
            </span>
        </a>

        <nav class="nav">
            <p class="nav__label">Monitor</p>
            <a class="nav__item <?= $active === 'wall' ? 'is-active' : '' ?>" href="<?= e(url('/')) ?>">
                <?= icon('wall') ?><span>Live wall</span>
            </a>
            <a class="nav__item <?= $active === 'cameras' ? 'is-active' : '' ?>" href="<?= e(url('/cameras')) ?>">
                <?= icon('camera') ?><span>Cameras</span>
            </a>
            <a class="nav__item <?= $active === 'groups' ? 'is-active' : '' ?>" href="<?= e(url('/groups')) ?>">
                <?= icon('groups') ?><span>Groups</span>
            </a>
            <a class="nav__item <?= $active === 'health' ? 'is-active' : '' ?>" href="<?= e(url('/health')) ?>">
                <?= icon('activity') ?><span>Health</span>
            </a>
            <?php if ($canManage): ?>
                <a class="nav__item <?= $active === 'discover' ? 'is-active' : '' ?>" href="<?= e(url('/discover')) ?>">
                    <?= icon('scan') ?><span>Discover</span>
                </a>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <p class="nav__label">Administration</p>
                <a class="nav__item <?= $active === 'users' ? 'is-active' : '' ?>" href="<?= e(url('/users')) ?>">
                    <?= icon('users') ?><span>Users</span>
                </a>
                <a class="nav__item <?= $active === 'activity' ? 'is-active' : '' ?>" href="<?= e(url('/activity')) ?>">
                    <?= icon('activity') ?><span>Activity</span>
                </a>
                <a class="nav__item <?= $active === 'settings' ? 'is-active' : '' ?>" href="<?= e(url('/settings')) ?>">
                    <?= icon('settings') ?><span>Settings</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar__footer">
            <a class="user-chip" href="<?= e(url('/account')) ?>">
                <span class="user-chip__avatar"><?= e(user_initials($me['name'] ?? '?')) ?></span>
                <span class="user-chip__text">
                    <strong><?= e($me['name'] ?? 'Signed out') ?></strong>
                    <span><?= e(ucfirst($me['role'] ?? '')) ?></span>
                </span>
            </a>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="icon-btn" type="submit" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn topbar__menu" type="button" data-sidebar-toggle aria-label="Toggle navigation">
                <?= icon('menu') ?>
            </button>
            <button class="icon-btn topbar__rail" type="button" data-rail-toggle aria-expanded="true"
                    aria-label="Collapse navigation" title="Collapse navigation">
                <?= icon('panel') ?>
            </button>
            <div class="topbar__title">
                <h1><?= e($title ?? '') ?></h1>
                <?php if (!empty($subtitle)): ?>
                    <p><?= e($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <div class="topbar__actions">
                <?php if (!empty($actions)): ?>
                    <?= $actions ?>
                <?php endif; ?>
                <button class="icon-btn" type="button" data-density-toggle aria-pressed="false"
                        aria-label="Toggle compact view" title="Switch to compact view">
                    <?= icon('rows') ?>
                </button>
                <button class="icon-btn" type="button" data-theme-toggle aria-pressed="false"
                        aria-label="Toggle color theme" title="Switch to light mode">
                    <span class="theme-icon theme-icon--dark"><?= icon('sun') ?></span>
                    <span class="theme-icon theme-icon--light"><?= icon('moon') ?></span>
                </button>
                <span class="clock" data-clock>
                    <span data-clock-time><?= e(gmdate('H:i:s')) ?></span>
                    <span class="clock__date" data-clock-date><?= e(gmdate('D d M')) ?></span>
                </span>
            </div>
        </header>

        <main class="content">
            <?= $content ?>
        </main>
    </div>
</div>

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
<?php foreach ($scripts as $script): ?>
    <script src="<?= e(asset('js/' . $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
