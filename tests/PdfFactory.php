<?php

declare(strict_types=1);

namespace Dud\ContaoPdfPreview\Tests;

/**
 * Erzeugt minimale Test-PDFs (sauber und präpariert).
 */
final class PdfFactory
{
    public static function build(array $objects, string $trailerExtra = '', string $prefix = ''): string
    {
        $out = $prefix."%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = \strlen($out);
            $out .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = \strlen($out);
        $out .= "xref\n0 ".(\count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $out .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $out.'trailer'."\n<< /Size ".(\count($objects) + 1).' /Root 1 0 R '.$trailerExtra.">>\nstartxref\n".$xref."\n%%EOF\n";
    }

    public static function clean(int $pages = 1): string
    {
        $kids = implode(' ', array_map(static fn ($i) => (3 + $i).' 0 R', range(0, $pages - 1)));
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids ['.$kids.'] /Count '.$pages.' >>'];

        for ($i = 0; $i < $pages; ++$i) {
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>';
        }

        return self::build($objects);
    }

    public static function withObject(string $object, string $trailerExtra = ''): string
    {
        return self::build([
            '<< /Type /Catalog /Pages 2 0 R /OpenAction 4 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
            $object,
        ], $trailerExtra);
    }

    public static function objectStream(string $content, string $filter = '/FlateDecode'): string
    {
        $data = '/FlateDecode' === $filter ? (string) gzcompress($content) : $content;

        return self::withObject('<< /Type /ObjStm /N 1 /First 4 /Filter '.$filter.' /Length '.\strlen($data)." >>\nstream\n".$data."\nendstream");
    }
}
