<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Tests\Security;

use Dud\ContaoPdfPreview\Security\PdfApprovals;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Dud\ContaoPdfPreview\Tests\PdfFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class PdfSecurityScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/dud-pdf-scan-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testCleanPdfIsSafeAndPagesAreCounted(): void
    {
        $result = $this->scanner()->scan($this->file(PdfFactory::clean(3)));

        $this->assertTrue($result->safe, implode(', ', $result->reasons));
        $this->assertSame(3, $result->pageCount);
    }

    #[DataProvider('unsafeProvider')]
    public function testUnsafePdfsAreBlocked(string $content, string $expectedReason): void
    {
        $result = $this->scanner()->scan($this->file($content));

        $this->assertFalse($result->safe);
        $this->assertStringContainsString($expectedReason, implode(' | ', $result->reasons));

        // Jeder Grund muss sich vollständig in einen Text übersetzen lassen
        foreach ($result->reasons as $reason) {
            $this->assertDoesNotMatchRegularExpression('/%(?:\d+\$)?s|\?/', ScanReason::format($reason));
        }
    }

    public static function unsafeProvider(): iterable
    {
        yield 'custom javascript' => [PdfFactory::withObject('<< /S /JavaScript /JS (app.alert\\(1\\)) >>'), 'script|app.alert(1)'];
        yield 'escaped name' => [PdfFactory::withObject('<< /S /J#61vaScr#69pt /JS (x) >>'), 'script|x'];
        yield 'hex encoded javascript' => [PdfFactory::withObject('<< /S /JavaScript /JS <'.bin2hex('this.exportDataObject({cName: "a"})').'> >>'), 'script|this.exportDataObject'];
        yield 'standard function mixed with custom code' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFNumber_Format\\(2, 0, 0, 0, "", true\\); app.launchURL\\("http://x"\\);) >>'), 'script|'];
        yield 'standard function with expression argument' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFSimple_Calculate\\(app.alert\\(1\\)\\);) >>'), 'script|'];
        yield 'code hidden between comment markers in strings' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFDate_FormatEx\\("/*"\\); app.alert\\(1\\); AFDate_FormatEx\\("*/"\\);) >>'), 'script|'];
        yield 'line comment' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFDate_FormatEx\\("x"\\); // harmless) >>'), 'script|'];
        yield 'unknown AF lookalike function' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFEvil_Run\\("x"\\);) >>'), 'script|'];
        yield 'unicode escape outside string' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFDate_FormatEx\\("x"\\); \\\\u0061pp.alert\\(1\\);) >>'), 'script|'];
        yield 'template literal' => [PdfFactory::withObject('<< /S /JavaScript /JS (AFDate_FormatEx\\(`${app.alert\\(1\\)}`\\);) >>'), 'script|'];
        yield 'javascript name without code' => [PdfFactory::withObject('<< /Names << /JavaScript 9 0 R >> >>'), 'feature|JavaScript'];
        yield 'launch' => [PdfFactory::withObject('<< /S /Launch /F (cmd.exe) >>'), 'feature|Launch'];
        yield 'embedded file' => [PdfFactory::withObject('<< /Type /EmbeddedFile /Length 0 >>'), 'feature|EmbeddedFile'];
        yield 'xfa' => [PdfFactory::withObject('<< /XFA 5 0 R >>'), 'feature|XFA'];
        yield 'hidden in object stream' => [PdfFactory::objectStream('4 0 << /S /J#61vaScript /J#53 (x) >>'), 'script|x'];
        yield 'uninspectable object stream' => [PdfFactory::objectStream('4 0 << >>', '/LZWDecode'), 'objstm_filter|'];
        yield 'encrypted' => [PdfFactory::withObject('<< /Filter /Standard /V 1 /R 2 /O <00> /U <00> /P -4 >>', '/Encrypt 4 0 R '), 'encrypted'];
        yield 'polyglot' => [PdfFactory::build(['<< /Type /Catalog >>'], '', "GIF89a\n"), 'header_offset|7|GIF89a'];
        yield 'truncated' => [substr(PdfFactory::clean(), 0, -8), 'eof_missing|'];
        yield 'not a pdf' => [str_repeat('A', 100), 'not_pdf|"AAAAAAAAAAAAAAAA"'];
        yield 'png named pdf' => ["\x89PNG\r\n\x1a\n".str_repeat("\0", 64), 'not_pdf|PNG'];
        yield 'corrupt object stream' => [PdfFactory::objectStreamRaw(substr((string) gzcompress(str_repeat('4 0 << /Type /Font >> ', 200)), 0, 20).str_repeat("\x00\xFF", 40), 3, 12), 'objstm_corrupt|'];
    }

    public function testCompressionBombIsDetectedByRatio(): void
    {
        $scanner = $this->scanner(maxDecompressed: 1024 * 1024);
        $result = $scanner->scan($this->file(PdfFactory::objectStream(str_repeat('0', 5 * 1024 * 1024))));

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('objstm_bomb|', implode(' ', $result->reasons));
    }

    public function testLargeRegularStreamIsNotCalledACompressionBomb(): void
    {
        // Zufallsdaten lassen sich kaum komprimieren (Verhältnis etwa 1:1)
        $content = '4 0 '.base64_encode(random_bytes(3 * 1024 * 1024));
        $result = $this->scanner(maxDecompressed: 1024 * 1024)->scan($this->file(PdfFactory::objectStream($content)));

        $this->assertFalse($result->safe);
        $this->assertStringNotContainsString('objstm_bomb', implode(' ', $result->reasons));
    }

    /**
     * Regression 1.0.4: Endeten die komprimierten Daten mit dem Byte 0x0D oder 0x0A, wurde
     * es als Zeilenende abgeschnitten und der Stream galt als "nicht entpackbar".
     */
    #[DataProvider('lineBreakProvider')]
    public function testCompressedDataEndingWithLineBreakByteIsDecoded(string $lastByte, bool $withLength, string $eol): void
    {
        [, $compressed] = PdfFactory::contentEndingCompressedWith($lastByte);
        $result = $this->scanner()->scan($this->file(PdfFactory::objectStreamRaw($compressed, 1, 4, $withLength, $eol)));

        $this->assertTrue($result->safe, implode(', ', $result->reasons));
    }

    public static function lineBreakProvider(): iterable
    {
        foreach (["\r" => 'CR', "\n" => 'LF'] as $byte => $name) {
            foreach (["\r\n", "\n", "\r"] as $eol) {
                yield $name.' with /Length, EOL '.bin2hex($eol) => [$byte, true, $eol];
                yield $name.' without /Length, EOL '.bin2hex($eol) => [$byte, false, $eol];
            }
        }
    }

    public function testIndirectLengthIsHandled(): void
    {
        $compressed = (string) gzcompress('4 0 << /Type /Font >>');
        $pdf = PdfFactory::withObject('<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length 12 0 R >>'."\nstream\n".$compressed."\nendstream");

        $this->assertTrue($this->scanner()->scan($this->file($pdf))->safe);
    }

    public function testRawDeflateAndTrailingGarbageAreAccepted(): void
    {
        $content = '4 0 << /Type /Font >>';

        $this->assertTrue($this->scanner()->scan($this->file(PdfFactory::objectStreamRaw((string) gzdeflate($content))))->safe);

        // Vollständiger Stream, danach Füllbytes (kommt bei manchen Erzeugern vor)
        $result = $this->scanner()->scan($this->file(PdfFactory::objectStreamRaw(gzcompress($content)."\x00\x00\x00")));
        $this->assertTrue($result->safe, implode(', ', $result->reasons));
    }

    public function testMissingChecksumAfterLastObjectIsTolerated(): void
    {
        $content = '4 0 << /Type /Font /BaseFont /Helvetica >>';
        $compressed = substr((string) gzcompress($content), 0, -4);
        $result = $this->scanner()->scan($this->file(PdfFactory::objectStreamRaw($compressed)));

        $this->assertTrue($result->safe, implode(', ', $result->reasons));
    }

    public function testFillableFormWithStandardFunctionsIsSafe(): void
    {
        // Wie in Formularen aus Acrobat/LibreOffice: Datums- und Zahlenformate
        $content = '4 0 5 60 << /JS (AFDate_FormatEx\\("dd.m.yyyy"\\);)/S/JavaScript>> << /AA << /F << /JS (AFNumber_Format\\(2, 0, 0, 0, "\\u20ac", true\\);) /S /JavaScript >> /K << /JS <'.bin2hex('AFNumber_Keystroke(2, 0, 0, 0, "", true);').'> /S /JavaScript >> /C << /JS (AFSimple_Calculate\\("SUM", new Array \\("a", "b"\\)\\);) /S /JavaScript >> >> >>';
        $result = $this->scanner()->scan($this->file(PdfFactory::objectStream($content)));

        $this->assertTrue($result->safe, implode(', ', $result->reasons));
        $this->assertStringContainsString('form_scripts|4|AFDate_FormatEx, AFNumber_Format, AFNumber_Keystroke, AFSimple_Calculate', implode(' ', $result->notices));
    }

    public function testFormScriptInSeparateStreamObjectIsInspected(): void
    {
        $code = (string) gzcompress('AFDate_KeystrokeEx("dd.mm.yyyy");');

        $pdf = PdfFactory::build([
            '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [4 0 R] >> >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
            '<< /FT /Tx /AA << /K << /S /JavaScript /JS 5 0 R >> >> >>',
            '<< /Length '.\strlen($code)." /Filter /FlateDecode >>\nstream\n".$code."\nendstream",
        ]);

        $result = $this->scanner()->scan($this->file($pdf));
        $this->assertTrue($result->safe, implode(', ', $result->reasons));

        $evil = (string) gzcompress('app.launchURL("http://example.org");');
        $pdf = str_replace(['/Length '.\strlen($code), $code], ['/Length '.\strlen($evil), $evil], $pdf);
        $this->assertStringContainsString('script|app.launchURL', implode(' ', $this->scanner()->scan($this->file($pdf))->reasons));
    }

    public function testJavaScriptIsReportedOnceAndFormFeaturesCanBeDisabled(): void
    {
        $pdf = PdfFactory::withObject('<< /S /JavaScript /JS (AFDate_FormatEx\\("dd.m.yyyy"\\);) >>');
        $result = $this->scanner(allowFormFeatures: false)->scan($this->file($pdf));

        $this->assertFalse($result->safe);
        $this->assertSame(['feature|JavaScript'], $result->reasons);
    }

    public function testSubmitFormIsANotice(): void
    {
        $result = $this->scanner()->scan($this->file(PdfFactory::withObject('<< /S /SubmitForm /F (https://example.org) >>')));

        $this->assertTrue($result->safe);
        $this->assertContains('form_submit', $result->notices);
    }

    public function testApprovalReleasesFindingsButKeepsThemVisible(): void
    {
        $approvals = new PdfApprovals($this->dir.'/approved.json');
        $file = $this->file(PdfFactory::withObject('<< /S /JavaScript /JS (app.alert\\(1\\)) >>'));

        $this->assertFalse($this->scanner(approvals: $approvals)->scan($file)->safe);

        $approvals->approve((string) PdfSecurityScanner::fingerprint($file), 'files/test.pdf', []);
        $result = $this->scanner(approvals: new PdfApprovals($this->dir.'/approved.json'))->scan($file);

        $this->assertTrue($result->safe);
        $this->assertSame('approved', $result->notices[0]);
        $this->assertStringContainsString('script|', implode(' ', $result->notices));

        // Verändert: Freigabe erlischt
        file_put_contents($file, ' ', FILE_APPEND);
        $this->assertFalse($this->scanner(approvals: new PdfApprovals($this->dir.'/approved.json'))->scan($file)->safe);
    }

    public function testPolyglotCanNeverBeApproved(): void
    {
        $approvals = new PdfApprovals($this->dir.'/approved.json');
        $file = $this->file(PdfFactory::build(['<< /Type /Catalog >>'], '', "%!PS-Adobe-3.0\n"));
        $approvals->approve((string) PdfSecurityScanner::fingerprint($file), 'files/test.pdf', []);

        $this->assertFalse($this->scanner(approvals: $approvals)->scan($file)->safe);
    }

    /**
     * Optional: alle PDFs eines Ordners mit echten Dateien prüfen, die nicht
     * blockiert werden dürfen (DUD_PDF_CORPUS=/pfad/zu/pdfs vendor/bin/phpunit).
     */
    public function testRealWorldCorpusIsNotBlocked(): void
    {
        $dir = getenv('DUD_PDF_CORPUS');

        if (!$dir || !is_dir($dir)) {
            $this->markTestSkipped('DUD_PDF_CORPUS not set.');
        }

        $blocked = [];

        foreach (glob(rtrim($dir, '/').'/*.pdf') ?: [] as $file) {
            $result = $this->scanner()->scan($file, false);

            if (!$result->safe) {
                $blocked[] = basename($file).': '.ScanReason::formatAll($result->reasons);
            }
        }

        $this->assertSame([], $blocked);
    }

    public function testFileSizeLimit(): void
    {
        $result = $this->scanner(maxFileSize: 100)->scan($this->file(PdfFactory::clean()));

        $this->assertFalse($result->safe);
    }

    public function testRequiredVirusScanWithoutScannerBlocks(): void
    {
        $scanner = new PdfSecurityScanner(new ArrayAdapter(), null, true, 50 * 1024 * 1024, ['JavaScript'], false, 'clamav', true, null, '/nonexistent/clamscan');
        $result = $scanner->scan($this->file(PdfFactory::clean()));

        $this->assertFalse($result->safe);
        $this->assertContains('no_scanner', $result->reasons);
    }

    public function testDisabledScannerAllowsEverything(): void
    {
        $scanner = new PdfSecurityScanner(new ArrayAdapter(), null, false, 1, [], false, 'off', false, null, null);

        $this->assertTrue($scanner->scan($this->file(PdfFactory::withObject('<< /S /JavaScript >>')))->safe);
    }

    public function testResultIsCachedPerFileVersion(): void
    {
        $cache = new ArrayAdapter();
        $file = $this->file(PdfFactory::clean());
        $scanner = new PdfSecurityScanner($cache, null, true, 1024 * 1024, ['JavaScript'], false, 'off', false, null, null);

        $this->assertTrue($scanner->scan($file)->safe);

        // Datei wird ersetzt (neue mtime/Größe) → muss neu geprüft werden
        file_put_contents($file, PdfFactory::withObject('<< /S /JavaScript /JS (x) >>'));
        touch($file, time() + 10);

        $fresh = new PdfSecurityScanner($cache, null, true, 1024 * 1024, ['JavaScript'], false, 'off', false, null, null);
        $this->assertFalse($fresh->scan($file)->safe);
    }

    public function testFtpReplacementWithSameSizeAndTimestampIsRescanned(): void
    {
        $cache = new ArrayAdapter();
        $evil = PdfFactory::withObject('<< /S /JavaScript /JS (x) >>');
        $clean = PdfFactory::withObject('<< /S /GoTo /D [3 0 R /Fit] >>');

        // Gleiche Dateigröße erzwingen (PDF-Kommentar zum Auffüllen)
        $length = max(\strlen($evil), \strlen($clean)) + 16;
        $pad = static fn (string $pdf): string => $pdf.'%'.str_repeat('x', $length - \strlen($pdf) - 2)."\n";
        [$evil, $clean] = [$pad($evil), $pad($clean)];
        $this->assertSame(\strlen($evil), \strlen($clean));

        $file = $this->file($clean);
        touch($file, 1700000000);

        $scanner = new PdfSecurityScanner($cache, null, true, 1024 * 1024, ['JavaScript'], false, 'off', false, null, null);
        $this->assertTrue($scanner->scan($file)->safe);

        // Per FTP ersetzt – Zeitstempel und Größe bleiben identisch
        file_put_contents($file, $evil);
        touch($file, 1700000000);
        clearstatcache();

        $fresh = new PdfSecurityScanner($cache, null, true, 1024 * 1024, ['JavaScript'], false, 'off', false, null, null);
        $this->assertFalse($fresh->scan($file)->safe);
    }

    private function scanner(int $maxFileSize = 50 * 1024 * 1024, int $maxDecompressed = 0, bool $allowFormFeatures = true, PdfApprovals|null $approvals = null): PdfSecurityScanner
    {
        return new PdfSecurityScanner(
            new ArrayAdapter(),
            null,
            true,
            $maxFileSize,
            ['JavaScript', 'JS', 'Launch', 'EmbeddedFile', 'EmbeddedFiles', 'RichMedia', 'XFA', 'SubmitForm', 'ImportData', 'GoToE'],
            false,
            'off',
            false,
            null,
            null,
            $maxDecompressed,
            $allowFormFeatures,
            $approvals,
        );
    }

    private function file(string $content): string
    {
        $file = $this->dir.'/'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($file, $content);

        return $file;
    }
}
