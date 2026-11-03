<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Creates dependency-free Office Open XML (.xlsx) workbooks for OAAS reports.
 *
 * The writer deliberately uses inline strings and a standards-compliant stored
 * ZIP container so report export does not depend on ZipArchive or an external
 * spreadsheet package. That keeps the existing PHP deployment footprint small
 * while still producing a real .xlsx file that opens in Excel and LibreOffice.
 */
final class ReportExcelExportService
{
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const DEFAULT_PROJECT_NAME = 'Office Attendance and Analytic System (OAAS)';

    /**
     * @param array{
     *   project_name:string,
     *   generated_at_label:string,
     *   active_filters_label:string,
     *   filter_summary:list<string>,
     *   sheets:list<array<string,mixed>>
     * } $workbook
     */
    public function stream(string $filename, array $workbook): void
    {
        $xlsx = $this->build($workbook);

        if (!headers_sent()) {
            header('Content-Type: ' . self::CONTENT_TYPE);
            header('Content-Disposition: attachment; filename="' . $this->sanitizeFilename($filename) . '"');
            header('Content-Length: ' . strlen($xlsx));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        echo $xlsx;
    }

    /**
     * @param array{
     *   project_name:string,
     *   generated_at_label:string,
     *   active_filters_label:string,
     *   filter_summary:list<string>,
     *   sheets:list<array<string,mixed>>
     * } $workbook
     */
    public function build(array $workbook): string
    {
        $sheets = $workbook['sheets'] ?? [];
        if (!is_array($sheets) || $sheets === []) {
            throw new InvalidArgumentException('An Excel workbook requires at least one worksheet.');
        }

        $projectName = trim((string) ($workbook['project_name'] ?? self::DEFAULT_PROJECT_NAME));
        $projectName = $projectName !== '' ? $projectName : self::DEFAULT_PROJECT_NAME;
        $generatedAtLabel = trim((string) ($workbook['generated_at_label'] ?? 'Generated at'));
        $activeFiltersLabel = trim((string) ($workbook['active_filters_label'] ?? 'Active Filters'));
        $filterSummary = $workbook['filter_summary'] ?? [];
        $filterSummary = is_array($filterSummary)
            ? array_values(array_map(static fn (mixed $value): string => (string) $value, $filterSummary))
            : [];
        $generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $normalizedSheets = $this->normalizeSheets($sheets);
        $files = [];
        $files['[Content_Types].xml'] = $this->contentTypesXml(count($normalizedSheets));
        $files['_rels/.rels'] = $this->rootRelationshipsXml();
        $files['docProps/core.xml'] = $this->corePropertiesXml($projectName, $generatedAt);
        $files['docProps/app.xml'] = $this->appPropertiesXml(array_column($normalizedSheets, 'name'));
        $files['xl/workbook.xml'] = $this->workbookXml($normalizedSheets);
        $files['xl/_rels/workbook.xml.rels'] = $this->workbookRelationshipsXml(count($normalizedSheets));
        $files['xl/styles.xml'] = $this->stylesXml();

        foreach ($normalizedSheets as $index => $sheet) {
            $files['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $this->worksheetXml(
                $sheet,
                $index === 0,
                $projectName,
                $generatedAtLabel,
                $generatedAt,
                $activeFiltersLabel,
                $filterSummary,
            );
        }

        return $this->zip($files);
    }

    /**
     * @param list<array<string,mixed>> $sheets
     * @return list<array{name:string,title:string,kind:string,columns:list<array{header:string,width:float}>,rows:list<array<int,mixed>>}>
     */
    private function normalizeSheets(array $sheets): array
    {
        $normalized = [];
        $usedNames = [];

        foreach ($sheets as $index => $sheet) {
            if (!is_array($sheet)) {
                throw new InvalidArgumentException('Each Excel worksheet definition must be an array.');
            }

            $columns = $sheet['columns'] ?? [];
            if (!is_array($columns) || $columns === []) {
                throw new InvalidArgumentException('Each Excel worksheet requires at least one column.');
            }

            $normalizedColumns = [];
            foreach ($columns as $column) {
                if (!is_array($column)) {
                    throw new InvalidArgumentException('Excel column definitions must be arrays.');
                }
                $normalizedColumns[] = [
                    'header' => (string) ($column['header'] ?? ''),
                    'width' => min(60.0, max(8.0, (float) ($column['width'] ?? 16))),
                ];
            }

            $name = $this->uniqueSheetName((string) ($sheet['name'] ?? ('Sheet ' . ($index + 1))), $usedNames);
            $usedNames[] = $name;
            $rows = $sheet['rows'] ?? [];
            if (!is_array($rows)) {
                throw new InvalidArgumentException('Excel worksheet rows must be an array.');
            }

            $normalizedRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Each Excel worksheet row must be an array.');
                }
                $normalizedRows[] = array_values($row);
            }

            $normalized[] = [
                'name' => $name,
                'title' => trim((string) ($sheet['title'] ?? $name)),
                'kind' => (string) ($sheet['kind'] ?? 'data') === 'summary' ? 'summary' : 'data',
                'columns' => $normalizedColumns,
                'rows' => $normalizedRows,
            ];
        }

        return $normalized;
    }

    /** @param list<string> $usedNames */
    private function uniqueSheetName(string $name, array $usedNames): string
    {
        $name = preg_replace('~[\\/?*\[\]:]+~u', ' ', trim($name)) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " ' ");
        $name = $name === '' ? 'Report' : $this->textSubstring($name, 0, 31);
        $candidate = $name;
        $suffix = 2;

        while (in_array($this->textLower($candidate), array_map(fn (string $used): string => $this->textLower($used), $usedNames), true)) {
            $suffixText = ' ' . $suffix;
            $candidate = $this->textSubstring($name, 0, 31 - $this->textLength($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    /** @param array{name:string,title:string,kind:string,columns:list<array{header:string,width:float}>,rows:list<array<int,mixed>>} $sheet */
    private function worksheetXml(
        array $sheet,
        bool $selected,
        string $projectName,
        string $generatedAtLabel,
        string $generatedAt,
        string $activeFiltersLabel,
        array $filterSummary,
    ): string {
        $columnCount = count($sheet['columns']);
        $isSummary = $sheet['kind'] === 'summary';
        $presentationColumnCount = $isSummary ? max(4, $columnCount) : $columnCount;
        $headerRow = 6;
        $dataStartRow = 7;
        $lastDataRow = max($headerRow, $dataStartRow + count($sheet['rows']) - 1);
        $lastColumn = $this->columnName($presentationColumnCount);
        $rowsXml = [];

        $rowsXml[] = $this->rowXml(1, [
            ['value' => $projectName, 'type' => 'title'],
        ], $presentationColumnCount, 32);
        $rowsXml[] = $this->rowXml(2, [
            ['value' => $sheet['title'], 'type' => 'subtitle'],
        ], $presentationColumnCount, 28);
        $rowsXml[] = $this->rowXml(3, [
            ['value' => $generatedAtLabel, 'type' => 'meta_label'],
            ['value' => $generatedAt, 'type' => 'datetime'],
        ], $presentationColumnCount, 24);
        $rowsXml[] = $this->rowXml(4, [
            ['value' => $activeFiltersLabel, 'type' => 'meta_label'],
            ['value' => implode('  •  ', $filterSummary), 'type' => 'meta_value'],
        ], $presentationColumnCount, 30);
        $rowsXml[] = '<row r="5" ht="10" customHeight="1"/>';

        $headers = [];
        foreach ($sheet['columns'] as $column) {
            $headers[] = ['value' => $column['header'], 'type' => 'header'];
        }
        $rowsXml[] = $this->rowXml($headerRow, $headers, $presentationColumnCount, 34);

        foreach ($sheet['rows'] as $offset => $row) {
            $hasWrappedCell = false;
            foreach ($row as $cell) {
                if (is_array($cell) && (($cell['type'] ?? '') === 'wrap')) {
                    $hasWrappedCell = true;
                    break;
                }
            }

            $rowsXml[] = $this->rowXml(
                $dataStartRow + $offset,
                $row,
                $presentationColumnCount,
                $isSummary ? 28 : ($hasWrappedCell ? 36 : 26),
                $isSummary ? 'summary' : 'data',
                !$isSummary && ($offset % 2 === 1),
            );
        }

        $columnsXml = '';
        for ($index = 0; $index < $presentationColumnCount; $index++) {
            $number = $index + 1;
            $width = $index < $columnCount ? $sheet['columns'][$index]['width'] : 14.0;
            $columnsXml .= '<col min="' . $number . '" max="' . $number . '" width="' . $this->number($width) . '" customWidth="1"/>';
        }

        $mergeRanges = [
            'A1:' . $lastColumn . '1',
            'A2:' . $lastColumn . '2',
        ];
        if ($presentationColumnCount > 2) {
            $mergeRanges[] = 'B3:' . $lastColumn . '3';
            $mergeRanges[] = 'B4:' . $lastColumn . '4';
        }
        if ($isSummary && $presentationColumnCount > $columnCount) {
            $mergeRanges[] = 'B' . $headerRow . ':' . $lastColumn . $headerRow;
            foreach (array_keys($sheet['rows']) as $offset) {
                $rowNumber = $dataStartRow + (int) $offset;
                $mergeRanges[] = 'B' . $rowNumber . ':' . $lastColumn . $rowNumber;
            }
        }
        $mergeXml = '<mergeCells count="' . count($mergeRanges) . '">';
        foreach ($mergeRanges as $range) {
            $mergeXml .= '<mergeCell ref="' . $range . '"/>';
        }
        $mergeXml .= '</mergeCells>';

        $autoFilter = !$isSummary && count($sheet['rows']) > 0
            ? '<autoFilter ref="A' . $headerRow . ':' . $lastColumn . $lastDataRow . '"/>'
            : '';

        $footerText = '&amp;L' . $this->xml($projectName) . '&amp;RPage &amp;P of &amp;N';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . $lastColumn . $lastDataRow . '"/>'
            . '<sheetViews><sheetView showGridLines="0"' . ($selected ? ' tabSelected="1"' : '') . ' workbookViewId="0">'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . $dataStartRow . '" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A' . $dataStartRow . '" sqref="A' . $dataStartRow . '"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/>'
            . '<cols>' . $columnsXml . '</cols>'
            . '<sheetData>' . implode('', $rowsXml) . '</sheetData>'
            . $autoFilter
            . $mergeXml
            . '<printOptions horizontalCentered="0" verticalCentered="0"/>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddFooter>' . $footerText . '</oddFooter></headerFooter>'
            . '</worksheet>';
    }

    /** @param array<int,mixed> $cells */
    private function rowXml(
        int $rowNumber,
        array $cells,
        int $columnCount,
        int $height,
        string $context = 'default',
        bool $alternate = false,
    ): string
    {
        $xml = '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">';

        for ($index = 0; $index < $columnCount; $index++) {
            $cell = $cells[$index] ?? null;
            $xml .= $this->cellXml(
                $this->columnName($index + 1) . $rowNumber,
                $cell,
                $context,
                $alternate,
                $index,
            );
        }

        return $xml . '</row>';
    }

    private function cellXml(
        string $reference,
        mixed $cell,
        string $context = 'default',
        bool $alternate = false,
        int $columnIndex = 0,
    ): string
    {
        $type = 'string';
        $value = $cell;

        if (is_array($cell) && array_key_exists('value', $cell)) {
            $value = $cell['value'];
            $type = (string) ($cell['type'] ?? 'string');
        }

        if ($context === 'summary') {
            if ($columnIndex === 0) {
                return $this->inlineStringCell($reference, $value, 21);
            }

            return match ($type) {
                'date' => $this->dateCell($reference, $value, 26, 'Y-m-d'),
                'datetime' => $this->dateCell($reference, $value, 27, 'Y-m-d H:i:s'),
                'time' => $this->timeCell($reference, $value, 28),
                'integer' => $this->numberCell($reference, $value, 23),
                'decimal' => $this->numberCell($reference, $value, 24),
                'duration' => $this->durationCell($reference, $value, 25),
                default => $this->inlineStringCell($reference, $value, 22),
            };
        }

        if (str_starts_with($type, 'status_')) {
            return $this->inlineStringCell($reference, $value, match ($type) {
                'status_success' => 29,
                'status_warning' => 30,
                'status_danger' => 31,
                'status_info' => 32,
                default => 33,
            });
        }

        $bodyStyles = $alternate
            ? ['string' => 13, 'date' => 14, 'datetime' => 15, 'time' => 16, 'integer' => 17, 'decimal' => 18, 'duration' => 19, 'wrap' => 20]
            : ['string' => 5, 'date' => 6, 'datetime' => 7, 'time' => 8, 'integer' => 9, 'decimal' => 10, 'duration' => 11, 'wrap' => 12];

        return match ($type) {
            'title' => $this->inlineStringCell($reference, $value, 1),
            'subtitle' => $this->inlineStringCell($reference, $value, 34),
            'meta_label' => $this->inlineStringCell($reference, $value, 2),
            'meta_value' => $this->inlineStringCell($reference, $value, 3),
            'header' => $this->inlineStringCell($reference, $value, 4),
            'wrap' => $this->inlineStringCell($reference, $value, $bodyStyles['wrap']),
            'date' => $this->dateCell($reference, $value, $bodyStyles['date'], 'Y-m-d'),
            'datetime' => $this->dateCell($reference, $value, $bodyStyles['datetime'], 'Y-m-d H:i:s'),
            'time' => $this->timeCell($reference, $value, $bodyStyles['time']),
            'integer' => $this->numberCell($reference, $value, $bodyStyles['integer']),
            'decimal' => $this->numberCell($reference, $value, $bodyStyles['decimal']),
            'duration' => $this->durationCell($reference, $value, $bodyStyles['duration']),
            default => $this->inlineStringCell($reference, $value, $bodyStyles['string']),
        };
    }

    private function inlineStringCell(string $reference, mixed $value, int $style): string
    {
        $text = $this->cleanText($value === null ? '' : (string) $value);
        $preserve = preg_match('/^\s|\s$/u', $text) === 1 ? ' xml:space="preserve"' : '';

        return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t' . $preserve . '>'
            . $this->xml($text) . '</t></is></c>';
    }

    private function numberCell(string $reference, mixed $value, int $style): string
    {
        if (!is_numeric($value)) {
            return $this->inlineStringCell($reference, $value, 5);
        }

        return '<c r="' . $reference . '" s="' . $style . '" t="n"><v>' . $this->number((float) $value) . '</v></c>';
    }

    private function durationCell(string $reference, mixed $minutes, int $style = 11): string
    {
        if (!is_numeric($minutes)) {
            return $this->inlineStringCell($reference, $minutes, 5);
        }

        $serial = max(0.0, (float) $minutes) / 1440;
        return '<c r="' . $reference . '" s="' . $style . '" t="n"><v>' . $this->number($serial) . '</v></c>';
    }

    private function dateCell(string $reference, mixed $value, int $style, string $format): string
    {
        if (!is_string($value) || trim($value) === '') {
            return $this->inlineStringCell($reference, $value, 5);
        }

        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!' . $format, trim($value), $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return $this->inlineStringCell($reference, $value, 5);
        }

        $base = new DateTimeImmutable('1899-12-30 00:00:00', $timezone);
        $serial = ($date->getTimestamp() - $base->getTimestamp()) / 86400;

        return '<c r="' . $reference . '" s="' . $style . '" t="n"><v>' . $this->number($serial) . '</v></c>';
    }

    private function timeCell(string $reference, mixed $value, int $style = 8): string
    {
        if (!is_string($value) || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', trim($value), $matches)) {
            return $this->inlineStringCell($reference, $value, 5);
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;
        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return $this->inlineStringCell($reference, $value, 5);
        }

        $serial = (($hours * 3600) + ($minutes * 60) + $seconds) / 86400;
        return '<c r="' . $reference . '" s="' . $style . '" t="n"><v>' . $this->number($serial) . '</v></c>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="4">'
            . '<numFmt numFmtId="164" formatCode="mmm d, yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="mmm d, yyyy h:mm AM/PM"/>'
            . '<numFmt numFmtId="166" formatCode="h:mm AM/PM"/>'
            . '<numFmt numFmtId="167" formatCode="[h]&quot;h &quot;mm&quot;m&quot;"/>'
            . '</numFmts>'
            . '<fonts count="11">'
            . '<font><color rgb="FF172033"/><sz val="11"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="18"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FF123A63"/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><color rgb="FF526174"/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FF123A63"/><sz val="12"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FF166534"/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FF92400E"/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFBE123C"/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FF1D4ED8"/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FF475569"/><sz val="10"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="12">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF123A63"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7EEF6"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF6F9FC"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFE4E6"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD7E0EA"/></left><right style="thin"><color rgb="FFD7E0EA"/></right><top style="thin"><color rgb="FFD7E0EA"/></top><bottom style="thin"><color rgb="FFD7E0EA"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="35">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="166" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="1" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="2" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="167" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="1" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="2" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="167" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="166" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="6" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="7" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="8" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="9" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="10" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }

    /** @param list<array{name:string}> $sheets */
    private function workbookXml(array $sheets): string
    {
        $sheetXml = '';
        foreach ($sheets as $index => $sheet) {
            $sheetXml .= '<sheet name="' . $this->xml($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000" activeTab="0"/></bookViews>'
            . '<sheets>' . $sheetXml . '</sheets>'
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/>'
            . '</workbook>';
    }

    private function workbookRelationshipsXml(int $sheetCount): string
    {
        $relationships = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
        }
        $relationships .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relationships
            . '</Relationships>';
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function corePropertiesXml(string $projectName, string $generatedAt): string
    {
        $timestamp = (new DateTimeImmutable($generatedAt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>' . $this->xml($projectName) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . $this->xml($projectName) . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    /** @param list<string> $sheetNames */
    private function appPropertiesXml(array $sheetNames): string
    {
        $titles = '';
        foreach ($sheetNames as $name) {
            $titles .= '<vt:lpstr>' . $this->xml($name) . '</vt:lpstr>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>OAAS</Application>'
            . '<DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($sheetNames) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($sheetNames) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '<Company></Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion>'
            . '</Properties>';
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $body = '';
        $centralDirectory = '';
        $offset = 0;
        $fileCount = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($files as $name => $contents) {
            $name = str_replace('\\', '/', $name);
            $crc = (int) hexdec(hash('crc32b', $contents));
            $size = strlen($contents);
            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($name),
                0,
            );
            $localRecord = $localHeader . $name . $contents;
            $body .= $localRecord;

            $centralDirectory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $offset,
            ) . $name;

            $offset += strlen($localRecord);
            $fileCount++;
        }

        $centralOffset = strlen($body);
        $centralSize = strlen($centralDirectory);
        $endRecord = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $fileCount,
            $fileCount,
            $centralSize,
            $centralOffset,
            0,
        );

        return $body . $centralDirectory . $endRecord;
    }

    /** @return array{0:int,1:int} */
    private function dosDateTime(): array
    {
        $now = getdate();
        $year = min(2107, max(1980, (int) $now['year']));
        $time = ((int) $now['hours'] << 11) | ((int) $now['minutes'] << 5) | intdiv((int) $now['seconds'], 2);
        $date = (($year - 1980) << 9) | ((int) $now['mon'] << 5) | (int) $now['mday'];

        return [$time, $date];
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function textLower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function textSubstring(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    }

    private function cleanText(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($this->cleanText($value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function number(float $value): string
    {
        if (!is_finite($value)) {
            return '0';
        }

        $formatted = sprintf('%.12F', $value);
        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename)) ?? 'report.xlsx';
        $filename = trim($filename, '._');
        if ($filename === '') {
            $filename = 'report.xlsx';
        }
        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename .= '.xlsx';
        }

        return $filename;
    }
}
