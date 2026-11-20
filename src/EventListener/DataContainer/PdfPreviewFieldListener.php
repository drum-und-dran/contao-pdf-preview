<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\EventListener\DataContainer;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Image\ImageFactoryInterface;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Image\ResizeConfiguration;
use Contao\StringUtil;
use Dud\ContaoPdfPreview\Hosting\HostingCheck;
use Dud\ContaoPdfPreview\Image\ClientPreviewStore;
use Dud\ContaoPdfPreview\Image\PdfPreviewGenerator;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Vorschau einer PDF-Seite in der Dateiverwaltung.
 *
 * Der Bildausschnitt wird genau wie bei Bildern im Contao-Standard bearbeitet:
 * mit Contaos eigenem Editor (Backend.editPreviewWizard) und gespeichert in
 * tl_files.importantPartX/Y/Width/Height. Zusätzlich zeigt das Feld den Status
 * von Sicherheitsprüfung und Renderer und rendert die PDF bei Bedarf im Browser
 * (pdf.js), falls der Server das nicht kann.
 */
class PdfPreviewFieldListener
{
    public function __construct(
        private readonly PdfSecurityScanner $scanner,
        private readonly PdfPreviewGenerator $generator,
        private readonly ClientPreviewStore $clientStore,
        private readonly ImageFactoryInterface $imageFactory,
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requestStack,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly Security $security,
        private readonly string $projectDir,
        private readonly int $previewSize,
        private readonly HostingCheck|null $hostingCheck = null,
    ) {
    }

    #[AsCallback(table: 'tl_files', target: 'fields.pdfPreview.input_field')]
    public function __invoke(DataContainer $dc): string
    {
        $lang = $GLOBALS['TL_LANG']['dud_pdf_preview'] ?? [];
        $t = static fn (string $key, string $fallback): string => StringUtil::specialchars($lang[$key] ?? $fallback);

        $relative = Path::canonicalize(urldecode((string) $dc->id));

        if ('' === $relative || str_contains($relative, '..') || !PdfPreviewGenerator::isPdf($relative)) {
            return '';
        }

        $absolute = Path::join($this->projectDir, $relative);
        $label = '<h3><label>'.$t('previewLabel', 'Preview and important part').'</label></h3>';
        $scan = $this->scanner->scan($absolute);

        if (!$scan->safe) {
            $reasons = implode('', array_map(static fn ($r) => '<li>'.StringUtil::specialchars(ScanReason::format($r)).'</li>', $scan->reasons));

            return '<div class="widget clr">'.$label.'<div class="tl_error dud-pdfpreview__blocked"><strong>'.$t('blocked', 'This PDF was blocked by the security check and will not be rendered anywhere:').'</strong><ul>'.$reasons.'</ul></div></div>';
        }

        $pages = $this->generator->getPages($absolute);
        $diagnosis = $this->generator->diagnose($absolute, $pages[0]);

        $basePath = $this->requestStack->getCurrentRequest()?->getBasePath() ?? '';

        // Wie im Contao-Standard: Vorschau in einer 699 × 524 px großen Box
        $imageHtml = '<img src="" alt="" hidden>';

        if (null !== $diagnosis['path'] && 'custom' !== $diagnosis['source']) {
            try {
                $image = $this->imageFactory->create($diagnosis['path'], [699, 524, ResizeConfiguration::MODE_BOX]);
                $size = $image->getDimensions()->getSize();

                $imageHtml = \sprintf('<img src="%s" width="%d" height="%d" alt="">', StringUtil::specialchars($basePath.'/'.ltrim($image->getUrl($this->projectDir), '/')), $size->getWidth(), $size->getHeight());
            } catch (\Throwable) {
            }
        }

        $source = [
            'server' => $t('sourceServer', 'server (Imagick/Ghostscript/Poppler)'),
            'browser' => $t('sourceBrowser', 'browser (pdf.js) – the server cannot render PDFs on this hosting'),
            'custom' => $t('sourceCustom', 'custom preview image'),
            'none' => $t('sourceNone', 'no preview yet – rendering in the browser now …'),
        ][$diagnosis['source']];

        $virus = $scan->virusScanner
            ? '<span class="dud-pdfpreview__virus is-ok">'.$t('virusYes', 'Virus scan').': '.StringUtil::specialchars($scan->virusScanner).' ✓</span>'
            : '<span class="dud-pdfpreview__virus is-missing">'.$t('virusNo', 'no virus scanner (ClamAV) available').'</span>';

        $pageInfo = $scan->pageCount
            ? \sprintf($lang['pageInfo'] ?? 'Page %s of %s', $pages[0], $scan->pageCount)
            : \sprintf($lang['pageInfoUnknown'] ?? 'Page %s', $pages[0]);

        $data = [
            'path' => $relative,
            'pages' => json_encode($pages),
            'stored' => json_encode($this->clientStore->getStoredPages($absolute)),
            'page-count' => (string) ($scan->pageCount ?? ''),
            'source' => $diagnosis['source'],
            'target-size' => (string) $this->previewSize,
            // Upload-Limit des Servers (upload_max_filesize/post_max_size), damit der
            // Browser das Seitenbild passend komprimiert
            'max-upload' => (string) (int) min(UploadedFile::getMaxFilesize(), ClientPreviewStore::MAX_UPLOAD_BYTES),
            'pdf-url' => $this->router->generate('dud_pdf_preview_source', ['path' => $relative]),
            'upload-url' => $this->router->generate('dud_pdf_preview_client'),
            'token' => $this->tokenManager->getDefaultTokenValue(),
            'pdfjs' => $basePath.'/bundles/contaopdfpreview/pdfjs/pdf.min.js',
            'worker' => $basePath.'/bundles/contaopdfpreview/pdfjs/pdf.worker.min.js',
            'msg-rendering' => $lang['rendering'] ?? 'Rendering page %s in the browser …',
            'msg-done' => $lang['renderDone'] ?? 'Preview rendered in the browser and saved.',
            'msg-failed' => $lang['renderFailed'] ?? 'Rendering in the browser failed: %s',
            'label-browser' => $lang['sourceBrowserShort'] ?? 'Browser (pdf.js)',
        ];

        $attributes = '';

        foreach ($data as $key => $value) {
            $attributes .= ' data-'.$key.'="'.StringUtil::specialchars((string) $value).'"';
        }

        $GLOBALS['TL_JAVASCRIPT']['dud_pdf_preview'] = 'bundles/contaopdfpreview/pdf-preview.js';
        $GLOBALS['TL_CSS']['dud_pdf_preview'] = 'bundles/contaopdfpreview/pdf-preview.css';

        $ctrl = 'ctrl_preview_pdf_'.substr(md5($relative), 0, 8);

        $html = '<div class="widget clr dud-pdfpreview" data-dud-pdfpreview'.$attributes.'>'.$label;
        $html .= '<p class="dud-pdfpreview__status">'
            .'<span>'.StringUtil::specialchars($pageInfo).'</span>'
            .'<span>'.$t('renderer', 'Preview').': <strong data-dud-pdfpreview-source>'.$source.'</strong></span>'
            .$virus
            .'</p>';

        if ($hosting = $this->hostingCheck?->getProblems()) {
            $html .= '<details class="dud-pdfpreview__hosting"><summary>'.StringUtil::specialchars(HostingCheck::text('summary')).'</summary><ul>'
                .implode(
                    '',
                    array_map(
                        static function (array $problem): string {
                            $text = HostingCheck::describe($problem);

                            return '<li><strong>'.StringUtil::specialchars($text['label'].': '.$text['value']).'</strong> – '.StringUtil::specialchars($text['recommendation']).'</li>';
                        },
                        $hosting,
                    ),
                )
                .'</ul></details>';
        }

        if ($scan->notices) {
            $html .= '<div class="tl_info dud-pdfpreview__notices">'.$t('notices', 'Notes from the security check (not blocking):').'<ul>'
                .implode('', array_map(static fn ($r) => '<li>'.StringUtil::specialchars(ScanReason::format($r)).'</li>', $scan->notices))
                .'</ul></div>';
        }

        if ('custom' === $diagnosis['source']) {
            $html .= '<p class="tl_info">'.$t('customHint', 'The custom preview image is used. Its important part is defined on the image itself.').'</p>';
        }

        $html .= '<div id="'.$ctrl.'" class="tl_edit_preview">'.$imageHtml.'</div>';

        // Contaos eigener Editor für den wichtigen Bereich – nur mit Schreibrechten
        // auf die Felder
        if ($this->canEditImportantPart()) {
            $html .= '<script>Backend.editPreviewWizard($(\''.$ctrl.'\'));</script>';
            $html .= '<p class="tl_help tl_tip">'.$t('previewHelp', 'The important part is marked by dragging a frame with the mouse button held down – just like with images. With "Show PDF in the important part only" only this area is shown.').'</p>';
        }

        // Nur relevant, wenn die Vorschau im Browser erzeugt wird (Server kann nicht rendern)
        if (\in_array($diagnosis['source'], ['browser', 'none'], true)) {
            $html .= '<p class="dud-pdfpreview__tools"><button type="button" class="tl_submit" data-dud-pdfpreview-render title="'.$t('renderBrowserHint', 'Regenerates the preview image in the browser, e.g. if it is faulty or incomplete.').'">'.$t('renderBrowser', 'Regenerate preview image').'</button> <span class="tl_help">'.$t('renderBrowserHint', 'Regenerates the preview image in the browser, e.g. if it is faulty or incomplete.').'</span></p>';
        }

        $html .= '<p class="dud-pdfpreview__message" data-dud-pdfpreview-message aria-live="polite"></p>';

        return $html.'</div>';
    }

    private function canEditImportantPart(): bool
    {
        foreach (['importantPartX', 'importantPartY', 'importantPartWidth', 'importantPartHeight'] as $field) {
            if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_files::'.$field)) {
                return false;
            }
        }

        return true;
    }
}
