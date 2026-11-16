# Changelog

## 1.0.4

**Deutsch**

- Wird auf dem Server nachträglich ein Renderer installiert (z. B. `poppler-utils` oder Imagick), rendert der Server die PDFs automatisch selbst. Zuvor gespeicherte Platzhalter blockieren das nicht mehr.
- Programme wie `pdftoppm`, `gs` und `clamdscan` werden auch gefunden, wenn PHP-FPM ohne `PATH`-Variable läuft.
- Statuszeile in der Dateiverwaltung besser lesbar: normale Schriftgröße, Virenscan grün (aktiv) bzw. orange (nicht verfügbar).
- Button „Im Browser neu rendern“ heißt jetzt „Vorschaubild neu erzeugen“, hat einen erklärenden Hinweis und erscheint nur, wenn die Vorschau im Browser erzeugt wird.
- README: Anleitung „Server-Rendering aktivieren“.

**English**

- If a renderer is installed on the server later (e.g. `poppler-utils` or Imagick), the server renders the PDFs itself automatically. Previously stored placeholders no longer prevent this.
- Programs like `pdftoppm`, `gs` and `clamdscan` are also found if PHP-FPM runs without a `PATH` variable.
- Status line in the file manager easier to read: normal font size, virus scan green (active) or orange (not available).
- Button "Re-render in browser" is now called "Regenerate preview image", has an explanatory hint and only appears if the preview is created in the browser.
- README: guide "Enabling server-side rendering".

## 1.0.3

**Deutsch**

- Inhaltselement „PDF-Seiten“: Die Seiten werden direkt als `<figure>` ausgegeben, ohne `<ul>`/`<li>`.
- Spalten-Klasse vereinheitlicht: `content-pdf-pages--cols-N` (passend zu Contaos Element-Klasse `content-pdf-pages`).

**English**

- Content element "PDF pages": pages are output directly as `<figure>`, without `<ul>`/`<li>`.
- Column class unified: `content-pdf-pages--cols-N` (matching Contao's element class `content-pdf-pages`).

## 1.0.2

**Deutsch**

- Rendern im Browser auf Servern mit kleinem Upload-Limit korrigiert: Das Seitenbild wurde als großes PNG übertragen und überschritt bei farbigen Seiten oft `upload_max_filesize`. Es wird jetzt als JPEG übertragen und automatisch so komprimiert, dass es unter das Upload-Limit des Servers passt.
- Klare Fehlermeldung, wenn das Upload-Limit trotzdem überschritten wird (statt „Ungültige Seite oder Bilddatei“).

**English**

- Fixed rendering in the browser on servers with a small upload limit: the page image was sent as a large PNG and often exceeded `upload_max_filesize` for colourful pages. It is now sent as JPEG and automatically compressed to fit the server's upload limit.
- Clear error message if the upload limit is still exceeded (instead of "Invalid page or image file").

## 1.0.1

**Deutsch**

- Installation über den Contao Manager korrigiert: Die Empfehlungen für Server-Komponenten (ClamAV, Poppler, Imagick/Gmagick) standen als Composer-Vorschläge in der `composer.json` und konnten im Contao Manager ausgewählt werden. Das führte zu einem Abbruch („Could not parse version constraint clamav“). Die Empfehlungen stehen jetzt nur noch im README.

**English**

- Fixed installation via the Contao Manager: the recommendations for server components (ClamAV, Poppler, Imagick/Gmagick) were listed as Composer suggestions in `composer.json` and could be selected in the Contao Manager. This caused an abort ("Could not parse version constraint clamav"). The recommendations are now only listed in the README.

## 1.0.0

**Deutsch**

- PDF-Dateien in allen Bild-Auswahlfeldern (News, Events, FAQ, Inhaltselemente, Module, Felder anderer Erweiterungen mit Bild-Endungen)
- Ausgabe über die Contao-Bildpipeline: responsive Bildgrößen, Dichten, Media-Queries, WebP/AVIF, Lightbox
- Seitenauswahl pro PDF, wichtiger Bereich wie bei Bildern (Contao-Standardfelder und -Editor), optional fester Zuschnitt, optionales eigenes Vorschaubild
- Inhaltselement „PDF-Seiten“ für mehrere Seiten
- Renderer-Kette in dieser Reihenfolge: Imagick/Gmagick, pdftoppm/Ghostscript, Browser (pdf.js), eigenes Vorschaubild
- Mehrstufige Sicherheitsprüfung inkl. ClamAV, auch für Core-Vorschauen
- Erkennung von PDFs, die per FTP/Deployment abgelegt wurden (Inhalts-Hash, stündlicher Cron, Hinweis auf der Backend-Startseite)
- Konsolenbefehl `contao-pdf-preview:scan`
- Alle Texte auf Deutsch und Englisch
- Kompatibel mit Contao 5.3, 5.7 und 6.x

**English**

- PDF files in all image selection fields (news, events, FAQ, content elements, modules, fields of other extensions using image extensions)
- Output via the Contao image pipeline: responsive image sizes, densities, media queries, WebP/AVIF, lightbox
- Page selection per PDF, important part like with images (Contao standard fields and editor), optional fixed crop, optional custom preview image
- Content element "PDF pages" for several pages
- Renderer chain in this order: Imagick/Gmagick, pdftoppm/Ghostscript, browser (pdf.js), custom preview image
- Multi-level security check including ClamAV, also for core previews
- Detection of PDFs placed via FTP/deployment (content hash, hourly cron, notice on the back end start page)
- Console command `contao-pdf-preview:scan`
- All texts in German and English
- Compatible with Contao 5.3, 5.7 and 6.x
