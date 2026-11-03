<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use DateTimeImmutable;

class ReportPdfExportService
{
    private const MYANMAR_PDF_FONT_FAMILY = 'OAAS Myanmar';
    private $isFile;

    public function __construct(
        private ?string $configuredBrowserPath = null,
        private array $environment = [],
        private ?string $platform = null,
        ?callable $isFile = null
    ) {
        $this->isFile = $isFile ?? static fn (string $path): bool => is_file($path);
    }

    public function isAvailable(): bool
    {
        return $this->isDompdfAvailable() || $this->shouldUseBrowserRenderer();
    }

    public function stream(string $title, string $filename, array $filterSummary, array $sections): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(Localization::current()->translate('reports.pdf_dependency_missing'));
        }

        $html = $this->buildHtml($title, $filterSummary, $sections);
        $pdf = $this->shouldUseBrowserRenderer()
            ? ($this->renderWithBrowser($html) ?? $this->renderWithDompdf($html))
            : $this->renderWithDompdf($html);

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $this->sanitizeFilename($filename) . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
        }

        echo $pdf;
    }

    private function buildHtml(string $title, array $filterSummary, array $sections): string
    {
        $generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $localization = Localization::current();
        $projectName = $localization->translate('app.export_name');
        $html = '<!doctype html><html lang="' . $this->escape($localization->htmlLang()) . '"><head><meta charset="utf-8"><style>';
        $html .= $this->myanmarFontFaceCss();
        $html .= '@page{size:A4 landscape;margin:12mm;}';
        $html .= 'body{font-family:' . $this->pdfFontStack() . ';color:#0f172a;font-size:11px;line-height:1.4;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
        $html .= '.report-header{margin:0 0 10px;padding:0 0 8px;border-bottom:2px solid #123a63;}';
        $html .= 'h1{font-size:17px;line-height:1.25;margin:0;color:#123a63;}h2{font-size:14px;line-height:1.3;margin:3px 0 0;color:#334155;}h3{font-size:13px;margin:16px 0 7px;color:#1e293b;}';
        $html .= '.meta{color:#475569;margin:5px 0 0;}.filters{margin:0 0 14px;padding:7px 9px;background:#f8fafc;border:1px solid #e2e8f0;}';
        $html .= 'table{width:100%;border-collapse:collapse;margin-bottom:14px;page-break-inside:auto;}thead{display:table-header-group;}tr{page-break-inside:avoid;page-break-after:auto;}th,td{border:1px solid #cbd5e1;padding:6px;vertical-align:top;}th{background:#e2e8f0;text-align:left;}';
        $html .= '.numeric{text-align:right;}.muted{color:#64748b;}';
        $html .= '</style></head><body>';
        $html .= '<header class="report-header"><h1>' . $this->escape($projectName) . '</h1><h2>' . $this->escape($title) . '</h2>';
        $html .= '<p class="meta">' . $this->escape($localization->translate('reports.generated_at')) . ': ' . $this->escape($generatedAt) . '</p></header>';
        $html .= '<div class="filters"><strong>' . $this->escape($localization->translate('reports.active_filters')) . ':</strong> ' . $this->escape($filterSummary === [] ? $localization->translate('reports.all_records') : implode('; ', $filterSummary)) . '</div>';

        foreach ($sections as $section) {
            $html .= '<h3>' . $this->escape((string) ($section['title'] ?? $localization->translate('reports.report_data'))) . '</h3>';
            $html .= '<table><thead><tr>';

            foreach (($section['headers'] ?? []) as $header) {
                $html .= '<th>' . $this->escape((string) $header) . '</th>';
            }

            $html .= '</tr></thead><tbody>';

            foreach (($section['rows'] ?? []) as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $value = is_scalar($cell) || $cell === null ? (string) $cell : '';
                    $html .= '<td>' . $this->escape($value === '' ? '-' : $value) . '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        return $html;
    }

    private function isDompdfAvailable(): bool
    {
        return class_exists('Dompdf\\Dompdf') && class_exists('Dompdf\\Options');
    }

    private function renderWithDompdf(string $html): string
    {
        if (!$this->isDompdfAvailable()) {
            throw new \RuntimeException(Localization::current()->translate('reports.pdf_dependency_missing'));
        }

        $optionsClass = 'Dompdf\\Options';
        $dompdfClass = 'Dompdf\\Dompdf';
        $options = new $optionsClass();
        $this->configureOptions($options);

        $dompdf = new $dompdfClass($options);
        $this->registerMyanmarFonts($dompdf);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function shouldUseBrowserRenderer(): bool
    {
        return $this->browserExecutablePath() !== null;
    }

    private function renderWithBrowser(string $html): ?string
    {
        $browserPath = $this->browserExecutablePath();
        $tempDirectory = $this->pdfTempDirectory();

        if ($browserPath === null || $tempDirectory === null) {
            return null;
        }

        $token = bin2hex(random_bytes(12));
        $htmlPath = $tempDirectory . DIRECTORY_SEPARATOR . 'sapas-pdf-' . $token . '.html';
        $pdfPath = $tempDirectory . DIRECTORY_SEPARATOR . 'sapas-pdf-' . $token . '.pdf';
        $profileDirectory = $tempDirectory . DIRECTORY_SEPARATOR . 'chrome-profile-' . $token;

        if (file_put_contents($htmlPath, $html) === false || !@mkdir($profileDirectory, 0775, true)) {
            @unlink($htmlPath);
            return null;
        }

        $arguments = [
            $browserPath,
            '--headless=new',
            '--disable-gpu',
            '--disable-extensions',
            '--disable-background-networking',
            '--no-first-run',
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
            '--allow-file-access-from-files',
            '--user-data-dir=' . $profileDirectory,
            '--print-to-pdf=' . $pdfPath,
            $this->fileUrl($htmlPath),
        ];

        $exitCode = $this->runProcess($arguments, 30);
        $pdf = $exitCode === 0 && is_file($pdfPath) ? file_get_contents($pdfPath) : false;

        @unlink($htmlPath);
        @unlink($pdfPath);
        $this->removeDirectory($profileDirectory, $tempDirectory);

        return is_string($pdf) && $pdf !== '' ? $pdf : null;
    }

    /**
     * @param list<string> $arguments
     */
    private function runProcess(array $arguments, int $timeoutSeconds): int
    {
        $process = @proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectRoot());

        if (!is_resource($process)) {
            return 1;
        }

        $deadline = time() + $timeoutSeconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        } while (time() < $deadline);

        if (($status['running'] ?? false) === true) {
            proc_terminate($process);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process);
    }

    private function configureOptions(object $options): void
    {
        $fontCacheDirectory = $this->fontCacheDirectory();

        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', $this->myanmarFontPath(false) === null ? 'DejaVu Sans' : self::MYANMAR_PDF_FONT_FAMILY);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', $this->dompdfChrootPaths());

        if ($fontCacheDirectory !== null) {
            $options->set('fontDir', $fontCacheDirectory);
            $options->set('fontCache', $fontCacheDirectory);
            $options->set('tempDir', $fontCacheDirectory);
        }
    }

    private function myanmarFontFaceCss(): string
    {
        $fontPath = $this->myanmarFontPath(false);
        $boldFontPath = $this->myanmarFontPath(true);

        if ($fontPath === null) {
            return '';
        }

        $css = '@font-face{font-family:"' . self::MYANMAR_PDF_FONT_FAMILY . '";font-style:normal;font-weight:400;src:url("' . $this->fileUrl($fontPath) . '") format("truetype");}';

        if ($boldFontPath !== null) {
            $css .= '@font-face{font-family:"' . self::MYANMAR_PDF_FONT_FAMILY . '";font-style:normal;font-weight:700;src:url("' . $this->fileUrl($boldFontPath) . '") format("truetype");}';
        }

        return $css;
    }

    private function pdfFontStack(): string
    {
        $fonts = [
            '"Noto Sans Myanmar"',
            '"Myanmar Text"',
            '"Padauk"',
            '"Pyidaungsu"',
            'DejaVu Sans',
            'Arial',
            'sans-serif',
        ];

        if ($this->myanmarFontPath(false) !== null) {
            array_unshift($fonts, '"' . self::MYANMAR_PDF_FONT_FAMILY . '"');
        }

        return implode(',', $fonts);
    }

    private function registerMyanmarFonts(object $dompdf): void
    {
        $fontPath = $this->myanmarFontPath(false);

        if ($fontPath === null || !method_exists($dompdf, 'getFontMetrics')) {
            return;
        }

        $fontMetrics = $dompdf->getFontMetrics();
        $fontMetrics->registerFont(
            ['family' => self::MYANMAR_PDF_FONT_FAMILY, 'style' => 'normal', 'weight' => '400'],
            $this->fileUrl($fontPath)
        );

        $boldFontPath = $this->myanmarFontPath(true);
        if ($boldFontPath !== null) {
            $fontMetrics->registerFont(
                ['family' => self::MYANMAR_PDF_FONT_FAMILY, 'style' => 'normal', 'weight' => '700'],
                $this->fileUrl($boldFontPath)
            );
        }
    }

    private function myanmarFontPath(bool $bold): ?string
    {
        $projectRoot = $this->projectRoot();
        $candidates = $bold
            ? [
                $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'NotoSansMyanmar-Bold.ttf',
                $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'NotoSansMyanmar-Bold.ttf',
                'C:\\Windows\\Fonts\\mmrtextb.ttf',
            ]
            : [
                $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'NotoSansMyanmar-Regular.ttf',
                $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'NotoSansMyanmar-Regular.ttf',
                'C:\\Windows\\Fonts\\mmrtext.ttf',
            ];

        foreach ($candidates as $candidate) {
            $realPath = realpath($candidate);
            if ($realPath !== false && is_file($realPath)) {
                return $realPath;
            }
        }

        return null;
    }

    private function dompdfChrootPaths(): array
    {
        $paths = [$this->projectRoot()];
        $fontPath = $this->myanmarFontPath(false);
        $boldFontPath = $this->myanmarFontPath(true);

        if ($fontPath !== null) {
            $paths[] = dirname($fontPath);
        }

        if ($boldFontPath !== null) {
            $paths[] = dirname($boldFontPath);
        }

        return array_values(array_unique($paths));
    }

    private function fontCacheDirectory(): ?string
    {
        $directory = $this->projectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dompdf-fonts';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        return $directory;
    }

    private function pdfTempDirectory(): ?string
    {
        $directory = $this->projectRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf-export';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        return $directory;
    }

    private function browserExecutablePath(): ?string
    {
        $platform = strtolower($this->platform ?? PHP_OS_FAMILY);
        $candidates = [$this->configuredBrowserPath];
        $candidates[] = $this->environmentValue('OAAS_CHROME_PATH');
        $candidates[] = $this->environmentValue('SAPAS_CHROME_PATH');
        $candidates[] = $this->environmentValue('CHROME_PATH');

        if ($platform === 'darwin') {
            array_push(
                $candidates,
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
                '/Applications/Chromium.app/Contents/MacOS/Chromium'
            );
        } elseif ($platform === 'windows') {
            array_push(
                $candidates,
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
            );
        } else {
            array_push($candidates, '/usr/bin/google-chrome', '/usr/bin/microsoft-edge', '/usr/bin/chromium', '/usr/bin/chromium-browser');
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if (($this->isFile)($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $names = $platform === 'windows'
            ? ['chrome.exe', 'msedge.exe', 'chromium.exe']
            : ['google-chrome', 'microsoft-edge', 'chromium', 'chromium-browser'];

        return $this->lookupExecutable($names, $platform);
    }

    private function environmentValue(string $name): ?string
    {
        $value = array_key_exists($name, $this->environment) ? $this->environment[$name] : getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param list<string> $names */
    private function lookupExecutable(array $names, string $platform): ?string
    {
        $path = $this->environmentValue('PATH') ?? (string) getenv('PATH');
        if ($path === '') {
            return null;
        }

        $separator = $platform === 'windows' ? ';' : ':';
        foreach (explode($separator, $path) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
                if (($this->isFile)($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }

        return null;
    }

    private function removeDirectory(string $directory, string $allowedRoot): void
    {
        $realDirectory = realpath($directory);
        $realRoot = realpath($allowedRoot);

        if ($realDirectory === false || $realRoot === false || strpos($realDirectory, $realRoot) !== 0) {
            return;
        }

        foreach (scandir($realDirectory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $realDirectory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path, $realRoot);
                continue;
            }

            @unlink($path);
        }

        @rmdir($realDirectory);
    }

    private function fileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = str_replace(' ', '%20', $normalized);

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return 'file://' . $normalized;
        }

        return 'file://' . (str_starts_with($normalized, '/') ? $normalized : '/' . $normalized);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename) ?? 'oaas_report.pdf';
        $filename = trim($filename, '._');

        return $filename === '' ? 'oaas_report.pdf' : $filename;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
