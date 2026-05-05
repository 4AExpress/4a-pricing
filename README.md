# 4a-pricing

Pricing engine & CMS import generator for **4A Express** (Greece + Cyprus offices).

## Purpose

Generates CMS-ready price imports for [cms.4aexpress.com](https://cms.4aexpress.com) by managing:

- **DHL yearly tariffs** (Express + Road, multiple zones, weight brackets)
- **Internal & domestic tariffs** (Greece, Cyprus, future routes)
- **Per-customer rate notifications** with markup over base costs
- **Fuel charges** (default per service + per-customer overrides)
- **Cyprus surcharge** (€1.20/kg for CY↔GR leg in COMBI services)
- **Additional charges** (insurance, batteries, pallete, protocol)
- **CMS Slab Rate format** with incremental pricing (FromWeight, ToWeight, Rate, AddWeight, AddRate, UptoWeight)

Replaces the current Excel-based workflow (`Z1-Z8 2026.xlsm`) with a multi-user web application.

## Why a new system

Current Excel has accumulated regressions:
- Validation checks only Zone 1 → broken prices in Z3-Z7 pass as ✓
- Shared functions break callers when modified
- Greek strings corrupt on copy-paste in VBA
- No audit trail, no role-based access, no multi-user
- One person bottleneck for the whole pricing operation

## Status

🚧 **Phase 3 — Python proof-of-concept.** Generating CMS-ready import files from DHL tariffs + markup.

## Roadmap

- [x] **Phase 0**: Discover CMS structure (Service Master, Client Master, Charge Types, Slab Rate format)
- [x] **Phase 1**: Specification (this phase)
- [x] **Phase 2**: Import yearly DHL tariffs as structured data
  - ✅ 4 services parsed: S1003, S1012, S1010, S1041
  - ✅ Monotonicity validated across all zones (Z7 first)
  - ✅ JSON exported to `data/` — versioned, valid_from 2026-01-01
  - ✅ Max weight 1000kg
- [ ] **Phase 3**: Python proof-of-concept — read JSON + markup % → generate CMS import TSV
- [ ] **Phase 4**: FastAPI + PostgreSQL backend, minimal web UI
- [ ] **Phase 5**: Multi-office rollout — users, roles, audit log
- [ ] **Phase 6**: Migrate active customers from Excel to web app

## Stack (planned)

- **Backend**: Python 3.11 + FastAPI + Pydantic
- **Database**: PostgreSQL
- **Frontend**: React (or server-rendered HTML for MVP)
- **Auth**: JWT
- **Hosting**: Railway / Render / Fly.io
- **CI**: GitHub Actions

## Repo structure

```
4a-pricing/
├── data/                        # DHL tariff JSON files (versioned)
│   ├── dhl_tariff_2026_S1003.json
│   ├── dhl_tariff_2026_S1012.json
│   ├── dhl_tariff_2026_S1010.json
│   ├── dhl_tariff_2026_S1041.json
│   └── dhl_tariffs_2026_ALL.json
├── docs/
│   ├── business-rules.md        # R1-R11 invariants
│   ├── cms-import-format.md     # CMS Slab Rate TSV format
│   ├── cms-mapping.md           # CMS ↔ engine entity mapping (TODO)
│   └── glossary.md              # EN/EL terminology (TODO)
├── poc/                         # Phase 3 scripts (TODO)
├── CLAUDE.md
├── README.md
└── SPEC.md
```
