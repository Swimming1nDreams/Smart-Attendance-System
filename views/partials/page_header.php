<?php
$pageTitle = $pageTitle ?? ($title ?? '');
$pageDescription = $pageDescription ?? '';
$pageActions = $pageActions ?? '';
$pageIcon = $pageIcon ?? '';

if ($pageTitle === '' && $pageDescription === '' && $pageActions === '') {
    return;
}
?>
<div class="sapas-page-header">
    <div class="sapas-page-header-inner">
        <div class="sapas-page-header-text">
            <?php if ($pageTitle !== ''): ?>
                <div class="sapas-page-title-row">
                    <?php if ($pageIcon !== ''): ?>
                        <span class="sapas-page-title-icon">
                            <?php
                            $iconName = (string) $pageIcon;
                            $iconClass = 'sapas-icon';
                            require __DIR__ . '/icon.php';
                            unset($iconName, $iconClass);
                            ?>
                        </span>
                    <?php endif; ?>
                    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                </div>
            <?php endif; ?>
            <?php if ($pageDescription !== ''): ?>
                <p><?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>

        <?php if ($pageActions !== ''): ?>
            <div class="sapas-page-actions">
                <?= $pageActions ?>
            </div>
        <?php endif; ?>
    </div>
</div>
