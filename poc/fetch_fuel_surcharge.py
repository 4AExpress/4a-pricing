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
from datetime import datetime
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

def enrich_data(parsed, previous_cache):
    now  = datetime.now().isoformat()
    air  = parsed.get('air', [])
    road = parsed.get('road', [])

    if not air:
        raise ValueError("Δεν βρέθηκαν δεδομένα AIR")

    current_air  = air[1]['pct'] if len(air) > 1 else air[0]['pct']
    current_road = road[1]['pct'] if len(road) > 1 else (road[0]['pct'] if road else None)

    for i, row in enumerate(air):
        row['is_next']    = (i == 0)
        row['is_current'] = (i == 1)
    for i, row in enumerate(road):
        row['is_next']    = (i == 0)
        row['is_current'] = (i == 1)

    prev_air  = previous_cache.get('current_air')  if previous_cache else None
    prev_road = previous_cache.get('current_road') if previous_cache else None
    changed   = (prev_air != current_air) or (prev_road != current_road)

    return {
        'fetched_at':    now,
        'source':        DHL_URL,
        'air':           air,
        'road':          road,
        'current_air':   current_air,
        'current_road':  current_road,
        'changed':       changed,
        'previous_air':  prev_air,
        'previous_road': prev_road,
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
