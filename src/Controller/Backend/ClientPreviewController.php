<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Controller\Backend;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Dud\ContaoPdfPreview\Image\ClientPreviewStore;
use Dud\ContaoPdfPreview\Image\PdfPreviewGenerator;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Dud\ContaoPdfPreview\Security\ScanReason;

/**
 * Nimmt im Browser (pdf.js) gerenderte Seitenbilder entgegen – für Webhostings,
 * auf denen der Server selbst keine PDFs rendern kann.
 *
 * Absicherung: Backend-Login, Zugriff auf die Dateiverwaltung inkl.
 * Bearbeitungsrecht und Dateifreigabe (Filemount), Request-Token, PDF muss die
 * Sicherheitsprüfung bestehen, Bilddaten werden geprüft und neu codiert.
 */
class ClientPreviewController
{
    public function __construct(
        private readonly PdfSecurityScanner $scanner,
        private readonly ClientPreviewStore $store,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $tokenManager,
        private readonly string $tokenName,
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Liefert die PDF an pdf.js im Backend aus – auch aus geschützten Ordnern,
     * aber nur für berechtigte Benutzer und nur, wenn die PDF sicher ist.
     */
    #[Route('%contao.backend.route_prefix%/dud-pdf-preview/source', name: 'dud_pdf_preview_source', defaults: ['_scope' => 'backend'], methods: ['GET'])]
    public function source(Request $request): Response
    {
        $relative = $this->validatePath((string) $request->query->get('path'));

        if ($relative instanceof JsonResponse) {
            return $relative;
        }

        $absolute = Path::join($this->projectDir, $relative);
        $result = $this->scanner->scan($absolute);

        if (!$result->safe) {
            return $this->error($this->t('blocked'), 422);
        }

        $response = new BinaryFileResponse($absolute, 200, ['Content-Type' => 'application/pdf'], false);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'document.pdf');
        $response->setPrivate();

        return $response;
    }

    #[Route('%contao.backend.route_prefix%/dud-pdf-preview/client-preview', name: 'dud_pdf_preview_client', defaults: ['_scope' => 'backend', '_token_check' => false], methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        // Überschreitet die Anfrage post_max_size, verwirft PHP alle Formulardaten
        // (auch das Token). Dann das Limit melden statt „ungültiges Token“.
        if (0 === $request->request->count() && 0 === $request->files->count() && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            return $this->error($this->t('upload_limit', self::formatBytes(UploadedFile::getMaxFilesize())), 413);
        }

        if (!$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, (string) $request->request->get('REQUEST_TOKEN')))) {
            return $this->error($this->t('token'), 403);
        }

        $relative = $this->validatePath((string) $request->request->get('path'));

        if ($relative instanceof JsonResponse) {
            return $relative;
        }

        $absolute = Path::join($this->projectDir, $relative);

        $result = $this->scanner->scan($absolute);

        if (!$result->safe) {
            return $this->error($this->t('blocked').' '.ScanReason::formatAll($result->reasons, $this->translator), 422);
        }

        $page = (int) $request->request->get('page');
        $file = $request->files->get('image');

        if ($page < 1 || ($result->pageCount && $page > $result->pageCount)) {
            return $this->error($this->t('page', (string) $page), 400);
        }

        if (!$file instanceof UploadedFile) {
            return $this->error($this->t('image'), 400);
        }

        if (!$file->isValid()) {
            // Häufigste Ursache: upload_max_filesize des Servers ist zu klein
            if (\in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->error($this->t('upload_limit', self::formatBytes(UploadedFile::getMaxFilesize())), 413);
            }

            return $this->error($this->t('upload_failed', $file->getErrorMessage()), 400);
        }

        if ($file->getSize() > ClientPreviewStore::MAX_UPLOAD_BYTES) {
            return $this->error($this->t('too_large'), 413);
        }

        try {
            $this->store->store($absolute, $page, (string) file_get_contents($file->getPathname()));
        } catch (\Throwable $exception) {
            return $this->error($this->t('store', $exception->getMessage()), 400);
        }

        return new JsonResponse(['ok' => true, 'page' => $page]);
    }

    private function validatePath(string $path): string|JsonResponse
    {
        $relative = Path::canonicalize($path);

        if (
            '' === $relative
            || Path::isAbsolute($relative)
            || str_contains($relative, '..')
            || !Path::isBasePath($this->uploadPath, $relative)
            || !PdfPreviewGenerator::isPdf($relative)
        ) {
            return $this->error($this->t('path'), 400);
        }

        if (
            !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'files')
            || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_RENAME_FILE)
            || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PATH, $relative)
        ) {
            return $this->error($this->t('permission'), 403);
        }

        if (!is_file(Path::join($this->projectDir, $relative))) {
            return $this->error($this->t('not_found'), 404);
        }

        return $relative;
    }

    private function t(string $key, string ...$parameters): string
    {
        $fallback = [
            'blocked' => 'PDF blocked by the security check.',
            'token' => 'Invalid request token.',
            'image' => 'Invalid page or image file.',
            'too_large' => 'Image file too large.',
            'path' => 'Invalid path.',
            'permission' => 'Access denied.',
            'not_found' => 'File not found.',
            'store' => 'Image could not be saved: %s',
            'page' => 'Invalid page number: %s',
            'upload_limit' => 'The image exceeds the upload limit of the server (%s).',
            'upload_failed' => 'Upload failed: %s',
        ];

        $id = 'dud_pdf_preview.error.'.$key;
        $text = $this->translator->trans($id, $parameters, 'contao_default');

        return $text !== $id ? $text : vsprintf($fallback[$key] ?? $key, $parameters);
    }

    public static function formatBytes(int|float $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024).' KB';
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
