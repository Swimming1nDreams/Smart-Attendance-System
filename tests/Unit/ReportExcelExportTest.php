<?php

declare(strict_types=1);

use App\Services\ReportExcelExportService;

/** @return array<string,string> */
function parseStoredZipEntries(string $zip): array
{
    $entries = [];
    $offset = 0;
    $length = strlen($zip);

    while ($offset + 30 <= $length && substr($zip, $offset, 4) === "PK\x03\x04") {
        $header = unpack(
            'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length',
            substr($zip, $offset, 30),
        );
        assert_true(is_array($header), 'ZIP local header should be readable.');
        assert_same(0, (int) $header['method'], 'The dependency-free XLSX writer should use stored ZIP entries.');

        $nameOffset = $offset + 30;
        $name = substr($zip, $nameOffset, (int) $header['name_length']);
        $dataOffset = $nameOffset + (int) $header['name_length'] + (int) $header['extra_length'];
        $contents = substr($zip, $dataOffset, (int) $header['compressed']);
        $entries[$name] = $contents;
        $offset = $dataOffset + (int) $header['compressed'];
    }

    return $entries;
}

function assertInlineStringCell(string $worksheetXml, string $reference, string $literal): void
{
    $pattern = '~<c r="' . preg_quote($reference, '~') . '"[^>]* t="inlineStr"><is><t(?: xml:space="preserve")?>(.*?)</t></is></c>~s';
    $matched = preg_match($pattern, $worksheetXml, $matches);
    assert_same(1, $matched, sprintf('Cell %s should be an explicit OOXML inline string.', $reference));

    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $literal) ?? '';
    $escaped = htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    assert_same($escaped, $matches[1], sprintf('Cell %s should preserve hostile text literally and XML-escape it.', $reference));
}

test('Excel export service creates a real styled XLSX workbook with Unicode-safe inline cells', function (): void {
    $service = new ReportExcelExportService();
    $projectName = 'Office Attendance and Analytic System (OAAS)';
    $xlsx = $service->build([
        'project_name' => $projectName,
        'generated_at_label' => 'Generated at',
        'active_filters_label' => 'Active Filters',
        'filter_summary' => ['Department: Information Technology'],
        'sheets' => [[
            'name' => 'Summary',
            'title' => 'Attendance Summary Report',
            'columns' => [
                ['header' => 'Metric', 'width' => 28],
                ['header' => 'Value', 'width' => 18],
            ],
            'rows' => [
                ['Total Records', ['value' => 12, 'type' => 'integer']],
                ['Work Duration', ['value' => 481, 'type' => 'duration']],
                ['Status', ['value' => 'Present', 'type' => 'status_success']],
                ['Myanmar', 'တက်ရောက်မှု'],
                ['Formula-like text', '=SUM(A1:A2)'],
            ],
        ]],
    ]);

    assert_same("PK\x03\x04", substr($xlsx, 0, 4), 'XLSX should be an OOXML ZIP container.');
    $entries = parseStoredZipEntries($xlsx);

    foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $entry) {
        assert_true(array_key_exists($entry, $entries), sprintf('Expected XLSX entry %s.', $entry));
    }

    assert_contains('Attendance Summary Report', $entries['xl/worksheets/sheet1.xml']);
    assertInlineStringCell($entries['xl/worksheets/sheet1.xml'], 'A1', $projectName);
    assertInlineStringCell($entries['xl/worksheets/sheet1.xml'], 'A2', 'Attendance Summary Report');
    assert_contains('<mergeCell ref="A1:B1"/>', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('<mergeCell ref="A2:B2"/>', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('တက်ရောက်မှု', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('=SUM(A1:A2)', $entries['xl/worksheets/sheet1.xml']);
    assert_not_contains('<f>', $entries['xl/worksheets/sheet1.xml'], 'Untrusted report text must never become an Excel formula.');
    assert_contains('formatCode="[h]&quot;h &quot;mm&quot;m&quot;"', $entries['xl/styles.xml']);
    assert_contains('formatCode="mmm d, yyyy"', $entries['xl/styles.xml']);
    assert_contains('showGridLines="0"', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('<autoFilter ref="A6:B11"/>', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('fillId="7"', $entries['xl/styles.xml']);
    assert_contains('<cellXfs count="35">', $entries['xl/styles.xml']);
    assert_contains('<headerFooter>', $entries['xl/worksheets/sheet1.xml']);
});

test('report views preserve active filters for Excel export links', function (): void {
    foreach ([
        'views/reports/attendance_summary.php' => '/admin/reports/attendance-summary/excel',
        'views/reports/employee_attendance_detail.php' => '/admin/reports/employee-attendance-detail/excel',
        'views/reports/leave_requests.php' => '/admin/reports/leave-requests/excel',
        'views/reports/late_absent_exceptions.php' => '/admin/reports/late-absent-exceptions/excel',
    ] as $view => $route) {
        $source = file_get_contents(project_path($view));
        assert_true(is_string($source), sprintf('%s should be readable.', $view));
        assert_contains($route, $source);
        assert_contains('http_build_query($paginationQuery)', $source);
        assert_contains("common.actions.export_excel", $source);
    }
});

test('Excel export keeps hostile formula prefixes literal across report text fields and filter summaries', function (): void {
    $hostileFields = [
        'Employee name' => '=HYPERLINK("http://evil.test/?a=1&b=<tag>")',
        'Email' => '+SUM(1,1)',
        'Staff code' => '-CMD',
        'Department' => '@SUM(1,1)',
        'Status label' => "\t=SUM(1,1)",
        'Leave review note' => "\r=SUM(1,1)",
        'Cancellation reason' => "\n=SUM(1,1) မြန်မာ",
    ];
    $filterSummary = [
        '=Employee filter: hostile employee',
        '@Department filter: hostile department',
    ];
    $rows = [];
    foreach ($hostileFields as $field => $value) {
        $rows[] = [$field, ['value' => $value, 'type' => str_contains($field, 'note') || str_contains($field, 'reason') ? 'wrap' : 'string']];
    }

    $xlsx = (new ReportExcelExportService())->build([
        'project_name' => 'OAAS <Reports> & Security',
        'generated_at_label' => 'Generated at',
        'active_filters_label' => 'Active Filters',
        'filter_summary' => $filterSummary,
        'sheets' => [[
            'name' => 'Export Security',
            'title' => 'Report Export Security',
            'columns' => [
                ['header' => 'Export field', 'width' => 24],
                ['header' => 'Literal value', 'width' => 48],
            ],
            'rows' => $rows,
        ]],
    ]);

    $entries = parseStoredZipEntries($xlsx);
    $worksheet = $entries['xl/worksheets/sheet1.xml'];

    foreach (array_values($hostileFields) as $offset => $literal) {
        assertInlineStringCell($worksheet, 'B' . (7 + $offset), $literal);
    }

    assert_true(
        preg_match('~<c r="B4"[^>]* t="inlineStr"><is><t[^>]*>(.*?)</t></is></c>~s', $worksheet, $filterCell) === 1,
        'Employee and department filter summaries should use an inline string cell.',
    );
    foreach ($filterSummary as $literal) {
        assert_contains(htmlspecialchars($literal, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $filterCell[1]);
    }

    assert_same(0, preg_match('/<f(?:\s|>)/', $worksheet), 'No hostile report value may generate an OOXML formula node.');
    assert_contains('&lt;tag&gt;', $worksheet, 'XML markup inside user-controlled text must be escaped.');
    assert_contains('&amp;', $worksheet, 'Ampersands inside user-controlled text must be escaped.');
    assert_contains('&quot;', $worksheet, 'Quotes inside user-controlled text must be escaped.');
    assert_contains('မြန်မာ', $worksheet, 'Myanmar text must remain valid beside a hostile formula prefix.');
});


test('Excel QA preserves special-day attendance, overtime durations, localized text, and safe worksheet names', function (): void {
    $service = new ReportExcelExportService();
    $projectName = 'Office Attendance and Analytic System (OAAS)';
    $xlsx = $service->build([
        'project_name' => $projectName,
        'generated_at_label' => 'Generated at',
        'active_filters_label' => 'Active Filters',
        'filter_summary' => ['Date: 2026-07-24 – 2026-07-26', 'Employee: SAP-0023 Khant Si Thu'],
        'sheets' => [
            [
                'name' => 'Attendance / Records',
                'title' => 'Employee Attendance Detail Report',
                'columns' => [
                    ['header' => 'Date', 'width' => 14],
                    ['header' => 'Status', 'width' => 16],
                    ['header' => 'Working Hours', 'width' => 18],
                    ['header' => 'Overtime', 'width' => 18],
                    ['header' => 'Remarks', 'width' => 34],
                ],
                'rows' => [
                    [
                        ['value' => '2026-07-25', 'type' => 'date'],
                        ['value' => 'Weekend', 'type' => 'status_success'],
                        ['value' => 300, 'type' => 'duration'],
                        ['value' => 300, 'type' => 'duration'],
                        ['value' => 'ပိတ်ရက် အလုပ်ချိန်', 'type' => 'wrap'],
                    ],
                    [
                        ['value' => '2026-07-24', 'type' => 'date'],
                        ['value' => 'Holiday', 'type' => 'status_success'],
                        ['value' => 210, 'type' => 'duration'],
                        ['value' => 210, 'type' => 'duration'],
                        ['value' => '=SUM(A1:A2)', 'type' => 'wrap'],
                    ],
                ],
            ],
            [
                'name' => 'Attendance / Records',
                'title' => 'Summary',
                'kind' => 'summary',
                'columns' => [
                    ['header' => 'Metric', 'width' => 28],
                    ['header' => 'Value', 'width' => 18],
                ],
                'rows' => [
                    ['Special-day overtime', ['value' => 510, 'type' => 'duration']],
                ],
            ],
        ],
    ]);

    $entries = parseStoredZipEntries($xlsx);
    assert_contains('name="Attendance Records"', $entries['xl/workbook.xml']);
    assert_contains('name="Attendance Records 2"', $entries['xl/workbook.xml']);
    assert_contains('Weekend', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('Holiday', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('ပိတ်ရက် အလုပ်ချိန်', $entries['xl/worksheets/sheet1.xml']);
    assert_contains('=SUM(A1:A2)', $entries['xl/worksheets/sheet1.xml']);
    assert_not_contains('<f>', $entries['xl/worksheets/sheet1.xml']);
    assertInlineStringCell($entries['xl/worksheets/sheet2.xml'], 'A1', $projectName);
    assertInlineStringCell($entries['xl/worksheets/sheet2.xml'], 'A2', 'Summary');
    assert_contains('<dimension ref="A1:D7"/>', $entries['xl/worksheets/sheet2.xml']);
    foreach (['A1:D1', 'A2:D2', 'B3:D3', 'B4:D4', 'B6:D6', 'B7:D7'] as $range) {
        assert_contains('<mergeCell ref="' . $range . '"/>', $entries['xl/worksheets/sheet2.xml']);
    }
    assert_contains('<col min="3" max="3" width="14" customWidth="1"/>', $entries['xl/worksheets/sheet2.xml']);
    assert_contains('<col min="4" max="4" width="14" customWidth="1"/>', $entries['xl/worksheets/sheet2.xml']);
    assert_contains('<c r="B7" s="25" t="n"><v>0.354166666667</v></c>', $entries['xl/worksheets/sheet2.xml']);
    assert_contains('<autoFilter ref="A6:E8"/>', $entries['xl/worksheets/sheet1.xml']);
});
