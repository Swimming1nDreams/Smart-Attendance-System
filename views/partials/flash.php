<?php if (!empty($flash)): ?>
    <?php if (!empty($flash['success'])): ?>
        <div class="sapas-flash sapas-flash-success" role="status" aria-live="polite" data-flash-message>
            <span><?= htmlspecialchars($flash['success'], ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="sapas-flash-dismiss" data-flash-dismiss aria-label="<?= htmlspecialchars($t('layout.dismiss_message'), ENT_QUOTES, 'UTF-8') ?>">
                <?php $iconName = 'x'; $iconClass = 'sapas-action-icon'; require __DIR__ . '/icon.php'; unset($iconName, $iconClass); ?>
            </button>
        </div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])): ?>
        <div class="sapas-flash sapas-flash-error" role="alert" aria-live="assertive" data-flash-message>
            <span><?= htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="sapas-flash-dismiss" data-flash-dismiss aria-label="<?= htmlspecialchars($t('layout.dismiss_message'), ENT_QUOTES, 'UTF-8') ?>">
                <?php $iconName = 'x'; $iconClass = 'sapas-action-icon'; require __DIR__ . '/icon.php'; unset($iconName, $iconClass); ?>
            </button>
        </div>
    <?php endif; ?>
<?php endif; ?>
