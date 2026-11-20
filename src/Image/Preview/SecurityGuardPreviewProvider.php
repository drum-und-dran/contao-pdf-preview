<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Image\Preview;

use Contao\CoreBundle\Image\Preview\PreviewProviderInterface;
use Contao\CoreBundle\Image\Preview\UnableToGeneratePreviewException;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Läuft mit höchster Priorität VOR allen anderen Preview-Providern von Contao
 * (Imagick/Gmagick, Ghostscript, Poppler …) und schützt damit auch die
 * Core-Funktionen, die PDFs rendern (Vorschaubilder im Download-Element,
 * Dateiauswahl und Dateivorschau im Backend).
 *
 * - Sichere PDF: Wir geben ab (UnableToGeneratePreviewException), der nächste
 *   Provider rendert wie gewohnt.
 * - Unsichere PDF: Wir liefern ein neutrales „gesperrt“-Symbol. Die Datei
 *   erreicht damit nie einen Renderer.
 */
class SecurityGuardPreviewProvider implements PreviewProviderInterface
{
    public function __construct(private readonly PdfSecurityScanner $scanner)
    {
    }

    public function getFileHeaderSize(): int
    {
        return 0;
    }

    public function supports(string $path, string $fileHeader = '', array $options = []): bool
    {
        // Auch Dateien mit PDF-Header, aber anderer Endung (Polyglots) abfangen
        return $this->scanner->isEnabled()
            && ('pdf' === strtolower(Path::getExtension($path)) || $this->hasPdfHeader($path));
    }

    public function generatePreviews(string $sourcePath, int $size, \Closure $targetPathCallback, int $lastPage = PHP_INT_MAX, int $firstPage = 1, array $options = []): iterable
    {
        $result = $this->scanner->scan($sourcePath);

        if ($result->safe) {
            throw new UnableToGeneratePreviewException('PDF passed the security check, delegating to the next provider.');
        }

        $target = $targetPathCallback($firstPage).'.svg';
        (new Filesystem())->dumpFile($target, $this->getBlockedSvg());

        return [$target];
    }

    private function hasPdfHeader(string $path): bool
    {
        $handle = @fopen($path, 'r');

        if (!$handle) {
            return false;
        }

        $head = (string) fread($handle, 1024);

        fclose($handle);

        return str_contains($head, '%PDF-');
    }

    private function getBlockedSvg(): string
    {
        return '<?xml version="1.0"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 160" width="120" height="160">'
            .'<rect width="120" height="160" fill="#f3f3f5"/>'
            .'<path d="M30 20h44l16 16v104H30z" fill="#fff" stroke="#ccc" stroke-width="2"/>'
            .'<circle cx="60" cy="88" r="22" fill="none" stroke="#c9473d" stroke-width="6"/>'
            .'<path d="M45 103l30-30" stroke="#c9473d" stroke-width="6"/>'
            .'</svg>';
    }
}
