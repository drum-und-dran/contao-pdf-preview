<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Tests\Image;

use Dud\ContaoPdfPreview\Image\PdfPreviewGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PdfPreviewGeneratorTest extends TestCase
{
    #[DataProvider('pagesProvider')]
    public function testParsePages(string $input, int|null $pageCount, array|null $expected): void
    {
        $this->assertSame($expected, PdfPreviewGenerator::parsePages($input, $pageCount));
    }

    public static function pagesProvider(): iterable
    {
        yield 'empty' => ['', null, null];
        yield 'single' => ['2', null, [2]];
        yield 'list' => ['1,3', null, [1, 3]];
        yield 'range' => ['2-4', null, [2, 3, 4]];
        yield 'mixed with spaces' => [' 1, 3 - 5 ', null, [1, 3, 4, 5]];
        yield 'reversed range' => ['4-2', null, [2, 3, 4]];
        yield 'duplicates' => ['1,1,2', null, [1, 2]];
        yield 'open range' => ['2-', 4, [2, 3, 4]];
        yield 'all' => ['*', 3, [1, 2, 3]];
        yield 'invalid' => ['abc', null, null];
        yield 'injection' => ['1;DROP TABLE', null, null];
    }

    public function testParsePagesIsLimited(): void
    {
        $this->assertCount(PdfPreviewGenerator::MAX_PAGES, PdfPreviewGenerator::parsePages('1-100000'));
    }

    #[DataProvider('cropProvider')]
    public function testParseCrop(string $input, array|null $expected): void
    {
        $this->assertSame($expected, PdfPreviewGenerator::parseCrop($input));
    }

    public static function cropProvider(): iterable
    {
        yield 'valid' => ['0.1,0.2,0.5,0.5', [0.1, 0.2, 0.5, 0.5]];
        yield 'clamped' => ['0.5,0.5,0.9,0.9', [0.5, 0.5, 0.5, 0.5]];
        yield 'full page = none' => ['0,0,1,1', null];
        yield 'unset (Contao default 0) = none' => ['0,0,0,0', null];
        yield 'too small' => ['0.1,0.1,0.001,0.5', null];
        yield 'invalid' => ['a,b,c,d', null];
        yield 'wrong count' => ['0.1,0.2', null];
    }

    public function testIsPdf(): void
    {
        $this->assertTrue(PdfPreviewGenerator::isPdf('files/Flyer.PDF'));
        $this->assertFalse(PdfPreviewGenerator::isPdf('files/flyer.pdf.jpg'));
    }
}
