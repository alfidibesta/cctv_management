<?php
/** @var string $message */
/** @var string $detail */
/** @var bool $logged */
require_once BASE_PATH . '/app/views/partials/icons.php';
?>
<div class="error-page">
    <div class="error-page__card">
        <span class="error-page__code">500</span>
        <h1>Something went wrong</h1>
        <p><?= e($message ?? 'The request could not be completed.') ?></p>
        <?php if (!empty($detail)): ?>
            <p class="error-page__detail"><code><?= e($detail) ?></code></p>
        <?php endif; ?>
        <p class="error-page__hint">
            <?= icon('info') ?>
            <?= !empty($logged)
                ? 'The full error was written to storage/logs/app.log and the activity feed.'
                : 'Check that the database is running and config/config.php is correct.' ?>
        </p>
        <div class="error-page__actions">
            <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= icon('wall') ?> Back to the wall</a>
            <a class="btn btn--ghost" href="javascript:location.reload()"><?= icon('refresh') ?> Try again</a>
        </div>
    </div>
</div>
