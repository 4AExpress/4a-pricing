# Zones PDF — Badge, TOC Entry & Merge Logic

## 1. Badge (UI)

The **"📄 Ζώνες 4A GR"** badge appears inline to the right of the pricelist tags whenever at least one selected pricelist belongs to a GR service.

**Triggering services:**

```js
const GR_SERVICES = new Set(['S1003','S1012','S1010','S1041','S1003_GR','S1012_GR']);
```

| Service | Description |
|---------|-------------|
| S1003 / S1003_GR | Αεροπορική Εξαγωγή |
| S1012 / S1012_GR | Αεροπορική Εισαγωγή |
| S1010 | Οδική Εξαγωγή |
| S1041 | Οδική Εισαγωγή |

The check runs in `renderPlTags()` after every add/remove:

```js
const hasGR = selectedPls.some(p => GR_SERVICES.has(p.service_id));
document.getElementById('zone-pdf-badge-wrap').style.display = hasGR ? 'block' : 'none';
```

Clicking the badge opens the zones PDF in a new tab.

---

## 2. TOC entry (server-side, via API parameter)

When generating the offer PDF, `previewPDF()` sends `zones_appendix` to `generate_pdf.php`:

```js
zones_appendix: hasGR
  ? {
      include:     true,
      code:        'zones',
      label:       'Ζώνες 4A Express GR',
      type:        'ΠΑΡΑΡΤΗΜΑ',
      toc_after:   'net',           // insert after the "net" row (TOC index 3)
      toc_section: 'ΕΠΙΣΥΝΑΠΤΟΜΕΝΑ' // section divider label above the row
    }
  : null
```

**Server responsibility** (`generate_pdf.php`):
- When `zones_appendix.include` is `true`, add a TOC row after the row with `code = 'net'` (position index 3) and before the pricelist rows.
- The row columns: `code="zones"` | `label="Ζώνες 4A Express GR"` | `type="ΠΑΡΑΡΤΗΜΑ"` | `page=<own_page_count + 1>`.
- Render a "ΕΠΙΣΥΝΑΠΤΟΜΕΝΑ" section divider above this row, visually separate from the main content rows.
- Add a PDF GoTo link annotation on the zones TOC row pointing to page `<own_page_count + 1>` (the first zones page, which the client appends immediately after).

---

## 3. PDF merge (client-side, `previewPDF`)

Zones pages are appended **directly** after the offer pages — no separator page:

```
[offer pages 1..N] + [zones pages 1..Z]
```

Implementation:

```js
const zonesResp  = await fetch(ZONES_PDF_URL);
const zonesBytes = new Uint8Array(await zonesResp.arrayBuffer());
const offerDoc   = await PDFDocument.load(offerBytes);
const zonesDoc   = await PDFDocument.load(zonesBytes);
const merged     = await PDFDocument.create();
(await merged.copyPages(offerDoc, offerDoc.getPageIndices())).forEach(p => merged.addPage(p));
(await merged.copyPages(zonesDoc, zonesDoc.getPageIndices())).forEach(p => merged.addPage(p));
finalBytes = await merged.save();
```

If the fetch or merge fails, the error is caught and the offer-only PDF is opened as fallback.

The zones PDF URL (GitHub Pages):
```
https://4aexpress.github.io/4a-pricing/docs/4A_Express_Zones_2026.pdf
```

---

## 4. Future CY extension

Cyprus uses zone **Z9** (special rate). To extend the same pattern for CY:

1. Define `CY_SERVICES = new Set(['S1050','S1051'])` (or relevant CY service IDs).
2. In `renderPlTags()`, add a second badge for CY that links to a `4A_Express_Zones_CY.pdf`.
3. In `previewPDF()`, if any CY service is selected:
   - Add a `cy_zones_appendix` parameter to the API call (same structure as `zones_appendix` but with CY-specific values and `toc_after: 'zones'`).
   - Fetch and append the CY zones PDF after the GR zones pages (or after the offer if no GR zones).
4. Generate `docs/4A_Express_Zones_CY.pdf` using a dedicated `generate_zones_cy_pdf.py` script.
