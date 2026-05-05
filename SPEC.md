# 4a-pricing — Specification

> **Status**: DRAFT. Single source of truth for what the system must do.
> Update this file BEFORE writing code that contradicts it.

## 1. System purpose

Manage all pricing data of 4A Express (Greece + Cyprus) and generate
CMS-ready imports for [cms.4aexpress.com](https://cms.4aexpress.com).

Replaces `Z1-Z8 2026.xlsm` and its VBA macros.

## 2. Glossary

See `docs/glossary.md` for full EN/EL terminology.

Quick reference:
- **Service** — CMS service (e.g. S1003 Export Express, S1041 Import Road, COMBI services)
- **Zone** — geographic tier (Z1 closest → Z9 furthest, defined per Service in CMS)
- **Pricelist** — autonomous named entity (like a "supermarket"); customers subscribe to one
- **Slab Rate** — CMS pricing format, cumulative (see `docs/cms-import-format.md`)
- **FC (Fuel Charge)** — % surcharge per service; can be overridden per customer
- **Tariff** — versioned set of base costs (DHL yearly, internal, etc.)

## 3. Entities

### 3.1 Office
- `id` (GR, CY)
- `name`
- `country`

### 3.2 Service (mirrors CMS Service Master)
- `code` (S1003, S1010, S1012, S1041, ...)
- `name` (Export Express, Import Road, ...)
- `mode` (AIR, SURFACE, SHIPCARGO, SHIPCOURIER, COMBI)
- `divisor` (volumetric divisor; 5000, 6000, 3000, ...)
- `tat` (turnaround time, optional)
- `sms_required` (boolean)
- `active` (boolean)

### 3.3 Zone
- `id`
- `service_id` (zones are per-service in CMS)
- `code` (z1..z9)
- `display_name`

### 3.4 ZoneCountryMapping (mirrors CMS Zone/Country Mapping)
- `zone_id`
- `country_code` (or city, depending on origin/destination type)

### 3.5 Carrier
- `id` (DHL, INTERNAL, CYPRUS_DOMESTIC, ...)
- `name`

### 3.6 Tariff (versioned base costs)
- `id`
- `carrier_id`
- `service_id`
- `region` (e.g. "International", "Greece domestic", "Cyprus domestic")
- `valid_from`, `valid_to`
- `notes`

### 3.7 TariffEntry
- `tariff_id`
- `zone_id`
- `weight_kg`
- `cost`

### 3.8 FuelChargeMaster (default FC per service)
- `id`
- `service_id`
- `rate_pct` (e.g. 22.00)
- `effective_from`, `effective_to`
- `is_default` (boolean)

### 3.9 CustomerFuelChargeOverride
- `customer_id`
- `service_id`
- `rate_pct` (e.g. 19.50)
- `effective_from`, `effective_to`
- `reason`

### 3.10 Pricelist (autonomous, named entity — "supermarket")
- `id`
- `name` (e.g. "1136", "ΕΥΑ", "χαλδαιος", "γραφω 123")
- `service_id`
- `docs_type` (DOCS, NON_DOCS)
- `status` (draft, active, archived)
- `valid_from`, `valid_to`
- `notes`

### 3.11 PricelistEntry (Slab Rate row)
- `pricelist_id`
- `origin_type` (Country, Zone, City)
- `origin` (value)
- `destination_type` (Country, Zone, City)
- `destination` (value)
- `from_weight`
- `to_weight`
- `rate`
- `add_weight`
- `add_rate`
- `upto_weight`

### 3.12 Customer (mirrors CMS Client Master)
- `customer_reference` (C1378, ...)
- `account_no`
- `powersoft_acc`
- `name`
- `office_id` (Station)
- `customer_group`
- `classification`
- `customer_type`
- `is_fuel_charge_applicable` (boolean)
- `status` (Active, Suspended)
- `sales_exec`

### 3.13 CustomerPricelistAssignment
- `customer_id`
- `pricelist_id`
- `valid_from`, `valid_to`

### 3.14 CustomerService (which services a customer can use)
- `customer_id`
- `service_id`
- `active`

### 3.15 ChargeType (mirrors CMS Charge Type Master)
- `code` (OTH, INSUR, BATTERIES, PALLETE, PRO, ...)
- `name`
- `category` (e.g. "TERMINATE CHARGES", "OTHER CHARGES", "INSURANCE-VALUE")
- `active`

### 3.16 CyprusSurcharge
- `applies_to_services` (list of service codes — currently COMBI only)
- `rate_per_kg` (€1.20)
- `weight_basis` ("actual" — never volumetric)
- `valid_from`, `valid_to`

## 4. Pricing Calculation

Final amount calculation (matches CMS Price Calculator):
