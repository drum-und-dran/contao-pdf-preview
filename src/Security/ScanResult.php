<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

final class ScanResult
{
    /**
     * @param list<string> $reasons Gründe für die Sperre
     * @param list<string> $notices Hinweise ohne Sperre (z. B. Formular-JavaScript)
     */
    public function __construct(
        public readonly bool $safe,
        public readonly array $reasons = [],
        public readonly string|null $virusScanner = null,
        public readonly int|null $pageCount = null,
        public readonly array $notices = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'safe' => $this->safe,
            'reasons' => $this->reasons,
            'virusScanner' => $this->virusScanner,
            'pageCount' => $this->pageCount,
            'notices' => $this->notices,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['safe'] ?? false),
            array_values(array_map('strval', (array) ($data['reasons'] ?? []))),
            isset($data['virusScanner']) ? (string) $data['virusScanner'] : null,
            isset($data['pageCount']) ? (int) $data['pageCount'] : null,
            array_values(array_map('strval', (array) ($data['notices'] ?? []))),
        );
    }
}
