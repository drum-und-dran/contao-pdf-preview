<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Image;

use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Point;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Speichert Seitenbilder, die im Browser des Redakteurs (pdf.js) gerendert
 * wurden. Das ist die Rückfallebene für Webhostings ohne Imagick/Ghostscript/
 * Poppler und ohne exec/proc_open.
 *
 * Hochgeladene Daten werden nie direkt abgelegt: Sie werden auf Typ, Größe und
 * Abmessungen geprüft und über Imagine (GD/Imagick) vollständig neu als JPEG
 * codiert, wodurch evtl. angehängte Fremddaten verworfen werden. JPEG statt PNG
 * spart bei gerenderten Seiten etwa 80 % Speicherplatz.
 */
class ClientPreviewStore
{
    public const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    public const MAX_DIMENSION = 8000;

    public function __construct(
        private readonly ImagineInterface $imagine,
        private readonly string $projectDir,
        private readonly string $storageDir,
    ) {
    }

    public function get(string $pdfPath, int $page): string|null
    {
        $path = $this->getTargetPath($pdfPath, $page);

        if (null === $path) {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        // Ältere Versionen haben PNG gespeichert
        $legacy = substr($path, 0, -4).'.png';

        return is_file($legacy) ? $legacy : null;
    }

    /**
     * @return list<int>
     */
    public function getStoredPages(string $pdfPath): array
    {
        $dir = $this->getDirectory($pdfPath);

        if (null === $dir || !is_dir($dir)) {
            return [];
        }

        $pages = [];

        foreach (glob($dir.'/page-*.{jpg,png}', GLOB_BRACE) ?: glob($dir.'/page-*') ?: [] as $file) {
            if (preg_match('/page-(\d+)\.(?:jpg|png)$/', $file, $m)) {
                $pages[] = (int) $m[1];
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    /**
     * @throws \InvalidArgumentException bei ungültigen Bilddaten
     */
    public function store(string $pdfPath, int $page, string $binary): string
    {
        if ($page < 1 || $page > PdfPreviewGenerator::MAX_PAGES * 100) {
            throw new \InvalidArgumentException('Invalid page number.');
        }

        if ('' === $binary || \strlen($binary) > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Invalid image size.');
        }

        $isPng = str_starts_with($binary, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($binary, "\xFF\xD8\xFF");
        $isWebp = 'RIFF' === substr($binary, 0, 4) && 'WEBP' === substr($binary, 8, 4);

        if (!$isPng && !$isJpeg && !$isWebp) {
            throw new \InvalidArgumentException('Only PNG, JPEG or WebP images are accepted.');
        }

        $info = @getimagesizefromstring($binary);

        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException('Invalid image dimensions.');
        }

        $target = $this->getTargetPath($pdfPath, $page);

        if (null === $target) {
            throw new \InvalidArgumentException('Invalid PDF path.');
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($target));

        // Vollständig neu codieren (entfernt Metadaten und angehängte Daten)
        $image = $this->imagine->load($binary);
        $size = $image->getSize();

        $clean = $this->imagine->create(new Box($size->getWidth(), $size->getHeight()), $image->palette()->color('#ffffff', 100));
        $clean->paste($image, new Point(0, 0));

        $tmp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $clean->save($tmp, ['format' => 'jpeg', 'jpeg_quality' => 88]);

        $filesystem->rename($tmp, $target, true);
        $filesystem->remove(substr($target, 0, -4).'.png');

        // Alte Stände (frühere Datei-Versionen) aufräumen
        $this->cleanupStaleVersions($pdfPath);

        return $target;
    }

    public function remove(string $pdfPath): void
    {
        $dir = $this->getDirectory($pdfPath);

        if (null !== $dir) {
            (new Filesystem())->remove($dir);
        }
    }

    private function getTargetPath(string $pdfPath, int $page): string|null
    {
        $dir = $this->getDirectory($pdfPath);

        return null === $dir ? null : Path::join($dir, 'page-'.$page.'.jpg');
    }

    /**
     * Verzeichnis je PDF-Pfad und Dateistand (mtime/Größe): Wird die PDF ersetzt,
     * sind alte Browser-Renderings automatisch ungültig.
     */
    private function getDirectory(string $pdfPath): string|null
    {
        $pdfPath = Path::isAbsolute($pdfPath) ? Path::canonicalize($pdfPath) : Path::makeAbsolute($pdfPath, $this->projectDir);

        if (!Path::isBasePath($this->projectDir, $pdfPath) || !is_file($pdfPath)) {
            return null;
        }

        $relative = Path::makeRelative($pdfPath, $this->projectDir);

        return Path::join(
            $this->storageDir,
            substr(hash('sha256', $relative), 0, 16),
            substr(hash('sha256', filemtime($pdfPath).'|'.filesize($pdfPath)), 0, 12),
        );
    }

    private function cleanupStaleVersions(string $pdfPath): void
    {
        $current = $this->getDirectory($pdfPath);

        if (null === $current) {
            return;
        }

        foreach (glob(\dirname($current).'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($dir !== $current) {
                (new Filesystem())->remove($dir);
            }
        }
    }

    /**
     * Entfernt Renderings gelöschter oder ersetzter PDFs und liegen gebliebene
     * temporäre Dateien.
     *
     * @param array<string, true> $existingPdfs relative Pfade aller vorhandenen PDFs
     *
     * @return array{files: int, bytes: int}
     */
    public function cleanup(array $existingPdfs): array
    {
        $stats = ['files' => 0, 'bytes' => 0];

        if (!is_dir($this->storageDir)) {
            return $stats;
        }

        $known = [];

        foreach (array_keys($existingPdfs) as $relative) {
            $known[substr(hash('sha256', (string) $relative), 0, 16)] = (string) $relative;
        }

        foreach (glob($this->storageDir.'/*', GLOB_ONLYDIR) ?: [] as $pathDir) {
            $relative = $known[basename($pathDir)] ?? null;

            if (null === $relative) {
                $this->removeCounting($pathDir, $stats);

                continue;
            }

            $current = $this->getDirectory($relative);

            foreach (glob($pathDir.'/*', GLOB_ONLYDIR) ?: [] as $versionDir) {
                if ($versionDir !== $current) {
                    $this->removeCounting($versionDir, $stats);

                    continue;
                }

                foreach (glob($versionDir.'/*.tmp') ?: [] as $tmp) {
                    if (filemtime($tmp) < time() - 3600) {
                        $this->removeCounting($tmp, $stats);
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * @param array{files: int, bytes: int} $stats
     */
    private function removeCounting(string $path, array &$stats): void
    {
        $files = is_dir($path) ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)), false) : [new \SplFileInfo($path)];

        foreach ($files as $file) {
            if ($file->isFile()) {
                ++$stats['files'];
                $stats['bytes'] += (int) $file->getSize();
            }
        }

        (new Filesystem())->remove($path);
    }
}
