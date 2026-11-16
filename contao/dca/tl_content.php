<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

// Inhaltselement „PDF-Seiten“: zeigt eine oder mehrere Seiten einer PDF als responsive Bilder
$GLOBALS['TL_DCA']['tl_content']['palettes']['pdf_pages'] = '{type_legend},type,headline;{source_legend},singleSRC,pdfPages,pdfLinkToFile;{image_legend},size,perRow,fullsize;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';

$GLOBALS['TL_DCA']['tl_content']['fields']['pdfPages'] = [
    'exclude' => true,
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50', 'decodeEntities' => true],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_content']['fields']['pdfLinkToFile'] = [
    'exclude' => true,
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => "char(1) NOT NULL default ''",
];
