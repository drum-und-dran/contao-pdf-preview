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
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly bool $safe,
        public readonly array $reasons = [],
        public readonly string|null $virusScanner = null,
        public readonly int|null $pageCount = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'safe' => $this->safe,
            'reasons' => $this->reasons,
            'virusScanner' => $this->virusScanner,
            'pageCount' => $this->pageCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['safe'] ?? false),
            array_values(array_map('strval', (array) ($data['reasons'] ?? []))),
            isset($data['virusScanner']) ? (string) $data['virusScanner'] : null,
            isset($data['pageCount']) ? (int) $data['pageCount'] : null,
        );
    }
}
