# Contao PDF Preview

**PDF-Dateien als responsive Bilder – in News, Events und überall, wo Contao ein Bild auswählen lässt.**

Eine Open-Source-Erweiterung von Gunnar Haeuschkel / [Drum und Dran](https://drum-und-dran.de) für Contao **5.3 LTS, 5.7 LTS und 6.x**.

Redakteure wählen im Backend einfach eine PDF statt eines Bildes aus, zum Beispiel einen Flyer, ein Programmheft oder eine Speisekarte. Im Frontend erscheint die gewünschte Seite als gestochen scharfe, responsive Grafik. Die PDF selbst bleibt dabei unverändert.

> **Für Redakteure:** Eine Schritt-für-Schritt-Anleitung in einfacher Sprache liegt hier: [docs/ANLEITUNG-REDAKTEURE.md](docs/ANLEITUNG-REDAKTEURE.md).
>
> **English:** [README.en.md](README.en.md) · [Editor guide](docs/EDITOR-GUIDE.md)

---

## Funktionen

### PDF überall dort, wo ein Bild gewählt werden kann
- **News** und **Events** (Beitragsbild)
- **Inhaltselemente**: Bild, Text mit Bild, Hyperlink mit Bild, Akkordeon, YouTube/Vimeo-Vorschaubild, **Galerie** (auch PDFs aus ausgewählten Ordnern)
- **FAQ**, Frontend-Module, Seiten und **Erweiterungen von Drittanbietern**: Jedes Dateiauswahlfeld, das Contaos Bild-Endungen verwendet, wird automatisch erkannt.
- Insert-Tags `{{image::…}}` / `{{figure::…}}` und die Twig-Funktion `figure()`

### Volle Contao-Bildpipeline
Die Ausgabe läuft über die Bildverarbeitung von Contao. Deshalb funktioniert alles, was Contao bei Bildern kann:
- **Responsive Bildgrößen** (unter „Themes“, „Bildgrößen“) mit `srcset`, `sizes`, Pixeldichten und Media-Queries
- moderne Formate (**WebP/AVIF**), Lazy Loading, Deferred Resizing
- **Großansicht/Lightbox**
- Metadaten der PDF aus der Dateiverwaltung (Alt-Text, Bildunterschrift, Link …)

### Seitenauswahl, auch für mehrseitige PDFs
In der **Dateiverwaltung** wird pro PDF festgelegt, welche Seite(n) verwendet werden: `1`, `3`, `2,4`, `1,3-5` oder `*` (alle).
- Felder mit einem Bild (News, Events, Bild-Element …) zeigen die **erste** ausgewählte Seite.
- Das neue Inhaltselement **„PDF-Seiten“** zeigt **mehrere Seiten** als responsive Bilder: mit Bildgröße, Spaltenanzahl und Lightbox. Optional verlinkt jede Seite direkt auf die passende Stelle der PDF (`#page=N`). Die Seitenauswahl lässt sich pro Element überschreiben.

### Bildausschnitt – wie bei Bildern im Contao-Standard
Der **wichtige Bereich** einer PDF-Seite wird in der Dateiverwaltung genauso festgelegt wie bei Bildern: mit **Contaos eigenem Editor**. Gespeichert wird er in den Standardfeldern `importantPartX/Y/Width/Height`.
- **Standard-Verhalten:** Contao berücksichtigt den Bereich beim Zuschneiden auf feste Bildgrößen (Modus „crop“), genau wie bei Fotos.
- **„PDF nur im wichtigen Bereich anzeigen“:** Mit diesem Haken wird die Seite fest auf den Bereich zugeschnitten, zum Beispiel um nur das Foto eines Flyers zu zeigen.

Die Originaldatei wird dabei nicht verändert (nicht-destruktiv).

### Eigenes Vorschaubild (optional)
Pro PDF lässt sich ein eigenes Bild hinterlegen. Es ersetzt die gerenderte Hauptseite und dient als letzte Rückfallebene.

---

## Funktioniert auf (fast) jedem Webhosting

Webhostings unterscheiden sich stark: Manche haben Imagick, andere nicht. Manchmal blockiert ImageMagick PDFs per Policy, oder `exec()` ist deaktiviert. Die Erweiterung nutzt deshalb eine **Renderer-Kette** und wechselt automatisch zur nächsten Stufe:

| Stufe | Renderer | Voraussetzung |
|---|---|---|
| 1 | **Imagick/Gmagick** (Contao-Core) | PHP-Extension + Ghostscript, PDF in der ImageMagick-Policy erlaubt |
| 2 | **pdftoppm** (Poppler) oder **Ghostscript** per Kommandozeile | `proc_open` erlaubt, Programm installiert |
| 3 | **Im Browser** des Redakteurs mit **pdf.js** | nichts, funktioniert auf jedem Hosting |
| 4 | **Eigenes Vorschaubild** | in der Dateiverwaltung hinterlegt |

Kann der Server nicht rendern, erzeugt das Backend beim Öffnen der PDF in der Dateiverwaltung die Seitenbilder automatisch im Browser (pdf.js ist mitgeliefert, es werden **keine externen CDNs** geladen). Der Server prüft die Bilder und speichert sie, danach werden sie wie gewohnt weiterverarbeitet. Die Dateiverwaltung zeigt jederzeit, welche Stufe gerade aktiv ist.

Gerenderte Seiten werden in `assets/previews` zwischengespeichert. Wird die PDF ersetzt, werden die Vorschauen automatisch neu erzeugt.

### Hosting-Prüfung

Die Erweiterung erkennt die Möglichkeiten und Grenzen des Hostings und passt sich daran an: Arbeitsspeicher (`memory_limit`), maximale Laufzeit, Upload-Limit, freier Speicherplatz, deaktivierte Funktionen (`proc_open`), vorhandene Renderer, Virenscanner und PHP-Erweiterungen. Echte Probleme (z. B. fehlende PHP-Erweiterungen, kaum noch Speicherplatz) erscheinen auf der Backend-Startseite, weitere Einschränkungen mit ihrer Auswirkung in der Dateiverwaltung.

```bash
vendor/bin/contao-console contao-pdf-preview:check
```

Bei knappen Ressourcen arbeitet die Erweiterung schrittweise statt abzubrechen: Die Prüfung aller PDFs wird bei kurzer Laufzeit auf mehrere Cron-Läufe verteilt, der Speicher für das Entpacken richtet sich nach dem freien Arbeitsspeicher.

### Speicherplatz

Vorschaubilder sollen den Webspace nicht füllen. Ein **täglicher Cronjob** entfernt deshalb:
- Vorschauen gelöschter oder ersetzter PDFs (auch per FTP ausgetauschter Dateien)
- Zuschnitte, die seit 30 Tagen nicht mehr genutzt wurden (sie entstehen bei Bedarf neu)
- liegen gebliebene temporäre Dateien

Im Browser gerenderte Seiten werden als JPEG gespeichert (etwa 80 % kleiner als PNG). Manuell: `contao-pdf-preview:check --cleanup`. Vorschauen, die vor Version 1.1.0 erzeugt wurden, lassen sich einmalig mit `contao-pdf-preview:scan --purge-previews` entfernen.

---

## Sicherheit

PDFs sind ein beliebter Angriffsvektor. Die Erweiterung prüft deshalb **jede PDF, bevor sie überhaupt einen Renderer erreicht**. Diese Prüfung schützt auch Funktionen von Contao selbst, die PDFs rendern (Vorschaubilder im Download-Element, Dateivorschau im Backend).

**Mehrstufige Prüfung (Grundsatz: im Zweifel blockieren):**

1. **Größenlimit** (Standard: 50 MB)
2. **Strenge Strukturprüfung**: PDF-Header am Dateianfang, Dateiende-Marker vorhanden. Polyglot-Dateien und manipulierte Dateien werden abgewiesen. Fälschlich als `.pdf` gespeicherte Dateien werden mit ihrem tatsächlichen Format benannt (z. B. PNG, HTML).
3. **Verschlüsselte PDFs** werden blockiert, weil ihr Inhalt nicht prüfbar ist.
4. **Aktive Inhalte** werden erkannt und blockiert: JavaScript, Launch-Aktionen, eingebettete Dateien, XFA-Formulare, RichMedia/Flash, ImportData, GoToE. Das gilt auch für **maskierte Namen** (`/J#61vaScript`) und für Inhalte in **komprimierten Objekt-Streams**. Nicht prüfbare Streams und **Kompressionsbomben** führen ebenfalls zur Sperre. Eine Kompressionsbombe wird am Verhältnis von gepackter zu entpackter Größe erkannt (ab 1:200, übliche PDFs erreichen etwa 1:30), nicht an einer festen Größe.
5. **Virenscan mit ClamAV**, wahlweise über den clamd-Socket, `clamdscan` oder `clamscan`. Optional als Pflicht konfigurierbar.

**Ausfüllbare Formulare:** Formular-Editoren (Acrobat, LibreOffice u. a.) hinterlegen für Datums-, Zahlen- und Berechnungsfelder JavaScript, das ausschließlich die eingebauten Acrobat-Formularfunktionen aufruft (z. B. `AFDate_FormatEx("dd.mm.yyyy")`). Dieser Code wird vollständig gelesen, auch aus komprimierten und ausgelagerten Objekten, und gegen eine feste Liste dieser Funktionen geprüft. Werden nur feste Werte übergeben, ist die PDF unbedenklich und erhält lediglich einen Hinweis. Jeder andere Code wird mit einem Auszug blockiert. Absende-Schaltflächen (`SubmitForm`) gelten ebenfalls als Hinweis. Mit `allow_form_features: false` wird jedes JavaScript blockiert.

**Eingebettete Dateien:** Anhänge werden mit Name, Typ und Größe gemeldet und blockiert. Ausnahme sind Herkunftsnachweise nach C2PA („Content Credentials“, z. B. von WeasyPrint), die am Inhalt erkannt werden und nur einen Hinweis erzeugen.

**Eindeutige Meldungen:** Jeder Befund nennt die konkrete Ursache, z. B. das betroffene Objekt, die Stelle, an der das Entpacken abbricht, den zlib-Fehler und wie viele Objekte lesbar sind, oder den Auszug des beanstandeten JavaScript-Codes.

**Manuelle Freigabe:** Ein Administrator kann eine geprüfte Datei trotz Befund freigeben:

```bash
vendor/bin/contao-console contao-pdf-preview:scan files/formular.pdf --approve
vendor/bin/contao-console contao-pdf-preview:scan --approvals                  # alle Freigaben
vendor/bin/contao-console contao-pdf-preview:scan files/formular.pdf --revoke
```

Die Freigabe gilt nur für genau diesen Dateiinhalt (SHA-256) und erlischt, sobald die Datei verändert wird. Der Virenscan läuft weiterhin. Virenfunde, Dateien, die keine PDF sind, und Polyglot-Dateien lassen sich nie freigeben.

**Weitere Schutzmaßnahmen:**
- Ghostscript läuft immer mit `-dSAFER`, alle externen Prozesse mit Zeitlimit und ohne Shell (keine Kommando-Injection).
- pdf.js läuft im Backend ohne `eval`, ohne XFA und ohne PDF-JavaScript.
- Im Browser gerenderte Bilder werden auf Typ, Größe und Abmessungen geprüft und **vollständig neu codiert**. Angehängte Fremddaten werden dabei verworfen.
- Der interne Endpunkt erfordert einen Backend-Login, Zugriff auf die Dateiverwaltung, Bearbeitungsrechte, die passende Dateifreigabe (Filemount) und ein gültiges Request-Token.
- Unsichere PDFs lassen sich im Backend **nicht als Bild auswählen**. Beim **Upload über die Contao-Dateiverwaltung** wird sofort geprüft und gewarnt. Blockierte PDFs erscheinen im Frontend nicht.
- **Upload per FTP/SFTP oder Deployment:** Die Prüfung hängt nicht am Upload-Weg. Jede PDF wird **vor jeder Verarbeitung** geprüft, egal wie sie auf den Server kam. Zusätzlich durchsucht ein **stündlicher Contao-Cronjob** das Dateiverzeichnis. Administratoren sehen blockierte Dateien als **Hinweis auf der Backend-Startseite** und im System-Log.
- Prüfergebnisse werden anhand des **Datei-Inhalts** (Hash) zwischengespeichert. Eine per FTP ausgetauschte Datei wird auch dann neu geprüft, wenn Name, Datum und Größe gleich bleiben.
- Mit `contao-pdf-preview:scan` lässt sich jederzeit manuell oder per System-Cron neu prüfen, z. B. nach Signatur-Updates von ClamAV.

> **Hinweis:** Keine Software kann eine absolute Sicherheit garantieren. Die Prüfung senkt das Risiko deutlich. Ghostscript, Poppler, ImageMagick und ClamAV sollten immer aktuell sein. Für maximale Sicherheit wird **ClamAV** mit `require_virus_scan: true` empfohlen.
>
> Vorschaubilder liegen wie alle Contao-Bilder öffentlich in `assets/`. PDFs aus geschützten Ordnern deshalb nur dann als Bild verwenden, wenn die gezeigte Seite öffentlich sein darf.

---

## Installation

**Über den Contao Manager:** Nach „dud/contao-pdf-preview“ suchen, installieren und anschließend die Datenbank aktualisieren.

**Über Composer:**
```bash
composer require dud/contao-pdf-preview
vendor/bin/contao-console contao:migrate
```

**Testversion als ZIP (ohne Packagist/GitHub):** Im Contao Manager unter **Pakete** über **„Pakete hochladen“** die Datei `dud-contao-pdf-preview-<version>.zip` hochladen, installieren und die Datenbank aktualisieren.

### Systemvoraussetzungen

| Contao | PHP |
|---|---|
| 5.3 LTS | ab 8.1 |
| 5.7 LTS | ab 8.3 |
| 6.x | ab 8.4 |

Die Erweiterung selbst benötigt PHP 8.1 oder höher. Das tatsächliche Minimum gibt die jeweilige Contao-Version vor, Composer bzw. der Contao Manager wählen die passende Kombination automatisch.

### Empfohlene Server-Ausstattung
- `ext-imagick` mit Ghostscript **oder** `poppler-utils` (pdftoppm)
- ClamAV (`clamd`)

Ohne diese Komponenten funktioniert die Erweiterung trotzdem. Dann wird im Browser gerendert, und es gibt keinen Virenscan. Die im Browser gerenderten Seitenbilder werden automatisch an das Upload-Limit des Servers (`upload_max_filesize`, `post_max_size`) angepasst. Ein Limit von mindestens 8 MB ergibt die beste Bildqualität.

### Server-Rendering aktivieren

Zeigt die Dateiverwaltung „Vorschau: Browser (pdf.js)“, kann der Server PDFs nicht selbst rendern. Am einfachsten lässt sich das mit Poppler beheben (Debian/Ubuntu):

```bash
sudo apt install poppler-utils
```

Die Erweiterung findet `pdftoppm` automatisch, eine Konfiguration ist nicht nötig. Voraussetzung ist, dass PHP externe Programme starten darf (`proc_open`). Das ist der Fall, wenn ClamAV über `clamdscan` bereits funktioniert.

Alternativ über Imagick: `php-imagick` und `ghostscript` installieren und in der ImageMagick-Richtlinie (z. B. `/etc/ImageMagick-6/policy.xml`) das Lesen von PDF erlauben (`<policy domain="coder" rights="read" pattern="PDF" />`). Danach PHP-FPM neu starten.

Wird ein Renderer nachträglich installiert, rendert der Server die PDFs beim nächsten Aufruf automatisch selbst. Nur nach einer Änderung der ImageMagick-Richtlinie einmal `vendor/bin/contao-console contao-pdf-preview:scan --purge-previews` ausführen.

---

## Berechtigungen für Redakteure

Administratoren haben automatisch alle Rechte. Für Redakteure werden in der **Benutzergruppe** (oder beim Benutzer) folgende Rechte vergeben. Alle Felder der Erweiterung beginnen mit „PDF“ und sind dadurch in der Liste eindeutig zu finden.

| Bereich in der Gruppe | Recht | Wofür |
|---|---|---|
| Backend-Module | Dateien | Dateiverwaltung öffnen |
| Filemounts | die gewünschten Ordner | Zugriff auf die PDFs |
| Erlaubte Datei-Operationen | Dateien und Verzeichnisse bearbeiten, kopieren und verschieben | PDF-Einstellungen bearbeiten, Vorschau im Browser erzeugen |
| Erlaubte Felder, Dateien (`tl_files`) | PDF-Seite(n) als Bild `[pdfPreviewPages]` | Seitenauswahl |
| | PDF: eigenes Vorschaubild `[pdfPreviewImage]` | eigenes Vorschaubild |
| | PDF nur im wichtigen Bereich anzeigen `[pdfCropToImportantPart]` | fester Zuschnitt |
| | x-/y-Koordinate, Breite und Höhe des wichtigen Teils `[importantPart…]` | wichtiger Bereich (Contao-Standardfelder, wie bei Bildern) |
| Inhaltselemente | Media-Elemente: PDF-Seiten | Element „PDF-Seiten“ |
| Erlaubte Felder, Inhaltselemente (`tl_content`) | PDF-Seiten: Seitenauswahl `[pdfPages]`, PDF-Seiten: mit PDF verlinken `[pdfLinkToFile]` | Optionen des Elements |

Die Vorschau samt Sicherheitsstatus ist kein eigenes Recht. Den Editor für den wichtigen Bereich zeigt sie nur, wenn die vier `importantPart`-Felder erlaubt sind. Der Hinweis auf blockierte PDFs auf der Backend-Startseite erscheint nur für Administratoren.

---

## Konfiguration (optional)

In `config/config.yaml` (alle Werte sind Standardwerte):

```yaml
contao_pdf_preview:
    preview_size: 2048          # Auflösung der gerenderten Seite (kürzere Kante, px)
    default_page: 1
    backend_fields: true        # PDFs in Bild-Auswahlfeldern erlauben
    excluded_fields: ['tl_theme.screenshot']   # z. B. 'tl_news.*'
    gallery: true               # PDFs in Galerien berücksichtigen
    lightbox: true              # Großansicht für PDF-Bilder
    cli_binary: ~               # Pfad zu pdftoppm/gs; ~ = automatisch, false = aus
    security:
        enabled: true
        max_file_size: 52428800
        max_decompressed_size: 0    # 0 = automatisch nach freiem Arbeitsspeicher
        blocked_features: [JavaScript, JS, Launch, EmbeddedFile, EmbeddedFiles, RichMedia, XFA, SubmitForm, ImportData, GoToE]
        allow_form_features: true   # Standard-Formular-JavaScript und SubmitForm nur als Hinweis
        allow_encrypted: false
        virus_scan: auto        # auto | clamav | off
        require_virus_scan: false
        clamav_socket: ~        # z. B. /run/clamd.scan/clamd.sock oder tcp://127.0.0.1:3310
        clamav_binary: ~
```

### Konsolenbefehl
```bash
vendor/bin/contao-console contao-pdf-preview:scan               # alle PDFs in files/ prüfen
vendor/bin/contao-console contao-pdf-preview:scan files/flyer   # bestimmter Ordner/Datei
vendor/bin/contao-console contao-pdf-preview:scan --no-cache --purge-previews
vendor/bin/contao-console contao-pdf-preview:scan files/formular.pdf --approve
vendor/bin/contao-console contao-pdf-preview:check              # Hosting-Prüfung
vendor/bin/contao-console contao-pdf-preview:check --cleanup    # Vorschau-Cache bereinigen
```

Für eigene Tests mit echten Dateien: `DUD_PDF_CORPUS=/pfad/zu/pdfs vendor/bin/phpunit` prüft, dass keine PDF aus diesem Ordner blockiert wird.

---

## Für Entwickler

Technisch dekoriert die Erweiterung den Service `contao.image.factory`. Wird ein PDF-Pfad übergeben, wird er transparent durch die geprüfte, gerenderte und ggf. zugeschnittene Seite ersetzt. Dadurch funktionieren **alle** Stellen, die Contaos Bildpipeline nutzen, ohne Template-Änderungen. Das Rendern übernimmt Contaos `PreviewFactory`, ergänzt um eigene Preview-Provider (Sicherheitsschranke, CLI-Fallback). Der wichtige Bereich aus `tl_files.importantPart*` wird als `ImportantPart` an das gerenderte Seitenbild übergeben.

Templates: `content_element/pdf_pages.html.twig` kann wie jedes Contao-Template angepasst werden.

---

## Sponsoring

Die Erweiterung ist kostenlos und quelloffen. Weiterentwicklung, Pflege und die Kompatibilität mit neuen Contao-Versionen kosten jedoch Zeit. Unterstützung ist über **GitHub Sponsors** möglich:

**[github.com/sponsors/drum-und-dran](https://github.com/sponsors/drum-und-dran)**

Auch Firmen können gezielt Funktionen sponsern. Kontakt: [drum-und-dran.de](https://drum-und-dran.de)

---

## Dank

Besonderer Dank gilt **Leo Feyer** für die Gründung von Contao (ursprünglich TYPOlight), die jahrelange Leitung des Projekts und die Grundlage, auf der diese Erweiterung aufbaut.

Herzlichen Dank an das **Contao-Core-Entwicklerteam** für dieses wunderbare CMS und die sorgfältige Arbeit daran, an alle, die Contao mit Erweiterungen, Übersetzungen, Dokumentation und Antworten in der Community bereichern, und an alle, die Contao nutzen und damit lebendig halten.

---

## Lizenz & Kontakt

MIT-Lizenz. Mitgeliefert: [pdf.js](https://github.com/mozilla/pdf.js) (Apache License 2.0).

**Gunnar Haeuschkel / Drum und Dran** · [drum-und-dran.de](https://drum-und-dran.de) · Fehler und Wünsche: [GitHub Issues](https://github.com/drum-und-dran/contao-pdf-preview/issues) · [Sponsor werden](https://github.com/sponsors/drum-und-dran)
