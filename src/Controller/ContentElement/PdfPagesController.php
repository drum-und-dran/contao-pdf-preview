<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Image\ImageFactoryInterface;
use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FilesModel;
use Dud\ContaoPdfPreview\Image\PdfPreviewGenerator;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsContentElement('pdf_pages', category: 'media', template: 'content_element/pdf_pages')]
class PdfPagesController extends AbstractContentElementController
{
    public function __construct(
        private readonly PdfPreviewGenerator $generator,
        private readonly Studio $studio,
        private readonly ImageFactoryInterface $imageFactory,
        private readonly string $projectDir,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $filesModel = $model->singleSRC ? FilesModel::findByUuid($model->singleSRC) : null;

        if (!$filesModel || 'file' !== $filesModel->type || !PdfPreviewGenerator::isPdf((string) $filesModel->path)) {
            return new Response();
        }

        $pdfPath = Path::join($this->projectDir, $filesModel->path);

        if (!is_file($pdfPath)) {
            return new Response();
        }

        $pages = PdfPreviewGenerator::parsePages((string) $model->pdfPages, $this->generator->getPageCount($pdfPath))
            ?? $this->generator->getPages($pdfPath);

        try {
            $paths = $this->generator->getPreviewPaths($pdfPath, $pages);
        } catch (\Throwable) {
            // z. B. durch die Sicherheitsprüfung blockiert
            return new Response();
        }

        if (!$paths) {
            return new Response();
        }

        // Metadaten (Alt-Text, Bildunterschrift …) der PDF aus der Dateiverwaltung übernehmen
        $metadata = null;

        try {
            $base = $this->studio->createFigureBuilder()->fromFilesModel($filesModel)->buildIfResourceExists();
            $metadata = $base?->hasMetadata() ? $base->getMetadata() : null;
        } catch (\Throwable) {
        }

        $figureBuilder = $this->studio
            ->createFigureBuilder()
            ->setSize($model->size)
            ->setLightboxGroupIdentifier('lb'.$model->id)
            ->enableLightbox((bool) $model->fullsize)
        ;

        $images = [];
        $importantPart = $this->generator->getImportantPart($pdfPath);
        $customImage = $this->generator->getSettings($pdfPath)['image'];

        foreach (array_values($paths) as $index => $path) {
            $page = $pages[$index] ?? $index + 1;
            $image = $this->imageFactory->create($path);

            if ($importantPart && $path !== $customImage) {
                $image->setImportantPart($importantPart);
            }

            $figureBuilder
                ->fromImage($image)
                ->setMetadata($metadata)
            ;

            if ($model->pdfLinkToFile && !$model->fullsize) {
                $figureBuilder->setLinkHref('/'.ltrim((string) $filesModel->path, '/').'#page='.$page);
            } else {
                $figureBuilder->setLinkHref(null);
            }

            if (($figure = $figureBuilder->buildIfResourceExists()) instanceof Figure) {
                $images[] = $figure;
            }
        }

        if (!$images) {
            return new Response();
        }

        $template->set('images', $images);
        $template->set('pages', $pages);
        $template->set('items_per_row', $model->perRow ?: 1);
        $template->set('pdf_path', $filesModel->path);

        return $template->getResponse();
    }
}
