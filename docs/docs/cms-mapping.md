# CMS Mapping

> How CMS entities ([cms.4aexpress.com](https://cms.4aexpress.com)) map to engine entities.
> Goal: 1-to-1 vocabulary so import generation needs no translation layer.

## CMS modules used

| CMS path | Purpose | Engine entity |
|---|---|---|
| Master → Service Master | Define services | `Service` |
| Master → Client Master | Customer database | `Customer` |
| Master → Charge Type Master | Additional charge types | `ChargeType` |
| Master → Country/State/City Master | Geography reference | (lookup, not stored) |
| Invoice → Zone Master | Define zones | `Zone` |
| Invoice → Zone/Country Mapping | Map countries to zones | `ZoneCountryMapping` |
| Invoice → Fuel Charge Master | Default FC per service | `FuelChargeMaster` |
| Invoice → Additional Rate Detail | Charge type rates | `ChargeTypeRate` (TBD) |
| Invoice → Client Rate Entry | Per-customer pricelists (manual UI) | `Pricelist` + `PricelistEntry` |
| Invoice → Client Rate Import | Per-customer pricelists (CSV upload) | `Pricelist` + `PricelistEntry` |
| Invoice → Re-Calculate Freight | Triggers price recalc on shipments | (CMS-side, no mirror) |
| Invoice → Price Calculator | Test calculation for given inputs | (validation reference) |

## Service Master

CMS field → Engine field

| CMS | Engine | Example |
|---|---|---|
| Service Code | `Service.code` | S1003, S1041 |
| Service Name | `Service.name` | Export Express, IMPORT ROAD |
| Mode | `Service.mode` | AIR, SURFACE, SHIPCARGO, SHIPCOURIER, COMBI |
| Divisor | `Service.divisor` | 5000 (default), 6000 (Air Freight), 3000 (Import Cargo) |
| TAT | `Service.tat` | (free text) |
| SMS Req. | `Service.sms_required` | YES / NO |
| Active | `Service.active` | YES / NO |

## Excel ↔ CMS service mapping (current)

| Excel name | CMS code | CMS name | Mode |
|---|---|---|---|
| ExpExp | S1003 | Export Express | AIR |
| ExpImp | S1012 | IMPORT EXPRESS | AIR |
| RoadExp | S1010 | Road 4-8 export | SURFACE |
| RoadImp | S1041 | IMPORT ROAD | SURFACE |

Cyprus office additionally uses COMBI services: S1048, S1049, S1050, S1051, S1053, S1054, S1055, S1056, S1060.

## Client Master

CMS field → Engine field

| CMS | Engine |
|---|---|
| Customer Reference | `Customer.customer_reference` (e.g. C1378) |
| Account No | `Customer.account_no` |
| Powersoft Acc. No | `Customer.powersoft_acc` |
| Company Name | `Customer.name` |
| Sales Exec. | `Customer.sales_exec` |
| Station | `Customer.office_id` (SKY-4A EXPRESS ATH → GR, etc.) |
| Customer Group | `Customer.customer_group` |
| Customer Classification | `Customer.classification` |
| Customer Type | `Customer.customer_type` |
| Is Fuel Charge Applicable | `Customer.is_fuel_charge_applicable` |
| Account Status | `Customer.status` (Active, Suspended) |
| Tab: Services | `CustomerService` (which services available) |
| Tab: Rates/Pricing | `CustomerPricelistAssignment` |

## Charge Type Master

| CMS code | CMS type | Active | Purpose |
|---|---|---|---|
| OTH | OTHER CHARGES | YES | Generic |
| INSUR | INSURANCE-VALUE | YES | Cargo insurance |
| BATTERIES | BATTERIES | YES | Lithium battery handling |
| PALLETE | PALLETE | YES | Palletized shipment fee |
| PRO | PROTOCOL | YES | Customs protocol |
| TSP | TERMINATE CHARGES, AUTOM | NO | Inactive |
| GREEN | TERMINATE CHARGES | NO | Inactive |
| COC | TERMINATE CHARGES | NO | Inactive |
| PC | TERMINATE CHARGES | NO | Inactive |
| OVERSIZE | TERMINATE CHARGES | NO | Inactive |

(28 records total in CMS, 5 active. Full snapshot in `data/charge_types.csv`.)

## Zone definition

Zones are **per-service** in CMS (e.g. S1041 has Z1..Z9, S1003 may have different).

`ZoneCountryMapping` maps countries (or cities) to zones for a given service.

## Fuel Charge

Two-tier model:

1. **FuelChargeMaster** — default per service, with `valid_from` / `valid_to`
2. **CustomerFuelChargeOverride** — per-customer override for specific (customer, service)

Lookup: customer override > service default.

Example confirmed:
- AESTHETIC TEAM + S1041 IMPORT ROAD → 19.50% (override)
- Other customers + S1041 → default value

## Slab Rate import

Per-customer pricelist upload. Format details in `docs/cms-import-format.md`.

## CMS data NOT mirrored in engine

The engine focuses on **pricing**. The following CMS modules are operational and stay in CMS only:
- PickUp (collection booking)
- Operation (shipment lifecycle)
- DRS / Runsheet
- Query / Tracking
- Reports
- Print (labels, AWBs)
- COD Management
- Invoice generation
- Daily Reconciliation

The engine produces pricing data; CMS consumes it via Client Rate Import.
