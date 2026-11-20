<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

/**
 * Zwischenergebnisse der Prüfung, gesammelt über die Datei und alle
 * entpackten Objekt-Streams.
 *
 * @internal
 */
final class ScanFindings
{
    public bool $encrypted = false;

    /**
     * @var array<string, true>
     */
    public array $features = [];

    public int $pages = 0;

    /**
     * @var list<string> JavaScript-Code aus /JS-Einträgen
     */
    public array $scripts = [];

    /**
     * @var array<string, string> Verweise auf JavaScript in eigenen Objekten ("12 0")
     */
    public array $scriptRefs = [];
}
