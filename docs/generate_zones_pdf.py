"""Generate docs/4A_Express_Zones_2026.pdf — compact single-page 3-column layout."""
import json, os, math
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import cm
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, Flowable
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
LIGHT = colors.HexColor("#f2f2f2")
DGRAY = colors.HexColor("#222222")
MGRAY = colors.HexColor("#666666")

ZONE_COLORS = {
    1: colors.HexColor("#1565c0"),
    2: colors.HexColor("#2e7d32"),
    3: colors.HexColor("#e65100"),
    4: colors.HexColor("#e91e8c"),
    5: colors.HexColor("#6a1b9a"),
    6: colors.HexColor("#f57f17"),
    7: colors.HexColor("#c62828"),
}

SERVICES = ["S1003", "S1012", "S1010", "S1041"]
NCOLS    = 3            # country-group columns across the page

PAGE_W, PAGE_H = A4
MARGIN   = 0.5 * cm
AVAIL_W  = PAGE_W - 2 * MARGIN
AVAIL_H  = PAGE_H - 2 * MARGIN

COL_CTR  = 90.0                                                  # country name width
COL_SVC  = (AVAIL_W - NCOLS * COL_CTR) / (NCOLS * len(SERVICES))  # ≈ 24.7 pt
GROUP    = 1 + len(SERVICES)                                     # cols per country group

HDR_H    = 28    # header row height — fits rotated "S1003" at 8 pt
ROW_H    = 9     # data row height — 7 pt font + 1 pt pad top/bottom


class VerticalText(Flowable):
    """Renders text rotated 90° CCW, centred inside (cell_w × cell_h)."""
    def __init__(self, text, font_name="Arial-Bold", font_size=8,
                 text_color=WHITE, cell_w=20.0, cell_h=28.0):
        super().__init__()
        self.text       = text
        self.font_name  = font_name
        self.font_size  = font_size
        self.text_color = text_color
        self._w         = cell_w
        self._h         = cell_h

    def wrap(self, availW, availH):
        return self._w, self._h

    def draw(self):
        c = self.canv
        c.saveState()
        c.setFont(self.font_name, self.font_size)
        c.setFillColor(self.text_color)
        c.translate(self._w / 2, self._h / 2)
        c.rotate(90)
        c.drawCentredString(0, -self.font_size * 0.3, self.text)
        c.restoreState()


def z_para(zone, style):
    if zone is None:
        return Paragraph('<font color="#bbbbbb">—</font>', style)
    col = ZONE_COLORS[zone].hexval()
    return Paragraph(f'<font color="{col}"><b>Z{zone}</b></font>', style)


def build_legend():
    labels = {1:"Z1 Blue", 2:"Z2 Green", 3:"Z3 Orange",
              4:"Z4 Pink",  5:"Z5 Purple", 6:"Z6 Amber", 7:"Z7 Red"}
    st    = ParagraphStyle("leg", fontName="Arial-Bold", fontSize=7,
                           textColor=WHITE, leading=9, alignment=TA_CENTER)
    cells = [Paragraph(labels[z], st) for z in sorted(ZONE_COLORS)]
    col_w = AVAIL_W / len(cells)
    tbl   = Table([cells], colWidths=[col_w] * len(cells))
    bg    = [("BACKGROUND", (i, 0), (i, 0), ZONE_COLORS[z])
             for i, z in enumerate(sorted(ZONE_COLORS))]
    tbl.setStyle(TableStyle([
        ("ALIGN",         (0, 0), (-1, -1), "CENTER"),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING",    (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        *bg,
    ]))
    return tbl


def build_grid(countries_by_code):
    sorted_codes = sorted(countries_by_code, key=lambda k: countries_by_code[k]["name"])
    n       = len(sorted_codes)
    per_col = math.ceil(n / NCOLS)

    cell_st = ParagraphStyle("cell", fontName="Arial",      fontSize=7,
                             textColor=DGRAY, leading=7, alignment=TA_LEFT)
    zone_st = ParagraphStyle("zone", fontName="Arial-Bold", fontSize=7,
                             textColor=DGRAY, leading=7, alignment=TA_CENTER)
    chdr_st = ParagraphStyle("chdr", fontName="Arial-Bold", fontSize=7,
                             textColor=WHITE, leading=9,  alignment=TA_LEFT)

    col_widths = ([COL_CTR] + [COL_SVC] * len(SERVICES)) * NCOLS

    # ── Header row ──────────────────────────────────────────────────────────
    hdr = []
    for _ in range(NCOLS):
        hdr.append(Paragraph("Country", chdr_st))
        for svc in SERVICES:
            hdr.append(VerticalText(svc, cell_w=COL_SVC, cell_h=HDR_H))

    # ── Data rows ────────────────────────────────────────────────────────────
    rows = [hdr]
    for i in range(per_col):
        row = []
        for g in range(NCOLS):
            idx = g * per_col + i
            if idx < n:
                code = sorted_codes[idx]
                info = countries_by_code[code]
                row.append(Paragraph(info["name"], cell_st))
                for svc in SERVICES:
                    row.append(z_para(info.get(svc), zone_st))
            else:
                row.append(Paragraph("", cell_st))
                for _ in SERVICES:
                    row.append(Paragraph("", zone_st))
        rows.append(row)

    n_rows = len(rows)
    tbl = Table(
        rows,
        colWidths=col_widths,
        rowHeights=[HDR_H] + [ROW_H] * (n_rows - 1),
        repeatRows=1,
    )

    # Alternating row backgrounds (data rows only)
    alt_bg = [
        ("BACKGROUND", (0, r), (-1, r), LIGHT if r % 2 == 0 else WHITE)
        for r in range(1, n_rows)
    ]
    # Thick separator between the 3 country groups
    seps = [
        ("LINEBEFORE", (g * GROUP, 0), (g * GROUP, -1), 1.0, colors.HexColor("#999999"))
        for g in range(1, NCOLS)
    ]
    # Left-align country columns in data rows
    ctr_align = [
        ("ALIGN", (g * GROUP, 1), (g * GROUP, -1), "LEFT")
        for g in range(NCOLS)
    ]

    style = [
        # ── Global defaults ─────────────────────────────────────────────────
        ("FONTNAME",      (0, 0), (-1, -1), "Arial"),
        ("FONTSIZE",      (0, 0), (-1, -1), 7),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("ALIGN",         (0, 0), (-1, -1), "CENTER"),
        ("TOPPADDING",    (0, 0), (-1, -1), 1),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 1),
        ("LEFTPADDING",   (0, 0), (-1, -1), 2),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 2),
        # ── Header row ──────────────────────────────────────────────────────
        ("BACKGROUND",    (0, 0), (-1, 0),  RED),
        ("TOPPADDING",    (0, 0), (-1, 0),  0),
        ("BOTTOMPADDING", (0, 0), (-1, 0),  0),
        ("LEFTPADDING",   (0, 0), (-1, 0),  0),
        ("RIGHTPADDING",  (0, 0), (-1, 0),  0),
        # ── Grid lines ──────────────────────────────────────────────────────
        ("LINEBELOW",  (0, 0), (-1, -1), 0.2, colors.HexColor("#e0e0e0")),
        ("LINEBEFORE", (1, 0), (-1, -1), 0.2, colors.HexColor("#e0e0e0")),
        ("BOX",        (0, 0), (-1, -1), 0.5, colors.HexColor("#bbbbbb")),
        *alt_bg,
        *seps,
        *ctr_align,
    ]
    tbl.setStyle(TableStyle(style))
    return tbl


def generate():
    with open(DATA_FILE, encoding="utf-8") as f:
        data = json.load(f)

    countries: dict[str, dict] = {}
    for c in data["air"]["zones"]:
        countries[c["code"]] = {
            "name":  c["name"],
            "S1003": c["zone"],
            "S1012": c["zone"],
            "S1010": None,
            "S1041": None,
        }
    for c in data["road"]["zones"]:
        code = c["code"]
        if code in countries:
            countries[code]["S1010"] = c["zone"]
            countries[code]["S1041"] = c["zone"]
        else:
            countries[code] = {
                "name":  c["name"],
                "S1003": None,
                "S1012": None,
                "S1010": c["zone"],
                "S1041": c["zone"],
            }

    title_st = ParagraphStyle(
        "Title", fontName="Arial-Bold", fontSize=13,
        textColor=WHITE, backColor=RED,
        alignment=TA_CENTER, leading=18,
        borderPadding=(5, 8, 5, 8), spaceAfter=3,
    )
    sub_st = ParagraphStyle(
        "Sub", fontName="Arial", fontSize=6.5,
        textColor=MGRAY, alignment=TA_CENTER, leading=9, spaceAfter=4,
    )

    doc = SimpleDocTemplate(
        OUT_FILE, pagesize=A4,
        leftMargin=MARGIN, rightMargin=MARGIN,
        topMargin=MARGIN,  bottomMargin=MARGIN,
    )
    story = [
        Paragraph("Zones DHL 2026 — 4A Express", title_st),
        Paragraph(
            f"Ισχύει από 01/01/2026  ·  Πηγή: DHL Express Rate Card 2026"
            f"  ·  {len(countries)} χώρες",
            sub_st,
        ),
        build_legend(),
        Spacer(1, 4),
        build_grid(countries),
    ]
    doc.build(story)
    print(f"OK  PDF generated: {OUT_FILE}  ({len(countries)} countries)")


if __name__ == "__main__":
    generate()
