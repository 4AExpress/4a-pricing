"""
fetch_fuel_surcharge.py — R13 v3 (Selenium)
Ανοίγει πραγματικό browser, φορτώνει το DHL site με JavaScript
και εξάγει τους εβδομαδιαίους επίναυλους καυσίμων.

Χρήση:
    python fetch_fuel_surcharge.py
    python fetch_fuel_surcharge.py --output data/fuel_surcharge_cache.json
    python fetch_fuel_surcharge.py --check
"""

import json
import argparse
import sys
import re
import unicodedata
from datetime import datetime, date, timedelta
from pathlib import Path

try:
    from selenium import webdriver
    from selenium.webdriver.chrome.options import Options
    from selenium.webdriver.chrome.service import Service
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
except ImportError:
    print("Εγκατάσταση: pip install selenium")
    sys.exit(1)

DATA_DIR   = Path(__file__).parent.parent / 'data'
CACHE_FILE = DATA_DIR / 'fuel_surcharge_cache.json'
DHL_URL    = 'https://mydhl.express.dhl/gr/el/ship/surcharges.html#/fuel_surcharge'

def load_cache():
    try:
        if CACHE_FILE.exists():
            with open(CACHE_FILE, encoding='utf-8') as f:
                return json.load(f)
    except Exception:
        pass
    return None

def save_cache(data):
    DATA_DIR.mkdir(exist_ok=True)
    with open(CACHE_FILE, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

def get_driver():
    """Headless Chrome για GitHub Actions."""
    options = Options()
    options.add_argument('--headless')
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--disable-gpu')
    options.add_argument('--window-size=1920,1080')
    options.add_argument('--lang=el-GR')
    options.add_argument('user-agent=Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36')
    driver = webdriver.Chrome(options=options)
    return driver

def parse_pct(text):
    """Εξαγωγή ποσοστού από κείμενο π.χ. '47.00%' → 47.00"""
    m = re.search(r'(\d+[\.,]\d+)', text.replace(',', '.'))
    return float(m.group(1)) if m else None

def scrape_surcharges():
    """Ανοίγει το DHL site και εξάγει τους επίναυλους."""
    driver = get_driver()
    results = {'air': [], 'road': []}

    try:
        print(f"Φορτώνω: {DHL_URL}")
        driver.get(DHL_URL)

        # Αναμονή για φόρτωση JavaScript
        wait = WebDriverWait(driver, 20)

        # Κλικ στο tab "Επίναυλος Καυσίμων" αν δεν είναι ήδη ενεργό
        try:
            fuel_tab = wait.until(EC.element_to_be_clickable(
                (By.XPATH, "//*[contains(text(), 'Καυσίμων') or contains(text(), 'Fuel')]")
            ))
            fuel_tab.click()
            print("Πάτησα tab Επίναυλος Καυσίμων")
        except Exception as e:
            print(f"Tab click: {e} — συνεχίζω")

        import time
        time.sleep(3)

        # Εύρεση πινάκων με εβδομαδιαία δεδομένα
        tables = driver.find_elements(By.TAG_NAME, 'table')
        print(f"Βρέθηκαν {len(tables)} πίνακες")

        for idx, table in enumerate(tables):
            rows = table.find_elements(By.TAG_NAME, 'tr')
            table_data = []
            for row in rows:
                cells = row.find_elements(By.TAG_NAME, 'td')
                if len(cells) >= 2:
                    week_text = cells[0].text.strip()
                    pct_text  = cells[1].text.strip()
                    pct = parse_pct(pct_text)
                    if pct and week_text:
                        table_data.append({'week': week_text, 'pct': pct})

            if table_data:
                print(f"Πίνακας {idx}: {len(table_data)} εγγραφές — {table_data[0]}")
                # Πρώτος πίνακας με δεδομένα = AIR
                if not results['air']:
                    results['air'] = table_data
                # Δεύτερος = ROAD
                elif not results['road']:
                    results['road'] = table_data

        # Fallback: αν δεν βρέθηκαν πίνακες, ψάξε rows με % pattern
        if not results['air']:
            print("Δεν βρέθηκαν πίνακες — ψάχνω με XPath...")
            all_text = driver.find_element(By.TAG_NAME, 'body').text
            print(f"Body text preview: {all_text[:500]}")

            # Ψάξε για pattern "Μάιος X-Y, 2026\n47.00%"
            lines = all_text.split('\n')
            current_week = None
            for line in lines:
                line = line.strip()
                if re.search(r'(Ιαν|Φεβ|Μαρ|Απρ|Μάι|Ιουν|Ιουλ|Αυγ|Σεπ|Οκτ|Νοε|Δεκ).+\d{4}', line):
                    current_week = line
                elif re.search(r'\d+[\.,]\d+%', line) and current_week:
                    pct = parse_pct(line)
                    if pct and 10 < pct < 100:
                        results['air'].append({'week': current_week, 'pct': pct})
                        current_week = None

    finally:
        driver.quit()

    return results

# ─────────────────────────────────────────────────────────────────────
#  ΕΤΙΚΕΤΑ ΕΒΔΟΜΑΔΑΣ → ISO ΗΜΕΡΟΜΗΝΙΕΣ
#
#  Η ετικέτα («Σεπτέμβριος 28-Οκτώβριος 4, 2026») είναι κείμενο τρίτου:
#  μορφή που δεν ελέγχουμε, ελληνικοί μήνες σε δύο πτώσεις, και έτος που
#  σε αλλαγή χρονιάς ΔΕΝ λέει σε ποιο από τα δύο άκρα ανήκει.
#
#  Αναλύεται ΕΔΩ, μία φορά, με ημερολόγιο στο χέρι και με αυτοελέγχους —
#  όχι σε τέσσερα HTML αρχεία που θα αποκλίνουν. Το frontend παίρνει
#  έτοιμα `week_start`/`week_end` και δεν διαβάζει ποτέ την ετικέτα.
#
#  Η ίδια η ετικέτα ΔΕΝ αλλάζει ποτέ: τυπώνεται αυτούσια στο PDF.
# ─────────────────────────────────────────────────────────────────────

# Αγγλικά ονόματα ως ασφάλεια: το `--lang=el-GR` (get_driver) είναι δική
# μας επιλογή, όχι εγγύηση της DHL.
_MONTHS = [
    ('ιανουαριος',  'january'),
    ('φεβρουαριος', 'february'),
    ('μαρτιος',     'march'),
    ('απριλιος',    'april'),
    ('μαιος',       'may'),
    ('ιουνιος',     'june'),
    ('ιουλιος',     'july'),
    ('αυγουστος',   'august'),
    ('σεπτεμβριος', 'september'),
    ('οκτωβριος',   'october'),
    ('νοεμβριος',   'november'),
    ('δεκεμβριος',  'december'),
]

_WEEK_RE = re.compile(
    r'^\s*([^\d]+?)\s*(\d{1,2})\s*[-–—]\s*(?:([^\d,]+?)\s*)?(\d{1,2})'
    r'\s*(?:,\s*(\d{4}))?\s*\.?\s*$'
)

def _normalize(text):
    """Χωρίς τόνους, πεζά, τελικό σίγμα → σίγμα, μόνο γράμματα."""
    t = unicodedata.normalize('NFD', text or '')
    t = ''.join(c for c in t if not unicodedata.combining(c))
    t = t.lower().replace('ς', 'σ')
    return ''.join(c for c in t if c.isalpha())

def _common_prefix_len(a, b):
    n = 0
    for x, y in zip(a, b):
        if x != y:
            break
        n += 1
    return n

def _month_number(token):
    """Ονομαστική, γενική ή συντομογραφία. Ασάφεια («Ιου») → None."""
    t = _normalize(token)
    if len(t) < 3:
        return None
    hits = set()
    for idx, names in enumerate(_MONTHS, start=1):
        for name in names:
            if _common_prefix_len(t, name) >= 3:
                hits.add(idx)
                break
    return hits.pop() if len(hits) == 1 else None

def parse_week_label(label, ref):
    """«Σεπτέμβριος 28-Οκτώβριος 4, 2026» → (date, date, None | αιτία)."""
    m = _WEEK_RE.match(label or '')
    if not m:
        return None, None, 'μη αναγνωρίσιμη μορφή'
    n1, d1, n2, d2, _year_in_label = m.groups()
    mon1 = _month_number(n1)
    mon2 = _month_number(n2) if n2 else mon1
    if not mon1 or not mon2:
        bad = n1.strip() + ('/' + n2.strip() if n2 else '')
        return None, None, f'άγνωστος ή διφορούμενος μήνας ({bad})'
    d1, d2 = int(d1), int(d2)

    # Το έτος ΔΕΝ διαβάζεται από την ετικέτα. Στο «Δεκέμβριος 28-Ιανουάριος
    # 3, 2027» το 2027 ανήκει στο τέλος, στο «Σεπτέμβριος 21-27, 2026» και
    # στα δύο· από την ετικέτα μόνη της δεν ξεχωρίζει. Διαλέγουμε εκείνο
    # που φέρνει την εβδομάδα πιο κοντά στην ημέρα σάρωσης.
    best = None
    for y in (ref.year - 1, ref.year, ref.year + 1):
        try:
            cand = date(y, mon1, d1)
        except ValueError:
            continue
        dist = abs((cand - ref).days)
        if best is None or dist < best[0]:
            best = (dist, cand)
    if best is None:
        return None, None, f'άκυρη ημερομηνία έναρξης ({d1}/{mon1})'
    start = best[1]

    # Αλλαγή χρονιάς: ο μήνας λήξης μικρότερος του μήνα έναρξης = επόμενο έτος.
    try:
        end = date(start.year + 1 if mon2 < mon1 else start.year, mon2, d2)
    except ValueError:
        return None, None, f'άκυρη ημερομηνία λήξης ({d2}/{mon2})'
    return start, end, None

def _attach_week_dates(rows, ref, kind, warnings):
    """Ανά γραμμή: 7 ημέρες, Δευτέρα→Κυριακή. Αστοχία → null + προειδοποίηση."""
    for i, row in enumerate(rows):
        label = row.get('week', '')
        start, end, reason = parse_week_label(label, ref)
        if start and (end - start).days != 6:
            start, end, reason = None, None, f'εβδομάδα {(end - start).days + 1} ημερών, όχι 7'
        if start and (start.weekday() != 0 or end.weekday() != 6):
            start, end, reason = None, None, 'δεν ξεκινά Δευτέρα ή δεν τελειώνει Κυριακή'
        if reason:
            warnings.append(f'{kind}[{i}] «{label}»: {reason}')
            row['week_start'] = None
            row['week_end']   = None
        else:
            row['week_start'] = start.isoformat()
            row['week_end']   = end.isoformat()

def _check_sequence(rows, kind, warnings):
    """Οι γραμμές πρέπει να είναι διαδοχικές εβδομάδες, φθίνουσες."""
    dated = [(i, r) for i, r in enumerate(rows) if r.get('week_start')]
    for (i, a), (j, b) in zip(dated, dated[1:]):
        gap = (date.fromisoformat(a['week_start']) - date.fromisoformat(b['week_start'])).days
        if gap != 7:
            warnings.append(
                f'{kind}: [{i}] και [{j}] δεν είναι διαδοχικές εβδομάδες '
                f'(διαφορά {gap} ημερών: «{a["week"]}» → «{b["week"]}»)'
            )

def _mark_current(rows, ref, kind, warnings):
    """Τρέχουσα = αυτή που ΠΕΡΙΕΧΕΙ την ημέρα σάρωσης — όχι η θέση i==1.

    Αν δεν προκύψει ακριβώς μία, υποχωρούμε στη θέση και το λέμε. Δεν
    αφήνουμε το αρχείο χωρίς τρέχουσα τιμή: το frontend που δεν ξέρει
    ακόμα από `parse_warnings` δεν πρέπει να χειροτερέψει.
    """
    for r in rows:
        r['is_current'] = False
        r['is_next']    = False

    hits = [i for i, r in enumerate(rows) if r.get('week_start')
            and date.fromisoformat(r['week_start']) <= ref <= date.fromisoformat(r['week_end'])]

    if len(hits) == 1:
        cur = hits[0]
        rows[cur]['is_current'] = True
        day_after = date.fromisoformat(rows[cur]['week_end']) + timedelta(days=1)
        for r in rows:
            if r.get('week_start') and date.fromisoformat(r['week_start']) == day_after:
                r['is_next'] = True
        return cur

    if len(hits) > 1:
        warnings.append(f'{kind}: {len(hits)} εβδομάδες περιέχουν την {ref.isoformat()} '
                        f'— εφαρμόστηκε η θέση (i==1)')
    else:
        warnings.append(f'{kind}: καμία εβδομάδα δεν περιέχει την {ref.isoformat()} '
                        f'— εφαρμόστηκε η θέση (i==1)')

    cur = 1 if len(rows) > 1 else 0
    rows[cur]['is_current'] = True
    if len(rows) > 1:
        rows[0]['is_next'] = True
    return cur

def enrich_data(parsed, previous_cache):
    now   = datetime.now()
    today = now.date()
    air   = parsed.get('air', [])
    road  = parsed.get('road', [])

    if not air:
        raise ValueError("Δεν βρέθηκαν δεδομένα AIR")

    warnings = []
    _attach_week_dates(air,  today, 'air',  warnings)
    _attach_week_dates(road, today, 'road', warnings)
    _check_sequence(air,  'air',  warnings)
    _check_sequence(road, 'road', warnings)

    air_cur  = _mark_current(air,  today, 'air',  warnings)
    road_cur = _mark_current(road, today, 'road', warnings) if road else None

    current_air  = air[air_cur]['pct']
    current_road = road[road_cur]['pct'] if road else None

    prev_air  = previous_cache.get('current_air')  if previous_cache else None
    prev_road = previous_cache.get('current_road') if previous_cache else None
    changed   = (prev_air != current_air) or (prev_road != current_road)

    return {
        'fetched_at':     now.isoformat(),
        'source':         DHL_URL,
        'parse_warnings': warnings,
        'air':            air,
        'road':           road,
        'current_air':    current_air,
        'current_road':   current_road,
        'changed':        changed,
        'previous_air':   prev_air,
        'previous_road':  prev_road,
    }

def print_summary(data):
    print()
    print("=" * 50)
    print(f"Επίναυλος Καυσίμων DHL — {data['fetched_at'][:10]}")
    print("=" * 50)
    curr_air  = next((r for r in data['air']  if r.get('is_current')), None)
    curr_road = next((r for r in data['road'] if r.get('is_current')), None)
    next_air  = next((r for r in data['air']  if r.get('is_next')),    None)
    if curr_air:
        print(f"\nΤρέχουσα ({curr_air['week']}):")
        print(f"  AIR:  {curr_air['pct']:.2f}%")
        if curr_road:
            print(f"  ROAD: {curr_road['pct']:.2f}%")
    if next_air:
        print(f"\nΕπόμενη ({next_air['week']}):")
        print(f"  AIR:  {next_air['pct']:.2f}%")
    if data['changed']:
        print(f"\n⚠️  ΑΛΛΑΓΗ: AIR {data['previous_air']} → {data['current_air']}")
    else:
        print("\n✅ Χωρίς αλλαγή")
    # Ορατές στο log του GitHub Action — εκεί τις βλέπει πρώτος άνθρωπος.
    if data.get('parse_warnings'):
        print("\n⚠️  ΑΝΑΛΥΣΗ ΕΒΔΟΜΑΔΑΣ:")
        for w in data['parse_warnings']:
            print(f"  · {w}")
    print("=" * 50)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', help='Αποθήκευση JSON')
    parser.add_argument('--check',  action='store_true')
    parser.add_argument('--force',  action='store_true')
    args = parser.parse_args()

    cache = load_cache() if not args.force else None

    try:
        parsed = scrape_surcharges()
    except Exception as e:
        print(f"❌ Σφάλμα scraping: {e}")
        sys.exit(1)

    try:
        data = enrich_data(parsed, cache)
    except Exception as e:
        print(f"❌ Σφάλμα επεξεργασίας: {e}")
        sys.exit(1)

    save_cache(data)
    print_summary(data)

    if args.output:
        out_path = Path(args.output)
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        print(f"\nΑποθηκεύτηκε: {out_path}")

    if args.check and data['changed']:
        sys.exit(1)

if __name__ == '__main__':
    main()
