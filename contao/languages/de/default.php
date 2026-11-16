<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

$GLOBALS['TL_LANG']['CTE']['pdf_pages'] = ['PDF-Seiten', 'Zeigt eine oder mehrere Seiten einer PDF als responsive Bilder.'];

$GLOBALS['TL_LANG']['ERR']['dudPdfUnsafe'] = 'Die PDF-Datei „%s“ wurde von der Sicherheitsprüfung blockiert: %s';
$GLOBALS['TL_LANG']['ERR']['dudPdfPages'] = 'Ungültige Seitenangabe. Beispiele: 1 oder 2,4 oder 1,3-5 oder *';
$GLOBALS['TL_LANG']['ERR']['dudPdfUploadBlocked'] = 'Die hochgeladene PDF „%s“ wurde von der Sicherheitsprüfung blockiert und wird nicht als Bild verarbeitet: %s';

$GLOBALS['TL_LANG']['dud_pdf_preview'] = [
    'blocked' => 'Diese PDF wurde von der Sicherheitsprüfung blockiert und wird nirgends gerendert:',
    'systemMessage' => '%d PDF-Datei(en) wurden von der Sicherheitsprüfung blockiert und werden nicht als Bild verarbeitet (z. B. per FTP hochgeladen). Diese Dateien sollten geprüft und ggf. gelöscht werden:',
    'previewLabel' => 'Vorschau und wichtiger Bereich',
    'previewHelp' => 'Mit gedrückter Maustaste einen Rahmen um den wichtigen Bereich ziehen – genau wie bei Bildern. Mit Haken bei „PDF nur im wichtigen Bereich anzeigen“ wird nur dieser Bereich gezeigt.',
    'customHint' => 'Es wird das eigene Vorschaubild verwendet. Der wichtige Bereich wird dafür beim Bild selbst festgelegt.',
    'sourceServer' => 'Server (Imagick/Ghostscript/Poppler)',
    'sourceBrowser' => 'Browser (pdf.js) – der Server kann auf diesem Hosting keine PDFs rendern',
    'sourceBrowserShort' => 'Browser (pdf.js)',
    'sourceCustom' => 'eigenes Vorschaubild',
    'sourceNone' => 'noch keine Vorschau – wird jetzt im Browser erzeugt …',
    'virusYes' => 'Virenscan',
    'virusNo' => 'kein Virenscanner (ClamAV) verfügbar',
    'renderer' => 'Vorschau',
    'renderBrowser' => 'Vorschaubild neu erzeugen',
    'renderBrowserHint' => 'Erzeugt das Vorschaubild im Browser neu, z. B. wenn es fehlerhaft oder unvollständig ist.',
    'rendering' => 'Seite %s wird im Browser gerendert …',
    'renderDone' => 'Vorschau im Browser erzeugt und gespeichert.',
    'renderFailed' => 'Rendern im Browser fehlgeschlagen: %s',
    'pageInfo' => 'Seite %s von %s',
    'pageInfoUnknown' => 'Seite %s',
    'reason' => [
        'unreadable' => 'Datei nicht lesbar',
        'too_large' => 'Datei größer als %s MB',
        'too_small' => 'Datei zu klein oder leer',
        'no_header' => 'Kein gültiger PDF-Header am Dateianfang (mögliche Polyglot-Datei)',
        'no_eof' => 'Kein Dateiende-Marker (%%EOF) – Datei unvollständig oder manipuliert',
        'encrypted' => 'Verschlüsselte PDF (Inhalt nicht prüfbar)',
        'feature' => 'Aktiver/gefährlicher Inhalt: /%s',
        'objstm_broken' => 'Beschädigter Objekt-Stream',
        'objstm_undecodable' => 'Objekt-Stream nicht entpackbar oder zu groß (Kompressionsbombe?)',
        'objstm_filter' => 'Objekt-Stream mit nicht prüfbarem Filter %s',
        'objstm_limit' => 'Entpackte Objekt-Streams überschreiten das Limit (Kompressionsbombe?)',
        'virus' => 'Virenscanner (%s): %s',
        'virus_failed' => 'Virenscan fehlgeschlagen (%s): %s',
        'no_scanner' => 'Kein Virenscanner (ClamAV) verfügbar, Virenscan ist aber vorgeschrieben',
    ],
    'error' => [
        'blocked' => 'PDF durch die Sicherheitsprüfung blockiert.',
        'token' => 'Ungültiges Request-Token.',
        'image' => 'Ungültige Seite oder Bilddatei.',
        'too_large' => 'Bilddatei zu groß.',
        'path' => 'Ungültiger Pfad.',
        'permission' => 'Keine Berechtigung.',
        'not_found' => 'Datei nicht gefunden.',
        'store' => 'Bild konnte nicht gespeichert werden: %s',
        'page' => 'Ungültige Seitennummer: %s',
        'upload_limit' => 'Das Bild überschreitet das Upload-Limit des Servers (%s).',
        'upload_failed' => 'Upload fehlgeschlagen: %s',
    ],
    'cli' => [
        'path' => 'Pfad nicht gefunden oder außerhalb des Projekts.',
        'col_file' => 'Datei',
        'col_status' => 'Status',
        'col_pages' => 'Seiten',
        'col_virus' => 'Virenscan',
        'col_reasons' => 'Gründe',
        'ok' => 'OK',
        'blocked' => 'BLOCKIERT',
        'no_scanner' => 'Kein Virenscanner (ClamAV) gefunden. Empfehlung: clamd installieren und contao_pdf_preview.security.clamav_socket setzen.',
        'purged' => 'Vorschau-Cache geleert. Vorschauen werden bei Bedarf neu (und erneut geprüft) erzeugt.',
        'blocked_count' => '%s von %s PDF(s) blockiert.',
        'success' => '%s PDF(s) geprüft, keine Auffälligkeiten.',
    ],
];
