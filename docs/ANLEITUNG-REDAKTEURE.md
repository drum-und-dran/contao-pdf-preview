# PDF als Bild zeigen – Anleitung für Redakteure

*English version: [EDITOR-GUIDE.md](EDITOR-GUIDE.md)*

Mit dieser Erweiterung lässt sich eine **PDF-Datei wie ein Bild** verwenden.
Zum Beispiel ein Flyer, ein Plakat oder eine Speisekarte.
Auf der Webseite sehen die Besucher dann ein Bild von der PDF.

Dafür ist **keine Bildbearbeitung** nötig.
Die PDF-Datei selbst wird **nicht verändert**.

---

## Schritt 1: PDF hochladen

1. Links im Menü auf **Dateiverwaltung** klicken.
2. Einen Ordner auswählen.
3. Oben auf **Dateien hochladen** klicken.
4. Die PDF-Datei auswählen und hochladen.

Also genauso wie bei Bildern.

**Wichtig:** Jede PDF wird automatisch auf Gefahren geprüft.
Das gilt auch für Dateien, die per FTP hochgeladen werden.
Erscheint eine **rote Meldung**, ist die PDF nicht sicher.
Dann wird sie **nicht** als Bild gezeigt.
In diesem Fall beim Absender eine neue, saubere PDF anfordern.

---

## Schritt 2: Seite und Bereich wählen (freiwillig)

Dieser Schritt ist freiwillig.
Ohne Einstellung wird automatisch die **erste Seite** ganz gezeigt.

1. In der **Dateiverwaltung** bei der PDF auf den **Stift** (Bearbeiten) klicken.
2. Jetzt erscheint ein Bild von der PDF.

### Welche Seite soll gezeigt werden?

Die Seite in das Feld **„PDF-Seite(n) als Bild“** eintragen.

| Eingabe | Das wird gezeigt |
|---|---|
| *(leer lassen)* | Seite 1 |
| `2` | Seite 2 |
| `1,3` | Seite 1 und Seite 3 |
| `2-4` | Seite 2, 3 und 4 |
| `*` | alle Seiten |

**Gut zu wissen:** Bei News, Events und normalen Bildern wird immer nur **ein** Bild gezeigt.
Das ist dann die **erste** Seite aus der Liste.
Mehrere Seiten zeigt das Element **„PDF-Seiten“** (siehe unten).

### Den wichtigen Bereich markieren

Das funktioniert **genauso wie bei Bildern** in Contao.

1. Unter **„Vorschau und wichtiger Bereich“** ist die PDF-Seite zu sehen.
2. Mit der Maus auf das Bild gehen.
3. **Die linke Maustaste gedrückt halten** und einen Rahmen um den wichtigen Teil ziehen.
   Zum Beispiel um das Foto auf dem Flyer.
4. Die Maustaste loslassen.
5. Unten auf **Speichern** klicken.

**Was passiert dann?**
Muss Contao das Bild zuschneiden (zum Beispiel für ein quadratisches Bild),
bleibt der markierte Bereich immer sichtbar.

### Nur den markierten Bereich zeigen

Soll **nur** der markierte Teil gezeigt und der Rest der Seite weggelassen werden?

1. Den Bereich wie oben beschrieben markieren.
2. Den Haken bei **„PDF nur im wichtigen Bereich anzeigen“** setzen.
3. Auf **Speichern** klicken.

**Alles wieder zeigen:** Den Haken entfernen und speichern.

### Eigenes Vorschaubild (nur bei Bedarf)

Im Feld **„PDF: eigenes Vorschaubild“** lässt sich ein normales Bild auswählen.
Dann wird **dieses Bild** statt der PDF-Seite gezeigt.
Das ist normalerweise **nicht** nötig.

---

## Schritt 3: PDF als Bild verwenden

Die PDF wird **genauso ausgewählt wie ein Bild**.

### In einer News oder einem Event

1. Die News oder das Event öffnen.
2. Den Haken bei **„Ein Bild hinzufügen“** setzen.
3. Bei **„Quelldatei“** auf **„Auswahl ändern“** klicken.
4. Die **PDF-Datei** auswählen. Sie steht neben den Bildern.
5. Bei **„Bildgröße“** die passende Größe auswählen.
6. Auf **Speichern** klicken.

### In einem Inhaltselement

Das geht genauso bei den Elementen **Bild**, **Text** (mit Bild), **Galerie** und **Hyperlink** (mit Bild):
Bei der Bildauswahl einfach die PDF auswählen.

---

## Mehrere Seiten zeigen: Element „PDF-Seiten“

Sollen mehrere Seiten einer PDF untereinander oder nebeneinander gezeigt werden?

1. Ein neues Inhaltselement anlegen.
2. Als **Elementtyp** **„PDF-Seiten“** wählen (unter „Media-Elemente“).
3. Bei **„Quelldatei“** die PDF auswählen.
4. Bei **„PDF-Seiten: Seitenauswahl“** lassen sich die Seiten eintragen, zum Beispiel `1-3`.
   Bleibt das Feld leer, werden die Seiten aus der Dateiverwaltung genommen.
5. Bei **„Bildgröße“** die Größe wählen.
6. Bei **„Vorschaubilder pro Reihe“** einstellen, wie viele Seiten nebeneinander stehen.
7. Sollen die Seiten beim Anklicken groß erscheinen? Dann den Haken bei **„Großansicht/Neues Fenster“** setzen.
8. Soll ein Klick die PDF öffnen? Dann den Haken bei **„PDF-Seiten: mit PDF verlinken“** setzen.
9. Auf **Speichern** klicken.

---

## Häufige Fragen

**Die PDF erscheint nicht in der Bildauswahl.**
Prüfen, ob der richtige Ordner geöffnet ist.
Wurde die PDF bei der Prüfung blockiert, kann sie nicht ausgewählt werden.

**Beim Speichern kommt die Meldung „wurde von der Sicherheitsprüfung blockiert“.**
Die PDF enthält etwas Gefährliches oder Ungewöhnliches.
Die Meldung nennt den genauen Grund.
Eine andere PDF verwenden oder beim Webmaster nachfragen.
Nach einer Prüfung kann der Webmaster unbedenkliche Dateien freigeben.

**In der Dateiverwaltung steht ein Hinweis zu Formular-JavaScript.**
Das ist kein Fehler. Ausfüllbare Formulare enthalten kleine Skripte, z. B. für Datumsfelder.
Die PDF kann ganz normal verwendet werden.

**In der Dateiverwaltung steht „Das Hosting schränkt die PDF-Vorschau ein“.**
Ein Klick auf den Hinweis zeigt, welche Grenze des Webhostings betroffen ist.
Die PDF-Vorschau funktioniert trotzdem. Den Hinweis an den Webmaster weitergeben.

**In der Dateiverwaltung steht „wird jetzt im Browser erzeugt“.**
Das ist normal. Ein paar Sekunden warten, bis das Bild erscheint.
Das Fenster so lange geöffnet lassen.
Sieht das Vorschaubild fehlerhaft aus, auf **„Vorschaubild neu erzeugen“** klicken.

**Die PDF wurde ausgetauscht, aber auf der Webseite ist noch das alte Bild.**
Die Webseite neu laden. Die Bilder werden automatisch erneuert.
Ist das Bild dann immer noch alt, die PDF einmal in der Dateiverwaltung öffnen.

**Auf der Startseite im Backend steht eine rote Liste mit PDF-Dateien.**
Diese PDFs wurden als unsicher erkannt. Sie werden **nicht** auf der Webseite gezeigt.
Die Liste bitte an den Webmaster weitergeben.

**Wird die PDF dabei verändert?**
Nein. Die PDF bleibt genau so, wie sie hochgeladen wurde.
Seite und Bereich gelten nur für das Bild auf der Webseite.

---

Bei Fragen oder Problemen hilft der Webmaster gern weiter.
Kontakt zum Entwickler: [Drum und Dran](https://drum-und-dran.de).
