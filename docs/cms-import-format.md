"""
generate_cms_import.py — Phase 3a v5
- AddWeight υπολογίζεται από το βήμα βάρους του JSON (R12)
- Markup στο incremental rate (R12)
- Συμπύκνωση min/max ομάδας με ανοχή 0.01 (R4)
- Χωρίς scientific notation
"""
import json, argparse, os
from decimal import Decimal, ROUND_HALF_UP
from datetime import datetime

DATA_DIR   = '/mnt/user-data/outputs'
OUTPUT_DIR = '/home/claude/output'
VALID_FROM = '01-JAN-2026'
TOLERANCE  = 0.01

SERVICE_MIN_WEIGHT = {'S1003':0.5,'S1012':0.5,'S1010':1.0,'S1041':1.0}

def r2(v):
    return float(Decimal(str(v)).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP))

def r3(v):
    return float(Decimal(str(v)).quantize(Decimal('0.001'), rounding=ROUND_HALF_UP))

def fmt(v):
    s = format(float(v), '.3f').rstrip('0').rstrip('.')
    return s if '.' in s else s + '.0'

def build_brackets(prices_flat, markup_pct):
    """
    R3+R12: markup στο incremental rate
    R4: συμπύκνωση min/max ομάδας ανοχή 0.01
    AddWeight = βήμα βάρους από το JSON (0.5 ή 1 ή 5)
    """
    if not prices_flat:
        return []

    raw = []
    prev_cost = 0.0
    for i, (w, cost) in enumerate(prices_flat):
        raw_rate    = r2(cost - prev_cost)
        marked_rate = r2(raw_rate * (1 + markup_pct / 100))
        from_w      = r3(0.001) if i == 0 else r3(prices_flat[i-1][0] + 0.001)
        # AddWeight = βήμα βάρους από το JSON
        add_w = r3(w) if i == 0 else r3(w - prices_flat[i-1][0])
        raw.append({'from_w':from_w, 'to_w':w, 'rate':marked_rate,
                    'add_weight':add_w, 'upto_weight':w})
        prev_cost = cost

    # R4 — Συμπύκνωση min/max ομάδας
    # ΚΡΙΣΙΜΟ: αλλαγή AddWeight = νέο bracket ακόμα και αν rate παρόμοιο
    compressed = []
    i = 0
    while i < len(raw):
        cur = dict(raw[i])
        group_rates = [raw[i]['rate']]
        base_add_w  = raw[i]['add_weight']
        j = i + 1
        while j < len(raw):
            # Νέο bracket αν αλλάξει AddWeight
            if raw[j]['add_weight'] != base_add_w:
                break
            candidate = raw[j]['rate']
            new_group = group_rates + [candidate]
            if max(new_group) - min(new_group) <= TOLERANCE:
                group_rates.append(candidate)
                cur['upto_weight'] = raw[j]['to_w']
                j += 1
            else:
                break
        compressed.append(cur)
        i = j

    return compressed

def check_monotonicity(by_zone, service_id):
    violations = []
    for zone in sorted(by_zone.keys(), reverse=True):
        prices = by_zone[zone]
        for i in range(1, len(prices)):
            if prices[i][1] < prices[i-1][1]:
                violations.append(
                    f"  ΠΑΡΑΒΙΑΣΗ {service_id} {zone}: "
                    f"{prices[i-1][0]}kg={prices[i-1][1]} > "
                    f"{prices[i][0]}kg={prices[i][1]}"
                )
    return violations

def generate_cms_rows(tariff_data, markup_pct, account_no):
    service_id = tariff_data['tariff']['service_id']
    entries    = tariff_data['entries']
    min_weight = SERVICE_MIN_WEIGHT.get(service_id, 0.5)

    by_zone = {}
    for e in entries:
        z = e['zone_code']
        if z not in by_zone: by_zone[z] = []
        by_zone[z].append((e['weight_kg'], e['cost']))

    violations = check_monotonicity(by_zone, service_id)
    if violations:
        for v in violations: print(v)
        return None

    rows = []
    for zone in sorted(by_zone.keys()):
        prices   = [(w,p) for w,p in by_zone[zone] if w >= min_weight]
        brackets = build_brackets(prices, markup_pct)
        for docs_type in ['non docs', 'docs']:
            for b in brackets:
                rows.append({
                    'AccountNo':       account_no,
                    'StationCountry':  'GR',
                    'RateType':        'Slab Rate',
                    'MinWeight':       min_weight,
                    'WEFrom':          VALID_FROM,
                    'ShipmentType':    'Normal',
                    'OriginType':      'COUNTRY',
                    'DestinationType': 'ZONE',
                    'Origin':          'GR',
                    'Destination':     zone,
                    'Service':         service_id,
                    'DocsType':        docs_type,
                    'FromWeight':      b['from_w'],
                    'ToWeight':        b['to_w'],
                    'Rate':            b['rate'],
                    'AddWeight':       b['add_weight'],
                    'AddRate':         b['rate'],
                    'UptoWeight':      b['upto_weight'],
                })
    return rows

def write_tsv(rows, filepath):
    headers = ['AccountNo','StationCountry','RateType','MinWeight','WEFrom',
               'ShipmentType','OriginType','DestinationType','Origin','Destination',
               'Service','DocsType','FromWeight','ToWeight','Rate','AddWeight','AddRate','UptoWeight']
    float_f = {'MinWeight','FromWeight','ToWeight','Rate','AddWeight','AddRate','UptoWeight'}
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write('\t'.join(headers) + '\n')
        for row in rows:
            vals = [fmt(row[h]) if h in float_f else str(row[h]) for h in headers]
            f.write('\t'.join(vals) + '\n')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--service')
    parser.add_argument('--all', action='store_true')
    parser.add_argument('--markup', type=float, required=True)
    parser.add_argument('--account', default='30004041')
    parser.add_argument('--label', default='')
    args = parser.parse_args()

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    services = ['S1003','S1012','S1010','S1041'] if args.all else [args.service]
    if not services or services == [None]:
        print("Dose --service i --all"); return

    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    label = f"_{args.label}" if args.label else ''
    all_rows = []

    print(f"\n=== CMS Import Generator v5 — Markup: {args.markup}% — Account: {args.account} ===\n")

    for service_id in services:
        print(f"Επεξεργασία: {service_id}")
        path = os.path.join(DATA_DIR, f'dhl_tariff_2026_{service_id}.json')
        if not os.path.exists(path):
            print(f"  Δεν βρέθηκε: {path}"); continue
        with open(path, encoding='utf-8') as f:
            tariff = json.load(f)
        rows = generate_cms_rows(tariff, args.markup, args.account)
        if rows is None:
            print(f"  Αποτυχία"); continue
        fname = f"cms_import_{service_id}_markup{int(args.markup)}{label}_{timestamp}.txt"
        write_tsv(rows, os.path.join(OUTPUT_DIR, fname))
        print(f"  OK {len(rows)} γραμμές → {fname}")
        all_rows.extend(rows)

    if len(services) > 1 and all_rows:
        fname_all = f"cms_import_ALL_markup{int(args.markup)}{label}_{timestamp}.txt"
        write_tsv(all_rows, os.path.join(OUTPUT_DIR, fname_all))
        print(f"\n  OK συνολικό: {len(all_rows)} γραμμές → {fname_all}")

    print("\nΟλοκληρώθηκε.")

if __name__ == '__main__':
    main()
