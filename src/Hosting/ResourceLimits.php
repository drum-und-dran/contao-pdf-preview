<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Hosting;

/**
 * Ermittelt die tatsächlich verfügbaren Ressourcen der PHP-Umgebung, damit
 * Prüfung, Rendering und Bereinigung sich an jedes Hosting anpassen – vom
 * knappen Shared Hosting bis zum eigenen Server.
 */
final class ResourceLimits
{
    /**
     * memory_limit in Bytes, -1 = unbegrenzt.
     */
    public static function memoryLimit(): int
    {
        return self::parseBytes(self::ini('memory_limit'));
    }

    /**
     * Noch freier Arbeitsspeicher in Bytes, null = unbegrenzt.
     */
    public static function freeMemory(): int|null
    {
        $limit = self::memoryLimit();

        return $limit <= 0 ? null : max(0, $limit - memory_get_usage(true));
    }

    /**
     * max_execution_time in Sekunden, 0 = unbegrenzt (z. B. Kommandozeile).
     */
    public static function timeLimit(): int
    {
        return \PHP_SAPI === 'cli' ? 0 : max(0, (int) self::ini('max_execution_time'));
    }

    /**
     * Zeitpunkt, bis zu dem längere Arbeiten (Prüfung aller PDFs, Bereinigung)
     * laufen dürfen. Versucht zuerst, das Limit zu verlängern; gelingt das nicht,
     * bleibt ein Sicherheitsabstand für den Rest der Anfrage.
     */
    public static function deadline(float $share = 0.5): float|null
    {
        $limit = self::timeLimit();

        if (0 === $limit) {
            return null;
        }

        if (\function_exists('set_time_limit') && @set_time_limit(max($limit, 300))) {
            return microtime(true) + 300 * $share;
        }

        $elapsed = isset($_SERVER['REQUEST_TIME_FLOAT']) ? microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;

        return microtime(true) + max(1.0, ($limit - $elapsed) * $share);
    }

    public static function functionAvailable(string $name): bool
    {
        if (!\function_exists($name)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', strtolower(self::ini('disable_functions'))));

        return !\in_array(strtolower($name), $disabled, true);
    }

    /**
     * Freier Speicherplatz in Bytes oder null, wenn nicht ermittelbar.
     */
    public static function freeDiskSpace(string $path): int|null
    {
        if (!self::functionAvailable('disk_free_space')) {
            return null;
        }

        $free = @disk_free_space($path);

        return false === $free ? null : (int) $free;
    }

    public static function uploadLimit(): int
    {
        $limits = array_filter([self::parseBytes(self::ini('upload_max_filesize')), self::parseBytes(self::ini('post_max_size'))], static fn (int $v): bool => $v > 0);

        return $limits ? min($limits) : -1;
    }

    public static function parseBytes(string $value): int
    {
        $value = trim($value);

        if ('' === $value || '-1' === $value) {
            return -1;
        }

        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function formatBytes(float|int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes <= 0) {
            return '0 KB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }

    private static function ini(string $key): string
    {
        return \function_exists('ini_get') ? (string) @\ini_get($key) : '';
    }
}
