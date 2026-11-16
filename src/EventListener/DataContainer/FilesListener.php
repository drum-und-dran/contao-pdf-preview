<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Dud\ContaoPdfPreview\Image\PdfPreviewGenerator;

/**
 * Blendet in der Dateiverwaltung bei PDF-Dateien die Felder für Seitenauswahl,
 * Vorschau, wichtigen Bereich (Contao-Standardfelder importantPart*) und ein
 * optionales eigenes Vorschaubild ein.
 *
 * Contao entfernt die importantPart-Felder für alles, was kein Bild ist
 * (tl_files::adjustPalettes). Dieser Callback läuft danach und fügt sie für
 * PDFs wieder hinzu.
 */
class FilesListener
{
    private const PDF_FIELDS = 'pdfPreviewPages,pdfPreviewImage,pdfPreview,importantPartX,importantPartY,importantPartWidth,importantPartHeight,pdfCropToImportantPart';

    #[AsCallback(table: 'tl_files', target: 'config.onpalette', priority: -128)]
    public function adjustPalette(string $palette, DataContainer|null $dc = null): string
    {
        if (!$dc || !PdfPreviewGenerator::isPdf((string) $dc->id) || str_contains($palette, 'pdfPreviewPages')) {
            return $palette;
        }

        // Vorhandene importantPart-Felder entfernen und in unserer Reihenfolge einfügen
        $palette = (string) preg_replace('/,?importantPart(?:X|Y|Width|Height)\b/', '', $palette);

        return str_contains($palette, 'syncExclude')
            ? str_replace('syncExclude', 'syncExclude,'.self::PDF_FIELDS, $palette)
            : self::PDF_FIELDS.','.$palette;
    }

    #[AsCallback(table: 'tl_files', target: 'fields.pdfPreviewPages.save')]
    public function validatePages(mixed $value, DataContainer|null $dc = null): string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return '';
        }

        if (null === PdfPreviewGenerator::parsePages($value)) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['ERR']['dudPdfPages'] ?? 'Invalid page selection. Examples: 1 or 2,4 or 1,3-5 or *');
        }

        return (string) preg_replace('/\s+/', '', $value);
    }
}
