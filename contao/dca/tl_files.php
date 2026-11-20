<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

// Die Felder werden nur bei PDF-Dateien eingeblendet
// (FilesListener::adjustPalette). Der Bildausschnitt wird – wie bei Bildern im
// Contao-Standard – in den Feldern importantPartX/Y/Width/Height gespeichert
// und mit Contaos eigenem Editor bearbeitet.
$GLOBALS['TL_DCA']['tl_files']['fields']['pdfPreviewPages'] = [
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50 clr', 'decodeEntities' => true, 'placeholder' => '1'],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_files']['fields']['pdfPreviewImage'] = [
    'inputType' => 'fileTree',
    'eval' => ['fieldType' => 'radio', 'filesOnly' => true, 'extensions' => '%contao.image.valid_extensions%', 'tl_class' => 'w50'],
    'sql' => 'binary(16) NULL',
];

// Vorschau mit Contaos Editor für den wichtigen Bereich (keine eigene
// Datenbankspalte)
$GLOBALS['TL_DCA']['tl_files']['fields']['pdfPreview'] = [
    'exclude' => false,
];

$GLOBALS['TL_DCA']['tl_files']['fields']['pdfCropToImportantPart'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 clr'],
    'sql' => "char(1) NOT NULL default ''",
];
