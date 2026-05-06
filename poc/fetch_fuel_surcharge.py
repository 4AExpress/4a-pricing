"""
fetch_fuel_surcharge.py — R13
Αυτόματη ανάκτηση επίναυλου καυσίμων DHL μέσω Claude API.

Χρήση:
    python fetch_fuel_surcharge.py
    python fetch_fuel_surcharge.py --output fuel_data.json
    python fetch_fuel_surcharge.py --check   (μόνο έλεγχος αλλαγής)

Output JSON:
{
  "fetched_at": "2026-05-06T09:00:00",
  "source": "mydhl.express.dhl",
  "air": [
    {"week": "Μάιος 11-17, 2026", "pct": 46.75, "is_current": false, "is_next": true},
    {"week": "Μάιος 4-10, 2026",  "pct": 47.00, "is_current": true,  "is_next": false},
    ...
  ],
  "road": [...],
  "current_air": 47.00,
  "current_road": 35.25,
  "changed": false,
  "previous_air": 47.00,
  "previous_road": 35.25
}
"""

import json
import argparse
import os
import sys
from datetime import datetime
from pathlib import Path

# Anthropic API
try:
    import anthropic
except ImportError:
    print("Εγκατάσταση anthropic: pip install anthropic")
    sys.exit(1)

DATA_DIR    = Path(__file__).parent.parent / 'data'
CACHE_FILE  = DATA_DIR / 'fuel_surcharge_cache.json'
DHL_URL     = 'https://mydhl.express.dhl/gr/el/ship/surcharges.html'

SYSTEM_PROMPT = """Είσαι βοηθός εξαγωγής δεδομένων. 
Όταν σου δίνω περιεχόμενο ιστοσελίδας, εξάγεις ΜΟΝΟ τους επίναυλους καυσίμων DHL 
σε καθαρό JSON. Απαντάς ΜΟΝΟ με JSON, χωρίς κείμενο, χωρίς markdown."""

USER_PROMPT = f"""Διάβασε την ιστοσελίδα {DHL_URL} και εξήγαγε τους επίναυλους καυσίμων.

Επίστρεψε ΜΟΝΟ αυτό το JSON (χωρίς markdown backticks):
{{
  "air": [
    {{"week": "Μάιος 11-17, 2026", "pct": 46.75}},
    {{"week": "Μάιος 4-10, 2026",  "pct": 47.00}},
    ... (όλες οι εβδομάδες που βλέπεις)
  ],
  "road": [
    {{"week": "Μάιος 11-17, 2026", "pct": 34.50}},
    ... (όλες οι εβδομάδες)
  ]
}}

Σημαντικό:
- Η πρώτη εγγραφή στο air[] είναι η επόμενη εβδομάδα (μέλλον)
- Η δεύτερη εγγραφή είναι η τρέχουσα εβδομάδα
- Βάλε ΟΛΕΣτις εβδομάδες που εμφανίζονται
- Χρησιμοποίησε ελληνικά ονόματα μηνών
"""

def load_cache():
    """Φόρτωση cached δεδομένων."""
    try:
        if CACHE_FILE.exists():
            with open(CACHE_FILE, encoding='utf-8') as f:
                return json.load(f)
    except Exception:
        pass
    return None

def save_cache(data):
    """Αποθήκευση δεδομένων."""
    DATA_DIR.mkdir(exist_ok=True)
    with open(CACHE_FILE, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

def fetch_from_claude():
    """Κλήση Claude API για ανάκτηση επίναυλου."""
    client = anthropic.Anthropic()

    print("Ανακτώ επίναυλο καυσίμων από DHL μέσω Claude API...")

    response = client.messages.create(
        model="claude-sonnet-4-20250514",
        max_tokens=1000,
        tools=[{
            "type": "web_search_20250305",
            "name": "web_search"
        }],
        system=SYSTEM_PROMPT,
        messages=[{"role": "user", "content": USER_PROMPT}]
    )

    # Εξαγωγή JSON από response
    raw_text = ""
    for block in response.content:
        if block.type == "text":
            raw_text += block.text

    # Καθαρισμός markdown αν υπάρχει
    raw_text = raw_text.strip()
    if raw_text.startswith("```"):
        raw_text = raw_text.split("```")[1]
        if raw_text.startswith("json"):
            raw_text = raw_text[4:]

    parsed = json.loads(raw_text.strip())
    return parsed

def enrich_data(parsed, previous_cache):
    """Εμπλουτισμός δεδομένων με metadata."""
    now = datetime.now().isoformat()

    air  = parsed.get('air', [])
    road = parsed.get('road', [])

    # Τρέχουσα = δεύτερη εγγραφή (πρώτη = επόμενη εβδομάδα)
    current_air  = air[1]['pct']  if len(air)  > 1 else air[0]['pct']  if air  else None
    current_road = road[1]['pct'] if len(road) > 1 else road[0]['pct'] if road else None

    # Flags is_current / is_next
    for i, row in enumerate(air):
        row['is_next']    = (i == 0)
        row['is_current'] = (i == 1)

    for i, row in enumerate(road):
        row['is_next']    = (i == 0)
        row['is_current'] = (i == 1)

    # Έλεγχος αλλαγής vs cache
    prev_air  = previous_cache.get('current_air')  if previous_cache else None
    prev_road = previous_cache.get('current_road') if previous_cache else None
    changed   = (prev_air != current_air) or (prev_road != current_road)

    result = {
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

    return result

def print_summary(data):
    """Εμφάνιση σύνοψης."""
    print()
    print("=" * 50)
    print(f"Επίναυλος Καυσίμων DHL — {data['fetched_at'][:10]}")
    print("=" * 50)

    # Τρέχουσα εβδομάδα
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
        print(f"\n⚠️  ΑΛΛΑΓΗ vs προηγούμενη:")
        print(f"  AIR:  {data['previous_air']} → {data['current_air']}")
        if data['previous_road'] != data['current_road']:
            print(f"  ROAD: {data['previous_road']} → {data['current_road']}")
    else:
        print("\n✅ Χωρίς αλλαγή vs προηγούμενη ενημέρωση")

    print("=" * 50)

def main():
    parser = argparse.ArgumentParser(description='Ανάκτηση επίναυλου καυσίμων DHL — R13')
    parser.add_argument('--output', help='Αποθήκευση σε αρχείο JSON')
    parser.add_argument('--check',  action='store_true', help='Μόνο έλεγχος αλλαγής (exit code 1 αν άλλαξε)')
    parser.add_argument('--force',  action='store_true', help='Αγνόησε cache')
    args = parser.parse_args()

    # Φόρτωση cache
    cache = load_cache() if not args.force else None

    # Ανάκτηση από Claude API
    try:
        parsed = fetch_from_claude()
    except Exception as e:
        print(f"❌ Σφάλμα ανάκτησης: {e}")
        sys.exit(1)

    # Εμπλουτισμός
    data = enrich_data(parsed, cache)

    # Αποθήκευση cache
    save_cache(data)

    # Σύνοψη
    print_summary(data)

    # Output σε αρχείο
    if args.output:
        out_path = Path(args.output)
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        print(f"\nΑποθηκεύτηκε: {out_path}")

    # Exit code για --check
    if args.check and data['changed']:
        sys.exit(1)

if __name__ == '__main__':
    main()
