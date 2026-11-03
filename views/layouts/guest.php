<?php
$t = $t ?? static fn (string $key): string => $key;
$htmlLang = $htmlLang ?? 'en';
$locale = $locale ?? 'en';
$languageOptions = $languageOptions ?? ['en' => 'English', 'my' => 'Myanmar'];
$languageSwitchUrl = $languageSwitchUrl ?? static fn (string $locale): string => '?lang=' . rawurlencode($locale);
$title = $title ?? $t('app.official_name');
$guestTitle = $guestTitle ?? $t('app.official_name');
$guestDescription = $guestDescription ?? $t('app.guest_description');
$guestBrandShowSubtitle = (bool) ($guestBrandShowSubtitle ?? true);
$guestBrandWordmark = $guestBrandWordmark ?? $t('app.acronym');
$guestBrandSubtitle = $guestBrandSubtitle ?? $t('app.tagline');
$guestFooterText = (string) ($guestFooterText ?? $t('app.acronym'));
$guestVisualTitle = $guestVisualTitle ?? $guestDescription;
$guestOverviewIntro = $guestOverviewIntro ?? '';
$guestOverviewFeatures = $guestOverviewFeatures ?? [];
$hasGuestOverview = is_array($guestOverviewFeatures) && $guestOverviewFeatures !== [];
$guestCompactVisual = (bool) ($guestCompactVisual ?? false);
$guestVisualShowBrand = (bool) ($guestVisualShowBrand ?? false);
$hasGuestVisualContent = $guestCompactVisual || $hasGuestOverview;
$showGuestVisual = (bool) ($showGuestVisual ?? true);
$guestFrameVariant = (string) ($guestFrameVariant ?? '');
$guestFrameVariantClass = match ($guestFrameVariant) {
    'focused-login' => 'is-focused-login',
    'verification' => 'is-verification',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <script src="/assets/js/main.js" defer></script>
</head>
<body class="sapas-guest-body" data-scrollable-table-label="<?= htmlspecialchars($t('layout.scrollable_table'), ENT_QUOTES, 'UTF-8') ?>">
    <a href="#main-content" class="sapas-skip-link"><?= htmlspecialchars($t('layout.skip_to_content'), ENT_QUOTES, 'UTF-8') ?></a>
    <div class="sapas-guest-shell px-4 py-3 sm:px-6 sm:py-4 lg:px-8">
        <div class="sapas-guest-frame <?= $showGuestVisual ? '' : 'is-single-panel' ?> <?= $guestCompactVisual ? 'is-compact-visual' : '' ?> <?= htmlspecialchars($guestFrameVariantClass, ENT_QUOTES, 'UTF-8') ?>">
            <section class="sapas-guest-form-panel">
                <div class="sapas-guest-language">
                    <?php require __DIR__ . '/../partials/language_switcher.php'; ?>
                </div>

                <div class="sapas-guest-header">
                    <?php
                    $brandVariant = 'guest';
                    $brandHref = null;
                    $brandShowSubtitle = $guestBrandShowSubtitle;
                    $brandWordmark = $guestBrandWordmark;
                    $brandSubtitle = $guestBrandSubtitle;
                    require __DIR__ . '/../partials/brand.php';
                    unset($brandVariant, $brandHref, $brandShowSubtitle, $brandWordmark, $brandSubtitle);
                    ?>
                    <?php if ($guestDescription !== ''): ?>
                        <p class="sapas-guest-description"><?= htmlspecialchars($guestDescription, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>

                <main id="main-content" class="sapas-guest-card" tabindex="-1">
                    <?php require __DIR__ . '/../partials/flash.php'; ?>
                    <?= $content ?>
                </main>

                <?php if ($guestFooterText !== ''): ?>
                    <p class="sapas-guest-footer">&copy; 2026 <?= htmlspecialchars($guestFooterText, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </section>

            <?php if ($showGuestVisual): ?>
            <aside class="sapas-guest-visual <?= $guestCompactVisual ? 'is-compact' : '' ?>" <?= $hasGuestVisualContent ? '' : 'aria-hidden="true" inert' ?>>
                <div class="sapas-guest-visual-inner">
                    <?php if ($guestVisualShowBrand): ?>
                        <div class="sapas-guest-visual-brand">
                            <?php
                            $brandVariant = 'guest';
                            $brandHref = null;
                            $brandShowSubtitle = true;
                            $brandWordmark = $t('app.acronym');
                            $brandSubtitle = $t('app.official_name');
                            require __DIR__ . '/../partials/brand.php';
                            unset($brandVariant, $brandHref, $brandShowSubtitle, $brandWordmark, $brandSubtitle);
                            ?>
                        </div>
                    <?php endif; ?>
                    <div class="sapas-guest-visual-copy">
                        <p class="sapas-guest-visual-kicker"><?= htmlspecialchars($t('app.tagline'), ENT_QUOTES, 'UTF-8') ?></p>
                        <h2 class="sapas-guest-visual-title"><?= htmlspecialchars($guestVisualTitle, ENT_QUOTES, 'UTF-8') ?></h2>

                        <?php if ($guestOverviewIntro !== ''): ?>
                            <p class="sapas-guest-overview-intro"><?= htmlspecialchars((string) $guestOverviewIntro, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasGuestOverview): ?>
                        <div class="sapas-guest-overview-grid">
                            <?php foreach ($guestOverviewFeatures as $feature): ?>
                                <?php
                                $featureTitle = (string) ($feature['title'] ?? '');
                                $featureCopy = (string) ($feature['copy'] ?? '');
                                $featureTone = (string) ($feature['tone'] ?? 'brand');
                                $featureIcon = (string) ($feature['icon'] ?? 'briefcase');
                                ?>
                                <div class="sapas-guest-overview-card">
                                    <span class="sapas-guest-feature-icon is-<?= htmlspecialchars($featureTone, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php
                                        $iconName = $featureIcon;
                                        $iconClass = 'sapas-icon';
                                        require __DIR__ . '/../partials/icon.php';
                                        unset($iconName, $iconClass);
                                        ?>
                                    </span>
                                    <div>
                                        <strong><?= htmlspecialchars($featureTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if ($featureCopy !== ''): ?>
                                            <p><?= htmlspecialchars($featureCopy, ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!$guestCompactVisual): ?>
                    <div class="sapas-guest-product-card">
                        <div class="sapas-guest-product-toolbar">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div class="sapas-guest-preview-window">
                            <div class="sapas-guest-preview-sidebar">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <div class="sapas-guest-preview-main">
                                <div class="sapas-guest-preview-row is-header">
                                    <span></span>
                                    <span></span>
                                </div>
                                <div class="sapas-guest-preview-row">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <div class="sapas-guest-preview-row">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <div class="sapas-guest-preview-row">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sapas-guest-visual-grid">
                        <div class="sapas-guest-capability-card">
                            <span class="sapas-guest-card-mark"></span>
                            <strong><?= htmlspecialchars($t('nav.attendance'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span></span>
                        </div>
                        <div class="sapas-guest-capability-card">
                            <span class="sapas-guest-card-mark is-emerald"></span>
                            <strong><?= htmlspecialchars($t('nav.leave_requests'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span></span>
                        </div>
                        <div class="sapas-guest-capability-card is-wide">
                            <span class="sapas-guest-card-mark is-amber"></span>
                            <strong><?= htmlspecialchars($t('nav.reports'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
