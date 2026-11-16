<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Stellt "contao_pdf_preview.image_extensions" (Bild-Endungen + pdf) bereit und
 * reicht die Liste gezielt an die Galerie (ImagesController) und den Image
 * Studio (Lightbox) weiter.
 *
 * Bewusst NICHT verändert wird "contao.image.valid_extensions" selbst: Die
 * ImageFactory und die PreviewFactory von Contao würden PDFs sonst als
 * Rastergrafik öffnen wollen.
 */
final class PdfExtensionsPass implements CompilerPassInterface
{
    public const PARAMETER = 'contao_pdf_preview.image_extensions';

    private const PLACEHOLDER = '%contao.image.valid_extensions%';

    private const GALLERY_SERVICE = 'Contao\CoreBundle\Controller\ContentElement\ImagesController';

    private const STUDIO_SERVICE = 'contao.image.studio';

    public function process(ContainerBuilder $container): void
    {
        $extensions = $container->hasParameter('contao.image.valid_extensions')
            ? (array) $container->getParameterBag()->resolveValue(self::PLACEHOLDER)
            : ['jpg', 'jpeg', 'gif', 'png', 'tif', 'tiff', 'bmp', 'svg', 'svgz', 'webp', 'avif'];

        $extensions = array_values(array_unique([...array_map('strtolower', $extensions), 'pdf']));

        $container->setParameter(self::PARAMETER, $extensions);

        if ($container->getParameter('contao_pdf_preview.gallery')) {
            $this->replacePlaceholder($container, self::GALLERY_SERVICE);
        }

        if ($container->getParameter('contao_pdf_preview.lightbox')) {
            $this->replacePlaceholder($container, self::STUDIO_SERVICE);
        }
    }

    private function replacePlaceholder(ContainerBuilder $container, string $serviceId): void
    {
        if (!$container->hasDefinition($serviceId)) {
            return;
        }

        $definition = $container->getDefinition($serviceId);

        foreach ($definition->getArguments() as $key => $argument) {
            if (self::PLACEHOLDER === $argument) {
                $definition->setArgument($key, '%'.self::PARAMETER.'%');
            }
        }
    }
}
