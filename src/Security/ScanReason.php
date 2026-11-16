<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prüfgründe werden sprachneutral als Code gespeichert ("feature|JavaScript")
 * und erst bei der Anzeige in die Sprache des Benutzers übersetzt
 * ($GLOBALS['TL_LANG']['dud_pdf_preview']['reason'][…]).
 */
final class ScanReason
{
    /**
     * Englische Texte als Rückfallebene (z. B. in Logs oder ohne Sprachdatei).
     */
    private const FALLBACK = [
        'unreadable' => 'File is not readable',
        'too_large' => 'File is larger than %s MB',
        'too_small' => 'File is too small or empty',
        'no_header' => 'No valid PDF header at the start of the file (possible polyglot file)',
        'no_eof' => 'No end-of-file marker (%%EOF) – file is incomplete or manipulated',
        'encrypted' => 'Encrypted PDF (content cannot be inspected)',
        'feature' => 'Active/dangerous content: /%s',
        'objstm_broken' => 'Damaged object stream',
        'objstm_undecodable' => 'Object stream cannot be decompressed or is too large (compression bomb?)',
        'objstm_filter' => 'Object stream with uninspectable filter %s',
        'objstm_limit' => 'Decompressed object streams exceed the limit (compression bomb?)',
        'virus' => 'Virus scanner (%s): %s',
        'virus_failed' => 'Virus scan failed (%s): %s',
        'no_scanner' => 'No virus scanner (ClamAV) available, but a virus scan is required',
    ];

    public static function make(string $code, string ...$parameters): string
    {
        return implode('|', [$code, ...array_map(static fn (string $p): string => str_replace('|', '/', $p), $parameters)]);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function parse(string $reason): array
    {
        $parts = explode('|', $reason);

        return [array_shift($parts), $parts];
    }

    public static function format(string $reason, TranslatorInterface|null $translator = null): string
    {
        [$code, $parameters] = self::parse($reason);

        // Unbekannter Code (z. B. Text aus älterem Cache): unverändert ausgeben
        if (!isset(self::FALLBACK[$code])) {
            return $reason;
        }

        $id = 'dud_pdf_preview.reason.'.$code;

        if ($translator) {
            try {
                $translated = $translator->trans($id, $parameters, 'contao_default');

                if ($translated !== $id && '' !== $translated) {
                    return $translated;
                }
            } catch (\Throwable) {
            }
        }

        $text = $GLOBALS['TL_LANG']['dud_pdf_preview']['reason'][$code] ?? self::FALLBACK[$code];

        try {
            return vsprintf($text, $parameters);
        } catch (\Throwable) {
            return $text;
        }
    }

    /**
     * @param list<string> $reasons
     */
    public static function formatAll(array $reasons, TranslatorInterface|null $translator = null, string $glue = '; '): string
    {
        return implode($glue, array_map(static fn (string $r): string => self::format($r, $translator), $reasons));
    }
}
