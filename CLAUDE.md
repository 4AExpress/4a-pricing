# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

**Run all tests:**
```bash
TEST_BASE_URL=https://4aexpress.com/api TEST_TOKEN=<token> ADMIN_TOKEN=<admin_token> pytest tests/
```

**Run a single test:**
```bash
pytest tests/test_cod.py::test_case2_calculate_flat_min_fee -v
```

**Deploy** — `api/` (PHP) deploy = SCP per file ΜΟΝΟ. `git push` ΔΕΝ ενημερώνει το backend (webhook νεκρός, server git παγωμένο, nested `api/.git`). Frontend = `git push` → GitHub Pages.

There is no build step, no bundler, no transpiler. Frontend is plain HTML/JS served directly from GitHub Pages at `https://4aexpress.github.io/4a-pricing/frontend/`.

## Architecture

### Two separate environments

| Layer | Where | Tech |
|---|---|---|
| Frontend | GitHub Pages | Static HTML + vanilla JS |
| Backend | `4aexpress.com/api/` | PHP + MySQL |

Frontend files use `const API = 'https://4aexpress.com/api'` hardcoded. There is no proxy, no CORS preflight issue for same-origin requests — the frontend is on a different origin, so all API calls are cross-origin (`Access-Control-Allow-Origin: *` is set by the backend).

### PHP API conventions

Every PHP endpoint follows the same pattern:

```php
require_once 'config.php';          // provides db(), respond(), body()
db()->exec("CREATE TABLE IF NOT EXISTS ..."); // idempotent DDL — runs every request
$method = $_SERVER['REQUEST_METHOD'];
// ... handle GET / POST / PUT
respond(['ok' => true]);            // json_encode + exit
```

- `db()` — singleton PDO (MySQL credentials in `config.php`, not in repo)
- `respond($data, $code=200)` — sets JSON headers, encodes, exits
- `body()` — `json_decode(file_get_contents('php://input'), true)`

Top-level files (`services.php`, `shelf.php`, etc.) are accessed directly as `/api/services.php`. Subdirectory endpoints (`cod/calculate.php`, `admin/cod/config.php`, `shipments/waybill.php`) are routed via `.htaccess` rewrites.

### Auth

`api/auth.php` provides two guards:
- `require_user()` — any valid session token (any role)
- `require_admin()` — role must be `'administrator'`

Tokens are `Bearer` tokens in `Authorization` header. The frontend stores the session object in `localStorage` as `4a_user` and reads `currentUser.token` to set the header.

Public endpoints (e.g. `cod/calculate`, `cod/services`) require no auth.

### COD subsystem

Four tables involved:
- `4a_cod_config` (singleton row `id=1`) — global thresholds/rates, managed via `admin/cod/config.php`
- `cod_service_config` — per-service COD settings (migration 004)
- `4a_cod_config_log` — audit log of config changes

The list of COD-capable service codes lives in `api/cod_constants.php` as `COD_CAPABLE_SERVICES` — import this constant instead of hardcoding codes elsewhere.

Fee calculation logic (in `api/cod/calculate.php`):
- `cod_amount ≤ threshold` → `fee = min_fee` (flat)
- `cod_amount > threshold` → `fee = max(min_fee, cod_amount × percentage / 100)`

### Business rules

All pricing rules are documented with IDs in `docs/business-rules.md`. The critical ones:

- **R3/R4** — CMS Slab Rate is incremental (diff between brackets), not flat. Consecutive identical rates compress into one row via `UptoWeight`.
- **R12** — Apply markup to the *incremental rate*, not the flat price, or rounding accumulates and prevents compression (35× more rows).
- **R7** — Ignore DHL's 0.3 kg envelope bracket; start from 0.5 kg.
- **R9** — Cyprus surcharge (€1.20/kg, actual weight only, COMBI services only).

### Frontend page responsibilities

- `pricelist-editor.html` — login (PIN-based), pricelist creation, shelf management
- `pricelist-table.html` — per-weight/zone price editing with markup controls
- `pricelist-clients.html` — client CRM: create offers, generate PDF, send email; contains `draftPreview()` (main PDF builder as HTML blob) and `buildOfferEmailAttachments()` (multi-doc email)
- `services.html` — CRUD for `4a_services` table
- `zones.html` — zone/country mapping management
- `users.html` — user management (admin only)
- `fuel-surcharge-dashboard.html` — displays current fuel surcharges from `data/fuel_surcharge_cache.json` (auto-updated by GitHub Actions)

### DHL tariff data

Raw tariffs are in root-level JSON files (`dhl_tariff_2026_S1003.json`, etc.) and also cached in `localStorage` by the frontend at runtime. The frontend fetches them from `raw.githubusercontent.com` on first load and caches in `localStorage`.

### GitHub Actions

`.github/workflows/update_fuel_surcharge.yml` runs daily at 07:00 to update `data/fuel_surcharge_cache.json` by scraping DHL. This is rule R13 — manual fuel surcharge entry is explicitly forbidden.
