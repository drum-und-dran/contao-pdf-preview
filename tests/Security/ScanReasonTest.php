<?php

declare(strict_types=1);

namespace Dud\ContaoPdfPreview\Tests\Security;

use Dud\ContaoPdfPreview\Security\ScanReason;
use PHPUnit\Framework\TestCase;

class ScanReasonTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testFormatsWithEnglishFallback(): void
    {
        $this->assertSame('Active/dangerous content: /JavaScript', ScanReason::format(ScanReason::make('feature', 'JavaScript')));
        $this->assertSame('No end-of-file marker (%EOF) – file is incomplete or manipulated', ScanReason::format(ScanReason::make('no_eof')));
    }

    public function testUsesContaoLanguageFile(): void
    {
        include __DIR__.'/../../contao/languages/de/default.php';

        $this->assertSame('Virenscanner (clamd): Eicar-Signature FOUND', ScanReason::format(ScanReason::make('virus', 'clamd', 'Eicar-Signature FOUND')));
    }

    public function testParametersCannotBreakTheFormat(): void
    {
        $reason = ScanReason::make('virus', 'clamd', 'a|b');

        $this->assertSame(['virus', ['clamd', 'a/b']], ScanReason::parse($reason));
    }

    public function testUnknownTextIsReturnedUnchanged(): void
    {
        $this->assertSame('Irgendein alter Text', ScanReason::format('Irgendein alter Text'));
    }
}
