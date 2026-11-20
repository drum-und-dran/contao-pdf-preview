<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

use Dud\ContaoPdfPreview\Hosting\ResourceLimits;
use Dud\ContaoPdfPreview\Image\Preview\CliPdfPreviewProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Mehrstufige Sicherheitsprüfung, bevor eine PDF an einen Renderer
 * (Ghostscript/Imagick/Poppler) übergeben wird:
 *
 *  1. Größenlimit
 *  2. Strikte Strukturprüfung (Header an Offset 0, %%EOF vorhanden) gegen
 *     Polyglot-Dateien und beschädigte Dokumente
 *  3. Verschlüsselte PDFs blockieren (Inhalt wäre nicht prüfbar)
 *  4. Aktive/gefährliche Inhalte erkennen (JavaScript, Launch, eingebettete
 *     Dateien, XFA, RichMedia, …) – auch mit #xx-maskierten Namen und in
 *     komprimierten Objekt-Streams; nicht prüfbare Objekt-Streams = blockiert.
 *     Formular-JavaScript, das nur die eingebauten Acrobat-Formatfunktionen
 *     aufruft, gilt als unbedenklich (Hinweis statt Sperre).
 *  5. Virenscan mit ClamAV (clamd-Socket, clamdscan oder clamscan)
 *
 * Grundsatz: fail closed – im Zweifel wird blockiert.
 */
class PdfSecurityScanner
{
    private const CACHE_VERSION = 'v4';

    /**
     * Ab diesem Verhältnis von entpackter zu gepackter Größe liegt eine
     * Kompressionsbombe nahe. Normale Objekt-Streams (Text) erreichen etwa 1:3 bis
     * 1:30, Deflate ist technisch auf rund 1:1032 begrenzt.
     */
    private const BOMB_RATIO = 200;

    private const AUTO_TOTAL_LIMIT = 4 * 1073741824;

    private const UNLIMITED_STREAM_BUDGET = 1073741824;

    private const FEATURE_ALIASES = ['JS' => 'JavaScript', 'EmbeddedFiles' => 'EmbeddedFile'];

    /**
     * Eingebaute Formatierungs-, Eingabe- und Berechnungsfunktionen von Acrobat
     * (AForm.js), die Formular-Editoren für Formularfelder erzeugen. Sie erhalten
     * ausschließlich feste Werte als Parameter.
     */
    private const FORM_FUNCTIONS = [
        'AFNumber_Format',
        'AFNumber_Keystroke',
        'AFPercent_Format',
        'AFPercent_Keystroke',
        'AFDate_Format',
        'AFDate_FormatEx',
        'AFDate_Keystroke',
        'AFDate_KeystrokeEx',
        'AFTime_Format',
        'AFTime_FormatEx',
        'AFTime_Keystroke',
        'AFTime_KeystrokeEx',
        'AFSpecial_Format',
        'AFSpecial_Keystroke',
        'AFSpecial_KeystrokeEx',
        'AFSimple_Calculate',
        'AFRange_Validate',
    ];

    private bool $pcreFailed = false;

    /**
     * @var array<string, ScanResult>
     */
    private array $memory = [];

    private false|string|null $clamBinary = null;

    /**
     * @param list<string> $blockedFeatures
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface|null $logger,
        private readonly bool $enabled,
        private readonly int $maxFileSize,
        private readonly array $blockedFeatures,
        private readonly bool $allowEncrypted,
        private readonly string $virusScan,
        private readonly bool $requireVirusScan,
        private readonly string|null $clamavSocket,
        private readonly string|null $clamavBinary,
        private readonly int $maxDecompressedBytes = 0,
        private readonly bool $allowFormFeatures = true,
        private readonly PdfApprovals|null $approvals = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @throws UnsafePdfException
     */
    public function assertSafe(string $path): ScanResult
    {
        $result = $this->scan($path);

        if (!$result->safe) {
            throw new UnsafePdfException($path, $result);
        }

        return $result;
    }

    public function scan(string $path, bool $useCache = true): ScanResult
    {
        clearstatcache(true, $path);

        if (!is_file($path) || !is_readable($path)) {
            return new ScanResult(false, [ScanReason::make('unreadable')]);
        }

        // Manuelle Freigabe durch einen Administrator: gilt nur für exakt diesen Inhalt
        // (SHA-256) und hebt nie einen Virenfund auf
        $approved = null !== $this->approvals && null !== ($hash = self::fingerprint($path)) && $this->approvals->isApproved($hash);

        // Der Inhalts-Hash sorgt dafür, dass auch per FTP ersetzte Dateien mit
        // beibehaltenem Zeitstempel und gleicher Größe neu geprüft werden
        $key = 'dud_pdf_scan_'.hash(
            'sha256',
            implode('|', [
                self::CACHE_VERSION,
                realpath($path),
                (string) filemtime($path),
                (string) filesize($path),
                (string) @hash_file('xxh128', $path),
                $this->getConfigHash(),
                $approved ? 'approved' : '',
            ]),
        );

        if ($useCache && isset($this->memory[$key])) {
            return $this->memory[$key];
        }

        if ($useCache) {
            try {
                $item = $this->cache->getItem($key);

                if ($item->isHit() && \is_array($item->get())) {
                    return $this->memory[$key] = ScanResult::fromArray($item->get());
                }
            } catch (\Throwable) {
                // Cache nicht verfügbar: einfach neu prüfen
            }
        }

        [$result, $cacheable] = $this->doScan($path, $approved);

        if (!$result->safe) {
            $this->logger?->warning(\sprintf('PDF preview blocked for "%s": %s', $path, ScanReason::formatAll($result->reasons)));
        }

        if ($cacheable) {
            try {
                $item = $this->cache->getItem($key);
                $item->set($result->toArray());
                $item->expiresAfter(86400 * 7);

                $this->cache->save($item);
            } catch (\Throwable) {
            }
        }

        return $this->memory[$key] = $result;
    }

    /**
     * Inhalts-Hash, unter dem eine manuelle Freigabe gespeichert wird.
     */
    public static function fingerprint(string $path): string|null
    {
        $hash = @hash_file('sha256', $path);

        return \is_string($hash) ? $hash : null;
    }

    /**
     * Ändert sich mit jeder neuen Prüflogik und jeder Konfigurationsänderung.
     */
    public function getVersion(): string
    {
        return self::CACHE_VERSION.'-'.$this->getConfigHash();
    }

    public function hasVirusScanner(): bool
    {
        return null !== $this->clamavSocket && '' !== $this->clamavSocket || false !== $this->getClamBinary();
    }

    public function isVirusScanRequired(): bool
    {
        return $this->enabled && ($this->requireVirusScan || 'clamav' === $this->virusScan);
    }

    /**
     * @return array{0: ScanResult, 1: bool} Ergebnis und ob es gecacht werden darf
     */
    private function doScan(string $path, bool $approved = false): array
    {
        if (!$this->enabled) {
            return [new ScanResult(true, [], null, null), false];
        }

        $reasons = [];
        $size = (int) filesize($path);

        // Ebene 1: Größe
        if ($size > $this->maxFileSize) {
            return [new ScanResult(false, [ScanReason::make('size_limit', $this->formatBytes($size), $this->formatBytes($this->maxFileSize))]), true];
        }

        if ($size < 32) {
            return [new ScanResult(false, [ScanReason::make('too_small')]), true];
        }

        $data = file_get_contents($path);

        if (false === $data) {
            return [new ScanResult(false, [ScanReason::make('unreadable')]), false];
        }

        // Ebene 2: Struktur
        if (!str_starts_with($data, '%PDF-')) {
            $headerPos = strpos(substr($data, 0, 1024), '%PDF-');

            $reasons[] = false === $headerPos
                ? ScanReason::make('not_pdf', $this->detectFormat($data))
                : ScanReason::make('header_offset', (string) $headerPos, $this->excerpt(substr($data, 0, min($headerPos, 24))));
        }

        // Bei Dateien ohne PDF-Header wäre der fehlende Dateiende-Marker nur eine
        // Folgemeldung
        if ((!$reasons || 'header_offset' === ScanReason::parse($reasons[0])[0]) && !str_contains(substr($data, -65536), '%%EOF')) {
            $reasons[] = ScanReason::make('eof_missing', $this->formatBytes($size));
        }

        if ($reasons && !($approved && $this->isApprovable($reasons))) {
            return [new ScanResult(false, $reasons), true];
        }

        // Ebenen 3 und 4: Die Datei selbst und jeder entpackte Objekt-Stream werden
        // einzeln untersucht und danach sofort wieder freigegeben. So liegt immer nur
        // ein entpackter Stream im Arbeitsspeicher.
        $findings = new ScanFindings();
        $this->pcreFailed = false;

        $this->inspect($data, $findings);

        [$streamProblems, $streamNotices] = $this->inspectObjectStreams($data, $findings);

        $reasons = [...$reasons, ...$streamProblems];
        $notices = $streamNotices;

        // Ebene 3: Verschlüsselung
        if (!$this->allowEncrypted && $findings->encrypted) {
            $reasons[] = ScanReason::make('encrypted');
        }

        // Ebene 4: aktive Inhalte
        [$featureReasons, $featureNotices] = $this->evaluateFeatures($data, $findings);
        $reasons = [...$reasons, ...$featureReasons];
        $notices = [...$notices, ...$featureNotices];
        $pageCount = $findings->pages > 0 ? $findings->pages : null;
        unset($findings);

        if ($this->pcreFailed) {
            $reasons[] = ScanReason::make('pcre_failed', (string) preg_last_error_msg());
        }

        $approved = $approved && $this->isApprovable($reasons);

        if ($reasons) {
            if (!$approved) {
                return [new ScanResult(false, array_values(array_unique($reasons)), null, $pageCount, $notices), true];
            }

            // Freigegeben: Befunde bleiben als Hinweis sichtbar, der Virenscan läuft trotzdem
            $notices = [ScanReason::make('approved'), ...array_values(array_unique($reasons)), ...$notices];
        }

        // Ebene 5: Virenscan
        [$virusReasons, $scanner, $definitive] = $this->virusScan($path, $data);
        unset($data);

        if ($virusReasons) {
            return [new ScanResult(false, $virusReasons, $scanner, $pageCount, $notices), $definitive];
        }

        return [new ScanResult(true, [], $scanner, $pageCount, $notices), $definitive];
    }

    /**
     * @param list<string> $reasons
     */
    private function isApprovable(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            $code = ScanReason::parse($reason)[0];

            // Keine echte PDF (z. B. PostScript-Polyglot für Ghostscript) oder Virenfund
            if (\in_array($code, ScanReason::NOT_APPROVABLE, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Erkennt gängige Dateitypen, die fälschlich als .pdf gespeichert sind.
     */
    private function detectFormat(string $data): string
    {
        $signatures = [
            "\x89PNG" => 'PNG',
            "\xFF\xD8\xFF" => 'JPEG',
            'GIF8' => 'GIF',
            'PK' => 'ZIP/Office',
            '%!PS' => 'PostScript',
            "\xD0\xCF\x11\xE0" => 'Office (alt)',
            '{\rtf' => 'RTF',
            'MZ' => 'EXE',
            "\x7FELF" => 'ELF',
            "II*\0" => 'TIFF',
            "MM\0*" => 'TIFF',
        ];

        foreach ($signatures as $signature => $format) {
            if (str_starts_with($data, $signature)) {
                return $format;
            }
        }

        $start = ltrim(substr($data, 0, 512));

        if (preg_match('#^(?:<!doctype html|<html|<\?php|<\?xml|<svg)#i', $start, $m)) {
            return strtoupper(trim($m[0], '<!?')).' ('.$this->excerpt(substr($start, 0, 24)).')';
        }

        return '"'.$this->excerpt(substr($data, 0, 16)).'"';
    }

    /**
     * Untersucht einen Datenblock (Datei oder entpackter Objekt-Stream).
     */
    private function inspect(string $haystack, ScanFindings $findings): void
    {
        $decoded = $this->decodeNamesIfNeeded($haystack);

        if (!$findings->encrypted && $this->containsName($decoded, 'Encrypt')) {
            $findings->encrypted = true;
        }

        foreach ($this->blockedFeatures as $feature) {
            $feature = ltrim($feature, '/');

            if ('' !== $feature && !isset($findings->features[$feature]) && $this->containsName($decoded, $feature)) {
                $findings->features[$feature] = true;
            }
        }

        $findings->pages += (int) preg_match_all('#/Type\s*/Page(?![A-Za-z0-9])#', $decoded);

        if ($this->allowFormFeatures && (isset($findings->features['JS']) || isset($findings->features['JavaScript']))) {
            $this->collectScripts($decoded, $findings);
        }
    }

    /**
     * @return array{0: list<string>, 1: list<string>} Gründe (blockierend) und Hinweise
     */
    private function evaluateFeatures(string $data, ScanFindings $findings): array
    {
        $reasons = [];
        $notices = [];
        $features = $findings->features;

        // Synonyme nur einmal melden (/JS ist der Code einer /JavaScript-Aktion)
        foreach (self::FEATURE_ALIASES as $alias => $canonical) {
            if (isset($features[$alias])) {
                unset($features[$alias]);

                $features[$canonical] = true;
            }
        }

        if ($this->allowFormFeatures && isset($features['JavaScript'])) {
            unset($features['JavaScript']);

            [$scriptReasons, $scriptNotices] = $this->evaluateScripts($data, $findings);
            $reasons = [...$reasons, ...$scriptReasons];
            $notices = [...$notices, ...$scriptNotices];
        }

        if ($this->allowFormFeatures && isset($features['SubmitForm'])) {
            unset($features['SubmitForm']);

            $notices[] = ScanReason::make('form_submit');
        }

        foreach (array_keys($features) as $feature) {
            $reasons[] = ScanReason::make('feature', (string) $feature);
        }

        return [$reasons, $notices];
    }

    /**
     * Ausfüllbare Formulare (z. B. aus Acrobat oder LibreOffice) enthalten
     * JavaScript für Datums-, Zahlen- und Pflichtfeldformate. Diese Aufrufe der
     * eingebauten Acrobat-Formularfunktionen (AFDate_FormatEx, AFNumber_Format,
     * AFSimple_Calculate …) übergeben nur feste Werte und sind unbedenklich. Jeder
     * andere Code wird weiterhin blockiert.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function evaluateScripts(string $data, ScanFindings $findings): array
    {
        // Code in eigenen Objekten (Stream oder String) nachladen
        foreach ($findings->scriptRefs as $ref) {
            $code = $this->resolveObject($data, $ref);

            if (null === $code) {
                return [[ScanReason::make('script_ref', $ref.' R')], []];
            }

            $findings->scripts[] = $code;
        }

        if (!$findings->scripts) {
            // Name /JavaScript ohne auffindbaren Code: nicht prüfbar, fail closed
            return [[ScanReason::make('feature', 'JavaScript')], []];
        }

        $functions = [];

        foreach ($findings->scripts as $code) {
            if (!$this->isStandardFormScript($code, $functions)) {
                return [[ScanReason::make('script', $this->excerpt($code))], []];
            }
        }

        $functions = array_keys($functions);
        sort($functions);

        return [[], [ScanReason::make('form_scripts', (string) \count($findings->scripts), implode(', ', $functions))]];
    }

    private function collectScripts(string $decoded, ScanFindings $findings): void
    {
        $offset = 0;

        while (1 === ($found = preg_match('#/JS(?=[\s/\[\]<>(){}%])\s*#', $decoded, $match, PREG_OFFSET_CAPTURE, $offset))) {
            $pos = $match[0][1] + \strlen($match[0][0]);
            $offset = $pos;
            $char = $decoded[$pos] ?? '';

            if ('(' === $char) {
                [$string, $offset] = $this->parseLiteralString($decoded, $pos);
                $findings->scripts[] = $this->toUtf8($string);
            } elseif ('<' === $char && '<' !== ($decoded[$pos + 1] ?? '')) {
                $close = strpos($decoded, '>', $pos);
                $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($decoded, $pos + 1, false === $close ? 0 : $close - $pos - 1));
                $findings->scripts[] = $this->toUtf8((string) hex2bin(\strlen($hex) % 2 ? $hex.'0' : $hex));
            } elseif (preg_match('#\G(\d+)\s+(\d+)\s+R#', $decoded, $ref, 0, $pos)) {
                $findings->scriptRefs[$ref[1].' '.$ref[2]] = $ref[1].' '.$ref[2];
            } else {
                // Unbekannte Form: als nicht prüfbarer Code werten
                $findings->scripts[] = substr($decoded, $pos, 80);
            }
        }

        if (false === $found) {
            $this->pcreFailed = true;
        }
    }

    /**
     * Lädt ein indirektes Objekt (String oder Stream) aus der Datei.
     */
    private function resolveObject(string $data, string $ref): string|null
    {
        [$number, $generation] = explode(' ', $ref);

        if (!preg_match_all('#(?<![0-9])'.$number.'\s+'.$generation.'\s+obj\b#', $data, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // Bei inkrementellen Updates gilt die letzte Fassung
        $start = (int) end($matches[0])[1] + \strlen((string) end($matches[0])[0]);
        $body = ltrim(substr($data, $start, 64));

        if (str_starts_with($body, '(')) {
            $pos = strpos($data, '(', $start);

            return $this->toUtf8($this->parseLiteralString($data, (int) $pos)[0]);
        }

        $streamPos = strpos($data, 'stream', $start);
        $endObj = strpos($data, 'endobj', $start);

        if (false === $streamPos || (false !== $endObj && $endObj < $streamPos)) {
            return null;
        }

        $dict = $this->decodeNames(substr($data, $start, $streamPos - $start));
        [$raw] = $this->readStreamData($data, $streamPos, $dict);

        if (null === $raw) {
            return null;
        }

        if (!preg_match('#/Filter#', $dict)) {
            return $this->toUtf8($raw[0]);
        }

        if (!preg_match('#/Filter\s*(?:/FlateDecode|\[\s*/FlateDecode\s*\])#', $dict)) {
            return null;
        }

        $result = $this->inflate($raw[0], 4 * 1048576);

        return null === $result['error'] || $result['complete'] ? $this->toUtf8($result['data']) : null;
    }

    /**
     * @param array<string, true> $functions
     */
    private function isStandardFormScript(string $code, array &$functions): bool
    {
        // Kommentare werden bewusst nicht entfernt: Acrobat erzeugt keine, und über
        // Kommentarzeichen in Strings ließe sich sonst Code verstecken
        $stripped = preg_replace('/"(?:[^"\\\\\r\n]|\\\\.)*"|\'(?:[^\'\\\\\r\n]|\\\\.)*\'/s', '0', $code);

        if (null === $stripped) {
            return false;
        }

        if ('' === trim($stripped)) {
            return true;
        }

        if (false === preg_match_all('#\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(#', $stripped, $calls) || !$calls[1]) {
            return false;
        }

        foreach ($calls[1] as $name) {
            if ('Array' !== $name && !\in_array($name, self::FORM_FUNCTIONS, true)) {
                return false;
            }
        }

        $rest = preg_replace(['#\b[A-Za-z]+_[A-Za-z]+\s*\(#', '#\bnew\s+Array\s*\(#', '#\b(?:true|false|null)\b#'], ['(', '(', '0'], $stripped);

        // Übrig bleiben dürfen nur Zahlen, Trennzeichen und Klammern
        if (null === $rest || 1 !== preg_match('#^[\s0-9.,+\-();]*$#', $rest)) {
            return false;
        }

        $depth = 0;

        foreach (str_split((string) preg_replace('#[^()]#', '', $rest)) as $char) {
            $depth += '(' === $char ? 1 : -1;

            if ($depth < 0) {
                return false;
            }
        }

        if (0 !== $depth) {
            return false;
        }

        foreach ($calls[1] as $name) {
            if ('Array' !== $name) {
                $functions[$name] = true;
            }
        }

        return true;
    }

    /**
     * @return array{0: string, 1: int} Inhalt und Position nach der schließenden Klammer
     */
    private function parseLiteralString(string $data, int $pos): array
    {
        $length = \strlen($data);
        $depth = 0;
        $out = '';
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"];

        for ($i = $pos; $i < $length; ++$i) {
            $char = $data[$i];

            if ('\\' === $char) {
                $next = $data[++$i] ?? '';

                if (isset($map[$next])) {
                    $out .= $map[$next];
                } elseif (preg_match('#\G[0-7]{1,3}#', $data, $octal, 0, $i)) {
                    $out .= \chr(octdec($octal[0]) & 0xFF);
                    $i += \strlen($octal[0]) - 1;
                } elseif ("\r" === $next || "\n" === $next) {
                    if ("\r" === $next && "\n" === ($data[$i + 1] ?? '')) {
                        ++$i;
                    }
                } else {
                    $out .= $next;
                }

                continue;
            }

            if ('(' === $char) {
                if ($depth++ > 0) {
                    $out .= $char;
                }

                continue;
            }

            if (')' === $char) {
                if (--$depth <= 0) {
                    return [$out, $i + 1];
                }

                $out .= $char;

                continue;
            }

            $out .= $char;
        }

        return [$out, $length];
    }

    private function toUtf8(string $value): string
    {
        if (str_starts_with($value, "\xFE\xFF")) {
            $converted = \function_exists('mb_convert_encoding') ? @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE') : @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($value, 2));

            return \is_string($converted) ? $converted : str_replace("\0", '', substr($value, 2));
        }

        return $value;
    }

    private function excerpt(string $code): string
    {
        // Nur druckbare Zeichen, damit Log und Backend lesbar bleiben
        $code = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]/', ' ', $code)));

        return \strlen($code) > 80 ? substr($code, 0, 79).'…' : ('' === $code ? '…' : $code);
    }

    /**
     * Entpackt alle Objekt-Streams nacheinander und untersucht sie einzeln.
     *
     * @return array{0: list<string>, 1: list<string>} Gründe (blockierend) und Hinweise
     */
    private function inspectObjectStreams(string $data, ScanFindings $findings): array
    {
        $problems = [];
        $notices = [];
        $total = 0;
        $offset = 0;
        $totalLimit = $this->maxDecompressedBytes > 0 ? $this->maxDecompressedBytes : self::AUTO_TOTAL_LIMIT;

        while (false !== ($pos = strpos($data, 'stream', $offset))) {
            $offset = $pos + 6;

            // "endstream" überspringen
            if ($pos >= 3 && 'end' === substr($data, $pos - 3, 3)) {
                continue;
            }

            // Dictionary vor dem Stream (ab dem letzten "obj")
            $windowStart = max(0, $pos - 4096);
            $window = substr($data, $windowStart, $pos - $windowStart);
            $objPos = strrpos($window, ' obj');

            $dict = false === $objPos ? $window : substr($window, $objPos);
            $dict = $this->decodeNames($dict);

            if (!preg_match('#/Type\s*/ObjStm(?![A-Za-z0-9])#', $dict)) {
                continue;
            }

            $id = false !== $objPos && preg_match('#(\d+)\s+(\d+)$#', substr($window, max(0, $objPos - 24), min(24, $objPos)), $idMatch)
                ? $idMatch[1].' '.$idMatch[2]
                : '@'.$pos;

            $label = $id.' obj';

            [$candidates, $end] = $this->readStreamData($data, $pos, $dict);

            if (null === $candidates) {
                $problems[] = ScanReason::make('objstm_unterminated', $label);
                break;
            }

            $offset = $end;

            if (preg_match('#/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+)#', $dict, $filter) && !preg_match('#^(?:/FlateDecode|\[\s*/FlateDecode\s*\])$#', trim($filter[1]))) {
                $problems[] = ScanReason::make('objstm_filter', $label, trim($filter[1]));
                continue;
            }

            $count = preg_match('#/N\s+(\d+)#', $dict, $n) ? (int) $n[1] : null;
            $first = preg_match('#/First\s+(\d+)#', $dict, $f) ? (int) $f[1] : null;

            if (empty($filter)) {
                $result = ['data' => $candidates[0], 'error' => null, 'complete' => true, 'exceeded' => false, 'consumed' => \strlen($candidates[0])];
            } else {
                $budget = min($this->streamBudget(), max(1048576, $totalLimit - $total));
                $result = null;

                foreach ($candidates as $raw) {
                    $result = $this->inflate($raw, $budget);

                    if (null === $result['error'] || $result['exceeded']) {
                        break;
                    }
                }

                if ($result['exceeded']) {
                    // Verhältnis der bisher entpackten zu den dafür gelesenen Daten
                    $consumed = max(1, $result['consumed']);
                    $ratio = (int) floor(\strlen($result['data']) / $consumed);

                    $problems[] = $ratio >= self::BOMB_RATIO
                        ? ScanReason::make('objstm_bomb', $label, $this->formatBytes($consumed), $this->formatBytes($budget), (string) $ratio)
                        : ScanReason::make('objstm_memory', $label, $this->formatBytes($budget), ResourceLimits::memoryLimit() > 0 ? $this->formatBytes(ResourceLimits::memoryLimit()) : '-1');
                    break;
                }
            }

            $decoded = $result['data'];
            $complete = $this->countStreamObjects($decoded, $count, $first);

            if (null !== $result['error'] || !$result['complete']) {
                // Fehler erst nach dem letzten Objekt (z. B. fehlende Prüfsumme oder
                // Füllbytes): Alle Objekte sind lesbar, so wie in jedem PDF-Programm.
                if (null !== $count && $complete >= $count) {
                    $notices[] = ScanReason::make('objstm_repaired', $label, (string) $count);
                } else {
                    $problems[] = ScanReason::make('objstm_corrupt', $label, $this->formatBytes($result['consumed']), $this->formatBytes(\strlen($candidates[0])), (string) $complete, null === $count ? '?' : (string) $count, $result['error'] ?? 'unexpected end of data');
                    continue;
                }
            }

            $total += \strlen($decoded);

            $this->inspect($decoded, $findings);
            unset($decoded, $result);

            if ($total >= $totalLimit) {
                $problems[] = ScanReason::make('objstm_total', $this->formatBytes($totalLimit));
                break;
            }
        }

        return [array_values(array_unique($problems)), array_values(array_unique($notices))];
    }

    /**
     * Liest die Rohdaten eines Streams. Bevorzugt über /Length, sonst bis
     * "endstream". Ohne /Length ist nicht eindeutig, ob ein abschließendes
     * Zeilenende zu den Daten gehört, daher werden beide Varianten geliefert.
     *
     * @return array{0: list<string>|null, 1: int} Kandidaten und Position nach dem Stream
     */
    private function readStreamData(string $data, int $streamKeywordPos, string $dict): array
    {
        $start = $streamKeywordPos + 6;

        if ("\r" === ($data[$start] ?? '')) {
            ++$start;
        }

        if ("\n" === ($data[$start] ?? '')) {
            ++$start;
        }

        // Nur direkte Längenangaben ("/Length 812"), keine Referenzen ("/Length 12 0 R")
        if (preg_match('#/Length\s+(\d++)(?!\s+\d+\s+R)#', $dict, $length)) {
            $end = $start + (int) $length[1];

            if (str_starts_with(ltrim(substr($data, $end, 64), "\r\n\t\f\0 "), 'endstream')) {
                return [[substr($data, $start, (int) $length[1])], $end];
            }
        }

        $end = strpos($data, 'endstream', $start);

        if (false === $end) {
            return [null, \strlen($data)];
        }

        $raw = substr($data, $start, $end - $start);
        $candidates = [$raw];

        foreach (["\r\n", "\n", "\r"] as $eol) {
            if (str_ends_with($raw, $eol)) {
                array_unshift($candidates, substr($raw, 0, -\strlen($eol)));
                break;
            }
        }

        return [$candidates, $end + 9];
    }

    /**
     * Entpackt Flate-Daten schrittweise. Bricht ab, sobald das Budget überschritten
     * wird, und liefert bei beschädigten Daten den bis dahin lesbaren Teil.
     *
     * @return array{data: string, error: string|null, complete: bool, exceeded: bool, consumed: int}
     */
    private function inflate(string $raw, int $budget): array
    {
        $result = ['data' => '', 'error' => null, 'complete' => false, 'exceeded' => false, 'consumed' => 0];

        if ('' === $raw) {
            $result['error'] = 'empty stream';

            return $result;
        }

        if (!ResourceLimits::functionAvailable('inflate_init')) {
            return $this->inflateFallback($raw, $budget);
        }

        // zlib-Header vorhanden? Sonst roher Deflate-Strom (kommt in der Praxis vor)
        $header = \strlen($raw) >= 2 ? (\ord($raw[0]) << 8) | \ord($raw[1]) : 0;
        $encoding = 0x08 === (\ord($raw[0]) & 0x0F) && 0 === $header % 31 ? ZLIB_ENCODING_DEFLATE : ZLIB_ENCODING_RAW;
        $context = @inflate_init($encoding);

        if (false === $context) {
            $result['error'] = 'zlib not available';

            return $result;
        }

        $chunkSize = 8192;
        $length = \strlen($raw);

        for ($i = 0; $i < $length; $i += $chunkSize) {
            error_clear_last();

            $out = @inflate_add($context, substr($raw, $i, $chunkSize), ZLIB_SYNC_FLUSH);

            if (false === $out) {
                $result['error'] = error_get_last()['message'] ?? 'data error';
                $result['error'] = (string) preg_replace('/^inflate_add\(\): /', '', $result['error']);

                return $result;
            }

            $result['data'] .= $out;
            $result['consumed'] = min($length, $i + $chunkSize);

            if (\strlen($result['data']) > $budget) {
                $result['exceeded'] = true;

                return $result;
            }

            if (ZLIB_STREAM_END === inflate_get_status($context)) {
                $result['complete'] = true;

                return $result;
            }
        }

        return $result;
    }

    /**
     * Ohne inflate_init() (sehr alte oder eingeschränkte zlib-Builds): in einem
     * Schritt entpacken, mit dem Budget als Obergrenze.
     *
     * @return array{data: string, error: string|null, complete: bool, exceeded: bool, consumed: int}
     */
    private function inflateFallback(string $raw, int $budget): array
    {
        if (!ResourceLimits::functionAvailable('zlib_decode')) {
            return ['data' => '', 'error' => 'PHP extension zlib missing', 'complete' => false, 'exceeded' => false, 'consumed' => 0];
        }

        $data = @zlib_decode($raw, $budget + 1);

        if (false === $data) {
            $data = @gzinflate($raw, $budget + 1);
        }

        if (false === $data) {
            return ['data' => '', 'error' => (string) preg_replace('/^\w+\(\): /', '', error_get_last()['message'] ?? 'data error'), 'complete' => false, 'exceeded' => false, 'consumed' => 0];
        }

        return ['data' => $data, 'error' => null, 'complete' => true, 'exceeded' => \strlen($data) > $budget, 'consumed' => \strlen($raw)];
    }

    /**
     * Zählt die Objekte eines Objekt-Streams, deren Anfang in den entpackten Daten liegt.
     */
    private function countStreamObjects(string $decoded, int|null $count, int|null $first): int
    {
        if (null === $count || null === $first || $first > \strlen($decoded)) {
            return 0;
        }

        preg_match_all('#(\d+)\s+(\d+)#', substr($decoded, 0, $first), $pairs, PREG_SET_ORDER);

        $available = 0;

        foreach (\array_slice($pairs, 0, $count) as $pair) {
            if ($first + (int) $pair[2] < \strlen($decoded)) {
                ++$available;
            }
        }

        return $available;
    }

    /**
     * Speicher pro entpacktem Stream: ein Viertel des freien memory_limit (Suche und
     * Namensdekodierung benötigen zusätzliche Kopien).
     */
    private function streamBudget(): int
    {
        $free = ResourceLimits::freeMemory();

        if (null === $free) {
            return self::UNLIMITED_STREAM_BUDGET;
        }

        return (int) max(4 * 1048576, min(self::UNLIMITED_STREAM_BUDGET, $free / 4));
    }

    private function formatBytes(int $bytes): string
    {
        return ResourceLimits::formatBytes($bytes);
    }

    /**
     * Sucht einen PDF-Namen (/Name), auch wenn einzelne Zeichen als #xx maskiert sind.
     */
    private function containsName(string $haystack, string $name): bool
    {
        $pattern = '';

        foreach (str_split($name) as $char) {
            $hex = strtoupper(bin2hex($char));
            $pattern .= '(?:'.preg_quote($char, '#').'|\#'.$hex[0].'['.$hex[1].strtolower($hex[1]).'])';
        }

        $match = preg_match('#/'.$pattern.'(?=[\s/\[\]<>(){}%]|$)#', $haystack);

        // PCRE-Fehler (z. B. Backtrack-Limit): fail closed
        return 0 !== $match;
    }

    private function decodeNamesIfNeeded(string $value): string
    {
        return str_contains($value, '#') ? $this->decodeNames($value) : $value;
    }

    private function decodeNames(string $value): string
    {
        $decoded = preg_replace_callback(
            '#/[^\s/\[\]<>(){}%]+#',
            static fn (array $m): string => (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn ($h) => \chr((int) hexdec($h[1])), $m[0]),
            $value,
        );

        // PCRE-Limit erreicht: Inhalt gilt als nicht vollständig prüfbar
        if (null === $decoded) {
            $this->pcreFailed = true;

            return $value;
        }

        return $decoded;
    }

    /**
     * @return array{0: list<string>, 1: string|null, 2: bool}
     */
    private function virusScan(string $path, string $data): array
    {
        if ('off' === $this->virusScan) {
            return [[], null, true];
        }

        if (null !== $this->clamavSocket && '' !== $this->clamavSocket) {
            try {
                $response = $this->scanViaSocket($this->clamavSocket, $data);

                if (str_ends_with($response, 'OK')) {
                    return [[], 'clamd', true];
                }

                if (str_ends_with($response, 'FOUND')) {
                    return [[ScanReason::make('virus', 'clamd', trim(substr($response, strpos($response, ':') + 1)))], 'clamd', true];
                }

                return [[ScanReason::make('virus_failed', 'clamd', $response)], 'clamd', false];
            } catch (\Throwable $exception) {
                return [[ScanReason::make('virus_failed', 'clamd', $exception->getMessage())], 'clamd', false];
            }
        }

        $binary = $this->getClamBinary();

        if (false === $binary) {
            if ($this->isVirusScanRequired()) {
                return [[ScanReason::make('no_scanner')], null, false];
            }

            return [[], null, true];
        }

        $name = pathinfo($binary, PATHINFO_FILENAME);

        $command = 'clamdscan' === $name
            ? [$binary, '--no-summary', '--infected', '--stream', $path]
            : [$binary, '--no-summary', '--infected', $path];

        $process = new Process($command);
        $process->setTimeout(300);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return [[ScanReason::make('virus_failed', $name, $exception->getMessage())], $name, false];
        }

        return match ($process->getExitCode()) {
            0 => [[], $name, true],
            1 => [[ScanReason::make('virus', $name, trim(str_replace($path.':', '', $process->getOutput())))], $name, true],
            default => [[ScanReason::make('virus_failed', $name, trim($process->getErrorOutput() ?: $process->getOutput()))], $name, false],
        };
    }

    private function scanViaSocket(string $socket, string $data): string
    {
        if (!str_contains($socket, '://')) {
            $socket = 'unix://'.$socket;
        }

        $handle = @stream_socket_client($socket, $errno, $errstr, 10);

        if (!$handle) {
            throw new \RuntimeException(\sprintf('clamd not reachable (%s): %s', $socket, $errstr));
        }

        stream_set_timeout($handle, 300);
        fwrite($handle, "zINSTREAM\0");

        foreach (str_split($data, 65536) as $chunk) {
            fwrite($handle, pack('N', \strlen($chunk)).$chunk);
        }

        fwrite($handle, pack('N', 0));

        $response = trim((string) stream_get_contents($handle), "\0\r\n ");

        fclose($handle);

        return $response;
    }

    private function getClamBinary(): false|string
    {
        if (null !== $this->clamBinary) {
            return $this->clamBinary;
        }

        if ('off' === $this->virusScan || !\function_exists('proc_open')) {
            return $this->clamBinary = false;
        }

        if (null !== $this->clamavBinary && '' !== $this->clamavBinary) {
            return $this->clamBinary = is_executable($this->clamavBinary) ? $this->clamavBinary : false;
        }

        $finder = new ExecutableFinder();

        foreach (['clamdscan', 'clamscan'] as $name) {
            if ($found = $finder->find($name, null, CliPdfPreviewProvider::EXTRA_DIRS)) {
                return $this->clamBinary = $found;
            }
        }

        return $this->clamBinary = false;
    }

    private function getConfigHash(): string
    {
        return md5(serialize([
            $this->enabled,
            $this->maxFileSize,
            $this->blockedFeatures,
            $this->allowEncrypted,
            $this->virusScan,
            $this->requireVirusScan,
            $this->clamavSocket,
            $this->clamavBinary,
            $this->hasVirusScanner(),
            $this->maxDecompressedBytes,
            $this->allowFormFeatures,
        ]));
    }
}
