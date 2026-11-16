<?php

declare(strict_types=1);

namespace Dud\ContaoPdfPreview\Tests\Security;

use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
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
    }

    public static function unsafeProvider(): iterable
    {
        yield 'javascript' => [PdfFactory::withObject('<< /S /JavaScript /JS (app.alert\(1\)) >>'), 'feature|JavaScript'];
        yield 'escaped name' => [PdfFactory::withObject('<< /S /J#61vaScr#69pt /JS (x) >>'), 'feature|JavaScript'];
        yield 'launch' => [PdfFactory::withObject('<< /S /Launch /F (cmd.exe) >>'), 'feature|Launch'];
        yield 'embedded file' => [PdfFactory::withObject('<< /Type /EmbeddedFile /Length 0 >>'), 'feature|EmbeddedFile'];
        yield 'xfa' => [PdfFactory::withObject('<< /XFA 5 0 R >>'), 'feature|XFA'];
        yield 'hidden in object stream' => [PdfFactory::objectStream('4 0 << /S /J#61vaScript /J#53 (x) >>'), 'feature|JavaScript'];
        yield 'uninspectable object stream' => [PdfFactory::objectStream('4 0 << >>', '/LZWDecode'), 'objstm_filter'];
        yield 'encrypted' => [PdfFactory::withObject('<< /Filter /Standard /V 1 /R 2 /O <00> /U <00> /P -4 >>', '/Encrypt 4 0 R '), 'encrypted'];
        yield 'polyglot' => [PdfFactory::build(['<< /Type /Catalog >>'], '', "GIF89a\n"), 'no_header'];
        yield 'truncated' => [substr(PdfFactory::clean(), 0, -8), 'no_eof'];
        yield 'not a pdf' => [str_repeat('A', 100), 'no_header'];
    }

    public function testCompressionBombIsBlocked(): void
    {
        $scanner = $this->scanner(maxDecompressed: 1024 * 1024);
        $result = $scanner->scan($this->file(PdfFactory::objectStream(str_repeat('0', 5 * 1024 * 1024))));

        $this->assertFalse($result->safe);
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

    private function scanner(int $maxFileSize = 50 * 1024 * 1024, int $maxDecompressed = 100 * 1024 * 1024): PdfSecurityScanner
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
        );
    }

    private function file(string $content): string
    {
        $file = $this->dir.'/'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($file, $content);

        return $file;
    }
}
