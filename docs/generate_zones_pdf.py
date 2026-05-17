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
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

pdfmetrics.registerFont(TTFont("Arial",      r"C:\Windows\Fonts\arial.ttf"))
pdfmetrics.registerFont(TTFont("Arial-Bold", r"C:\Windows\Fonts\arialbd.ttf"))
pdfmetrics.registerFontFamily("Arial", normal="Arial", bold="Arial-Bold")

BASE      = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_FILE = os.path.join(BASE, "data", "dhl_zones_2026.json")
OUT_FILE  = os.path.join(BASE, "docs", "4A_Express_Zones_2026.pdf")

RED   = colors.HexColor("#cc0000")
WHITE = colors.white
LIGHT = colors.HexColor("#f5f5f5")
DGRAY = colors.HexColor("#222222")
MGRAY = colors.HexColor("#666666")
HDR   = colors.HexColor("#1a1a1a")

ZONE_COLORS = {
    1: colors.HexColor("#1565c0"),  # blue
    2: colors.HexColor("#2e7d32"),  # green
    3: colors.HexColor("#e65100"),  # orange
    4: colors.HexColor("#e91e8c"),  # pink
    5: colors.HexColor("#6a1b9a"),  # purple
    6: colors.HexColor("#f57f17"),  # amber
    7: colors.HexColor("#c62828"),  # red
}

SERVICES = ["S1003", "S1012", "S1010", "S1041"]

# Page geometry
PAGE_W    = A4[0]
MARGIN    = 1.5 * cm
AVAIL_W   = PAGE_W - 2 * MARGIN
COL_COUNTRY = 200
COL_SVC     = (AVAIL_W - COL_COUNTRY) / len(SERVICES)  # ~4 equal service cols


def z_para(zone, style):
    """Colored zone-number paragraph, or a dash if zone is None."""
    if zone is None:
        return Paragraph('<font color="#bbbbbb">—</font>', style)
    c = ZONE_COLORS[zone].hexval()
    return Paragraph(f'<font color="{c}" name="Arial-Bold"><b>Z{zone}</b></font>', style)


def build_legend():
    label_names = {
        1: "Z1 Blue", 2: "Z2 Green", 3: "Z3 Orange",
        4: "Z4 Pink",  5: "Z5 Purple", 6: "Z6 Amber", 7: "Z7 Red",
    }
    st = ParagraphStyle("leg", fontName="Arial-Bold", fontSize=7.5,
                        textColor=WHITE, leading=11, alignment=TA_CENTER)
    cells  = [Paragraph(label_names[z], st) for z in sorted(ZONE_COLORS)]
    col_w  = AVAIL_W / len(cells)
    tbl    = Table([cells], colWidths=[col_w] * len(cells))
    bg     = [("BACKGROUND", (i, 0), (i, 0), ZONE_COLORS[z])
              for i, z in enumerate(sorted(ZONE_COLORS))]
    tbl.setStyle(TableStyle([
        ("ALIGN",         (0, 0), (-1, -1), "CENTER"),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING",    (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        *bg,
    ]))
    return tbl


def build_grid(countries_by_code):
    """Build the main country × service grid table."""

    svc_st = ParagraphStyle("svc", fontName="Arial-Bold", fontSize=8,
                            textColor=WHITE, leading=11, alignment=TA_CENTER)
    ctr_st = ParagraphStyle("ctr", fontName="Arial-Bold", fontSize=8,
                            textColor=WHITE, leading=11, alignment=TA_LEFT)

    # Header row
    hdr_row = [
        Paragraph("Χώρα / Country", ctr_st),
        *[Paragraph(s, svc_st) for s in SERVICES],
    ]

    # Data rows — sorted alphabetically by country name
    cell_st = ParagraphStyle("cell", fontName="Arial", fontSize=7.5,
                             textColor=DGRAY, leading=11, alignment=TA_LEFT)
    zone_st = ParagraphStyle("zone", fontName="Arial-Bold", fontSize=8,
                             textColor=DGRAY, leading=11, alignment=TA_CENTER)

    sorted_codes = sorted(countries_by_code.keys(),
                          key=lambda k: countries_by_code[k]["name"])
    rows = [hdr_row]
    for code in sorted_codes:
        info = countries_by_code[code]
        name_para = Paragraph(
            f'{info["name"]}  <font size="6.5" color="#999999">[{code}]</font>',
            cell_st,
        )
        row = [name_para] + [z_para(info.get(s), zone_st) for s in SERVICES]
        rows.append(row)

    col_widths = [COL_COUNTRY] + [COL_SVC] * len(SERVICES)
    tbl = Table(rows, colWidths=col_widths, repeatRows=1)

    n = len(rows)
    style_cmds = [
        # Global
        ("FONTNAME",      (0, 0), (-1, -1), "Arial"),
        ("FONTSIZE",      (0, 0), (-1, -1), 8),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING",    (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LEFTPADDING",   (0, 0), (-1, -1), 5),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 5),
        ("ALIGN",         (1, 0), (-1, -1), "CENTER"),
        ("ALIGN",         (0, 0), (0, -1),  "LEFT"),
        # Header row
        ("BACKGROUND",    (0, 0), (-1, 0),  RED),
        ("TEXTCOLOR",     (0, 0), (-1, 0),  WHITE),
        ("FONTNAME",      (0, 0), (-1, 0),  "Arial-Bold"),
        ("FONTSIZE",      (0, 0), (-1, 0),  8.5),
        ("TOPPADDING",    (0, 0), (-1, 0),  5),
        ("BOTTOMPADDING", (0, 0), (-1, 0),  5),
        # Alternating row backgrounds (data rows only)
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [WHITE, LIGHT]),
        # Grid lines
        ("LINEBELOW",     (0, 0), (-1, -1), 0.3, colors.HexColor("#dddddd")),
        ("LINEBEFORE",    (1, 0), (-1, -1), 0.3, colors.HexColor("#dddddd")),
        # Outer border
        ("BOX",           (0, 0), (-1, -1), 0.5, colors.HexColor("#cccccc")),
    ]
    tbl.setStyle(TableStyle(style_cmds))
    return tbl


def generate():
    with open(DATA_FILE, encoding="utf-8") as f:
        data = json.load(f)

    # Build unified country dict: code -> {name, S1003, S1012, S1010, S1041}
    countries: dict[str, dict] = {}

    for c in data["air"]["zones"]:
        countries[c["code"]] = {
            "name":  c["name"],
            "S1003": c["zone"],
            "S1012": c["zone"],   # same zone for both air services
            "S1010": None,
            "S1041": None,
        }

    for c in data["road"]["zones"]:
        code = c["code"]
        if code in countries:
            countries[code]["S1010"] = c["zone"]
            countries[code]["S1041"] = c["zone"]  # same zone for both road services
        else:
            countries[code] = {
                "name":  c["name"],
                "S1003": None,
                "S1012": None,
                "S1010": c["zone"],
                "S1041": c["zone"],
            }

    doc = SimpleDocTemplate(
        OUT_FILE,
        pagesize=A4,
        leftMargin=MARGIN, rightMargin=MARGIN,
        topMargin=MARGIN,  bottomMargin=MARGIN,
    )

    title_st = ParagraphStyle(
        "Title", fontName="Arial-Bold", fontSize=16,
        textColor=WHITE, backColor=RED,
        alignment=TA_CENTER, leading=22,
        borderPadding=(10, 12, 10, 12), spaceAfter=4,
    )
    sub_st = ParagraphStyle(
        "Sub", fontName="Arial", fontSize=8,
        textColor=MGRAY, alignment=TA_CENTER, leading=12, spaceAfter=6,
    )

    story = [
        Paragraph("Zones DHL 2026 — 4A Express", title_st),
        Paragraph(
            "Ισχύει από 01/01/2026  ·  Πηγή: DHL Express Rate Card 2026  ·"
            f"  {len(countries)} χώρες",
            sub_st,
        ),
        build_legend(),
        Spacer(1, 10),
        HRFlowable(width="100%", thickness=0.5, color=colors.HexColor("#cccccc")),
        Spacer(1, 8),
        build_grid(countries),
    ]

    doc.build(story)
    print(f"OK  PDF generated: {OUT_FILE}  ({len(countries)} countries)")


if __name__ == "__main__":
    generate()
