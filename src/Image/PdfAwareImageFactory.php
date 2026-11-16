<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Image;

use Contao\CoreBundle\Image\ImageFactoryInterface;
use Contao\Image\ImageInterface;
use Contao\Image\ImportantPart;
use Contao\Image\ResizeConfiguration;
use Contao\Image\ResizeOptions;

/**
 * Dekoriert "contao.image.factory".
 *
 * Alle Bildausgaben von Contao (Image Studio/Figure, Picture-Factory, Twig
 * figure(), Insert-Tags, News, Events, Inhaltselemente, Lightbox …) erzeugen
 * ihre Bilder letztlich über diese Factory. Wird hier ein PDF-Pfad übergeben,
 * ersetzen wir ihn durch die gerenderte (und ggf. zugeschnittene) Seite. Alle
 * Bildgrößen, Dichten, Media-Queries, WebP/AVIF-Formate und das Deferred
 * Resizing von Contao funktionieren dadurch unverändert.
 */
class PdfAwareImageFactory implements ImageFactoryInterface
{
    public function __construct(
        private readonly ImageFactoryInterface $inner,
        private readonly PdfPreviewGenerator $generator,
    ) {
    }

    /**
     * Weitere öffentliche Methoden der Original-Factory (z. B.
     * setPreserveMetadataFields) durchreichen.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }

    public function create(ImageInterface|string $path, ResizeConfiguration|array|int|string|null $size = null, ResizeOptions|string|null $options = null): ImageInterface
    {
        if (!\is_string($path) || !PdfPreviewGenerator::isPdf($path)) {
            return $this->inner->create($path, $size, $options);
        }

        try {
            $preview = $this->generator->getPreviewPath($path);
            $importantPart = $preview === $this->generator->getSettings($path)['image'] ? null : $this->generator->getImportantPart($path);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(\sprintf('Could not create a preview image for PDF file "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (null === $importantPart) {
            return $this->inner->create($preview, $size, $options);
        }

        // Wichtigen Bereich der PDF (tl_files.importantPart*) wie bei Bildern übernehmen
        $image = $this->inner->create($preview);
        $image->setImportantPart($importantPart);

        return null === $size && null === $options ? $image : $this->inner->create($image, $size, $options);
    }

    public function getImportantPartFromLegacyMode(ImageInterface $image, string $mode): ImportantPart
    {
        return $this->inner->getImportantPartFromLegacyMode($image, $mode);
    }
}
