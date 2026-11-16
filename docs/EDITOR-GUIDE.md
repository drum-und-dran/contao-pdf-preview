# Showing a PDF as an image – guide for editors

*Deutsche Version: [ANLEITUNG-REDAKTEURE.md](ANLEITUNG-REDAKTEURE.md)*

This extension allows a **PDF file to be used like an image**.
For example a flyer, a poster or a menu.
Visitors of the website then see an image of the PDF.

**No image editing** is required.
The PDF file itself is **not changed**.

---

## Step 1: Upload the PDF

1. Click **Files** in the menu on the left.
2. Choose a folder.
3. Click **Upload files** at the top.
4. Select the PDF file and upload it.

Exactly the same as with images.

**Important:** Every PDF is checked for threats automatically.
This also applies to files uploaded via FTP.
If a **red message** appears, the PDF is not safe.
It will then **not** be shown as an image.
In that case, request a new, clean PDF from the sender.

---

## Step 2: Choose page and area (optional)

This step is optional.
Without any setting, the **first page** is shown in full automatically.

1. In **Files**, click the **pencil** (edit) next to the PDF.
2. An image of the PDF is now displayed.

### Which page should be shown?

Enter the page in the field **"PDF page(s) as image"**.

| Input | What is shown |
|---|---|
| *(leave empty)* | page 1 |
| `2` | page 2 |
| `1,3` | page 1 and page 3 |
| `2-4` | pages 2, 3 and 4 |
| `*` | all pages |

**Good to know:** News, events and normal images always show just **one** image.
That is the **first** page of the list.
Several pages are shown by the element **"PDF pages"** (see below).

### Marking the important part

This works **just like with images** in Contao.

1. The PDF page is displayed under **"Preview and important part"**.
2. Move the mouse onto the image.
3. **Hold down the left mouse button** and drag a frame around the important part.
   For example around the photo on the flyer.
4. Release the mouse button.
5. Click **Save** at the bottom.

**What happens then?**
If Contao has to crop the image (for example for a square image),
the marked area always stays visible.

### Showing only the marked area

Should **only** the marked part be shown and the rest of the page be left out?

1. Mark the area as described above.
2. Tick **"Show PDF in the important part only"**.
3. Click **Save**.

**Show everything again:** Untick the option and save.

### Custom preview image (only if needed)

The field **"PDF: custom preview image"** allows selecting a normal image.
**That image** is then shown instead of the PDF page.
This is normally **not** needed.

---

## Step 3: Use the PDF as an image

The PDF is selected **just like an image**.

### In a news item or event

1. Open the news item or event.
2. Tick **"Add an image"**.
3. Next to **"Source file"**, click **"Change selection"**.
4. Select the **PDF file**. It is listed next to the images.
5. Choose a suitable size under **"Image size"**.
6. Click **Save**.

### In a content element

This works the same way for the elements **Image**, **Text** (with image), **Gallery** and **Hyperlink** (with image):
simply select the PDF when choosing the image.

---

## Showing several pages: element "PDF pages"

Should several pages of a PDF be shown below or next to each other?

1. Create a new content element.
2. Choose **"PDF pages"** as **element type** (under "Media elements").
3. Select the PDF under **"Source file"**.
4. Pages can be entered under **"PDF pages: page selection"**, for example `1-3`.
   If the field stays empty, the pages from the file manager are used.
5. Choose the size under **"Image size"**.
6. Set how many pages are shown next to each other under **"Thumbnails per row"**.
7. Should the pages open large when clicked? Then tick **"Full-size view/new window"**.
8. Should a click open the PDF? Then tick **"PDF pages: link to PDF"**.
9. Click **Save**.

---

## Frequently asked questions

**The PDF does not appear when selecting an image.**
Check whether the right folder is open.
If the PDF was blocked by the security check, it cannot be selected.

**When saving, the message "was blocked by the security check" appears.**
The PDF contains something dangerous or unusual.
Use a different PDF or ask the webmaster.

**The file manager says "rendering in the browser now".**
That's normal. Wait a few seconds until the image appears.
Keep the window open in the meantime.
If the preview image looks faulty, click **"Regenerate preview image"**.

**The PDF was replaced, but the website still shows the old image.**
Reload the website. The images are renewed automatically.
If the image is still old, open the PDF once in the file manager.

**There is a red list of PDF files on the back end start page.**
These PDFs were detected as unsafe. They are **not** shown on the website.
Please pass the list on to the webmaster.

**Is the PDF changed in the process?**
No. The PDF stays exactly as it was uploaded.
Page and area only apply to the image on the website.

---

For questions or problems, the webmaster is happy to help.
Contact the developer: [Drum und Dran](https://drum-und-dran.de).
