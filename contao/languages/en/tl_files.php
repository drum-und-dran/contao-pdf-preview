<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

// The labels are also shown in the user group manager ("Allowed fields") and
// therefore clearly start with "PDF".
$GLOBALS['TL_LANG']['tl_files']['pdfPreviewPages'] = ['PDF page(s) as image', 'Page(s) used as image, e.g. <em>1</em>, <em>2,4</em>, <em>1,3-5</em> or <em>*</em> for all. Fields with a single image (news, events, image element …) use the first one. Empty = page 1.'];
$GLOBALS['TL_LANG']['tl_files']['pdfPreviewImage'] = ['PDF: custom preview image', 'Optional. Replaces the automatically rendered main page of the PDF. Also used as a fallback if the PDF cannot be rendered.'];
$GLOBALS['TL_LANG']['tl_files']['pdfPreview'] = ['PDF: preview and important part', ''];
$GLOBALS['TL_LANG']['tl_files']['pdfCropToImportantPart'] = ['Show PDF in the important part only', 'Crops the PDF page to the marked important part. Without this option the whole page is shown and – like with images – the important part is only used when cropping to fixed image sizes. The PDF itself is not modified.'];
