# docs/cms-import-format.md — CMS Slab Rate Import Format

> Τελευταία ενημέρωση: 2026-05-05
> Βασίζεται σε πραγματικό CMS import αρχείο (S1003, 2026)

## 1. Μορφή αρχείου

- Τύπος: **TSV** (Tab-Separated Values) — διαχωριστικό: tab `\t`
- Κωδικοποίηση: UTF-8
- Πρώτη γραμμή: επικεφαλίδες (headers)
- Επέκταση αρχείου: `.txt` ή `.tsv`

## 2. Πεδία (columns)

| # | Πεδίο | Τύπος | Παράδειγμα | Περιγραφή |
|---|-------|-------|------------|-----------|
| 1 | AccountNo | integer | 30004041 | Αριθμός λογαριασμού πελάτη στο CMS |
| 2 | StationCountry | string | GR | Χώρα γραφείου (GR ή CY) |
| 3 | RateType | string | Slab Rate | Πάντα "Slab Rate" |
| 4 | MinWeight | float | 0.5 | Ελάχιστο χρεώσιμο βάρος (0.5 ή 1.0) |
| 5 | WEFrom | date | 01-JAN-2026 | Ημερομηνία έναρξης ισχύος (DD-MON-YYYY) |
| 6 | ShipmentType | string | Normal | Πάντα "Normal" |
| 7 | OriginType | string | COUNTRY | Πάντα "COUNTRY" |
| 8 | DestinationType | string | ZONE | Πάντα "ZONE" |
| 9 | Origin | string | GR | Χώρα αποστολής |
| 10 | Destination | string | z1 | Zone προορισμού (z1..z7) |
| 11 | Service | string | S1003 | Κωδικός service στο CMS |
| 12 | DocsType | string | non docs | "docs" ή "non docs" |
| 13 | FromWeight | float | 0.001 | Από βάρος bracket (πάντα x.001) |
| 14 | ToWeight | float | 0.5 | Έως βάρος bracket |
| 15 | Rate | float | 8.48 | Τιμή bracket (βλ. λογική παρακάτω) |
| 16 | AddWeight | float | 0.5 | Βήμα επιπλέον βάρους (0.5 ή 1.0) |
| 17 | AddRate | float | 8.48 | Πάντα ίσο με Rate |
| 18 | UptoWeight | float | 0.5 | Μέγιστο βάρος εντός bracket (βλ. παρακάτω) |

## 3. Λογική Rate (ΚΡΙΣΙΜΟ)

### 3.1 Rate = διαφορά τιμών (incremental)

Το `Rate` δεν είναι η συνολική τιμή — είναι η **διαφορά** μεταξύ της τιμής του
τρέχοντος βάρους και του προηγούμενου bracket.

```
Rate[bracket_n] = price[ToWeight_n] - price[ToWeight_{n-1}]
```

**Παράδειγμα Z1 S1003 (raw DHL, χωρίς markup):**

| FromWeight | ToWeight | Rate | Λογική |
|------------|----------|------|--------|
| 0.001 | 0.5 | 8.48 | Πλήρης τιμή πρώτου bracket |
| 0.501 | 1.0 | 0.00 | 8.48 - 8.48 = 0.00 |
| 1.001 | 1.5 | 1.07 | 9.55 - 8.48 = 1.07 |
| 1.501 | 2.0 | 1.07 | 10.62 - 9.55 = 1.07 |
| 2.001 | 2.5 | 1.07 | 11.69 - 10.62 = 1.07 |

### 3.2 AddRate = Rate (πάντα ίσα)

Το `AddRate` είναι πάντα ίσο με το `Rate` στο ίδιο bracket.

## 4. Λογική UptoWeight (ΚΡΙΣΙΜΟ)

Το `UptoWeight` **δεν είναι πάντα ίσο με το `ToWeight`**.

Όταν πολλά συνεχόμενα brackets έχουν το ίδιο AddRate, το CMS τα συμπτύσσει:
το πρώτο bracket παίρνει `UptoWeight` = το τελευταίο `ToWeight` της ομάδας.

**Παράδειγμα:**

```
FromWeight=6.501  ToWeight=7    Rate=1.35  UptoWeight=10   <- συμπτύσσει 7.0-10.0
FromWeight=10.001 ToWeight=10.5 Rate=4.00  UptoWeight=10.5 <- νέο rate
FromWeight=10.501 ToWeight=11   Rate=1.70  UptoWeight=20   <- συμπτύσσει 11.0-20.0
FromWeight=20.001 ToWeight=20.5 Rate=2.98  UptoWeight=30   <- συμπτύσσει 20.5-30.0
FromWeight=30.001 ToWeight=31   Rate=5.97  UptoWeight=70   <- συμπτύσσει 31-70
```

**Κανόνας συμπύκνωσης:**
Αν `Rate[n] == Rate[n+1] == ... == Rate[n+k]` τότε:
- Γράφεται μία γραμμή με `FromWeight[n]`, `ToWeight[n]`, `Rate[n]`
- `UptoWeight = ToWeight[n+k]` (το τελευταίο της ομάδας)

## 5. Σειρά εγγραφών

Για κάθε service, οι εγγραφές γράφονται με αυτή τη σειρά:
1. Ανά zone (z1 → z7 για TD, z1 → z3 για DD)
2. Εντός κάθε zone: πρώτα `non docs`, μετά `docs`
3. Εντός κάθε docs_type: αύξουσα σειρά βάρους

## 6. Σταθερά πεδία ανά γραφείο

| Πεδίο | Αθήνα (GR) | Κύπρος (CY) |
|-------|-----------|-------------|
| AccountNo | 30004041 | (να συμπληρωθεί) |
| StationCountry | GR | CY |
| Origin | GR | CY |

## 7. Μορφή ημερομηνίας WEFrom

```
DD-MON-YYYY  όπου MON = JAN, FEB, MAR, APR, MAY, JUN,
                         JUL, AUG, SEP, OCT, NOV, DEC
Παράδειγμα: 01-JAN-2026
```

## 8. Παράδειγμα πλήρους γραμμής

```
AccountNo	StationCountry	RateType	MinWeight	WEFrom	ShipmentType	OriginType	DestinationType	Origin	Destination	Service	DocsType	FromWeight	ToWeight	Rate	AddWeight	AddRate	UptoWeight
30004041	GR	Slab Rate	0.5	01-JAN-2026	Normal	COUNTRY	ZONE	GR	z1	S1003	non docs	0.001	0.5	8.48	0.5	8.48	0.5
```
