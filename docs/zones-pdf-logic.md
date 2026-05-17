# Zones PDF Badge & Merge Logic

## When the badge appears

The **"📄 Ζώνες 4A GR"** badge is shown automatically in the pricelist-clients page whenever at least one selected pricelist belongs to a GR service. It appears inline to the right of the pricelist tags row.

## Which services trigger it

```js
const GR_SERVICES = new Set(['S1003','S1012','S1010','S1041','S1003_GR','S1012_GR']);
```

| Service | Description |
|---------|-------------|
| S1003 / S1003_GR | Αεροπορική Εξαγωγή |
| S1012 / S1012_GR | Αεροπορική Εισαγωγή |
| S1010 | Οδική Εξαγωγή |
| S1041 | Οδική Εισαγωγή |

The check runs inside `renderPlTags()` after every add/remove:

```js
const hasGR = selectedPls.some(p => GR_SERVICES.has(p.service_id));
document.getElementById('zone-pdf-badge-wrap').style.display = hasGR ? 'block' : 'none';
```

Clicking the badge opens `4A_Express_Zones_2026.pdf` in a new tab (GitHub Pages).

## How the PDF merge works (previewPDF)

When the user clicks **Προεπισκόπηση PDF**, if any selected pricelist is a GR service, the zones PDF is fetched and appended to the offer PDF using [pdf-lib](https://pdf-lib.js.org/):

1. The offer PDF arrives as a base64 string from the server and is decoded to `Uint8Array`.
2. The zones PDF is fetched from GitHub Pages (`fetch(ZONES_PDF_URL)`).
3. Both are loaded as `PDFDocument` objects via `pdf-lib`.
4. A new merged document is created; all offer pages are copied first, then all zones pages.
5. The merged document is saved and opened as a Blob URL in a new tab.

If the fetch or merge fails (e.g. CORS, network error), the error is caught and the offer-only PDF is opened instead — the user still gets the preview.

The zones PDF URL:
```
https://4aexpress.github.io/4a-pricing/docs/4A_Express_Zones_2026.pdf
```

## Future CY extension

Cyprus uses zone **Z9** (special rate). To add a separate CY zones badge/merge:

1. Add a `CY_SERVICES` constant (e.g. `['S1050','S1051']`).
2. In `renderPlTags()`, check `selectedPls.some(p => CY_SERVICES.has(p.service_id))` and show a second badge linking to a Cyprus-specific PDF.
3. In `previewPDF()`, similarly merge the CY zones PDF when any CY service is present.
4. Generate a `4A_Express_Zones_CY.pdf` from a dedicated script similar to `generate_zones_pdf.py` but scoped to CY services.
