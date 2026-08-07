<?php
$t = $t ?? static fn (string $key): string => $key;
$htmlLang = $htmlLang ?? 'en';
$title = $title ?? $t('app.acronym');
$titleSuffix = $t('app.title_suffix');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($titleSuffix, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <script src="/assets/js/main.js" defer></script>
</head>
<body class="sapas-app-body min-h-screen text-slate-900" data-scrollable-table-label="<?= htmlspecialchars($t('layout.scrollable_table'), ENT_QUOTES, 'UTF-8') ?>">
    <a href="#main-content" class="sapas-skip-link"><?= htmlspecialchars($t('layout.skip_to_content'), ENT_QUOTES, 'UTF-8') ?></a>
    <div class="sapas-app-shell mx-auto">
        <div class="sapas-shell-grid">
            <div class="sapas-sidebar-backdrop" data-sidebar-backdrop hidden></div>
            <aside id="app-sidebar" class="sapas-sidebar" aria-label="<?= htmlspecialchars($t('layout.primary_navigation'), ENT_QUOTES, 'UTF-8') ?>">
                <?php require __DIR__ . '/../partials/sidebar.php'; ?>
            </aside>

            <div class="sapas-main">
                <?php require __DIR__ . '/../partials/topbar.php'; ?>
                <?php require __DIR__ . '/../partials/page_header.php'; ?>
                <?php require __DIR__ . '/../partials/flash.php'; ?>
                <main id="main-content" class="sapas-content" tabindex="-1">
                    <?= $content ?>
                </main>
            </div>
        </div>
    </div>
</body>
</html>
