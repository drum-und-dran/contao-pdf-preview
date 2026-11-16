<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Image;

use Contao\CoreBundle\Image\Preview\PreviewFactory;
use Contao\CoreBundle\Image\Preview\UnableToGeneratePreviewException;
use Doctrine\DBAL\Connection;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Contao\Image\ImportantPart;
use Dud\ContaoPdfPreview\Image\Preview\CliPdfPreviewProvider;
use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Point;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Prüft eine PDF (PdfSecurityScanner), rendert die gewünschte(n) Seite(n) über
 * die PreviewFactory von Contao (gecacht unter assets/previews) und wendet
 * optional den in der Dateiverwaltung definierten wichtigen Bereich als festen
 * Zuschnitt an (falls gewünscht). Die PDF selbst
 * bleibt unverändert.
 */
class PdfPreviewGenerator
{
    public const MAX_PAGES = 100;

    /**
     * @var array<string, array{pages: list<int>|null, crop: array{0: float, 1: float, 2: float, 3: float}|null, importantPart: array{0: float, 1: float, 2: float, 3: float}|null, image: string|null}>
     */
    private array $settingsCache = [];

    /**
     * @var array<string, string>
     */
    private array $pathCache = [];

    /**
     * @param \Closure(): PreviewFactory $previewFactory
     * @param \Closure(): Connection     $connection
     */
    public function __construct(
        private readonly \Closure $previewFactory,
        private readonly \Closure $connection,
        private readonly PdfSecurityScanner $scanner,
        private readonly ClientPreviewStore $clientStore,
        private readonly CliPdfPreviewProvider $cliProvider,
        private readonly ImagineInterface $imagine,
        private readonly string $projectDir,
        private readonly string $cacheDir,
        private readonly int $previewSize,
        private readonly int $defaultPage,
    ) {
    }

    public static function isPdf(string $path): bool
    {
        return 'pdf' === strtolower(Path::getExtension($path));
    }

    /**
     * Absoluter Pfad zu einer Rastergrafik, die anstelle der PDF weiterverarbeitet
     * werden kann (erste ausgewählte Seite).
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|false|null $crop Null = Einstellung aus tl_files, false = kein Zuschnitt
     *
     * @throws \Dud\ContaoPdfPreview\Security\UnsafePdfException
     */
    public function getPreviewPath(string $pdfPath, int|null $page = null, array|false|null $crop = null): string
    {
        $pdfPath = $this->absolute($pdfPath);
        $page ??= $this->getPages($pdfPath)[0];

        return $this->renderAndCrop($pdfPath, $page, $crop);
    }

    /**
     * Pfade aller ausgewählten Seiten (für das Inhaltselement „PDF-Seiten“).
     *
     * @param list<int>|null $pages Null = Einstellung aus tl_files
     *
     * @return list<string>
     */
    public function getPreviewPaths(string $pdfPath, array|null $pages = null, array|false|null $crop = null): array
    {
        $pdfPath = $this->absolute($pdfPath);
        $pages ??= $this->getPages($pdfPath);
        $paths = [];

        foreach (\array_slice($pages, 0, self::MAX_PAGES) as $page) {
            try {
                $paths[] = $this->renderAndCrop($pdfPath, $page, $crop, false);
            } catch (UnableToGeneratePreviewException) {
                // Seite existiert nicht – überspringen
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<int>
     */
    public function getPages(string $pdfPath): array
    {
        $pdfPath = $this->absolute($pdfPath);
        $pages = $this->getSettings($pdfPath)['pages'];

        return $pages ?: [$this->defaultPage];
    }

    /**
     * Wichtiger Bereich für das gerenderte Seitenbild (Contao-Standard): Wird
     * nicht fest zugeschnitten, übernimmt Contao ihn wie bei normalen Bildern
     * für zuschneidende Bildgrößen.
     */
    public function getImportantPart(string $pdfPath): ImportantPart|null
    {
        $settings = $this->getSettings($this->absolute($pdfPath));

        if (null !== $settings['crop'] || null === ($part = $settings['importantPart'])) {
            return null;
        }

        try {
            return new ImportantPart($part[0], $part[1], $part[2], $part[3]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{pages: list<int>|null, crop: array{0: float, 1: float, 2: float, 3: float}|null, importantPart: array{0: float, 1: float, 2: float, 3: float}|null, image: string|null}
     */
    public function getSettings(string $pdfPath): array
    {
        $pdfPath = $this->absolute($pdfPath);

        if (isset($this->settingsCache[$pdfPath])) {
            return $this->settingsCache[$pdfPath];
        }

        $settings = ['pages' => null, 'crop' => null, 'importantPart' => null, 'image' => null];

        if (Path::isBasePath($this->projectDir, $pdfPath)) {
            try {
                $connection = ($this->connection)();
                $row = $connection->fetchAssociative(
                    'SELECT pdfPreviewPages, pdfPreviewImage, pdfCropToImportantPart, importantPartX, importantPartY, importantPartWidth, importantPartHeight FROM tl_files WHERE path = ?',
                    [Path::makeRelative($pdfPath, $this->projectDir)],
                );

                if ($row) {
                    $settings['pages'] = self::parsePages((string) $row['pdfPreviewPages'], $this->getPageCount($pdfPath));

                    // Wichtiger Bereich – gespeichert wie bei Bildern im Contao-Standard
                    $settings['importantPart'] = self::parseCrop(implode(',', [
                        (float) $row['importantPartX'],
                        (float) $row['importantPartY'],
                        (float) $row['importantPartWidth'],
                        (float) $row['importantPartHeight'],
                    ]));

                    $settings['crop'] = $row['pdfCropToImportantPart'] ? $settings['importantPart'] : null;

                    if (\is_string($row['pdfPreviewImage']) && 16 === \strlen($row['pdfPreviewImage'])) {
                        $imagePath = $connection->fetchOne("SELECT path FROM tl_files WHERE uuid = ? AND type = 'file'", [$row['pdfPreviewImage']]);

                        if (\is_string($imagePath) && !self::isPdf($imagePath) && is_file($abs = Path::join($this->projectDir, $imagePath))) {
                            $settings['image'] = $abs;
                        }
                    }
                }
            } catch (\Throwable) {
                // z. B. Datenbank noch nicht migriert: Standardwerte verwenden
            }
        }

        return $this->settingsCache[$pdfPath] = $settings;
    }

    public function getPageCount(string $pdfPath): int|null
    {
        try {
            return $this->scanner->scan($this->absolute($pdfPath))->pageCount;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Seitenangabe wie "1", "2,4", "3-5", "1,3-5,8" oder "*" (alle).
     *
     * @return list<int>|null
     */
    public static function parsePages(string $value, int|null $pageCount = null): array|null
    {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        $max = $pageCount && $pageCount > 0 ? $pageCount : self::MAX_PAGES;

        if ('*' === $value) {
            return range(1, min($max, self::MAX_PAGES));
        }

        if (!preg_match('/^\s*\d+\s*(?:-\s*\d*\s*)?(?:[,;]\s*\d+\s*(?:-\s*\d*\s*)?)*$/', $value)) {
            return null;
        }

        $pages = [];

        foreach (preg_split('/[,;]/', $value) as $part) {
            $range = array_map('trim', explode('-', $part, 2));
            $from = max(1, (int) $range[0]);
            $to = isset($range[1]) ? ('' === $range[1] ? $max : (int) $range[1]) : $from;

            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }

            for ($page = max(1, $from); $page <= min($to, $from + self::MAX_PAGES); ++$page) {
                $pages[$page] = $page;

                if (\count($pages) >= self::MAX_PAGES) {
                    break 2;
                }
            }
        }

        return array_values($pages) ?: null;
    }

    /**
     * Zuschnitt im Format "x,y,breite,höhe" als Anteile (0–1) der Seite.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function parseCrop(string $value): array|null
    {
        $parts = array_map('trim', explode(',', $value));

        if (4 !== \count($parts) || 4 !== \count(array_filter($parts, 'is_numeric'))) {
            return null;
        }

        [$x, $y, $w, $h] = array_map('floatval', $parts);

        $x = min(max($x, 0.0), 1.0);
        $y = min(max($y, 0.0), 1.0);
        $w = min(max($w, 0.0), 1.0 - $x);
        $h = min(max($h, 0.0), 1.0 - $y);

        // Kein oder vollständiger Zuschnitt = kein Zuschnitt
        if ($w < 0.01 || $h < 0.01 || ($x < 0.0005 && $y < 0.0005 && $w > 0.999 && $h > 0.999)) {
            return null;
        }

        return [round($x, 4), round($y, 4), round($w, 4), round($h, 4)];
    }

    /**
     * Ermittelt, welche Stufe der Renderer-Kette aktuell eine Vorschau liefern
     * kann (für die Statusanzeige im Backend).
     *
     * @return array{source: 'custom'|'server'|'browser'|'none', path: string|null, error: string|null}
     */
    public function diagnose(string $pdfPath, int $page): array
    {
        $pdfPath = $this->absolute($pdfPath);

        try {
            $this->scanner->assertSafe($pdfPath);
        } catch (\Throwable $exception) {
            return ['source' => 'none', 'path' => null, 'error' => $exception->getMessage()];
        }

        try {
            if (null !== ($path = $this->renderOnServer($pdfPath, $page, false))) {
                return ['source' => 'server', 'path' => $path, 'error' => null];
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        if (null !== ($path = $this->clientStore->get($pdfPath, $page))) {
            return ['source' => 'browser', 'path' => $path, 'error' => null];
        }

        if (null !== ($path = $this->getSettings($pdfPath)['image'])) {
            return ['source' => 'custom', 'path' => $path, 'error' => null];
        }

        return ['source' => 'none', 'path' => null, 'error' => $error ?? null];
    }

    /**
     * Renderer-Kette:
     *  1. eigenes Vorschaubild (nur für die Hauptseite, wenn hinterlegt)
     *  2. Server: Imagick/Gmagick → pdftoppm/Ghostscript (PreviewFactory)
     *  3. im Browser des Redakteurs gerendertes Seitenbild (pdf.js)
     *  4. eigenes Vorschaubild als letzte Rückfallebene
     */
    private function renderAndCrop(string $pdfPath, int $page, array|false|null $crop, bool $isPrimary = true): string
    {
        $settings = $this->getSettings($pdfPath);
        $crop ??= $settings['crop'] ?? false;
        $cacheKey = $pdfPath.'|'.$page.'|'.($crop ? implode(',', $crop) : '').'|'.(int) $isPrimary;

        if (isset($this->pathCache[$cacheKey])) {
            return $this->pathCache[$cacheKey];
        }

        // Sicherheitsprüfung, bevor die Datei irgendeinen Renderer erreicht
        $this->scanner->assertSafe($pdfPath);

        if ($isPrimary && null !== $settings['image']) {
            return $this->pathCache[$cacheKey] = $settings['image'];
        }

        $preview = null;
        $error = null;

        try {
            $preview = $this->renderOnServer($pdfPath, $page, $isPrimary);
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $preview ??= $this->clientStore->get($pdfPath, $page);

        if (null === $preview && $isPrimary && $page > 1) {
            $preview = $this->clientStore->get($pdfPath, 1);
        }

        if (null === $preview) {
            if (null !== $settings['image']) {
                return $this->pathCache[$cacheKey] = $settings['image'];
            }

            throw new UnableToGeneratePreviewException(\sprintf('No renderer could create a preview of page %d of "%s".', $page, basename($pdfPath)), 0, $error);
        }

        if ($crop) {
            $preview = $this->crop($preview, $crop);
        }

        return $this->pathCache[$cacheKey] = $preview;
    }

    /**
     * Liefert null, wenn auf dem Server kein echter Renderer verfügbar ist
     * (Contao liefert dann nur ein Datei-Symbol als SVG).
     */
    private function renderOnServer(string $pdfPath, int $page, bool $fallbackToFirstPage): string|null
    {
        $factory = ($this->previewFactory)();

        try {
            $preview = $factory->createPreview($pdfPath, $this->previewSize, max(1, $page), $this->getPreviewOptions())->getPath();
        } catch (UnableToGeneratePreviewException $exception) {
            if (!$fallbackToFirstPage || $page <= 1) {
                throw $exception;
            }

            // Seite existiert nicht (mehr) – auf Seite 1 zurückfallen
            $preview = $factory->createPreview($pdfPath, $this->previewSize, 1, $this->getPreviewOptions())->getPath();
        }

        return 'svg' === strtolower(Path::getExtension($preview)) ? null : $preview;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $crop
     */
    private function crop(string $previewPath, array $crop): string
    {
        $hash = substr(hash('sha256', $previewPath.'|'.filemtime($previewPath).'|'.implode(',', $crop)), 0, 16);
        $target = Path::join($this->cacheDir, $hash[0], pathinfo($previewPath, PATHINFO_FILENAME).'-crop-'.substr($hash, 1).'.png');

        if (is_file($target)) {
            return $target;
        }

        $image = $this->imagine->open($previewPath);
        $size = $image->getSize();

        $x = (int) round($crop[0] * $size->getWidth());
        $y = (int) round($crop[1] * $size->getHeight());
        $w = max(1, min((int) round($crop[2] * $size->getWidth()), $size->getWidth() - $x));
        $h = max(1, min((int) round($crop[3] * $size->getHeight()), $size->getHeight() - $y));

        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($target));

        // Atomar schreiben, damit parallele Requests keine halben Dateien sehen
        $tmp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $image->crop(new Point($x, $y), new Box($w, $h))->save($tmp, ['format' => 'png']);
        $filesystem->rename($tmp, $target, true);

        return $target;
    }

    /**
     * Der Renderer-Fingerabdruck fließt in den Cache-Schlüssel der PreviewFactory
     * ein. Wird auf dem Server nachträglich ein Renderer installiert (z. B.
     * poppler-utils oder Imagick), werden zuvor gespeicherte Platzhalter nicht
     * mehr verwendet und die Seiten automatisch auf dem Server neu gerendert.
     *
     * @return array<string, string>
     */
    private function getPreviewOptions(): array
    {
        static $fingerprint = null;

        if (null === $fingerprint) {
            $parts = ['cli:'.$this->cliProvider->getRendererId()];

            if (\extension_loaded('imagick')) {
                try {
                    $parts[] = 'imagick:'.implode(',', \Imagick::queryFormats('PDF'));
                } catch (\Throwable) {
                    $parts[] = 'imagick';
                }
            }

            if (\extension_loaded('gmagick')) {
                $parts[] = 'gmagick';
            }

            $fingerprint = substr(md5(implode('|', $parts)), 0, 8);
        }

        return ['dud_renderer' => $fingerprint];
    }

    private function absolute(string $path): string
    {
        return Path::isAbsolute($path) ? Path::canonicalize($path) : Path::makeAbsolute($path, $this->projectDir);
    }
}
