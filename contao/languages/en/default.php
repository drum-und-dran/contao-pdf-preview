<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

$GLOBALS['TL_LANG']['CTE']['pdf_pages'] = ['PDF pages', 'Shows one or more pages of a PDF as responsive images.'];

$GLOBALS['TL_LANG']['ERR']['dudPdfUnsafe'] = 'The PDF file "%s" was blocked by the security check: %s';
$GLOBALS['TL_LANG']['ERR']['dudPdfPages'] = 'Invalid page selection. Examples: 1 or 2,4 or 1,3-5 or *';
$GLOBALS['TL_LANG']['ERR']['dudPdfUploadBlocked'] = 'The uploaded PDF "%s" was blocked by the security check and will not be processed as an image: %s';

$GLOBALS['TL_LANG']['dud_pdf_preview'] = [
    'blocked' => 'This PDF was blocked by the security check and will not be rendered anywhere:',
    'systemMessage' => '%d PDF file(s) were blocked by the security check and will not be processed as images (e.g. uploaded via FTP). These files should be reviewed and deleted if necessary:',
    'previewLabel' => 'Preview and important part',
    'previewHelp' => 'The important part is marked by dragging a frame with the mouse button held down – just like with images. With "Show PDF in the important part only" only this area is shown.',
    'customHint' => 'The custom preview image is used. Its important part is defined on the image itself.',
    'sourceServer' => 'server (Imagick/Ghostscript/Poppler)',
    'sourceBrowser' => 'browser (pdf.js) – the server cannot render PDFs on this hosting',
    'sourceBrowserShort' => 'browser (pdf.js)',
    'sourceCustom' => 'custom preview image',
    'sourceNone' => 'no preview yet – rendering in the browser now …',
    'virusYes' => 'Virus scan',
    'virusNo' => 'no virus scanner (ClamAV) available',
    'renderer' => 'Preview',
    'renderBrowser' => 'Regenerate preview image',
    'renderBrowserHint' => 'Regenerates the preview image in the browser, e.g. if it is faulty or incomplete.',
    'rendering' => 'Rendering page %s in the browser …',
    'renderDone' => 'Preview rendered in the browser and saved.',
    'renderFailed' => 'Rendering in the browser failed: %s',
    'pageInfo' => 'Page %s of %s',
    'pageInfoUnknown' => 'Page %s',
    'reason' => [
        'unreadable' => 'File is not readable',
        'too_large' => 'File is larger than %s MB',
        'too_small' => 'File is too small or empty',
        'no_header' => 'No valid PDF header at the start of the file (possible polyglot file)',
        'no_eof' => 'No end-of-file marker (%%EOF) – file is incomplete or manipulated',
        'encrypted' => 'Encrypted PDF (content cannot be inspected)',
        'feature' => 'Active/dangerous content: /%s',
        'objstm_broken' => 'Damaged object stream',
        'objstm_undecodable' => 'Object stream cannot be decompressed or is too large (compression bomb?)',
        'objstm_filter' => 'Object stream with uninspectable filter %s',
        'objstm_limit' => 'Decompressed object streams exceed the limit (compression bomb?)',
        'virus' => 'Virus scanner (%s): %s',
        'virus_failed' => 'Virus scan failed (%s): %s',
        'no_scanner' => 'No virus scanner (ClamAV) available, but a virus scan is required',
    ],
    'error' => [
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
    ],
    'cli' => [
        'path' => 'Path not found or outside the project.',
        'col_file' => 'File',
        'col_status' => 'Status',
        'col_pages' => 'Pages',
        'col_virus' => 'Virus scan',
        'col_reasons' => 'Reasons',
        'ok' => 'OK',
        'blocked' => 'BLOCKED',
        'no_scanner' => 'No virus scanner (ClamAV) found. Recommended: installing clamd and setting contao_pdf_preview.security.clamav_socket.',
        'purged' => 'Preview cache purged. Previews will be regenerated (and re-checked) on demand.',
        'blocked_count' => '%s of %s PDF(s) blocked.',
        'success' => '%s PDF(s) checked, no issues found.',
    ],
];
