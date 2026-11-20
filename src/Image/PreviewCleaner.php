<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Image;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Hält den Speicherbedarf der Vorschauen klein: Entfernt Vorschauen gelöschter
 * oder ersetzter PDFs, ungenutzte Zuschnitte und temporäre Reste. Im Browser
 * gerenderte Seiten vorhandener, unveränderter PDFs bleiben erhalten, da sie
 * sich auf Hostings ohne Server-Renderer nicht automatisch neu erzeugen lassen.
 */
class PreviewCleaner
{
    public function __construct(
        private readonly PdfPreviewGenerator $generator,
        private readonly ClientPreviewStore $clientStore,
        private readonly string $projectDir,
        private readonly string $uploadPath,
    ) {
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public function cleanup(): array
    {
        $root = Path::join($this->projectDir, $this->uploadPath);
        $existing = [];

        if (is_dir($root)) {
            foreach ((new Finder())->files()->in($root)->name('/\.pdf$/i')->ignoreUnreadableDirs()->followLinks() as $file) {
                $existing[Path::makeRelative($file->getPathname(), $this->projectDir)] = true;
            }
        }

        $client = $this->clientStore->cleanup($existing);
        $server = $this->generator->cleanup();

        return ['files' => $client['files'] + $server['files'], 'bytes' => $client['bytes'] + $server['bytes']];
    }
}
