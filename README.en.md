# Contao PDF Preview

**PDF files as responsive images – in news, events and wherever Contao allows selecting an image.**

An open source extension by Gunnar Haeuschkel / [Drum und Dran](https://drum-und-dran.de) for Contao **5.3 LTS, 5.7 LTS and 6.x**.

Editors simply select a PDF instead of an image in the back end, for example a flyer, a programme or a menu. In the front end, the chosen page appears as a crisp, responsive image. The PDF itself stays untouched.

> **For editors:** A step-by-step guide in plain language is available here: [docs/EDITOR-GUIDE.md](docs/EDITOR-GUIDE.md).
>
> **Deutsch:** [README.md](README.md) · [Anleitung für Redakteure](docs/ANLEITUNG-REDAKTEURE.md)

---

## Features

### PDF wherever an image can be selected
- **News** and **events** (teaser image)
- **Content elements**: image, text with image, hyperlink with image, accordion, YouTube/Vimeo splash image, **gallery** (including PDFs from selected folders)
- **FAQ**, front end modules, pages and **third-party extensions**: every file picker that uses Contao's image extensions is detected automatically.
- Insert tags `{{image::…}}` / `{{figure::…}}` and the Twig function `figure()`

### Full Contao image pipeline
The output uses Contao's image processing, so everything Contao can do with images works:
- **Responsive image sizes** (under "Themes", "Image sizes") with `srcset`, `sizes`, pixel densities and media queries
- modern formats (**WebP/AVIF**), lazy loading, deferred resizing
- **Lightbox / full-size view**
- PDF metadata from the file manager (alt text, caption, link …)

### Page selection, also for multi-page PDFs
In the **file manager**, the page(s) to be used are set per PDF: `1`, `3`, `2,4`, `1,3-5` or `*` (all).
- Fields with a single image (news, events, image element …) show the **first** selected page.
- The new content element **"PDF pages"** shows **several pages** as responsive images: with image size, number of columns and lightbox. Optionally, each page links directly to the matching position in the PDF (`#page=N`). The page selection can be overridden per element.

### Image section – just like images in standard Contao
The **important part** of a PDF page is set in the file manager exactly like with images: with **Contao's own editor**. It is stored in the standard fields `importantPartX/Y/Width/Height`.
- **Default behaviour:** Contao respects the area when cropping to fixed image sizes (mode "crop"), just like with photos.
- **"Show PDF in the important part only":** With this option the page is cropped to the area, for example to show only the photo of a flyer.

The original file is never modified (non-destructive).

### Custom preview image (optional)
A custom image can be stored per PDF. It replaces the rendered main page and serves as the last fallback.

---

## Works on (almost) any web hosting

Web hostings differ a lot: some have Imagick, others don't. Sometimes ImageMagick blocks PDFs via policy, or `exec()` is disabled. The extension therefore uses a **renderer chain** and automatically moves on to the next level:

| Level | Renderer | Requirement |
|---|---|---|
| 1 | **Imagick/Gmagick** (Contao core) | PHP extension + Ghostscript, PDF allowed in the ImageMagick policy |
| 2 | **pdftoppm** (Poppler) or **Ghostscript** via command line | `proc_open` allowed, program installed |
| 3 | **In the editor's browser** with **pdf.js** | nothing, works on any hosting |
| 4 | **Custom preview image** | stored in the file manager |

If the server cannot render, the back end automatically renders the page images in the browser when the PDF is opened in the file manager (pdf.js is bundled, **no external CDNs** are loaded). The server checks and stores the images, which are then processed as usual. The file manager always shows which level is currently active.

Rendered pages are cached in `assets/previews`. When the PDF is replaced, the previews are regenerated automatically.

---

## Security

PDFs are a popular attack vector. The extension therefore checks **every PDF before it reaches any renderer**. This check also protects Contao's own features that render PDFs (preview images in the download element, file preview in the back end).

**Multi-level check (principle: block when in doubt):**

1. **Size limit** (default: 50 MB)
2. **Strict structure check**: PDF header at the start of the file, end-of-file marker present. Polyglot files and manipulated files are rejected.
3. **Encrypted PDFs** are blocked because their content cannot be inspected.
4. **Active content** is detected and blocked: JavaScript, launch actions, embedded files, XFA forms, RichMedia/Flash, SubmitForm, ImportData, GoToE. This also applies to **obfuscated names** (`/J#61vaScript`) and to content in **compressed object streams**. Uninspectable streams and **compression bombs** are blocked as well.
5. **Virus scan with ClamAV**, via the clamd socket, `clamdscan` or `clamscan`. Can optionally be made mandatory.

**Further protection:**
- Ghostscript always runs with `-dSAFER`, all external processes run with a time limit and without a shell (no command injection).
- pdf.js runs in the back end without `eval`, without XFA and without PDF JavaScript.
- Images rendered in the browser are checked for type, size and dimensions and **completely re-encoded**. Any appended data is discarded.
- The internal endpoint requires a back end login, access to the file manager, edit permissions, the matching filemount and a valid request token.
- Unsafe PDFs **cannot be selected as images** in the back end. **Uploads via the Contao file manager** are checked immediately with a warning. Blocked PDFs never appear in the front end.
- **Upload via FTP/SFTP or deployment:** The check does not depend on how a file was uploaded. Every PDF is checked **before any processing**, no matter how it got onto the server. In addition, an **hourly Contao cron job** scans the files directory. Administrators see blocked files as a **notice on the back end start page** and in the system log.
- Scan results are cached based on the **file content** (hash). A file replaced via FTP is checked again even if name, date and size stay the same.
- `contao-pdf-preview:scan` re-checks at any time, manually or via system cron, e.g. after ClamAV signature updates.

> **Note:** No software can guarantee absolute security. The check reduces the risk considerably. Ghostscript, Poppler, ImageMagick and ClamAV should always be kept up to date. For maximum security, **ClamAV** with `require_virus_scan: true` is recommended.
>
> Like all Contao images, preview images are publicly accessible in `assets/`. Only use PDFs from protected folders as images if the page shown may be public.

---

## Installation

**Via the Contao Manager:** Search for "dud/contao-pdf-preview", install it and then update the database.

**Via Composer:**
```bash
composer require dud/contao-pdf-preview
vendor/bin/contao-console contao:migrate
```

**Test version as ZIP (without Packagist/GitHub):** In the Contao Manager under **Packages**, use **"Upload packages"** to upload the file `dud-contao-pdf-preview-<version>.zip`, install it and update the database.

### System requirements

| Contao | PHP |
|---|---|
| 5.3 LTS | 8.1 or higher |
| 5.7 LTS | 8.3 or higher |
| 6.x | 8.4 or higher |

The extension itself requires PHP 8.1 or higher. The actual minimum is defined by the Contao version; Composer or the Contao Manager pick the matching combination automatically.

### Recommended server setup
- `ext-imagick` with Ghostscript **or** `poppler-utils` (pdftoppm)
- ClamAV (`clamd`)

The extension also works without these components. In that case pages are rendered in the browser and there is no virus scan. Page images rendered in the browser are automatically adapted to the server's upload limit (`upload_max_filesize`, `post_max_size`). A limit of at least 8 MB gives the best image quality.

### Enabling server-side rendering

If the file manager shows "Preview: browser (pdf.js)", the server cannot render PDFs itself. The easiest fix is Poppler (Debian/Ubuntu):

```bash
sudo apt install poppler-utils
```

The extension detects `pdftoppm` automatically, no configuration is needed. PHP must be allowed to run external programs (`proc_open`). This is the case if ClamAV already works via `clamdscan`.

Alternatively via Imagick: install `php-imagick` and `ghostscript` and allow reading PDF in the ImageMagick policy (e.g. `/etc/ImageMagick-6/policy.xml`: `<policy domain="coder" rights="read" pattern="PDF" />`). Then restart PHP-FPM.

If a renderer is installed later, the server renders the PDFs itself on the next request. Only after changing the ImageMagick policy, run `vendor/bin/contao-console contao-pdf-preview:scan --purge-previews` once.

---

## Permissions for editors

Administrators automatically have all permissions. For editors, the following permissions are granted in the **user group** (or on the user). All fields of the extension start with "PDF" and are therefore easy to find in the list.

| Section in the user group | Permission | Purpose |
|---|---|---|
| Back end modules | Files | open the file manager |
| Filemounts | the desired folders | access to the PDFs |
| File operation permissions | Edit, copy or move files and folders | edit PDF settings, render previews in the browser |
| Allowed fields, files (`tl_files`) | PDF page(s) as image `[pdfPreviewPages]` | page selection |
| | PDF: custom preview image `[pdfPreviewImage]` | custom preview image |
| | Show PDF in the important part only `[pdfCropToImportantPart]` | fixed crop |
| | Important part x/y coordinate, width and height `[importantPart…]` | important part (Contao standard fields, like images) |
| Content elements | Media elements: PDF pages | element "PDF pages" |
| Allowed fields, content elements (`tl_content`) | PDF pages: page selection `[pdfPages]`, PDF pages: link to PDF `[pdfLinkToFile]` | element options |

The preview including the security status is not a separate permission. It only shows the important part editor if the four `importantPart` fields are allowed. The notice about blocked PDFs on the back end start page is only shown to administrators.

---

## Configuration (optional)

In `config/config.yaml` (all values are defaults):

```yaml
contao_pdf_preview:
    preview_size: 2048          # resolution of the rendered page (shorter edge, px)
    default_page: 1
    backend_fields: true        # allow PDFs in image selection fields
    excluded_fields: ['tl_theme.screenshot']   # e.g. 'tl_news.*'
    gallery: true               # include PDFs in galleries
    lightbox: true              # full-size view for PDF images
    cli_binary: ~               # path to pdftoppm/gs; ~ = automatic, false = off
    security:
        enabled: true
        max_file_size: 52428800
        max_decompressed_size: 104857600
        blocked_features: [JavaScript, JS, Launch, EmbeddedFile, EmbeddedFiles, RichMedia, XFA, SubmitForm, ImportData, GoToE]
        allow_encrypted: false
        virus_scan: auto        # auto | clamav | off
        require_virus_scan: false
        clamav_socket: ~        # e.g. /run/clamd.scan/clamd.sock or tcp://127.0.0.1:3310
        clamav_binary: ~
```

### Console command
```bash
vendor/bin/contao-console contao-pdf-preview:scan               # check all PDFs in files/
vendor/bin/contao-console contao-pdf-preview:scan files/flyer   # specific folder/file
vendor/bin/contao-console contao-pdf-preview:scan --no-cache --purge-previews
```

---

## For developers

Technically, the extension decorates the `contao.image.factory` service. When a PDF path is passed, it is transparently replaced by the checked, rendered and, if configured, cropped page. As a result, **every** place that uses Contao's image pipeline works without template changes. Rendering is done by Contao's `PreviewFactory`, extended by custom preview providers (security barrier, CLI fallback). The important part from `tl_files.importantPart*` is passed to the rendered page image as `ImportantPart`.

Templates: `content_element/pdf_pages.html.twig` can be customised like any Contao template.

All texts are available in German and English (`contao/languages/de|en`).

---

## Sponsoring

The extension is free and open source. Development, maintenance and compatibility with new Contao versions take time, though. Support is possible via **GitHub Sponsors**:

**[github.com/sponsors/drum-und-dran](https://github.com/sponsors/drum-und-dran)**

Companies can also sponsor specific features. Contact: [drum-und-dran.de](https://drum-und-dran.de)

---

## License & contact

MIT license. Bundled: [pdf.js](https://github.com/mozilla/pdf.js) (Apache License 2.0).

**Gunnar Haeuschkel / Drum und Dran** · [drum-und-dran.de](https://drum-und-dran.de) · Bugs and feature requests: [GitHub Issues](https://github.com/drum-und-dran/contao-pdf-preview/issues) · [Become a sponsor](https://github.com/sponsors/drum-und-dran)
