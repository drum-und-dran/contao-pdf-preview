<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

// Die Bezeichnungen erscheinen auch in der Benutzergruppen-Verwaltung („Erlaubte Felder“)
// und beginnen deshalb eindeutig mit „PDF“.
$GLOBALS['TL_LANG']['tl_files']['pdfPreviewPages'] = ['PDF-Seite(n) als Bild', 'Seite(n), die als Bild verwendet werden, z. B. <em>1</em>, <em>2,4</em>, <em>1,3-5</em> oder <em>*</em> für alle. Bei Feldern mit nur einem Bild (News, Events, Bild-Element …) wird die erste Angabe verwendet. Leer = Seite 1.'];
$GLOBALS['TL_LANG']['tl_files']['pdfPreviewImage'] = ['PDF: eigenes Vorschaubild', 'Optional. Ersetzt die automatisch gerenderte Hauptseite der PDF. Dient außerdem als Rückfallebene, falls die PDF nicht gerendert werden kann.'];
$GLOBALS['TL_LANG']['tl_files']['pdfPreview'] = ['PDF: Vorschau und wichtiger Bereich', ''];
$GLOBALS['TL_LANG']['tl_files']['pdfCropToImportantPart'] = ['PDF nur im wichtigen Bereich anzeigen', 'Schneidet die PDF-Seite fest auf den markierten wichtigen Bereich zu. Ohne Haken wird die ganze Seite gezeigt und der Bereich – wie bei Bildern – nur beim Zuschneiden auf feste Bildgrößen berücksichtigt. Die PDF selbst bleibt unverändert.'];
