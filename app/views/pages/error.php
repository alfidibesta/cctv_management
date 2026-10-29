<?php
/** @var int $code */
/** @var string $message */
require_once BASE_PATH . '/app/views/partials/icons.php';
$code = $code ?? 404;
?>
<div class="error-page">
    <div class="error-page__card">
        <span class="error-page__code"><?= (int) $code ?></span>
        <h1><?= $code === 403 ? 'Access denied' : ($code === 404 ? 'Page not found' : 'Something went wrong') ?></h1>
        <p><?= e($message ?? 'The page you requested is not available.') ?></p>
        <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= icon('wall') ?> Back to the wall</a>
    </div>
</div>
