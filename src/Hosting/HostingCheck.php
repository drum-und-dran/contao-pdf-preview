<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Hosting;

use Dud\ContaoPdfPreview\Image\Preview\CliPdfPreviewProvider;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Prüft, welche Möglichkeiten und Grenzen das Hosting für die PDF-Vorschau
 * bietet, und begründet, wann ein leistungsfähigeres Hosting sinnvoll ist.
 */
class HostingCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /**
     * Englische Texte als Rückfallebene.
     */
    private const FALLBACK = [
        'title' => 'Hosting check for the PDF preview',
        'summary' => 'The PDF preview is limited on this server:',
        'status_ok' => 'OK',
        'status_warning' => 'Notice',
        'status_error' => 'Problem',
        'col_check' => 'Check',
        'col_status' => 'Status',
        'col_value' => 'Value',
        'col_recommendation' => 'Effect',
        'label_renderer' => 'Server-side rendering',
        'label_proc_open' => 'External programs (proc_open)',
        'label_virus' => 'Virus scanner',
        'label_memory' => 'Memory (memory_limit)',
        'label_time' => 'Maximum execution time (max_execution_time)',
        'label_upload' => 'Upload limit (upload_max_filesize/post_max_size)',
        'label_disk' => 'Free disk space',
        'label_zlib' => 'Decompressing content (zlib)',
        'label_image' => 'Image processing (GD/Imagick)',
        'label_cache' => 'Disk usage of the preview images',
        'value_none' => 'not available',
        'value_available' => 'available',
        'value_disabled' => 'disabled',
        'value_unlimited' => 'unlimited',
        'value_unknown' => 'cannot be determined',
        'value_missing' => 'missing',
        'rec_renderer' => 'The server cannot render PDFs itself. Preview images are created in the browser as soon as the PDF is opened in the file manager.',
        'rec_proc_open' => 'proc_open is disabled, pdftoppm, Ghostscript and clamdscan cannot be called.',
        'rec_virus' => 'No virus scanner found (neither clamav_socket configured nor clamdscan/clamscan available). PDFs are only checked structurally.',
        'rec_memory' => 'memory_limit %s: very large PDFs can only be checked and rendered to a limited extent.',
        'rec_time' => 'max_execution_time %s: rendering large PDFs may be aborted, checking all files is spread over several cron runs.',
        'rec_upload' => 'Upload limit %s: larger PDFs and page images rendered in the browser cannot be uploaded.',
        'rec_disk' => 'Only %s left: new preview images may soon no longer be saved.',
        'rec_zlib' => 'PHP extension zlib missing: PDFs with compressed object streams are blocked.',
        'rec_image' => 'Neither GD nor Imagick available: crops and pages rendered in the browser cannot be saved.',
        'cleanup' => 'Preview cache cleaned up: %s file(s) removed, %s freed.',
    ];

    /**
     * @var list<array{key: string, status: string, value: string, recommendation: string|null}>|null
     */
    private array|null $results = null;

    public function __construct(
        private readonly CliPdfPreviewProvider $cliProvider,
        private readonly PdfSecurityScanner $scanner,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $projectDir,
        private readonly string $previewDir,
        private readonly int $maxFileSize,
    ) {
    }

    /**
     * @return list<array{key: string, status: string, value: string, recommendation: string|null}>
     */
    public function run(bool $withCacheSize = false): array
    {
        if (null !== $this->results && !$withCacheSize) {
            return $this->results;
        }

        $results = [];

        $add = static function (string $key, string $status, string $value, string|null $recommendation = null) use (&$results): void {
            $results[] = ['key' => $key, 'status' => $status, 'value' => $value, 'recommendation' => self::OK === $status ? null : $recommendation];
        };

        // Server-Rendering
        $renderers = [];

        if ($this->imagickCanReadPdf()) {
            $renderers[] = 'Imagick';
        }

        if ('' !== ($binary = $this->cliProvider->getRendererId())) {
            $renderers[] = basename($binary).' ('.$binary.')';
        }

        $add('renderer', $renderers ? self::OK : self::WARNING, $renderers ? implode(', ', $renderers) : 'none', 'rec_renderer');

        // Externe Programme (pdftoppm, gs, clamdscan)
        $procOpen = ResourceLimits::functionAvailable('proc_open');
        $add('proc_open', $procOpen ? self::OK : self::WARNING, $procOpen ? 'available' : 'disabled', 'rec_proc_open');

        // Virenscan Mit virus_scan: off bewusst abgeschaltet: kein Hinweis
        $virus = $this->scanner->hasVirusScanner();
        $add('virus', $virus || $this->scanner->isVirusScanDisabled() ? self::OK : self::WARNING, $virus ? 'ClamAV' : ($this->scanner->isVirusScanDisabled() ? 'disabled' : 'none'), 'rec_virus');

        // Arbeitsspeicher
        $memory = ResourceLimits::memoryLimit();

        $add(
            'memory',
            match (true) {
                $memory <= 0 || $memory >= 256 * 1048576 => self::OK,
                $memory >= 128 * 1048576 => self::WARNING,
                default => self::ERROR,
            },
            $memory <= 0 ? 'unlimited' : ResourceLimits::formatBytes($memory),
            'rec_memory',
        );

        // Laufzeit (nur im Web-Kontext aussagekräftig)
        $time = ResourceLimits::timeLimit();
        $add('time', 0 === $time || $time >= 60 ? self::OK : self::WARNING, 0 === $time ? 'unlimited' : $time.' s', 'rec_time');

        // Upload-Limit (große PDFs und im Browser gerenderte Seiten)
        $upload = ResourceLimits::uploadLimit();
        $add('upload', $upload <= 0 || $upload >= min($this->maxFileSize, 32 * 1048576) ? self::OK : self::WARNING, $upload <= 0 ? 'unlimited' : ResourceLimits::formatBytes($upload), 'rec_upload');

        // Speicherplatz
        $free = ResourceLimits::freeDiskSpace($this->projectDir);

        $add(
            'disk',
            match (true) {
                null === $free || $free >= 2 * 1073741824 => self::OK,
                $free >= 512 * 1048576 => self::WARNING,
                default => self::ERROR,
            },
            null === $free ? 'unknown' : ResourceLimits::formatBytes($free),
            'rec_disk',
        );

        // Entpacken komprimierter PDF-Inhalte
        $zlib = ResourceLimits::functionAvailable('inflate_init') || ResourceLimits::functionAvailable('zlib_decode');
        $add('zlib', $zlib ? self::OK : self::ERROR, $zlib ? 'available' : 'missing', 'rec_zlib');

        // Bildbearbeitung (Zuschnitt, Browser-Renderings)
        $image = array_values(array_filter(['gd', 'imagick', 'gmagick'], 'extension_loaded'));
        $add('image', $image ? self::OK : self::ERROR, $image ? implode(', ', $image) : 'missing', 'rec_image');

        if ($withCacheSize) {
            $add('cache', self::OK, ResourceLimits::formatBytes($this->getCacheSize()));
        }

        return $this->results = $results;
    }

    /**
     * Warnungen und Fehler, eine Stunde zwischengespeichert.
     *
     * @param list<string> $exclude
     *
     * @return list<array{key: string, status: string, value: string, recommendation: string|null}>
     */
    public function getProblems(array $exclude = [], bool $errorsOnly = false): array
    {
        $item = null;

        try {
            $item = $this->cache->getItem('dud_pdf_hosting_check');
            $problems = $item->isHit() && \is_array($item->get()) ? $item->get() : null;
        } catch (\Throwable) {
            $problems = null;
        }

        if (null === $problems) {
            $problems = array_values(array_filter($this->run(), static fn (array $r): bool => self::OK !== $r['status']));

            try {
                if (null !== $item) {
                    $item->set($problems);
                    $item->expiresAfter(3600);

                    $this->cache->save($item);
                }
            } catch (\Throwable) {
            }
        }

        return array_values(array_filter(
            $problems,
            static fn (array $r): bool => !\in_array($r['key'], $exclude, true) && (!$errorsOnly || self::ERROR === $r['status']),
        ));
    }

    private function imagickCanReadPdf(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return [] !== \Imagick::queryFormats('PDF');
        } catch (\Throwable) {
            return false;
        }
    }

    private function getCacheSize(): int
    {
        if (!is_dir($this->previewDir)) {
            return 0;
        }

        $size = 0;
        $deadline = ResourceLimits::deadline(0.2);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->previewDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (null !== $deadline && microtime(true) > $deadline) {
                break;
            }

            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Übersetzter Text ($GLOBALS['TL_LANG']['dud_pdf_preview']['hosting'][…]).
     */
    public static function text(string $key, string ...$parameters): string
    {
        $text = $GLOBALS['TL_LANG']['dud_pdf_preview']['hosting'][$key] ?? self::FALLBACK[$key] ?? $key;

        try {
            return $parameters ? vsprintf($text, $parameters) : $text;
        } catch (\Throwable) {
            return $text;
        }
    }

    /**
     * @param array{key: string, status: string, value: string, recommendation: string|null} $result
     *
     * @return array{label: string, status: string, value: string, recommendation: string}
     */
    public static function describe(array $result): array
    {
        $value = isset(self::FALLBACK['value_'.$result['value']]) ? self::text('value_'.$result['value']) : $result['value'];

        return [
            'label' => self::text('label_'.$result['key']),
            'status' => self::text('status_'.$result['status']),
            'value' => $value,
            'recommendation' => null === $result['recommendation'] ? '' : self::text($result['recommendation'], $value),
        ];
    }
}
