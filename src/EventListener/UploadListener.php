<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Component\Filesystem\Path;

/**
 * PDFs werden ganz normal über die Contao-Dateiverwaltung hochgeladen. Direkt
 * nach dem Upload prüfen wir sie und informieren den Redakteur, falls eine
 * Datei blockiert wird. Die Datei selbst wird nicht verändert oder gelöscht,
 * aber nie als Bild verarbeitet.
 */
class UploadListener
{
    public function __construct(
        private readonly PdfSecurityScanner $scanner,
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string> $files
     */
    #[AsHook('postUpload')]
    public function onPostUpload(array $files): void
    {
        if (!$this->scanner->isEnabled()) {
            return;
        }

        foreach ($files as $file) {
            if ('pdf' !== strtolower(Path::getExtension((string) $file))) {
                continue;
            }

            // Ohne Cache prüfen: Die Datei ist neu
            $result = $this->scanner->scan(Path::join($this->projectDir, (string) $file), false);

            if (!$result->safe) {
                $this->framework->initialize();
                $this->framework->getAdapter(Message::class)->addError(\sprintf(
                    $GLOBALS['TL_LANG']['ERR']['dudPdfUploadBlocked'] ?? 'The uploaded PDF "%s" was blocked by the security check: %s',
                    $file,
                    ScanReason::formatAll($result->reasons),
                ));
            }
        }
    }
}
