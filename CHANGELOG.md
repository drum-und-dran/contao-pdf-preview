# Changelog

## 1.1.1

**Deutsch**

- Herkunftsnachweise nach C2PA („Content Credentials“, z. B. von WeasyPrint 68 automatisch eingebettet) führen nicht mehr zur Sperre. Erkannt wird am Inhalt (JUMBF mit C2PA-Kennung), nicht am Namen.
- Andere eingebettete Dateien werden mit Name, Typ und Größe gemeldet statt nur mit „/EmbeddedFile“.
- Backend-Startseite: Hosting-Hinweise nur noch bei echten Problemen, ohne Hosting-Empfehlung. Ein fehlender Virenscanner erscheint nur noch in der Dateiverwaltung und entfällt mit `virus_scan: off`.
- Meldung zu blockierten PDFs klarer und kürzer: Betroffen ist nur das Vorschaubild, der Download nicht. Einzahl und Mehrzahl werden unterschieden.
- Darstellung der Hinweise in Contao 5.7 korrigiert (fehlende Leerzeichen).

**English**

- Proofs of origin according to C2PA ("Content Credentials", e.g. embedded automatically by WeasyPrint 68) no longer lead to blocking. They are recognized by their content (JUMBF with C2PA identifier), not by their name.
- Other embedded files are reported with name, type and size instead of just "/EmbeddedFile".
- Back end start page: hosting notices only for actual problems, without hosting recommendation. A missing virus scanner is only shown in the file manager and is omitted with `virus_scan: off`.
- Message about blocked PDFs clearer and shorter: only the preview image is affected, not the download. Singular and plural are distinguished.
- Display of the notices in Contao 5.7 fixed (missing spaces).

## 1.1.0

**Deutsch**

- Behoben: Objekt-Streams, deren komprimierte Daten mit dem Byte 0x0D oder 0x0A enden, galten fälschlich als „nicht entpackbar (Kompressionsbombe?)“. Die Daten werden jetzt über `/Length` gelesen, schrittweise und fehlertolerant entpackt.
- Kompressionsbomben werden am Verhältnis von gepackter zu entpackter Größe erkannt (ab 1:200), nicht mehr an einer festen Größe. Der Speicher pro Stream richtet sich nach dem freien `memory_limit`, jeder Stream wird einzeln geprüft und sofort wieder freigegeben (`max_decompressed_size: 0` = automatisch).
- Ausfüllbare Formulare: JavaScript, das ausschließlich die eingebauten Acrobat-Formularfunktionen (`AFDate_FormatEx`, `AFNumber_Format`, `AFSimple_Calculate` …) mit festen Werten aufruft, führt nicht mehr zur Sperre, sondern zu einem Hinweis. Anderer Code wird weiterhin blockiert und mit einem Auszug gemeldet. `SubmitForm` ist ebenfalls nur noch ein Hinweis. Abschaltbar mit `allow_form_features: false`.
- Eindeutige Meldungen: Jeder Befund nennt die konkrete Ursache (Objekt, Abbruchstelle, zlib-Fehler, lesbare Objekte, Code-Auszug, tatsächliches Dateiformat, Dateigröße und Limit). `/JavaScript` und `/JS` werden nur noch einmal gemeldet.
- Manuelle Freigabe geprüfter Dateien: `contao-pdf-preview:scan <Datei> --approve`, `--revoke`, `--approvals`. Gilt nur für genau diesen Inhalt (SHA-256), der Virenscan läuft weiter. Virenfunde, Nicht-PDFs und Polyglot-Dateien sind nie freigebbar.
- Hosting-Prüfung: Renderer, `proc_open`, Virenscanner, Arbeitsspeicher, Laufzeit, Upload-Limit, Speicherplatz, zlib und Bildbearbeitung werden erkannt. Einschränkungen erscheinen mit Empfehlung auf der Backend-Startseite und in der Dateiverwaltung, Details mit `contao-pdf-preview:check`.
- Ressourcen: Die Prüfung aller PDFs verteilt sich bei kurzer Laufzeit auf mehrere Cron-Läufe. Deaktivierte Funktionen und fehlende Erweiterungen werden erkannt und umgangen.
- Speicherplatz: Täglicher Cronjob entfernt Vorschauen gelöschter oder ersetzter PDFs, ungenutzte Zuschnitte (30 Tage) und temporäre Reste. Manuell mit `contao-pdf-preview:check --cleanup`. Im Browser gerenderte Seiten werden als JPEG statt PNG gespeichert (etwa 80 % kleiner).
- Die Liste blockierter PDFs auf der Backend-Startseite wird nach einem Update oder einer Konfigurationsänderung sofort neu geprüft und beim Upload direkt aktualisiert. Hinweise (z. B. Formular-JavaScript) erscheinen beim Upload und in der Dateiverwaltung.
- Sicherheit: Nicht vollständig prüfbare Inhalte (PCRE-Limit) werden blockiert, Ausgaben auf der Konsole maskiert, die Bereinigung löscht ausschließlich im Vorschau-Verzeichnis.
- README: Dank an Leo Feyer, das Contao-Core-Team und die Contao-Community.

**English**

- Fixed: object streams whose compressed data end with the byte 0x0D or 0x0A were wrongly reported as "cannot be decompressed (compression bomb?)". The data are now read via `/Length` and decompressed step by step and fault-tolerantly.
- Compression bombs are detected by the ratio of compressed to decompressed size (from 1:200) instead of a fixed size. The memory per stream depends on the free `memory_limit`, each stream is checked separately and released right away (`max_decompressed_size: 0` = automatic).
- Fillable forms: JavaScript that only calls the built-in Acrobat form functions (`AFDate_FormatEx`, `AFNumber_Format`, `AFSimple_Calculate` …) with fixed values no longer blocks the PDF but results in a notice. Other code is still blocked and reported with an excerpt. `SubmitForm` is a notice as well. Can be disabled with `allow_form_features: false`.
- Clear messages: every finding names the specific cause (object, position of the error, zlib error, readable objects, code excerpt, actual file format, file size and limit). `/JavaScript` and `/JS` are only reported once.
- Manual approval of reviewed files: `contao-pdf-preview:scan <file> --approve`, `--revoke`, `--approvals`. Applies to exactly this content (SHA-256), the virus scan keeps running. Virus findings, non-PDFs and polyglot files can never be approved.
- Hosting check: renderer, `proc_open`, virus scanner, memory, execution time, upload limit, disk space, zlib and image processing are detected. Limitations are shown with a recommendation on the back end start page and in the file manager, details via `contao-pdf-preview:check`.
- Resources: with a short execution time, checking all PDFs is spread over several cron runs. Disabled functions and missing extensions are detected and worked around.
- Disk space: a daily cron job removes previews of deleted or replaced PDFs, unused crops (30 days) and temporary leftovers. Manually via `contao-pdf-preview:check --cleanup`. Pages rendered in the browser are stored as JPEG instead of PNG (about 80 % smaller).
- The list of blocked PDFs on the back end start page is rechecked right after an update or a configuration change and updated directly on upload. Notices (e.g. form JavaScript) are shown on upload and in the file manager.
- Security: content that cannot be inspected completely (PCRE limit) is blocked, console output is escaped, the cleanup only deletes inside the preview directory.
- README: thanks to Leo Feyer, the Contao core team and the Contao community.

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
