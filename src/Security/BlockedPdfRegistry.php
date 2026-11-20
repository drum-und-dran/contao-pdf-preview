<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

use Dud\ContaoPdfPreview\Hosting\ResourceLimits;
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
     * Die Liste liegt bewusst nicht im Cache, damit der Hinweis ein Leeren des Caches
     * (z. B. beim Deployment) übersteht.
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

        // Bei knappem max_execution_time (Web-Cron auf Shared Hosting) rechtzeitig
        // aufhören: Bereits geprüfte Dateien kommen beim nächsten Lauf aus dem Cache,
        // sodass die Prüfung schrittweise vollständig wird
        $deadline = ResourceLimits::deadline();
        $previous = $this->getStoredFiles();
        $complete = true;

        foreach ($finder as $file) {
            $relative = Path::makeRelative($file->getPathname(), $this->projectDir);

            if (null !== $deadline && microtime(true) > $deadline) {
                $complete = false;

                if (isset($previous[$relative])) {
                    $blocked[$relative] = $previous[$relative];
                }

                continue;
            }

            $result = $this->scanner->scan($file->getPathname(), $useCache);

            if (!$result->safe) {
                $blocked[$relative] = $result->reasons;
            }
        }

        ksort($blocked);
        $this->store($blocked, $complete);

        return $blocked;
    }

    /**
     * Aktualisiert den Eintrag einer einzelnen Datei (z. B. direkt nach dem Upload),
     * ohne auf den nächsten Cron-Lauf zu warten.
     */
    public function update(string $relativePath, ScanResult $result): void
    {
        [$version, $blocked] = $this->read();

        if ($result->safe) {
            unset($blocked[$relativePath]);
        } else {
            $blocked[$relativePath] = $result->reasons;
        }

        ksort($blocked);

        // Versionsstand beibehalten: Ein Einzeleintrag ersetzt keinen vollständigen Lauf
        try {
            (new Filesystem())->dumpFile($this->storageFile, json_encode(['version' => $version, 'files' => $blocked], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function getBlocked(): array
    {
        [$version, $blocked] = $this->read();

        // Inzwischen gelöschte Dateien nicht mehr melden
        $blocked = array_filter($blocked, fn ($reasons, $path) => \is_array($reasons) && is_file(Path::join($this->projectDir, (string) $path)), ARRAY_FILTER_USE_BOTH);

        // Nach einem Update oder einer Konfigurationsänderung die gemeldeten Dateien sofort
        // neu prüfen, statt bis zum nächsten Cron-Lauf veraltete Befunde anzuzeigen
        if ($blocked && $version !== $this->scanner->getVersion()) {
            $current = [];
            $deadline = ResourceLimits::deadline(0.3);
            $complete = true;

            foreach ($blocked as $path => $reasons) {
                if (null !== $deadline && microtime(true) > $deadline) {
                    $current[(string) $path] = $reasons;
                    $complete = false;

                    continue;
                }

                $result = $this->scanner->scan(Path::join($this->projectDir, (string) $path));

                if (!$result->safe) {
                    $current[(string) $path] = $result->reasons;
                }
            }

            $this->store($current, $complete);

            $blocked = $current;
        }

        return $blocked;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getStoredFiles(): array
    {
        return $this->read()[1];
    }

    /**
     * @return array{0: string, 1: array<string, list<string>>}
     */
    private function read(): array
    {
        try {
            $stored = is_file($this->storageFile) ? (array) json_decode((string) file_get_contents($this->storageFile), true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable) {
            return ['', []];
        }

        // Format bis 1.0.4: direkt Pfad => Gründe, ohne Version
        if (!isset($stored['files'])) {
            return ['', $stored];
        }

        return [(string) ($stored['version'] ?? ''), (array) $stored['files']];
    }

    private function store(array $blocked, bool $complete = true): void
    {
        try {
            // Unvollständiger Lauf: Version nicht übernehmen, damit neu geprüft wird
            $payload = ['version' => $complete ? $this->scanner->getVersion() : '', 'files' => $blocked];
            (new Filesystem())->dumpFile($this->storageFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
        }
    }
}
