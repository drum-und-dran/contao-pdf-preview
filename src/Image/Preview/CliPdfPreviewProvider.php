<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Image\Preview;

use Contao\CoreBundle\Image\Preview\PreviewProviderInterface;
use Contao\CoreBundle\Image\Preview\UnableToGeneratePreviewException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fallback-Provider für die PreviewFactory von Contao: Rendert PDFs über
 * "pdftoppm" (Poppler) oder "gs" (Ghostscript), falls Imagick/Gmagick nicht
 * vorhanden ist oder die ImageMagick-Policy PDFs blockiert.
 *
 * Läuft nach dem Imagine-Provider von Contao (Priorität 0) und vor dem
 * Platzhalter-Icon (Priorität -1024).
 */
class CliPdfPreviewProvider implements PreviewProviderInterface
{
    private const PROBE_DPI = 18;

    /**
     * Zusätzliche Suchpfade: PHP-FPM läuft häufig ohne PATH-Umgebungsvariable.
     */
    public const EXTRA_DIRS = ['/usr/bin', '/usr/local/bin', '/bin', '/opt/local/bin', '/opt/homebrew/bin'];

    private string|false|null $binary = null;

    public function __construct(private readonly string|bool|null $configuredBinary = null)
    {
    }

    public function getFileHeaderSize(): int
    {
        return 5;
    }

    public function supports(string $path, string $fileHeader = '', array $options = []): bool
    {
        if ('pdf' !== strtolower(Path::getExtension($path))) {
            return false;
        }

        if ('' !== $fileHeader && !str_starts_with($fileHeader, '%PDF')) {
            return false;
        }

        return false !== $this->getBinary();
    }

    public function generatePreviews(string $sourcePath, int $size, \Closure $targetPathCallback, int $lastPage = PHP_INT_MAX, int $firstPage = 1, array $options = []): iterable
    {
        $binary = $this->getBinary();

        if (false === $binary) {
            throw new UnableToGeneratePreviewException('No PDF renderer binary available.');
        }

        $targetPaths = [];

        // Höchstens 100 Seiten auf einmal – PreviewFactory fordert i. d. R. nur eine an
        $lastPage = min($lastPage, $firstPage + 99);

        try {
            for ($page = $firstPage; $page <= $lastPage; ++$page) {
                $target = $targetPathCallback($page).'.png';

                if (!$this->renderPage($binary, $sourcePath, $page, $size, $target)) {
                    break;
                }

                $targetPaths[] = $target;
            }
        } catch (\Throwable $exception) {
            throw new UnableToGeneratePreviewException($exception->getMessage(), 0, $exception);
        }

        if (!$targetPaths) {
            throw new UnableToGeneratePreviewException(\sprintf('Could not render page %d of "%s".', $firstPage, $sourcePath));
        }

        return $targetPaths;
    }

    private function renderPage(string $binary, string $source, int $page, int $size, string $target): bool
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($target));

        // 1. Durchgang in sehr niedriger Auflösung, um das Seitenformat zu ermitteln
        $probe = $target.'.probe.png';

        if (!$this->run($binary, $source, $page, self::PROBE_DPI, $probe)) {
            return false;
        }

        $dimensions = @getimagesize($probe);
        $filesystem->remove($probe);

        if (!$dimensions || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return false;
        }

        // 2. Durchgang so, dass die kürzere Kante mindestens $size Pixel hat
        $dpi = (int) ceil(self::PROBE_DPI * $size / min($dimensions[0], $dimensions[1]));
        $dpi = min(max($dpi, 36), 1200);

        return $this->run($binary, $source, $page, $dpi, $target);
    }

    private function run(string $binary, string $source, int $page, int $dpi, string $target): bool
    {
        if ('pdftoppm' === strtolower(pathinfo($binary, PATHINFO_FILENAME))) {
            // pdftoppm hängt ".png" selbst an
            $outputBase = substr($target, 0, -4);
            $command = [$binary, '-f', (string) $page, '-l', (string) $page, '-r', (string) $dpi, '-png', '-singlefile', '-aa', 'yes', '-aaVector', 'yes', $source, $outputBase];
        } else {
            $command = [$binary, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dUseTrimBox', '-sDEVICE=png16m', '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4', '-dFirstPage='.$page, '-dLastPage='.$page, '-r'.$dpi, '-sOutputFile='.$target, $source];
        }

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        clearstatcache(true, $target);

        return $process->isSuccessful() && is_file($target) && filesize($target) > 0;
    }

    /**
     * Pfad des gefundenen Kommandozeilen-Renderers oder leer.
     */
    public function getRendererId(): string
    {
        return (string) $this->getBinary();
    }

    private function getBinary(): string|false
    {
        if (null !== $this->binary) {
            return $this->binary;
        }

        if (false === $this->configuredBinary || 'false' === $this->configuredBinary) {
            return $this->binary = false;
        }

        if (\is_string($this->configuredBinary) && '' !== $this->configuredBinary) {
            return $this->binary = is_executable($this->configuredBinary) ? $this->configuredBinary : false;
        }

        if (!\function_exists('proc_open')) {
            return $this->binary = false;
        }

        $finder = new ExecutableFinder();

        foreach (['pdftoppm', 'gs'] as $name) {
            if ($path = $finder->find($name, null, self::EXTRA_DIRS)) {
                return $this->binary = $path;
            }
        }

        return $this->binary = false;
    }
}
