<?php

declare(strict_types=1);

$paginationTotalPages = max(1, (int) ($pages ?? 1));
$paginationCurrentPage = min($paginationTotalPages, max(1, (int) ($page ?? 1)));
$paginationBasePath = (string) ($paginationPath ?? '');
$paginationParameters = is_array($paginationQuery ?? null) ? $paginationQuery : [];
$paginationPerPage = max(1, (int) ($perPage ?? 20));
$paginationTotalRecords = max(0, (int) ($paginationTotalRecords ?? $total ?? $totalRecords ?? $totalRequests ?? 0));
$paginationPageSizeOptions = array_values(array_filter(
    array_map('intval', is_array($paginationPageSizeOptions ?? null) ? $paginationPageSizeOptions : []),
    static fn (int $option): bool => $option > 0
));
$paginationPageSizeOptions = array_values(array_unique($paginationPageSizeOptions));

$paginationUrl = static function (int $targetPage) use ($paginationBasePath, $paginationParameters): string {
    return $paginationBasePath . '?' . http_build_query(array_merge($paginationParameters, ['page' => $targetPage]));
};

if ($paginationTotalPages <= 7) {
    $paginationVisiblePages = range(1, $paginationTotalPages);
} elseif ($paginationCurrentPage <= 2) {
    $paginationVisiblePages = [1, 2, 3, $paginationTotalPages];
} elseif ($paginationCurrentPage >= $paginationTotalPages - 1) {
    $paginationVisiblePages = [1, $paginationTotalPages - 2, $paginationTotalPages - 1, $paginationTotalPages];
} else {
    $paginationVisiblePages = [
        1,
        $paginationCurrentPage - 1,
        $paginationCurrentPage,
        $paginationCurrentPage + 1,
        $paginationTotalPages,
    ];
}

$paginationVisiblePages = array_values(array_unique(array_filter(
    $paginationVisiblePages,
    static fn (int $visiblePage): bool => $visiblePage >= 1 && $visiblePage <= $paginationTotalPages
)));
sort($paginationVisiblePages);

$paginationRecordStart = $paginationTotalRecords === 0
    ? 0
    : (($paginationCurrentPage - 1) * $paginationPerPage) + 1;
$paginationRecordEnd = min($paginationTotalRecords, $paginationCurrentPage * $paginationPerPage);

$paginationLabel = $t('common.pagination.label');
$paginationPreviousLabel = $t('common.pagination.previous');
$paginationNextLabel = $t('common.pagination.next');
$paginationSummary = sprintf($t('common.pagination.summary'), $paginationCurrentPage, $paginationTotalPages);
$paginationRecordsSummary = sprintf(
    $t('common.pagination.records_summary'),
    $paginationRecordStart,
    $paginationRecordEnd,
    $paginationTotalRecords
);
$paginationRowsPerPageLabel = $t('common.pagination.rows_per_page');
?>
<?php if ($paginationTotalPages > 1 || $paginationPageSizeOptions !== []): ?>
    <nav class="sapas-pagination" aria-label="<?= htmlspecialchars($paginationLabel, ENT_QUOTES, 'UTF-8') ?>">
        <div class="sapas-pagination-meta">
            <p class="sapas-pagination-summary"><?= htmlspecialchars($paginationRecordsSummary, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-pagination-page-summary"><?= htmlspecialchars($paginationSummary, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-pagination-controls">
            <?php if ($paginationPageSizeOptions !== []): ?>
                <form method="GET" action="<?= htmlspecialchars($paginationBasePath, ENT_QUOTES, 'UTF-8') ?>" class="sapas-pagination-size-form">
                    <?php foreach ($paginationParameters as $paginationParameterName => $paginationParameterValue): ?>
                        <?php if (in_array((string) $paginationParameterName, ['page', 'per_page'], true) || !is_scalar($paginationParameterValue)): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <input
                            type="hidden"
                            name="<?= htmlspecialchars((string) $paginationParameterName, ENT_QUOTES, 'UTF-8') ?>"
                            value="<?= htmlspecialchars((string) $paginationParameterValue, ENT_QUOTES, 'UTF-8') ?>"
                        >
                    <?php endforeach; ?>
                    <input type="hidden" name="page" value="1">
                    <label for="pagination-per-page" class="sapas-pagination-size-label">
                        <?= htmlspecialchars($paginationRowsPerPageLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select
                        id="pagination-per-page"
                        name="per_page"
                        class="sapas-pagination-size-select"
                        onchange="this.form.submit()"
                    >
                        <?php foreach ($paginationPageSizeOptions as $paginationPageSize): ?>
                            <option value="<?= $paginationPageSize ?>" <?= $paginationPerPage === $paginationPageSize ? 'selected' : '' ?>>
                                <?= $paginationPageSize ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <?php if ($paginationTotalPages > 1): ?>
                <div class="sapas-pagination-list">
                    <?php if ($paginationCurrentPage > 1): ?>
                        <a
                            href="<?= htmlspecialchars($paginationUrl($paginationCurrentPage - 1), ENT_QUOTES, 'UTF-8') ?>"
                            class="sapas-pagination-link is-arrow"
                            aria-label="<?= htmlspecialchars($paginationPreviousLabel, ENT_QUOTES, 'UTF-8') ?>"
                        >&lsaquo;</a>
                    <?php else: ?>
                        <span class="sapas-pagination-link is-arrow is-disabled" aria-disabled="true">&lsaquo;</span>
                    <?php endif; ?>

                    <?php $paginationPreviousVisiblePage = null; ?>
                    <?php foreach ($paginationVisiblePages as $paginationVisiblePage): ?>
                        <?php if ($paginationPreviousVisiblePage !== null && $paginationVisiblePage - $paginationPreviousVisiblePage > 1): ?>
                            <span class="sapas-pagination-ellipsis" aria-hidden="true">&hellip;</span>
                        <?php endif; ?>

                        <a
                            href="<?= htmlspecialchars($paginationUrl($paginationVisiblePage), ENT_QUOTES, 'UTF-8') ?>"
                            class="sapas-pagination-link <?= $paginationCurrentPage === $paginationVisiblePage ? 'is-active' : '' ?>"
                            <?= $paginationCurrentPage === $paginationVisiblePage ? 'aria-current="page"' : '' ?>
                        ><?= $paginationVisiblePage ?></a>

                        <?php $paginationPreviousVisiblePage = $paginationVisiblePage; ?>
                    <?php endforeach; ?>

                    <?php if ($paginationCurrentPage < $paginationTotalPages): ?>
                        <a
                            href="<?= htmlspecialchars($paginationUrl($paginationCurrentPage + 1), ENT_QUOTES, 'UTF-8') ?>"
                            class="sapas-pagination-link is-arrow"
                            aria-label="<?= htmlspecialchars($paginationNextLabel, ENT_QUOTES, 'UTF-8') ?>"
                        >&rsaquo;</a>
                    <?php else: ?>
                        <span class="sapas-pagination-link is-arrow is-disabled" aria-disabled="true">&rsaquo;</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
