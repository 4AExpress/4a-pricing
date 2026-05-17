"""Generate docs/4A_Express_Zones_2026.pdf from data/dhl_zones_2026.json."""
import json
import os
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import cm
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable
)
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_FILE = os.path.join(BASE, "data", "dhl_zones_2026.json")
OUT_FILE  = os.path.join(BASE, "docs", "4A_Express_Zones_2026.pdf")

# Zone palette
ZONE_COLORS = {
    1: colors.HexColor("#1565c0"),  # blue
    2: colors.HexColor("#2e7d32"),  # green
    3: colors.HexColor("#f57f17"),  # yellow/amber
    4: colors.HexColor("#e91e8c"),  # pink
    5: colors.HexColor("#6a1b9a"),  # purple
    6: colors.HexColor("#e65100"),  # orange
    7: colors.HexColor("#c62828"),  # red
}
ZONE_LABELS = {
    1: "Ζώνη 1", 2: "Ζώνη 2", 3: "Ζώνη 3", 4: "Ζώνη 4",
    5: "Ζώνη 5", 6: "Ζώνη 6", 7: "Ζώνη 7",
}

RED    = colors.HexColor("#cc0000")
WHITE  = colors.white
LIGHT  = colors.HexColor("#f5f5f5")
DGRAY  = colors.HexColor("#333333")
MGRAY  = colors.HexColor("#666666")

def zone_pill_style(z):
    return ParagraphStyle(
        f"pill{z}",
        fontName="Helvetica-Bold",
        fontSize=7,
        textColor=WHITE,
        backColor=ZONE_COLORS[z],
        borderPadding=(2, 5, 2, 5),
        borderRadius=4,
        leading=10,
    )

def build_section(section_data, section_title, available_zones):
    """Return a list of flowables for one section (AIR or ROAD)."""
    flowables = []

    # Section header bar
    header_style = ParagraphStyle(
        "SectionHdr",
        fontName="Helvetica-Bold",
        fontSize=13,
        textColor=WHITE,
        backColor=RED,
        leading=18,
        leftIndent=8,
        rightIndent=8,
        spaceBefore=0,
        spaceAfter=0,
        borderPadding=(6, 8, 6, 8),
    )
    flowables.append(Paragraph(section_title, header_style))
    flowables.append(Spacer(1, 6))

    # Sort: zone asc, then name asc
    zones_list = sorted(section_data["zones"], key=lambda c: (c["zone"], c["name"]))

    # Group by zone
    by_zone = {}
    for c in zones_list:
        by_zone.setdefault(c["zone"], []).append(c)

    for z in sorted(by_zone.keys()):
        countries = by_zone[z]
        col = ZONE_COLORS[z]

        # Zone sub-header
        zh_style = ParagraphStyle(
            f"ZH{z}",
            fontName="Helvetica-Bold",
            fontSize=9,
            textColor=WHITE,
            backColor=col,
            leading=13,
            borderPadding=(3, 6, 3, 6),
            spaceBefore=4,
            spaceAfter=3,
        )
        flowables.append(Paragraph(f"● {ZONE_LABELS[z]}  ({len(countries)} χώρες)", zh_style))

        # 3-column table of countries
        COLS = 3
        rows = []
        for i in range(0, len(countries), COLS):
            chunk = countries[i:i+COLS]
            row = []
            for c in chunk:
                cell_style = ParagraphStyle(
                    f"cell{z}",
                    fontName="Helvetica",
                    fontSize=7.5,
                    textColor=DGRAY,
                    leading=11,
                    leftIndent=2,
                )
                flag = f"[{c['code']}]"
                row.append(Paragraph(f"<b>{flag}</b> {c['name']}", cell_style))
            while len(row) < COLS:
                row.append(Paragraph("", cell_style))
            rows.append(row)

        col_w = (A4[0] - 3*cm) / COLS
        tbl = Table(rows, colWidths=[col_w]*COLS, repeatRows=0)
        tbl.setStyle(TableStyle([
            ("VALIGN",      (0,0), (-1,-1), "TOP"),
            ("TOPPADDING",  (0,0), (-1,-1), 2),
            ("BOTTOMPADDING",(0,0),(-1,-1), 2),
            ("LEFTPADDING", (0,0), (-1,-1), 4),
            ("RIGHTPADDING",(0,0), (-1,-1), 4),
            ("ROWBACKGROUNDS",(0,0),(-1,-1),[WHITE, LIGHT]),
            ("LINEBELOW",   (0,0), (-1,-1), 0.3, colors.HexColor("#e0e0e0")),
        ]))
        flowables.append(tbl)
        flowables.append(Spacer(1, 4))

    return flowables


def build_legend():
    """Return a legend row as a Table flowable."""
    cells = []
    for z in sorted(ZONE_COLORS):
        pill_style = ParagraphStyle(
            f"legend{z}",
            fontName="Helvetica-Bold",
            fontSize=7,
            textColor=WHITE,
            backColor=ZONE_COLORS[z],
            leading=10,
            borderPadding=(2, 6, 2, 6),
        )
        cells.append(Paragraph(f"Z{z}", pill_style))

    col_w = (A4[0] - 3*cm) / len(cells)
    tbl = Table([cells], colWidths=[col_w]*len(cells))
    tbl.setStyle(TableStyle([
        ("ALIGN",       (0,0), (-1,-1), "CENTER"),
        ("VALIGN",      (0,0), (-1,-1), "MIDDLE"),
        ("TOPPADDING",  (0,0), (-1,-1), 4),
        ("BOTTOMPADDING",(0,0),(-1,-1), 4),
    ]))
    return tbl


def generate():
    with open(DATA_FILE, encoding="utf-8") as f:
        data = json.load(f)

    doc = SimpleDocTemplate(
        OUT_FILE,
        pagesize=A4,
        leftMargin=1.5*cm, rightMargin=1.5*cm,
        topMargin=1.5*cm,  bottomMargin=1.5*cm,
    )

    story = []

    # ── Main header ──────────────────────────────────────────────
    title_style = ParagraphStyle(
        "Title",
        fontName="Helvetica-Bold",
        fontSize=16,
        textColor=WHITE,
        backColor=RED,
        alignment=TA_CENTER,
        leading=22,
        borderPadding=(10, 12, 10, 12),
        spaceAfter=4,
    )
    story.append(Paragraph("Ζώνες DHL 2026 — 4A Express", title_style))

    sub_style = ParagraphStyle(
        "Sub",
        fontName="Helvetica",
        fontSize=8,
        textColor=MGRAY,
        alignment=TA_CENTER,
        leading=12,
        spaceAfter=6,
    )
    story.append(Paragraph("Ισχύει από 01/01/2026 · Πηγή: DHL Express Rate Card 2026", sub_style))

    # Legend
    story.append(build_legend())
    story.append(Spacer(1, 10))
    story.append(HRFlowable(width="100%", thickness=0.5, color=colors.HexColor("#cccccc")))
    story.append(Spacer(1, 8))

    # AIR section
    story += build_section(
        data["air"],
        "✈  AIR — Express (S1003 / S1012)",
        sorted(ZONE_COLORS.keys()),
    )

    story.append(Spacer(1, 10))
    story.append(HRFlowable(width="100%", thickness=0.5, color=colors.HexColor("#cccccc")))
    story.append(Spacer(1, 8))

    # ROAD section
    story += build_section(
        data["road"],
        "🚛  ROAD — Economy (S1010 / S1041)",
        sorted(ZONE_COLORS.keys()),
    )

    doc.build(story)
    print(f"OK  PDF generated: {OUT_FILE}")


if __name__ == "__main__":
    generate()
