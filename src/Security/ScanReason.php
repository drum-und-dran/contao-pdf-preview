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
 * Prüfgründe werden sprachneutral als Code gespeichert
 * ("feature|JavaScript") und erst bei der Anzeige in die Sprache des
 * Benutzers übersetzt
 * ($GLOBALS['TL_LANG']['dud_pdf_preview']['reason'][…]).
 */
final class ScanReason
{
    /**
     * Englische Texte als Rückfallebene (z. B. in Logs oder ohne Sprachdatei).
     */
    /**
     * Englische Texte als Rückfallebene (z. B. in Logs oder ohne Sprachdatei).
     * Parameter werden positionsbezogen eingesetzt (%1$s, %2$s …).
     */
    private const FALLBACK = [
        'unreadable' => 'File is not readable (missing file permissions or file deleted during the check)',
        'size_limit' => 'File is %1$s and exceeds the limit of %2$s (contao_pdf_preview.security.max_file_size)',
        'too_small' => 'File is empty or too small to be a PDF',
        'not_pdf' => 'Not a PDF file: there is no PDF header at the start of the file, detected: %s',
        'header_offset' => 'The PDF header only starts at byte %1$s, preceded by "%2$s" (typical of polyglot files that are another format at the same time)',
        'eof_missing' => 'End-of-file marker %%EOF missing in the last 64 KB: the file (%s) was probably uploaded incompletely or truncated',
        'encrypted' => 'Encrypted PDF: the content cannot be inspected (can be allowed with contao_pdf_preview.security.allow_encrypted)',
        'feature' => 'Active content /%1$s: %2$s',
        'script' => 'JavaScript with custom code that goes beyond the standard form functions: "%s"',
        'script_ref' => 'JavaScript in object %s could not be read and therefore not inspected',
        'objstm_unterminated' => 'Object stream %s has no endstream marker (file truncated or damaged)',
        'objstm_filter' => 'Object stream %1$s uses the compression method %2$s, which cannot be inspected',
        'objstm_corrupt' => 'Object stream %1$s is damaged: decompression stops after %2$s of %3$s (zlib: %6$s), only %4$s of %5$s contained objects are readable',
        'objstm_bomb' => 'Compression bomb in object stream %1$s: %2$s of compressed data expand to more than %3$s (ratio above 1:%4$s, regular files reach about 1:30)',
        'objstm_memory' => 'Object stream %1$s is larger than %2$s when decompressed and does not fit into the free memory (memory_limit %3$s). Not a compression bomb: increasing memory_limit solves this',
        'pcre_failed' => 'The content could not be inspected completely (PCRE limit reached: %s). Increasing pcre.backtrack_limit may help',
        'objstm_total' => 'Decompressed object streams exceed %s in total (contao_pdf_preview.security.max_decompressed_size)',
        'virus' => 'Virus scanner (%s): %s',
        'virus_failed' => 'Virus scan failed (%s): %s',
        'no_scanner' => 'No virus scanner (ClamAV) available, but a virus scan is required',
        'form_scripts' => 'Form JavaScript: %1$s field script(s) using only standard form functions (%2$s). Harmless, not executed during rendering',
        'form_submit' => 'Form with a submit button (SubmitForm). Harmless, not executed during rendering',
        'objstm_repaired' => 'Object stream %1$s ends with a minor formal error, all %2$s objects are fully readable',
        'approved' => 'Approved manually after review (contao-pdf-preview:scan --approve), findings:',
        'too_large' => 'File is larger than %s MB',
        'no_header' => 'No valid PDF header at the start of the file (possible polyglot file)',
        'no_eof' => 'No end-of-file marker (%%EOF) – file is incomplete or manipulated',
        'objstm_broken' => 'Damaged object stream',
        'objstm_undecodable' => 'Object stream cannot be decompressed',
        'objstm_limit' => 'Decompressed object streams exceed the limit',
    ];

    /**
     * Erklärung der Namen beim Code "feature".
     */
    private const FEATURE_INFO = [
        'JavaScript' => 'JavaScript action',
        'JS' => 'JavaScript action',
        'Launch' => 'starts a program or opens a file',
        'EmbeddedFile' => 'embedded file (attachment)',
        'EmbeddedFiles' => 'embedded file (attachment)',
        'RichMedia' => 'Flash/multimedia content',
        'XFA' => 'XFA form (Adobe LiveCycle)',
        'SubmitForm' => 'sends form data to an address',
        'ImportData' => 'imports form data from an external file',
        'GoToE' => 'jumps into an embedded file',
    ];

    /**
     * Gründe, die eine manuelle Freigabe nicht aufheben darf.
     */
    public const NOT_APPROVABLE = ['virus', 'virus_failed', 'no_scanner', 'unreadable', 'size_limit', 'too_small', 'not_pdf', 'header_offset', 'pcre_failed'];

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

        if ($translator && 'feature' !== $code) {
            try {
                $translated = $translator->trans($id, $parameters, 'contao_default');

                if ($translated !== $id && '' !== $translated) {
                    return $translated;
                }
            } catch (\Throwable) {
            }
        }

        $text = $GLOBALS['TL_LANG']['dud_pdf_preview']['reason'][$code] ?? self::FALLBACK[$code];

        if ('feature' === $code && 1 === \count($parameters)) {
            $parameters[] = $GLOBALS['TL_LANG']['dud_pdf_preview']['feature'][$parameters[0]] ?? self::FEATURE_INFO[$parameters[0]] ?? '';
        }

        // Fehlende Parameter (z. B. Eintrag aus älterer Version) nicht als Fehler
        // enden lassen
        $parameters = array_pad($parameters, 8, '?');

        try {
            $formatted = vsprintf($text, $parameters);

            return 'feature' === $code ? rtrim($formatted, ' :') : $formatted;
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
