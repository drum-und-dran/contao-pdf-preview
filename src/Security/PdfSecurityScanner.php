<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

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
 *     komprimierten Objekt-Streams; nicht prüfbare Objekt-Streams = blockiert
 *  5. Virenscan mit ClamAV (clamd-Socket, clamdscan oder clamscan)
 *
 * Grundsatz: fail closed – im Zweifel wird blockiert.
 */
class PdfSecurityScanner
{
    private const CACHE_VERSION = 'v3';

    /**
     * @var array<string, ScanResult>
     */
    private array $memory = [];

    private string|false|null $clamBinary = null;

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
        private readonly int $maxDecompressedBytes = 104857600,
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

        // Der Inhalts-Hash sorgt dafür, dass auch per FTP ersetzte Dateien mit
        // beibehaltenem Zeitstempel und gleicher Größe neu geprüft werden
        $key = 'dud_pdf_scan_'.hash('sha256', implode('|', [
            self::CACHE_VERSION,
            realpath($path),
            (string) filemtime($path),
            (string) filesize($path),
            (string) @hash_file('xxh128', $path),
            $this->getConfigHash(),
        ]));

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

        [$result, $cacheable] = $this->doScan($path);

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
    private function doScan(string $path): array
    {
        if (!$this->enabled) {
            return [new ScanResult(true, [], null, null), false];
        }

        $reasons = [];
        $size = (int) filesize($path);

        // Ebene 1: Größe
        if ($size > $this->maxFileSize) {
            return [new ScanResult(false, [ScanReason::make('too_large', (string) (int) round($this->maxFileSize / 1048576))]), true];
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
            $reasons[] = ScanReason::make('no_header');
        }

        if (!str_contains(substr($data, -65536), '%%EOF')) {
            $reasons[] = ScanReason::make('no_eof');
        }

        if ($reasons) {
            return [new ScanResult(false, $reasons), true];
        }

        // Komprimierte Objekt-Streams entpacken, damit dort versteckte Objekte
        // ebenfalls geprüft werden
        [$objectStreams, $streamProblems] = $this->extractObjectStreams($data);
        $reasons = [...$reasons, ...$streamProblems];
        $haystacks = [$data, ...$objectStreams];

        // Ebene 3: Verschlüsselung
        if (!$this->allowEncrypted && $this->containsName($haystacks, 'Encrypt')) {
            $reasons[] = ScanReason::make('encrypted');
        }

        // Ebene 4: aktive Inhalte
        foreach ($this->blockedFeatures as $feature) {
            $feature = ltrim($feature, '/');

            if ('' !== $feature && $this->containsName($haystacks, $feature)) {
                $reasons[] = ScanReason::make('feature', $feature);
            }
        }

        $pageCount = $this->countPages($haystacks);
        unset($haystacks, $objectStreams);

        if ($reasons) {
            return [new ScanResult(false, $reasons, null, $pageCount), true];
        }

        // Ebene 5: Virenscan
        [$virusReasons, $scanner, $definitive] = $this->virusScan($path, $data);
        unset($data);

        if ($virusReasons) {
            return [new ScanResult(false, $virusReasons, $scanner, $pageCount), $definitive];
        }

        return [new ScanResult(true, [], $scanner, $pageCount), $definitive];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function extractObjectStreams(string $data): array
    {
        $streams = [];
        $problems = [];
        $total = 0;
        $offset = 0;

        while (false !== ($pos = strpos($data, 'stream', $offset))) {
            $offset = $pos + 6;

            // "endstream" überspringen
            if ($pos >= 3 && 'end' === substr($data, $pos - 3, 3)) {
                continue;
            }

            // Dictionary vor dem Stream (bis zum letzten "obj")
            $windowStart = max(0, $pos - 4096);
            $window = substr($data, $windowStart, $pos - $windowStart);
            $objPos = strrpos($window, ' obj');
            $dict = false === $objPos ? $window : substr($window, $objPos);
            $dict = $this->decodeNames($dict);

            if (!preg_match('#/Type\s*/ObjStm(?![A-Za-z0-9])#', $dict)) {
                continue;
            }

            $start = $pos + 6;

            if ("\r" === ($data[$start] ?? '')) {
                ++$start;
            }

            if ("\n" === ($data[$start] ?? '')) {
                ++$start;
            }

            $end = strpos($data, 'endstream', $start);

            if (false === $end) {
                $problems[] = ScanReason::make('objstm_broken');
                break;
            }

            $raw = substr($data, $start, $end - $start);
            $offset = $end + 9;

            if (!preg_match('#/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+)#', $dict, $filter)) {
                $decoded = $raw;
            } elseif (preg_match('#^(?:/FlateDecode|\[\s*/FlateDecode\s*\])$#', trim($filter[1]))) {
                $decoded = @zlib_decode(rtrim($raw, "\r\n"), max(1, $this->maxDecompressedBytes - $total));

                if (false === $decoded) {
                    $problems[] = ScanReason::make('objstm_undecodable');
                    continue;
                }
            } else {
                $problems[] = ScanReason::make('objstm_filter', trim($filter[1]));
                continue;
            }

            $total += \strlen($decoded);

            if ($total >= $this->maxDecompressedBytes) {
                $problems[] = ScanReason::make('objstm_limit');
                break;
            }

            $streams[] = $decoded;
        }

        return [$streams, array_values(array_unique($problems))];
    }

    /**
     * Sucht einen PDF-Namen (/Name), auch wenn einzelne Zeichen als #xx maskiert sind.
     *
     * @param list<string> $haystacks
     */
    private function containsName(array $haystacks, string $name): bool
    {
        $pattern = '';

        foreach (str_split($name) as $char) {
            $hex = strtoupper(bin2hex($char));
            $pattern .= '(?:'.preg_quote($char, '#').'|\#'.$hex[0].'['.$hex[1].strtolower($hex[1]).'])';
        }

        $pattern = '#/'.$pattern.'(?=[\s/\[\]<>(){}%]|$)#';

        foreach ($haystacks as $haystack) {
            $match = preg_match($pattern, $haystack);

            if (false === $match) {
                // PCRE-Fehler (z. B. Backtrack-Limit): fail closed
                return true;
            }

            if (1 === $match) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $haystacks
     */
    private function countPages(array $haystacks): int|null
    {
        $count = 0;

        foreach ($haystacks as $haystack) {
            $count += (int) preg_match_all('#/Type\s*/Page(?![A-Za-z0-9])#', $this->decodeNamesIfNeeded($haystack));
        }

        return $count > 0 ? $count : null;
    }

    private function decodeNamesIfNeeded(string $value): string
    {
        return str_contains($value, '#') ? $this->decodeNames($value) : $value;
    }

    private function decodeNames(string $value): string
    {
        return (string) preg_replace_callback(
            '#/[^\s/\[\]<>(){}%]+#',
            static fn (array $m): string => (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn ($h) => \chr((int) hexdec($h[1])), $m[0]),
            $value,
        );
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

    private function getClamBinary(): string|false
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
        ]));
    }
}
