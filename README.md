# 4a-pricing

Pricing engine & CMS import generator for **4A Express** (Greece + Cyprus offices).

## Purpose

Generates CMS-ready price imports for [cms.4aexpress.com](https://cms.4aexpress.com) by managing:

- **DHL yearly tariffs** (Express + Road, multiple zones, weight brackets)
- **Per-customer rate notifications** with markup over base costs
- **Fuel charges** (default per service + per-customer overrides)
- **Cyprus surcharge** (€1.20/kg for CY↔GR leg in COMBI services)
- **CMS Slab Rate format** with incremental pricing

## Status

🚧 **Phase 3b — Pricelist Editor & Table UI** — σε εξέλιξη.

## Roadmap

- [x] **Phase 0**: Discover CMS structure
- [x] **Phase 1**: Specification
- [x] **Phase 2**: Import DHL 2026 tariffs
  - ✅ 4 services: S1003 ✈️↗️, S1012 ✈️🇬🇷↙️, S1010 🚛↗️, S1041 🚛🇬🇷↙️
  - ✅ Monotonicity validated (R1), JSON exported, max 1000kg
- [x] **Phase 3a**: CMS import generator (Python)
  - ✅ `poc/generate_cms_import.py` — R4 συμπύκνωση + R12 markup στο rate
  - ✅ Δοκιμάστηκε επιτυχώς στο CMS
- [ ] **Phase 3b**: Pricelist UI (HTML/JS — GitHub Pages)
  - ✅ `frontend/fuel-surcharge-dashboard.html` — αυτόματος επίναυλος DHL
  - ✅ `frontend/pricelist-editor.html` — login PIN, markup ανά service, ράφι, πελάτες
  - ✅ `frontend/pricelist-table.html` — πίνακας ανά βάρος/zone, Min% (1-click flat, 2-click cascade), 120+/80+ γραμμή
  - ⏳ CMS Export από browser (JavaScript) — εκκρεμεί
- [ ] **Phase 3c**: Αυτόματη ενημέρωση επίναυλου — GitHub Actions 07:00
  - ✅ `poc/fetch_fuel_surcharge.py` — Selenium scraper DHL
  - ✅ `.github/workflows/update_fuel_surcharge.yml`
  - ⏳ UPS, FedEx, Aramex, Skynet (εκκρεμεί — απαιτούνται URLs)
- [ ] **Phase 4**: FastAPI + PostgreSQL backend
- [ ] **Phase 5**: Multi-office — users, roles, audit log
- [ ] **Phase 6**: Migrate customers from Excel

## Infrastructure

- **GitHub Pages**: `https://4aexpress.github.io/4a-pricing/frontend/`
- **GitHub Actions**: Αυτόματη ενημέρωση `data/fuel_surcharge_cache.json` καθημερινά
- **localStorage**: Αποθήκευση pricelists/clients στον browser (έως Phase 4)

## Stack (planned)

- **Backend**: Python 3.11 + FastAPI + Pydantic
- **Database**: PostgreSQL
- **Frontend**: React (MVP: static HTML/JS)
- **Auth**: JWT
- **Hosting**: Railway / Render / Fly.io

## Repo structure

```
4a-pricing/
├── .github/workflows/
│   └── update_fuel_surcharge.yml   # Καθημερινή ενημέρωση επίναυλου
├── data/
│   ├── dhl_tariff_2026_S1003.json
│   ├── dhl_tariff_2026_S1012.json
│   ├── dhl_tariff_2026_S1010.json
│   ├── dhl_tariff_2026_S1041.json
│   ├── dhl_tariffs_2026_ALL.json
│   └── fuel_surcharge_cache.json   # Auto-updated καθημερινά
├── docs/
│   ├── business-rules.md           # R1-R13
│   ├── cms-import-format.md        # CMS Slab Rate TSV format
│   ├── cms-mapping.md              # TODO
│   └── glossary.md                 # TODO
├── frontend/
│   ├── fuel-surcharge-dashboard.html
│   ├── pricelist-editor.html
│   └── pricelist-table.html
├── poc/
│   ├── generate_cms_import.py      # CMS import generator
│   └── fetch_fuel_surcharge.py     # DHL fuel surcharge scraper
├── CLAUDE.md
├── README.md
└── SPEC.md
```

## Users (Phase 3b)

| Χρήστης | Γραφείο | Ρόλος | PIN |
|---|---|---|---|
| Απόστολος Ορφανίδης | Αθήνα | Manager | 1111 |
| Κύπρος Manager | — | Manager | TBD |
