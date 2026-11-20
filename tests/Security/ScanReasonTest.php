<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

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
        $this->assertSame('Active content /Launch: starts a program or opens a file', ScanReason::format(ScanReason::make('feature', 'Launch')));
        $this->assertSame('Active content /Custom', ScanReason::format(ScanReason::make('feature', 'Custom')));
        $this->assertSame('No end-of-file marker (%EOF) – file is incomplete or manipulated', ScanReason::format(ScanReason::make('no_eof')));
        $this->assertSame('File is 60 MB and exceeds the limit of 50 MB (contao_pdf_preview.security.max_file_size)', ScanReason::format(ScanReason::make('size_limit', '60 MB', '50 MB')));
    }

    public function testUsesContaoLanguageFile(): void
    {
        include __DIR__.'/../../contao/languages/de/default.php';

        $this->assertSame('Virenscanner (clamd): Eicar-Signature FOUND', ScanReason::format(ScanReason::make('virus', 'clamd', 'Eicar-Signature FOUND')));
    }

    public function testGermanAndEnglishTextsAreComplete(): void
    {
        foreach (['de', 'en'] as $language) {
            $GLOBALS['TL_LANG'] = [];
            include __DIR__.'/../../contao/languages/'.$language.'/default.php';
            $lang = $GLOBALS['TL_LANG']['dud_pdf_preview'];

            foreach (['reason', 'feature', 'hosting', 'cli'] as $group) {
                $this->assertNotEmpty($lang[$group] ?? null, $language.': '.$group);
            }

            $other = 'de' === $language ? 'en' : 'de';
            $GLOBALS['TL_LANG'] = [];
            include __DIR__.'/../../contao/languages/'.$other.'/default.php';

            foreach (['reason', 'feature', 'hosting', 'cli', 'error'] as $group) {
                $this->assertSame(array_keys($GLOBALS['TL_LANG']['dud_pdf_preview'][$group]), array_keys($lang[$group]), $language.'/'.$other.': '.$group);
            }
        }
    }

    public function testLegacyReasonWithoutParametersDoesNotFail(): void
    {
        include __DIR__.'/../../contao/languages/de/default.php';

        $this->assertStringContainsString('Kompressionsverfahren ?', ScanReason::format('objstm_filter'));
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
