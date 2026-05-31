#!/usr/bin/env python3
# shipments/waybill_pdf.py | v1.0 | 30-05-2026
import sys, json, os, base64, tempfile
from datetime import datetime
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Spacer, HRFlowable
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_RIGHT

# Fonts live in api/ — one level up from api/shipments/
FONT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
pdfmetrics.registerFont(TTFont('DV',   os.path.join(FONT_DIR, 'DejaVuSans.ttf')))
pdfmetrics.registerFont(TTFont('DV-B', os.path.join(FONT_DIR, 'DejaVuSans-Bold.ttf')))

F = 'DV'; FB = 'DV-B'
W, H = A4; ML = MR = 15*mm; cw = W - ML - MR

RED        = colors.HexColor('#cc0000')
DRED       = colors.HexColor('#990000')
MGRAY      = colors.HexColor('#555555')
LGRAY      = colors.HexColor('#f2f2f2')
DGRAY      = colors.HexColor('#222222')
BGRAY      = colors.HexColor('#999999')
BORDER     = colors.HexColor('#dddddd')
WHITE      = colors.white
COD_GREEN  = colors.HexColor('#1a7a1a')
COD_BG     = colors.HexColor('#e8f5e9')
COD_BORDER = colors.HexColor('#2e7d32')

def s(n, **k):  return ParagraphStyle(n, fontName=F,  **k)
def sb(n, **k): return ParagraphStyle(n, fontName=FB, **k)
P = lambda t, st: Paragraph(t, st)


def generate(data):
    shipment_id      = data.get('shipment_id', 0)
    cod_enabled      = bool(data.get('cod_enabled', False))
    cod_amount       = data.get('cod_amount')
    cod_fee          = data.get('cod_fee')
    cod_confirmed_at = data.get('cod_confirmed_at') or '—'
    now              = datetime.now()

    tmpfile = tempfile.NamedTemporaryFile(suffix='.pdf', delete=False)
    tmpfile.close()

    doc = SimpleDocTemplate(
        tmpfile.name, pagesize=A4,
        rightMargin=MR, leftMargin=ML, topMargin=15*mm, bottomMargin=12*mm,
        title=f"Waybill #{shipment_id}", author='4A Express Worldwide'
    )
    story = []

    # ── Header ──────────────────────────────────────────────────────────────────
    hdr = Table([[
        Table([[P('4A', sb('l', fontSize=16, textColor=RED, alignment=TA_CENTER))]], colWidths=[14*mm]),
        P('WORLDWIDE EXPRESS', sb('w', fontSize=8, textColor=WHITE)),
        P(f"WAYBILL #{shipment_id}", sb('n', fontSize=9, textColor=WHITE, alignment=TA_RIGHT)),
    ]], colWidths=[14*mm, cw - 74*mm, 60*mm])
    hdr.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (0, 0), WHITE), ('BACKGROUND', (1, 0), (2, 0), RED),
        ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
        ('TOPPADDING', (0, 0), (-1, -1), 5), ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
        ('LEFTPADDING', (1, 0), (1, 0), 10), ('RIGHTPADDING', (2, 0), (2, 0), 8),
        ('BOX', (0, 0), (-1, -1), 1.2, RED),
    ]))
    story.append(hdr)
    story.append(Spacer(1, 5*mm))

    # ── Shipment info bar ────────────────────────────────────────────────────────
    story.append(Table([[
        Table([[P('ΑΡ. ΑΠΟΣΤΟΛΗΣ', s('sl', fontSize=7, textColor=BGRAY))],
               [P(f"#{shipment_id}", sb('sv', fontSize=14, textColor=RED))]], colWidths=[50*mm]),
        Table([[P('ΗΜΕΡΟΜΗΝΙΑ', s('dl', fontSize=7, textColor=BGRAY))],
               [P(now.strftime('%d-%m-%Y'), sb('dv', fontSize=11, textColor=DGRAY))]], colWidths=[50*mm]),
        Table([[P('ΩΡΑ', s('tl', fontSize=7, textColor=BGRAY))],
               [P(now.strftime('%H:%M'), sb('tv', fontSize=11, textColor=DGRAY))]], colWidths=[cw - 100*mm]),
    ]], colWidths=[50*mm, 50*mm, cw - 100*mm], style=[
        ('BACKGROUND', (0, 0), (-1, -1), LGRAY),
        ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
        ('LEFTPADDING', (0, 0), (-1, -1), 8),
        ('BOX', (0, 0), (-1, -1), 0.5, BORDER), ('LINEAFTER', (0, 0), (1, -1), 0.5, BORDER),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
    ]))
    story.append(Spacer(1, 6*mm))

    # ── COD section ─────────────────────────────────────────────────────────────
    if cod_enabled:
        # Green header band
        story.append(Table([[
            P('ΑΝΤΙΚΑΤΑΒΟΛΗ (COD)', sb('ct', fontSize=9, textColor=WHITE)),
        ]], colWidths=[cw], style=[
            ('BACKGROUND', (0, 0), (-1, -1), COD_GREEN),
            ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
            ('LEFTPADDING', (0, 0), (-1, -1), 10),
        ]))
        # Data cells
        story.append(Table([[
            Table([[P('COD',    s('cl',  fontSize=7,  textColor=BGRAY))],
                   [P('ΝΑΙ',   sb('cv', fontSize=16, textColor=COD_GREEN))]], colWidths=[28*mm]),
            Table([[P('ΠΟΣΟ',  s('al',  fontSize=7,  textColor=BGRAY))],
                   [P(f"€{cod_amount:.2f}", sb('av', fontSize=16, textColor=DGRAY))]], colWidths=[55*mm]),
            Table([[P('ΧΡΕΩΣΗ', s('fl', fontSize=7,  textColor=BGRAY))],
                   [P(f"€{cod_fee:.2f}",    sb('fv', fontSize=16, textColor=DGRAY))]], colWidths=[55*mm]),
            Table([[P('ΕΠΙΒΕΒΑΙΩΘΗΚΕ', s('rl', fontSize=7, textColor=BGRAY))],
                   [P(cod_confirmed_at,           s('rv', fontSize=8,  textColor=MGRAY))]], colWidths=[cw - 138*mm]),
        ]], colWidths=[28*mm, 55*mm, 55*mm, cw - 138*mm], style=[
            ('BACKGROUND', (0, 0), (-1, -1), COD_BG),
            ('TOPPADDING', (0, 0), (-1, -1), 8), ('BOTTOMPADDING', (0, 0), (-1, -1), 8),
            ('LEFTPADDING', (0, 0), (-1, -1), 10),
            ('BOX',       (0, 0), (-1, -1), 1.5, COD_BORDER),
            ('LINEAFTER', (0, 0), (2,  -1), 0.5, COD_BORDER),
            ('VALIGN',    (0, 0), (-1, -1), 'MIDDLE'),
        ]))
        story.append(Spacer(1, 3*mm))
        # Plain-text summary line (visible in print)
        story.append(P(
            f"COD: ΝΑΙ  |  Ποσό: €{cod_amount:.2f}  |  Χρέωση: €{cod_fee:.2f}",
            sb('cnote', fontSize=9, textColor=COD_GREEN)
        ))
    else:
        story.append(Table([[
            P('Χωρίς Αντικαταβολή  (COD: ΟΧΙ)',
              s('nc', fontSize=8, textColor=BGRAY)),
        ]], colWidths=[cw], style=[
            ('BACKGROUND', (0, 0), (-1, -1), LGRAY),
            ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
            ('LEFTPADDING', (0, 0), (-1, -1), 10),
            ('BOX', (0, 0), (-1, -1), 0.5, BORDER),
        ]))

    # ── Footer ───────────────────────────────────────────────────────────────────
    story.append(Spacer(1, 8*mm))
    story.append(HRFlowable(width=cw, thickness=0.5, color=BORDER))
    story.append(Table([[
        P('Ε.Ε.Τ.Τ. 033-99  ·  ΑΦΜ: 044978638  ·  ΚΕΦΟΔΕ ΑΘΗΝΩΝ',
          s('f1', fontSize=6.5, textColor=BGRAY)),
        P(f"4A Express  ·  #{shipment_id}  ·  www.4aexpress.com",
          s('f2', fontSize=6.5, textColor=BGRAY, alignment=TA_RIGHT)),
    ]], colWidths=[cw // 2, cw // 2], style=[('TOPPADDING', (0, 0), (-1, -1), 4)]))

    doc.build(story)

    with open(tmpfile.name, 'rb') as f:
        pdf_b64 = base64.b64encode(f.read()).decode()
    os.unlink(tmpfile.name)
    return pdf_b64


if __name__ == '__main__':
    data   = json.loads(sys.stdin.read())
    result = generate(data)
    print(json.dumps({'ok': True, 'pdf': result}))
