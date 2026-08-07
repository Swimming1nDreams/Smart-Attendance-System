<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('reports_module.title');
}
if (!isset($reportTypes)) {
    $reportTypes = [];
}
if (!isset($selectedType)) {
    $selectedType = '';
}

$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('reports_module.title');
$pageDescription = $pageDescription ?? $t('reports_module.description');

$reportLabels = [
    'attendance-summary' => $t('reports_module.attendance_summary'),
    'employee-attendance-detail' => $t('reports_module.employee_attendance_detail'),
    'leave-request' => $t('reports_module.leave_request'),
    'late-absent-exception' => $t('reports_module.late_absent_exception'),
];

$reportIcons = [
    'attendance-summary' => 'analytics',
    'employee-attendance-detail' => 'user',
    'leave-request' => 'leave',
    'late-absent-exception' => 'warning',
];

$reportTones = [
    'attendance-summary' => 'is-attendance',
    'employee-attendance-detail' => 'is-employee',
    'leave-request' => 'is-leave',
    'late-absent-exception' => 'is-exception',
];

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};
?>
<div class="sapas-workspace-stack">
    <div class="sapas-report-card-grid">
        <?php foreach ($reportTypes as $key => $report): ?>
            <?php
            $isSelected = $selectedType === $key;
            $reportTitle = $reportLabels[$key] ?? (string) ($report['title'] ?? $t('reports_module.title'));
            $reportDescription = (string) ($report['description'] ?? 'Report foundation.');
            $reportHref = match ($key) {
                'attendance-summary' => '/admin/reports/attendance-summary',
                'employee-attendance-detail' => '/admin/reports/employee-attendance-detail',
                'leave-request' => '/admin/reports/leave-requests',
                'late-absent-exception' => '/admin/reports/late-absent-exceptions',
                default => '/admin/reports?type=' . urlencode((string) $key),
            };
            ?>
            <a
                href="<?= htmlspecialchars($reportHref, ENT_QUOTES, 'UTF-8') ?>"
                class="sapas-report-card <?= htmlspecialchars($reportTones[$key] ?? '', ENT_QUOTES, 'UTF-8') ?><?= $isSelected ? ' is-selected' : '' ?>"
            >
                <div class="sapas-report-card-body">
                    <span class="sapas-report-card-icon">
                        <?= $renderIcon($reportIcons[$key] ?? 'file-text', 'sapas-icon') ?>
                    </span>
                    <div class="sapas-report-card-content min-w-0">
                        <h3 class="sapas-report-card-title"><?= htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="sapas-report-card-copy"><?= htmlspecialchars($reportDescription, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <div class="sapas-report-card-footer">
                    <span class="sapas-report-card-action<?= $isSelected ? ' is-selected-action' : '' ?>">
                        <?= htmlspecialchars($isSelected ? $t('reports_module.selected') : $t('reports_module.open'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
