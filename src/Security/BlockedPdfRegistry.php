<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Durchsucht das Dateiverzeichnis nach PDFs – unabhängig davon, wie sie dort
 * hingekommen sind (Contao-Upload, FTP/SFTP, Deployment, Synchronisation) – und
 * merkt sich die blockierten Dateien für den Hinweis im Backend.
 */
class BlockedPdfRegistry
{
    /**
     * Die Liste liegt bewusst nicht im Cache, damit der Hinweis ein Leeren des
     * Caches (z. B. beim Deployment) übersteht.
     */
    public function __construct(
        private readonly PdfSecurityScanner $scanner,
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly string $storageFile,
    ) {
    }

    /**
     * @return array<string, list<string>> Pfad => Gründe
     */
    public function scanAll(bool $useCache = true): array
    {
        $root = Path::join($this->projectDir, $this->uploadPath);
        $blocked = [];

        if (!$this->scanner->isEnabled() || !is_dir($root)) {
            $this->store([]);

            return [];
        }

        $finder = (new Finder())->files()->in($root)->name('/\.pdf$/i')->ignoreUnreadableDirs()->followLinks();

        foreach ($finder as $file) {
            $result = $this->scanner->scan($file->getPathname(), $useCache);

            if (!$result->safe) {
                $blocked[Path::makeRelative($file->getPathname(), $this->projectDir)] = $result->reasons;
            }
        }

        ksort($blocked);
        $this->store($blocked);

        return $blocked;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getBlocked(): array
    {
        try {
            $blocked = is_file($this->storageFile) ? (array) json_decode((string) file_get_contents($this->storageFile), true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable) {
            return [];
        }

        // Inzwischen gelöschte Dateien nicht mehr melden
        return array_filter($blocked, fn ($reasons, $path) => is_file(Path::join($this->projectDir, (string) $path)), ARRAY_FILTER_USE_BOTH);
    }

    private function store(array $blocked): void
    {
        try {
            (new Filesystem())->dumpFile($this->storageFile, json_encode($blocked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
        }
    }
}
