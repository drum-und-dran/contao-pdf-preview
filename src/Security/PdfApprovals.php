<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Manuelle Freigaben für PDFs, die nach Prüfung durch einen Administrator trotz
 * Befund gerendert werden dürfen (z. B. Formulare mit eigenem JavaScript). Eine
 * Freigabe gilt nur für genau diesen Dateiinhalt (SHA-256): Wird die Datei verändert
 * oder ersetzt, ist sie wieder gesperrt. Virenfunde lassen sich nicht freigeben.
 */
class PdfApprovals
{
    /**
     * @var array<string, array{path: string, date: string, reasons: list<string>}>|null
     */
    private array|null $approvals = null;

    public function __construct(private readonly string $storageFile)
    {
    }

    public function isApproved(string $hash): bool
    {
        return isset($this->all()[$hash]);
    }

    /**
     * @param list<string> $reasons
     */
    public function approve(string $hash, string $path, array $reasons): void
    {
        $approvals = $this->all();
        $approvals[$hash] = ['path' => $path, 'date' => date('c'), 'reasons' => $reasons];

        $this->store($approvals);
    }

    /**
     * @return int Anzahl entfernter Freigaben
     */
    public function revoke(string $hashOrPath): int
    {
        $approvals = $this->all();
        $before = \count($approvals);

        $approvals = array_filter($approvals, static fn (array $entry, string $hash): bool => $hash !== $hashOrPath && $entry['path'] !== $hashOrPath, ARRAY_FILTER_USE_BOTH);
        $this->store($approvals);

        return $before - \count($approvals);
    }

    /**
     * @return array<string, array{path: string, date: string, reasons: list<string>}>
     */
    public function all(): array
    {
        if (null !== $this->approvals) {
            return $this->approvals;
        }

        try {
            $data = is_file($this->storageFile) ? json_decode((string) file_get_contents($this->storageFile), true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable) {
            $data = [];
        }

        $approvals = [];

        foreach (\is_array($data) ? $data : [] as $hash => $entry) {
            if (\is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) && \is_array($entry)) {
                $approvals[$hash] = [
                    'path' => (string) ($entry['path'] ?? ''),
                    'date' => (string) ($entry['date'] ?? ''),
                    'reasons' => array_values(array_map('strval', (array) ($entry['reasons'] ?? []))),
                ];
            }
        }

        return $this->approvals = $approvals;
    }

    private function store(array $approvals): void
    {
        ksort($approvals);
        (new Filesystem())->dumpFile($this->storageFile, json_encode($approvals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->approvals = $approvals;
    }
}
