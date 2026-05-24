from jinja2 import Environment, FileSystemLoader
from weasyprint import HTML
import os

import os`nBASE_DIR = os.path.dirname(os.path.abspath(__file__))`nTEMPLATE_DIR = BASE_DIR
OUTPUT      = "/mnt/user-data/outputs/4A_Ogkometria_v9.pdf"

env = Environment(loader=FileSystemLoader(TEMPLATE_DIR))
env.globals["enumerate"] = enumerate   # expose enumerate to Jinja2

template = env.get_template("template.html")

# ── DATA ──────────────────────────────────────────────────────────────
data = {
    "doc_title"   : "Επεξήγηση Χρέωσης",
    "doc_subtitle": "BILLING EXPLANATION  ·  4A EXPRESS",
    "date"        : "—",
    "subject"     : "Ογκομέτρηση Δεμάτων",
    "category"    : "Τιμολόγηση",
    "doc_code"    : "4A-EXPLAIN-001",

    "sections": [
        {
            "title": "Τι είναι το Ογκομετρικό Βάρος;",
            "type" : "text",
            "lines": [
                {"text": "Κάθε δέμα χρεώνεται βάσει του ΜΕΓΑΛΥΤΕΡΟΥ από τα δύο: πραγματικό βάρος ή ογκομετρικό βάρος.", "muted": False},
                {"text": "Μεγάλα αλλά ελαφριά δέματα καταλαμβάνουν χώρο στο όχημα / αποθήκη / αεροπλάνο / container ακριβώς όπως ένα βαρύ δέμα δεσμεύει φορτίο.", "muted": True},
            ]
        },
        {
            "title"  : "Ογκομετρικός Τύπος",
            "type"   : "formula",
            "formula": "Ογκομετρικό Βάρος (kg)  =  ( Μ × Π × Υ )  ÷  5.000",
            "legend" : "Μ = Μήκος (cm)   |   Π = Πλάτος (cm)   |   Υ = Ύψος (cm)   |   ÷ 5.000 = Διεθνής Συντελεστής (IATA / Courier)",
        },
        {
            "title": "Πώς Μετράμε το Δέμα;",
            "type" : "box_diagram",
        },
        {
            "title"  : "Παράδειγμα Υπολογισμού",
            "type"   : "table",
            "columns": ["Μέτρηση", "Τιμή", "Περιγραφή"],
            "rows"   : [
                {"cells": ["Μήκος",               "60 cm",                        "Μετράμε την πιο μακριά πλευρά"],  "highlight": False},
                {"cells": ["Πλάτος",              "40 cm",                        "Μετράμε οριζόντια"],              "highlight": False},
                {"cells": ["Ύψος",                "30 cm",                        "Μετράμε κατακόρυφα"],             "highlight": False},
                {"cells": ["Ογκομετρικό Βάρος",  "(60×40×30) ÷ 5.000 = 14,4 kg","Αποτέλεσμα τύπου"],               "highlight": False},
                {"cells": ["Πραγματικό Βάρος",   "8 kg",                         "Από ζυγό"],                       "highlight": False},
                {"cells": ["ΧΡΕΩΣΙΜΟ ΒΑΡΟΣ",     "14,4 kg  (ογκομετρικό > πραγματικό)",    "14,4 > 8 → χρεώνεται το 14,4"],  "highlight": True},
            ]
        },
        {
            "title": "Τι Σημαίνει για Εσάς;",
            "type" : "bullets",
            "lines": [
                "Η χρέωση γίνεται πάντα βάσει του μεγαλύτερου βάρους (πραγματικό vs ογκομετρικό).",
                "Δέματα με μεγάλο όγκο αλλά μικρό βάρος χρεώνονται ογκομετρικά.",
                "Καλή συσκευασία χωρίς κενά αέρα μειώνει το ογκομετρικό βάρος και άρα το κόστος.",
                "Ο συντελεστής 5.000 είναι διεθνές πρότυπο (IATA / courier).",
            ]
        },
    ]
}

# ── RENDER ─────────────────────────────────────────────────────────────
html_out = template.render(**data)

# ── GENERATE PDF ───────────────────────────────────────────────────────
HTML(string=html_out, base_url=TEMPLATE_DIR).write_pdf(OUTPUT)
print(f"OK: {OUTPUT}")
