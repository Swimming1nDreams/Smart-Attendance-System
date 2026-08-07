<?php
$t = $t ?? static fn (string $key): string => $key;
$locale = $locale ?? 'en';
$languageOptions = $languageOptions ?? ['en' => 'English', 'my' => 'Myanmar'];
$languageSwitchUrl = $languageSwitchUrl ?? static fn (string $targetLocale): string => '?lang=' . rawurlencode($targetLocale);
$languageShortLabels = $languageShortLabels ?? ['en' => 'EN', 'my' => 'မြန်မာ'];
?>
<nav class="sapas-language-switcher" aria-label="<?= htmlspecialchars($t('language.label'), ENT_QUOTES, 'UTF-8') ?>">
    <span class="sapas-language-switcher-icon" aria-hidden="true">
        <?php
        $iconName = 'globe';
        $iconClass = 'sapas-action-icon';
        require __DIR__ . '/icon.php';
        unset($iconName, $iconClass);
        ?>
    </span>
    <span class="sapas-language-options">
        <?php foreach ($languageOptions as $languageLocale => $languageLabel): ?>
            <a
                href="<?= htmlspecialchars($languageSwitchUrl((string) $languageLocale), ENT_QUOTES, 'UTF-8') ?>"
                class="sapas-language-option <?= $locale === $languageLocale ? 'is-active' : '' ?>"
                lang="<?= htmlspecialchars((string) $languageLocale, ENT_QUOTES, 'UTF-8') ?>"
                hreflang="<?= htmlspecialchars((string) $languageLocale, ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars((string) $languageLabel, ENT_QUOTES, 'UTF-8') ?>"
                <?= $locale === $languageLocale ? 'aria-current="true"' : '' ?>
            >
                <?= htmlspecialchars((string) ($languageShortLabels[$languageLocale] ?? $languageLabel), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </span>
</nav>
