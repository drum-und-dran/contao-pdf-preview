<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Tests\Image;

use Dud\ContaoPdfPreview\Image\ClientPreviewStore;
use Dud\ContaoPdfPreview\Tests\PdfFactory;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class ClientPreviewStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('GD not available.');
        }

        $this->dir = sys_get_temp_dir().'/dud-pdf-store-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/files', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testStoresPagesAsJpegAndReadsLegacyPng(): void
    {
        $store = $this->store();

        file_put_contents($this->dir.'/files/a.pdf', PdfFactory::clean(2));

        $path = $store->store('files/a.pdf', 1, $this->png());

        $this->assertStringEndsWith('page-1.jpg', $path);
        $this->assertSame("\xFF\xD8\xFF", substr((string) file_get_contents($path), 0, 3));
        $this->assertSame($path, $store->get('files/a.pdf', 1));

        // Seitenbild einer älteren Version (PNG) bleibt nutzbar
        $legacy = \dirname($path).'/page-2.png';
        file_put_contents($legacy, $this->png());

        $this->assertSame($legacy, $store->get('files/a.pdf', 2));
        $this->assertSame([1, 2], $store->getStoredPages('files/a.pdf'));
    }

    public function testCleanupRemovesRenderingsOfDeletedAndReplacedPdfs(): void
    {
        $store = $this->store();

        file_put_contents($this->dir.'/files/keep.pdf', PdfFactory::clean());
        file_put_contents($this->dir.'/files/gone.pdf', PdfFactory::clean());
        file_put_contents($this->dir.'/files/changed.pdf', PdfFactory::clean());

        $keep = $store->store('files/keep.pdf', 1, $this->png());
        $gone = $store->store('files/gone.pdf', 1, $this->png());
        $changed = $store->store('files/changed.pdf', 1, $this->png());

        unlink($this->dir.'/files/gone.pdf');
        file_put_contents($this->dir.'/files/changed.pdf', PdfFactory::clean(3));
        touch($this->dir.'/files/changed.pdf', time() + 60);
        clearstatcache();

        $stats = $store->cleanup(['files/keep.pdf' => true, 'files/changed.pdf' => true]);

        $this->assertFileExists($keep);
        $this->assertFileDoesNotExist($gone);
        $this->assertFileDoesNotExist($changed);
        $this->assertSame(2, $stats['files']);
        $this->assertGreaterThan(0, $stats['bytes']);
    }

    private function store(): ClientPreviewStore
    {
        return new ClientPreviewStore(new Imagine(), $this->dir, $this->dir.'/assets/previews/pdf-client');
    }

    private function png(): string
    {
        return (new Imagine())->create(new Box(40, 60))->get('png');
    }
}
