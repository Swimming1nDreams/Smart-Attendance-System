<?php
$t = $t ?? static fn (string $key): string => $key;
$brandVariant = $brandVariant ?? 'primary';
$brandHref = $brandHref ?? null;
$brandShowText = $brandShowText ?? true;
$brandShowSubtitle = $brandShowSubtitle ?? true;
$brandWordmark = $brandWordmark ?? $t('app.acronym');
$brandSubtitle = $brandSubtitle ?? $t('app.tagline');

$brandClass = 'sapas-brand-lockup';
if ($brandVariant === 'guest') {
    $brandClass .= ' sapas-brand-lockup-guest';
} elseif ($brandVariant === 'compact') {
    $brandClass .= ' sapas-brand-lockup-compact';
}
?>
<?php if ($brandHref !== null): ?>
    <a href="<?= htmlspecialchars((string) $brandHref, ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars($brandClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($t('app.official_name'), ENT_QUOTES, 'UTF-8') ?>">
<?php else: ?>
    <div class="<?= htmlspecialchars($brandClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($t('app.official_name'), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
        <span class="sapas-brand-mark" aria-hidden="true"><span>O</span></span>
        <?php if ($brandShowText): ?>
            <span class="sapas-brand-text">
                <span class="sapas-brand-wordmark"><?= htmlspecialchars((string) $brandWordmark, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($brandShowSubtitle): ?>
                    <span class="sapas-brand-subtitle"><?= htmlspecialchars((string) $brandSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </span>
        <?php endif; ?>
<?php if ($brandHref !== null): ?>
    </a>
<?php else: ?>
    </div>
<?php endif; ?>
