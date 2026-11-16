<?php

declare(strict_types=1);

namespace Dud\ContaoPdfPreview\Tests\DependencyInjection;

use Dud\ContaoPdfPreview\DependencyInjection\Compiler\PdfExtensionsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class PdfExtensionsPassTest extends TestCase
{
    public function testAddsPdfAndReplacesPlaceholders(): void
    {
        $container = $this->container(true, true);
        (new PdfExtensionsPass())->process($container);

        $this->assertSame(['jpg', 'png', 'pdf'], $container->getParameter(PdfExtensionsPass::PARAMETER));
        $this->assertSame('%contao_pdf_preview.image_extensions%', $container->getDefinition('contao.image.studio')->getArgument(2));
        $this->assertSame('%contao_pdf_preview.image_extensions%', $container->getDefinition('Contao\CoreBundle\Controller\ContentElement\ImagesController')->getArgument(1));

        // Die globale Liste bleibt unverändert (ImageFactory/PreviewFactory)
        $this->assertSame(['jpg', 'png'], $container->getParameter('contao.image.valid_extensions'));
    }

    public function testRespectsDisabledOptions(): void
    {
        $container = $this->container(false, false);
        (new PdfExtensionsPass())->process($container);

        $this->assertSame('%contao.image.valid_extensions%', $container->getDefinition('contao.image.studio')->getArgument(2));
        $this->assertSame('%contao.image.valid_extensions%', $container->getDefinition('Contao\CoreBundle\Controller\ContentElement\ImagesController')->getArgument(1));
    }

    private function container(bool $gallery, bool $lightbox): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('contao.image.valid_extensions', ['jpg', 'png']);
        $container->setParameter('contao_pdf_preview.gallery', $gallery);
        $container->setParameter('contao_pdf_preview.lightbox', $lightbox);
        $container->setDefinition('contao.image.studio', new Definition(null, ['a', 'b', '%contao.image.valid_extensions%']));
        $container->setDefinition('Contao\CoreBundle\Controller\ContentElement\ImagesController', new Definition(null, ['a', '%contao.image.valid_extensions%']));

        return $container;
    }
}
