"""Generate docs/4A_Express_Zones_2026.pdf — GR edition, compact single-page 3-column layout."""
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

# Try to register an emoji font for the EU flag; fall back to text label if unavailable
_EU_EMOJI_FONT = None
for _ep in [r"C:\Windows\Fonts\seguiemj.ttf", r"C:\Windows\Fonts\seguisym.ttf"]:
    if os.path.exists(_ep):
        try:
            pdfmetrics.registerFont(TTFont("_EuEmoji", _ep))
            _EU_EMOJI_FONT = "_EuEmoji"
        except Exception:
            pass
        break

BASE      = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_FILE = os.path.join(BASE, "data", "dhl_zones_2026.json")
OUT_FILE  = os.path.join(BASE, "docs", "4A_Express_Zones_2026.pdf")

RED   = colors.HexColor("#cc0000")
WHITE = colors.white
LIGHT = colors.HexColor("#f2f2f2")
DGRAY = colors.HexColor("#222222")
MGRAY = colors.HexColor("#666666")

# Pastel backgrounds (print-friendly) — used in legend + grid zone cells
ZONE_BG = {
    1: colors.HexColor("#dce8f7"),
    2: colors.HexColor("#dcf0e0"),
    3: colors.HexColor("#fef4d0"),
    4: colors.HexColor("#fde0eb"),
    5: colors.HexColor("#ede0f5"),
    6: colors.HexColor("#fde8d0"),
    7: colors.HexColor("#fde0d0"),
    9: colors.HexColor("#d0f0fd"),
}

# Dark matching text colors for readability on pastel backgrounds
ZONE_FG = {
    1: colors.HexColor("#1565c0"),
    2: colors.HexColor("#2e7d32"),
    3: colors.HexColor("#7a6000"),
    4: colors.HexColor("#c2185b"),
    5: colors.HexColor("#6a1b9a"),
    6: colors.HexColor("#bf5500"),
    7: colors.HexColor("#b71c1c"),
    9: colors.HexColor("#0277bd"),
}

ZONE_LABELS = {
    1: "Z1 Δυτική Ευρώπη",
    2: "Z2 Κεντρική & Βόρεια Ευρώπη",
    3: "Z3 Υπόλοιπη Ευρώπη",
    4: "Z4 Κεντρική Αμερική",
    5: "Z5 Μεσόγειος/Αφρική/Μ.Ανατολή",
    6: "Z6 Ασία",
    7: "Z7 Λατ.Αμερική/Υπόλοιπος Κόσμος",
    9: "Z9 Κύπρος",
}

EU_COUNTRIES = {
    "AT","BE","BG","HR","CY","CZ","DK","EE","FI","FR",
    "DE","GR","HU","IE","IT","LV","LT","LU","MT","NL",
    "PL","PT","RO","SK","SI","ES","SE",
}

SERVICES = ["S1003", "S1012", "S1010", "S1041"]
NCOLS    = 3

PAGE_W, PAGE_H = A4
MARGIN   = 0.5 * cm
AVAIL_W  = PAGE_W - 2 * MARGIN
AVAIL_H  = PAGE_H - 2 * MARGIN

COL_CTR  = 90.0
COL_SVC  = (AVAIL_W - NCOLS * COL_CTR) / (NCOLS * len(SERVICES))
GROUP    = 1 + len(SERVICES)

HDR_H    = 28
ROW_H    = 9


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
    col = ZONE_FG[zone].hexval()
    return Paragraph(f'<font color="{col}"><b>Z{zone}</b></font>', style)


def country_para(code, name, style):
    if code in EU_COUNTRIES:
        if _EU_EMOJI_FONT:
            flag = f' <font name="{_EU_EMOJI_FONT}" size="7">🇪🇺</font>'
        else:
            flag = ' <font color="#003399" size="5.5"><b>EU</b></font>'
        return Paragraph(name + flag, style)
    return Paragraph(name, style)


def build_legend():
    zones = sorted(ZONE_LABELS)        # [1,2,3,4,5,6,7,9]
    mid   = len(zones) // 2            # 4 per row
    rows_data = [zones[:mid], zones[mid:]]

    st    = ParagraphStyle("leg", fontName="Arial-Bold", fontSize=7,
                           textColor=DGRAY, leading=9, alignment=TA_CENTER)
    col_w = AVAIL_W / mid
    tbl   = Table(
        [[Paragraph(ZONE_LABELS[z], st) for z in row] for row in rows_data],
        colWidths=[col_w] * mid,
    )
    bg = [
        ("BACKGROUND", (ci, ri), (ci, ri), ZONE_BG[z])
        for ri, row in enumerate(rows_data)
        for ci, z in enumerate(row)
    ]
    tbl.setStyle(TableStyle([
        ("ALIGN",         (0, 0), (-1, -1), "CENTER"),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING",    (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LINEBELOW",     (0, 0), (-1, -1), 0.3, colors.HexColor("#cccccc")),
        ("LINEBEFORE",    (1, 0), (-1, -1), 0.3, colors.HexColor("#cccccc")),
        ("BOX",           (0, 0), (-1, -1), 0.5, colors.HexColor("#bbbbbb")),
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
        hdr.append(Paragraph("Χώρα", chdr_st))
        for svc in SERVICES:
            hdr.append(VerticalText(svc, cell_w=COL_SVC, cell_h=HDR_H))

    # ── Data rows + per-zone-cell background commands ────────────────────────
    rows          = [hdr]
    zone_bg_cmds  = []

    for i in range(per_col):
        row = []
        for g in range(NCOLS):
            idx = g * per_col + i
            if idx < n:
                code = sorted_codes[idx]
                info = countries_by_code[code]
                row.append(country_para(code, info["name"], cell_st))
                for si, svc in enumerate(SERVICES):
                    zone = info.get(svc)
                    row.append(z_para(zone, zone_st))
                    if zone is not None:
                        zone_bg_cmds.append((
                            "BACKGROUND",
                            (g * GROUP + 1 + si, i + 1),
                            (g * GROUP + 1 + si, i + 1),
                            ZONE_BG[zone],
                        ))
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

    alt_bg = [
        ("BACKGROUND", (0, r), (-1, r), LIGHT if r % 2 == 0 else WHITE)
        for r in range(1, n_rows)
    ]
    seps = [
        ("LINEBEFORE", (g * GROUP, 0), (g * GROUP, -1), 1.0, colors.HexColor("#999999"))
        for g in range(1, NCOLS)
    ]
    ctr_align = [
        ("ALIGN", (g * GROUP, 1), (g * GROUP, -1), "LEFT")
        for g in range(NCOLS)
    ]

    style = [
        ("FONTNAME",      (0, 0), (-1, -1), "Arial"),
        ("FONTSIZE",      (0, 0), (-1, -1), 7),
        ("VALIGN",        (0, 0), (-1, -1), "MIDDLE"),
        ("ALIGN",         (0, 0), (-1, -1), "CENTER"),
        ("TOPPADDING",    (0, 0), (-1, -1), 1),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 1),
        ("LEFTPADDING",   (0, 0), (-1, -1), 2),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 2),
        ("BACKGROUND",    (0, 0), (-1, 0),  RED),
        ("TOPPADDING",    (0, 0), (-1, 0),  0),
        ("BOTTOMPADDING", (0, 0), (-1, 0),  0),
        ("LEFTPADDING",   (0, 0), (-1, 0),  0),
        ("RIGHTPADDING",  (0, 0), (-1, 0),  0),
        ("LINEBELOW",     (0, 0), (-1, -1), 0.2, colors.HexColor("#e0e0e0")),
        ("LINEBEFORE",    (1, 0), (-1, -1), 0.2, colors.HexColor("#e0e0e0")),
        ("BOX",           (0, 0), (-1, -1), 0.5, colors.HexColor("#bbbbbb")),
        *alt_bg,
        *zone_bg_cmds,   # overrides alt_bg for non-None zone cells
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

    # CY is a special zone (Z9 Κύπρος) — override whatever the data says
    if "CY" in countries:
        for svc in SERVICES:
            if countries["CY"][svc] is not None:
                countries["CY"][svc] = 9

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
        Paragraph("Ζώνες DHL 2026 — 4A Express GR", title_st),
        Paragraph(f"Ισχύει από 01/01/2026  ·  {len(countries)} χώρες", sub_st),
        build_legend(),
        Spacer(1, 4),
        build_grid(countries),
    ]
    doc.build(story)
    print(f"OK  PDF generated: {OUT_FILE}  ({len(countries)} countries)")


if __name__ == "__main__":
    generate()
