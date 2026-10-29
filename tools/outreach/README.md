<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Outreach material

`build.mjs` makes a flyer, a business card and two banners about the
Cantoria app, with the name, logo, contact and colours of the choir that
hands them out. It is a tool for choirs and for the project, not a function of
the app, and it is not part of the release archive.

| Product | Size | Files in `out/<lang>/` |
|---|---|---|
| Flyer DIN lang, two-sided | 99 × 210 mm | `flyer-dl.pdf` (final size, for office printers and screens), `flyer-dl-print.pdf` (with bleed, for the print shop), previews `flyer-dl-1.png` (front) and `flyer-dl-2.png` (back) |
| Business card, two-sided | 85 × 55 mm | `business-card.pdf`, `business-card-print.pdf`, previews `business-card-1.png` and `business-card-2.png` |
| Banner for link previews | 1280 × 640 px | `banner-social.png`, e.g. as "Social preview" of a repository or for posts |
| Wide banner | 1500 × 500 px | `banner-web.png`, for a website or the header of a profile |

Each product also gets an `.html` file. It stands alone (logo and QR code are
embedded, nothing is loaded from the internet) and shows the pages in any
browser. The texts are German by default; `--lang en` builds the English
edition.

## Requirements

- Node.js 22 or newer
- Chrome, Chromium or Microsoft Edge for the PDFs and pictures. The script
  looks in the usual places; otherwise give the path with `--browser`.
- Once: `npm ci` in this folder (needs network access). It installs
  [puppeteer-core](https://pptr.dev), which drives the browser, and
  [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) for
  the QR code. Neither is part of the app.

## Make your own flyer

1. Install the tool once:

   ```sh
   cd tools/outreach
   npm ci
   ```

2. Copy the example sender and put your logo into a folder `sender/`:

   ```sh
   cp example/sender.json sender.json
   mkdir sender
   cp /path/to/your-logo.svg sender/logo.svg
   ```

   The commands work in PowerShell, too.
3. Open `sender.json` in a text editor and fill in your choir (see
   [The sender fields](#the-sender-fields)). Set `"logo": "sender/logo.svg"`.
4. Build:

   ```sh
   node build.mjs
   ```

   The script prints every file it writes, and warnings when a colour has too
   little contrast or a text does not fit.
5. Check the results in `out/de/`: look at the previews (`*.png`) or the PDFs,
   read every text once and scan the QR code with a phone.
6. Send `flyer-dl-print.pdf` and `business-card-print.pdf` to the print shop:
   two-sided, final size 99 × 210 mm and 85 × 55 mm, 3 mm bleed on every
   side. For printing at home or in the office, use `flyer-dl.pdf` and
   `business-card.pdf`.

`sender.json` and the folder `sender/` are listed in `.gitignore`: your sender
belongs to your choir, not to this repository. Keep a copy in your choir's
own files.

## The sender fields

Paths are relative to the sender file. Every field except `name` may be empty
(`""`) or left out.

| Field | Meaning | Where it appears |
|---|---|---|
| `name` | name of your choir (required) | everywhere |
| `tagline` | a short line under the name, e.g. "Gemischter Chor" | flyer front |
| `logo` | logo file: SVG (best), PNG, JPEG or WebP | flyer, card, wide banner, link preview banner |
| `logo_on_dark` | a version of the logo for dark ground; when empty, the logo stands on a light plate | link preview banner |
| `web`, `mail`, `phone` | contact; empty fields are left out | flyer back, card back |
| `colors` | six colours as `#rrggbb`, see below | everywhere |
| `fonts` | `heading` and `body`, each a list of font names as in CSS | everywhere |

| Colour | Used for |
|---|---|
| `paper` | light ground of the pages |
| `ink` | text and the QR code |
| `accent` | headings and numbers; ground of the link preview banner |
| `deep` | dark band of the flyer, back of the card |
| `light` | text on `deep` and `accent`, staff lines |
| `muted` | secondary text on `paper` |

Colours and fonts that the sender file leaves out come from
`example/sender.json`. The build warns when a text colour has less contrast
than 4.5:1 on its ground (WCAG 2.2 AA).

No fonts are loaded from the internet: name fonts that are installed on the
computer that builds, e.g. `"heading": "\"Noto Serif\", Georgia, serif"`. The
browser takes the next name of the list when a font is missing. Names with
spaces need quotes, written as `\"…\"` inside the JSON text.

## Options

| Option | Meaning |
|---|---|
| `--sender <file>` | sender file (default: `sender.json` in this folder, otherwise `example/sender.json`) |
| `--lang <code>` | language of the texts (default: `de`; `en` is there as well) |
| `--only <a,b>` | only these products: `flyer-dl`, `business-card`, `banner-social`, `banner-web` |
| `--out <dir>` | output folder (default: `out`); the files go to `<dir>/<lang>/` |
| `--bleed <mm>` | bleed of the print PDFs (default: 3; `0` makes no print PDFs) |
| `--browser <path>` | Chrome, Chromium or Edge (or the environment variable `BROWSER_PATH`) |
| `--html-only` | write the HTML files only, without a browser |

`out/` is listed in `.gitignore`.

## The texts

`content.json` holds the address of the project (also used for the QR code)
and, under `texts`, one block of texts per language. The texts describe the
app as it is, following the app's [README](../../README.md) and
`appinfo/info.xml`, and name no choir. When the app changes, update them and
`as_of` together; do not announce anything that is not built yet. Keep the
sentence that the rights helpers follow German law and are no legal advice.

To add a language, copy the `de` block, name it with the language code (e.g.
`fr`), translate every entry and build with `--lang fr`. Use neutral,
inclusive wording, as the app does.

The layout is in `templates/`: one HTML file per product and `base.css` for
all of them. `{{name}}` inserts a value as text, `{{{name}}}` as HTML, and
`{{#each list}}…{{/each}}` repeats a block for every item of a list.

## Accessibility

- Text is at least 9 pt on the flyer and 8 pt on the card.
- The example colours reach a contrast of at least 4.5:1 for every text; the
  build checks the colours of your sender file in the same way.
- The HTML files and the tagged PDFs name their language. The logo and the QR
  code have text alternatives; decorations are hidden from screen readers.
- No information depends on colour alone.

## Common problems

| Problem | What to do |
|---|---|
| "Only the HTML files were written: no Chrome, Chromium or Edge was found" | Install one of them, or give its path: `node build.mjs --browser "C:\Program Files\Google\Chrome\Application\chrome.exe"`. Until then, open an HTML file in a browser and print it to PDF with no margins, background graphics on and the paper size from the table above. |
| "The packages of this tool are not installed" | Run `npm ci` in `tools/outreach`. |
| "… is not valid JSON" | A comma is missing or there is one too many (none after the last entry of a block), or a quote is missing. The message names the line and column. |
| "The logo … does not exist" | Paths are relative to the sender file. With `sender.json` in this folder and the logo in `sender/`, write `"logo": "sender/logo.svg"`. |
| Warning "the content runs … past the edge" | The name, tagline or contact is too long for the layout. Shorten it, or leave out the tagline. |
| Warning "… has a contrast of …" | Make the text colour darker on `paper`, or lighter on `deep` and `accent`. |
| The logo looks blurred | Use an SVG, or a PNG at least 1000 px wide. |
| A font looks different than expected | It is not installed on the computer that builds. Install it, or name another font in `fonts`. |
| The print shop asks for CMYK or crop marks | The PDFs are RGB with bleed and without crop marks; most online print shops convert the colours and need nothing else. Ask the shop if in doubt. |
| The print shop reports a page size a fraction of a millimetre off | Chrome rounds page sizes by up to about 0.2 mm. The bleed covers it, and most shops accept it. |
| The QR code does not scan | Keep `ink` dark, and scan a printed proof rather than the screen. |

## License

The tool, the templates and the texts are free software under
AGPL-3.0-or-later, like the app. The example sender "Beispielchor Musterstadt
e. V.", its addresses (`example.org`) and its logo are invented. Your own
sender file and logo stay yours.
