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
from reportlab.lib.enums import TA_CENTER
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

# Register Arial (supports Greek) from Windows system fonts
pdfmetrics.registerFont(TTFont("Arial",      r"C:\Windows\Fonts\arial.ttf"))
pdfmetrics.registerFont(TTFont("Arial-Bold", r"C:\Windows\Fonts\arialbd.ttf"))
pdfmetrics.registerFontFamily("Arial", normal="Arial", bold="Arial-Bold")

BASE      = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_FILE = os.path.join(BASE, "data", "dhl_zones_2026.json")
OUT_FILE  = os.path.join(BASE, "docs", "4A_Express_Zones_2026.pdf")

# Zone full colors (for badges)
ZONE_COLORS = {
    1: colors.HexColor("#1565c0"),  # blue
    2: colors.HexColor("#2e7d32"),  # green
    3: colors.HexColor("#f57f17"),  # amber
    4: colors.HexColor("#e91e8c"),  # pink
    5: colors.HexColor("#6a1b9a"),  # purple
    6: colors.HexColor("#e65100"),  # orange
    7: colors.HexColor("#c62828"),  # red
}

# Light tints for cell backgrounds
ZONE_LIGHT = {
    1: colors.HexColor("#e3f2fd"),
    2: colors.HexColor("#e8f5e9"),
    3: colors.HexColor("#fff8e1"),
    4: colors.HexColor("#fce4ec"),
    5: colors.HexColor("#f3e5f5"),
    6: colors.HexColor("#fbe9e7"),
    7: colors.HexColor("#ffebee"),
}

RED   = colors.HexColor("#cc0000")
WHITE = colors.white
DGRAY = colors.HexColor("#333333")
MGRAY = colors.HexColor("#666666")


def build_legend():
    label_style = ParagraphStyle(
        "LegendLabel",
        fontName="Arial-Bold",
        fontSize=8,
        textColor=WHITE,
        leading=12,
        alignment=TA_CENTER,
    )
    zone_names = {
        1: "Z1 Μπλε", 2: "Z2 Πρασινο", 3: "Z3 Κιτρινο",
        4: "Z4 Ροζ",  5: "Z5 Μωβ",    6: "Z6 Πορτοκαλι", 7: "Z7 Κοκκινο",
    }
    cells = [Paragraph(zone_names[z], label_style) for z in sorted(ZONE_COLORS)]
    col_w = (A4[0] - 3 * cm) / len(cells)
    tbl = Table([cells], colWidths=[col_w] * len(cells))
    bg_cmds = [("BACKGROUND", (i, 0), (i, 0), ZONE_COLORS[z])
               for i, z in enumerate(sorted(ZONE_COLORS))]
    tbl.setStyle(TableStyle([
        ("ALIGN",         (0, 0), (-1, -1), "CENTER"),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING",    (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        *bg_cmds,
    ]))
    return tbl


def build_section(section_data, section_title):
    flowables = []

    header_style = ParagraphStyle(
        "SectionHdr",
        fontName="Arial-Bold",
        fontSize=13,
        textColor=WHITE,
        backColor=RED,
        leading=18,
        borderPadding=(6, 8, 6, 8),
        spaceAfter=6,
    )
    flowables.append(Paragraph(section_title, header_style))

    # Sort purely alphabetically
    countries = sorted(section_data["zones"], key=lambda c: c["name"])

    COLS = 3
    col_w = (A4[0] - 3 * cm) / COLS
    rows = []
    bg_cmds = []

    for i in range(0, len(countries), COLS):
        chunk = countries[i:i + COLS]
        row_idx = i // COLS
        row = []
        for col_idx, c in enumerate(chunk):
            z = c["zone"]
            cell_style = ParagraphStyle(
                f"C{i}{col_idx}",
                fontName="Arial",
                fontSize=7.5,
                textColor=DGRAY,
                leading=11,
                leftIndent=2,
            )
            zcolor = ZONE_COLORS[z].hexval()  # e.g. "#1565c0"
            text = (
                f'<font name="Arial-Bold" color="{zcolor}">Z{z}</font>'
                f'  {c["name"]}'
                f'  <font size="6.5" color="#999999">[{c["code"]}]</font>'
            )
            row.append(Paragraph(text, cell_style))
            bg_cmds.append(("BACKGROUND", (col_idx, row_idx), (col_idx, row_idx), ZONE_LIGHT[z]))

        # Pad short last row
        while len(row) < COLS:
            row.append(Paragraph("", ParagraphStyle("empty", fontName="Arial", fontSize=7.5)))
        rows.append(row)

    tbl = Table(rows, colWidths=[col_w] * COLS)
    tbl.setStyle(TableStyle([
        ("VALIGN",       (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING",   (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING",(0, 0), (-1, -1), 3),
        ("LEFTPADDING",  (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("LINEBELOW",    (0, 0), (-1, -1), 0.3, colors.HexColor("#dddddd")),
        *bg_cmds,
    ]))
    flowables.append(tbl)
    return flowables


def generate():
    with open(DATA_FILE, encoding="utf-8") as f:
        data = json.load(f)

    doc = SimpleDocTemplate(
        OUT_FILE,
        pagesize=A4,
        leftMargin=1.5 * cm, rightMargin=1.5 * cm,
        topMargin=1.5 * cm,  bottomMargin=1.5 * cm,
    )

    story = []

    title_style = ParagraphStyle(
        "Title",
        fontName="Arial-Bold",
        fontSize=16,
        textColor=WHITE,
        backColor=RED,
        alignment=TA_CENTER,
        leading=22,
        borderPadding=(10, 12, 10, 12),
        spaceAfter=4,
    )
    story.append(Paragraph("Zones DHL 2026 — 4A Express", title_style))

    sub_style = ParagraphStyle(
        "Sub",
        fontName="Arial",
        fontSize=8,
        textColor=MGRAY,
        alignment=TA_CENTER,
        leading=12,
        spaceAfter=6,
    )
    story.append(Paragraph(
        "Ισχύει από 01/01/2026  ·  Πηγή: DHL Express Rate Card 2026",
        sub_style,
    ))

    story.append(build_legend())
    story.append(Spacer(1, 10))
    story.append(HRFlowable(width="100%", thickness=0.5, color=colors.HexColor("#cccccc")))
    story.append(Spacer(1, 8))

    story += build_section(data["air"],  "AIR — Express (S1003 / S1012)")

    story.append(Spacer(1, 12))
    story.append(HRFlowable(width="100%", thickness=0.5, color=colors.HexColor("#cccccc")))
    story.append(Spacer(1, 8))

    story += build_section(data["road"], "ROAD — Economy (S1010 / S1041)")

    doc.build(story)
    print(f"OK  PDF generated: {OUT_FILE}")


if __name__ == "__main__":
    generate()
