<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Security;

final class UnsafePdfException extends \RuntimeException
{
    public function __construct(
        private readonly string $path,
        private readonly ScanResult $result,
    ) {
        parent::__construct(\sprintf('PDF file "%s" was blocked by the security check: %s', basename($path), ScanReason::formatAll($result->reasons)));
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getResult(): ScanResult
    {
        return $this->result;
    }
}
