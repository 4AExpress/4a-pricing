#!/usr/bin/env python3
# generate_pdf.py | v1.2 | 19-05-2026
import sys, json, os, base64, tempfile
from datetime import datetime, timedelta
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Spacer, HRFlowable, PageBreak
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

# Fonts path
FONT_DIR = os.path.dirname(os.path.abspath(__file__))
pdfmetrics.registerFont(TTFont('DV',  os.path.join(FONT_DIR,'DejaVuSans.ttf')))
pdfmetrics.registerFont(TTFont('DV-B',os.path.join(FONT_DIR,'DejaVuSans-Bold.ttf')))

F='DV'; FB='DV-B'
W,H=A4; ML=MR=15*mm; cw=W-ML-MR

RED=colors.HexColor('#cc0000'); DRED=colors.HexColor('#990000')
MGRAY=colors.HexColor('#555555'); LGRAY=colors.HexColor('#f2f2f2')
XLGRAY=colors.HexColor('#f8f8f8'); DGRAY=colors.HexColor('#222222')
BGRAY=colors.HexColor('#999999'); BORDER=colors.HexColor('#dddddd'); WHITE=colors.white

ZONE_BG={1:colors.HexColor('#f0f0f0'),2:colors.HexColor('#e8e8e8'),
         3:colors.HexColor('#e0e0e0'),4:colors.HexColor('#d8d8d8'),
         5:colors.HexColor('#d0d0d0'),6:colors.HexColor('#c8c8c8'),7:colors.HexColor('#c0c0c0'),
         8:colors.HexColor('#ddeeff'),9:colors.HexColor('#ddeeff')}

OFFICES={
    'ATH':{'name':'Αθήνα',   'prefix':'0107','addr':'Βελεστίνου 7, 11523, Αθήνα',         'tel':'+30 210 9966661','email':'sales@4aexpress.com','maps':'https://maps.google.com/?q=Βελεστίνου+7,+Αθήνα'},
    'NIC':{'name':'Λευκωσία','prefix':'0108','addr':'Αθαλάσσης 107, Λευκωσία, Κύπρος',    'tel':'+357 22 953311', 'email':'4aexpress@cytanet.com.cy','maps':'https://maps.google.com/?q=Athalassas+107,+Nicosia'},
    'QLI':{'name':'Λεμεσός', 'prefix':'0109','addr':'Spyrou Kyprianou Ave 20, Λεμεσός',    'tel':'+357 25590500',  'email':'qli1@4aexpress.com','maps':'https://maps.google.com/?q=Spyrou+Kyprianou+20,+Limassol'},
    'LCA':{'name':'Λάρνακα', 'prefix':'0110','addr':'Αρχιεπ. Κυπριανού 58, Λάρνακα',      'tel':'+357 24 424280', 'email':'4aexpress@cytanet.com.cy','maps':'https://maps.google.com/?q=Archiepiskopou+Kyprianou+58,+Larnaca'},
}

SVC_INFO={
    'S1003':   {'name':'Express Export',          'desc':'Αεροπορική Μεταφορά — Export',          'type':'AIR'},
    'S1012':   {'name':'Express Import',          'desc':'Αεροπορική Μεταφορά — Import',          'type':'AIR'},
    'S1010':   {'name':'Economy Export',          'desc':'Οδική Μεταφορά — Export',               'type':'ROAD'},
    'S1041':   {'name':'Economy Import',          'desc':'Οδική Μεταφορά — Import',               'type':'ROAD'},
    'S1003_GR':{'name':'Export Ελλάδα→Κύπρος',   'desc':'Αεροπορική Μεταφορά — Export GR→CY',    'type':'AIR'},
    'S1012_GR':{'name':'Import Κύπρος→Ελλάδα',   'desc':'Αεροπορική Μεταφορά — Import CY→GR',    'type':'AIR'},
    'S1003_CY':{'name':'Express Export Κύπρος',  'desc':'Αεροπορική Μεταφορά — Export CY',       'type':'AIR'},
    'S1012_CY':{'name':'Express Import Κύπρος',  'desc':'Αεροπορική Μεταφορά — Import CY',       'type':'AIR'},
    'S1050':   {'name':'Export CY→GR→World',      'desc':'COMBI AIR+ROAD — Export',               'type':'COMBI'},
    'S1051':   {'name':'Import World→GR→CY',      'desc':'COMBI ROAD+AIR — Import',               'type':'COMBI'},
}

def s(n,**k):  return ParagraphStyle(n,fontName=F, **k)
def sb(n,**k): return ParagraphStyle(n,fontName=FB,**k)
P=lambda t,st:Paragraph(t,st)

def hdr(num):
    t=Table([[
        Table([[P('4A',sb('l',fontSize=16,textColor=RED,alignment=TA_CENTER))]],colWidths=[14*mm]),
        P('WORLDWIDE EXPRESS',sb('w',fontSize=8,textColor=WHITE)),
        P(num,sb('n',fontSize=9,textColor=WHITE,alignment=TA_RIGHT)),
    ]],colWidths=[14*mm,cw-74*mm,60*mm])
    t.setStyle(TableStyle([
        ('BACKGROUND',(0,0),(0,0),WHITE),('BACKGROUND',(1,0),(2,0),RED),
        ('VALIGN',(0,0),(-1,-1),'MIDDLE'),('TOPPADDING',(0,0),(-1,-1),5),
        ('BOTTOMPADDING',(0,0),(-1,-1),5),('LEFTPADDING',(1,0),(1,0),10),
        ('RIGHTPADDING',(2,0),(2,0),8),('BOX',(0,0),(-1,-1),1.2,RED),
    ]))
    return t

def ftr(num,date,version=''):
    right=f"4A Express  ·  {num}  ·  {date}  ·  www.4aexpress.com"
    if version: right+=f"  ·  {version}"
    return Table([[
        P('Ε.Ε.Τ.Τ. 033-99  ·  ΑΦΜ: 044978638  ·  ΚΕΦΟΔΕ ΑΘΗΝΩΝ',
          s('f1',fontSize=6.5,textColor=BGRAY)),
        P(right,s('f2',fontSize=6.5,textColor=BGRAY,alignment=TA_RIGHT)),
    ]],colWidths=[cw//2,cw//2],style=[('TOPPADDING',(0,0),(-1,-1),4)])

def sec(txt):
    t=Table([[P(txt,sb('st',fontSize=10,textColor=WHITE))]],colWidths=[cw])
    t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,-1),DRED),
        ('TOPPADDING',(0,0),(-1,-1),7),('BOTTOMPADDING',(0,0),(-1,-1),7),
        ('LEFTPADDING',(0,0),(-1,-1),10)]))
    return t

def apply_markup(entries, markup_pct, zones, markup_z9=None):
    table={}
    for e in entries:
        z=int(e['zone_code'].replace('z',''))
        if z>zones and z!=9: continue
        w=e['weight_kg']
        if w not in table: table[w]={}
        if z==9 and markup_z9 is not None:
            table[w][z]=round(e['cost']*(1+markup_z9/100),2)
        else:
            table[w][z]=round(e['cost']*(1+markup_pct/100),2)
    return table

def generate(offer_data, dhl_data, fuel_data):
    office_code = offer_data.get('office_code','ATH')
    office = OFFICES.get(office_code, OFFICES['ATH'])

    curr_air  = next((x for x in fuel_data.get('air',[])  if x.get('is_current')),None)
    curr_road = next((x for x in fuel_data.get('road',[]) if x.get('is_current')),None)
    fuel_air  = curr_air['pct']  if curr_air  else 0
    fuel_road = curr_road['pct'] if curr_road else 0
    fuel_week = curr_air['week'] if curr_air  else '—'

    prefix = office.get('prefix','0107')
    offer_num = f"{prefix}-{offer_data.get('offer_number','4A-2026-0001')}"

    services = offer_data.get('pricelists',[])
    date = offer_data.get('date','—')
    vstamp = datetime.now().strftime('v%d-%m-%Y %H:%M')

    try:
        d = datetime.strptime(date,'%d-%m-%Y')
        validity_days = int(offer_data.get('validity',30))
        valid_until = (d+timedelta(days=validity_days)).strftime('%d-%m-%Y')
    except:
        valid_until = '—'

    tmpfile = tempfile.NamedTemporaryFile(suffix='.pdf', delete=False)
    tmpfile.close()

    doc=SimpleDocTemplate(tmpfile.name,pagesize=A4,
        rightMargin=MR,leftMargin=ML,topMargin=15*mm,bottomMargin=12*mm,
        title=f"Προσφορά {offer_num}",author='4A Express Worldwide')
    story=[]

    # ── COVER ──
    story.append(hdr(offer_num))
    story.append(Table([[
        P('ΠΡΟΣΦΟΡΑ',sb('t1',fontSize=20,textColor=WHITE,alignment=TA_CENTER)),
        P('OFFER',   sb('t2',fontSize=20,textColor=colors.HexColor('#ffcccc'),alignment=TA_CENTER)),
    ]],colWidths=[cw//2,cw//2],style=[
        ('BACKGROUND',(0,0),(-1,-1),DRED),('TOPPADDING',(0,0),(-1,-1),9),
        ('BOTTOMPADDING',(0,0),(-1,-1),9),('VALIGN',(0,0),(-1,-1),'MIDDLE'),
        ('LINEAFTER',(0,0),(0,-1),0.5,colors.HexColor('#dd3333'))]))
    story.append(Spacer(1,4*mm))

    C=cw//2-3*mm
    def trow(l1,v1,l2,v2,bold=False):
        fs=10 if bold else 9; fc=RED if bold else DGRAY
        return [
            Table([[P(l1,s('rl',fontSize=7,textColor=BGRAY))],[P(v1,sb('rv',fontSize=fs,textColor=fc))]],colWidths=[C]),
            Table([[P(l2,s('rl2',fontSize=7,textColor=BGRAY))],[P(v2,sb('rv2',fontSize=fs,textColor=fc))]],colWidths=[C]),
        ]
    mt=Table([
        trow('ΠΕΛΑΤΗΣ',offer_data.get('name','—'),'ΓΡΑΦΕΙΟ',f"4A Express — {office['name']}",bold=True),
        trow('ΑΦΜ',offer_data.get('afm','—'),'ΔΙΕΥΘΥΝΣΗ',office['addr']),
        trow('EMAIL',offer_data.get('email','—'),'ΤΗΛΕΦΩΝΟ',office['tel']),
        trow('ΥΠΕΥΘΥΝΟΣ',offer_data.get('contact','—'),'EMAIL ΓΡΑΦΕΙΟΥ',office['email']),
        trow('ΕΚΔΟΤΗΣ',offer_data.get('user','—'),'ΙΣΤΟΣΕΛΙΔΑ','www.4aexpress.com'),
    ],colWidths=[C,C])
    mt.setStyle(TableStyle([
        ('VALIGN',(0,0),(-1,-1),'TOP'),('TOPPADDING',(0,0),(-1,-1),5),
        ('BOTTOMPADDING',(0,0),(-1,-1),5),('LEFTPADDING',(0,0),(-1,-1),8),
        ('LINEAFTER',(0,0),(0,-1),0.5,BORDER),('LINEBELOW',(0,0),(-1,-2),0.25,BORDER),
        ('BACKGROUND',(0,0),(-1,0),LGRAY),
    ]))
    story.append(mt)
    story.append(Spacer(1,4*mm))

    story.append(Table([[
        Table([[P('ΑΡ. ΠΡΟΣΦΟΡΑΣ',s('nl',fontSize=7,textColor=BGRAY))],[P(offer_num,sb('nv',fontSize=11,textColor=RED))]],colWidths=[65*mm]),
        Table([[P('ΗΜΕΡΟΜΗΝΙΑ',s('dl',fontSize=7,textColor=BGRAY))],[P(date,sb('dv',fontSize=11,textColor=DGRAY))]],colWidths=[38*mm]),
        Table([[P('ΙΣΧΥΣ ΑΠΟΔΟΧΗΣ ΕΩΣ',s('vl',fontSize=7,textColor=BGRAY))],[P(valid_until,sb('vv',fontSize=11,textColor=RED))]],colWidths=[38*mm]),
        Table([[P('ΓΛΩΣΣΑ',s('ll',fontSize=7,textColor=BGRAY))],[P('Ελληνικά',sb('lv',fontSize=9,textColor=DGRAY))]],colWidths=[cw-141*mm]),
    ]],colWidths=[65*mm,38*mm,38*mm,cw-141*mm],style=[
        ('BACKGROUND',(0,0),(-1,-1),LGRAY),('TOPPADDING',(0,0),(-1,-1),6),
        ('BOTTOMPADDING',(0,0),(-1,-1),6),('LEFTPADDING',(0,0),(-1,-1),8),
        ('BOX',(0,0),(-1,-1),0.5,BORDER),('LINEAFTER',(0,0),(2,-1),0.5,BORDER),
        ('VALIGN',(0,0),(-1,-1),'TOP'),
    ]))
    story.append(Spacer(1,5*mm))

    # TOC
    story.append(sec('ΠΕΡΙΕΧΟΜΕΝΟ ΠΡΟΣΦΟΡΑΣ'))
    story.append(Spacer(1,2*mm))
    has_gr = any(svc['service_id'] in {'S1003','S1012','S1010','S1041'} for svc in services)
    pg = 2
    toc_items = [(pg,'How','Πώς Λειτουργεί ο Τιμοκατάλογος','ΠΛΗΡΟΦΟΡΙΕΣ')]
    pg += 1
    if has_gr:
        toc_items.append((pg,'Zones','Ζώνες 4A Express GR','ΠΛΗΡΟΦΟΡΙΕΣ'))
        pg += 1
    for svc in services:
        svc_id = svc['service_id']
        dhl_key = {'S1003_GR':'S1003','S1012_GR':'S1012'}.get(svc_id, svc_id)
        if not dhl_data.get(dhl_key,[]): continue
        info = SVC_INFO.get(svc_id,{})
        toc_items.append((pg,svc_id,f"{info.get('name',svc_id)} — {info.get('desc','')}",info.get('type','')))
        pg += 1
    toc_items.append((pg,'net','Δίκτυο Γραφείων 4A Express','ΠΛΗΡΟΦΟΡΙΕΣ')); pg+=1
    toc_items.append((pg,'track','Εύρεση & Tracking Αποστολής','ΠΛΗΡΟΦΟΡΙΕΣ')); pg+=1
    toc_items.append((pg,'terms','Όροι, Χρεώσεις & Επίναυλος','ΟΡΟΙ')); pg+=1
    toc_items.append((pg,'accept','Αποδοχή Προσφοράς','ΑΠΟΔΟΧΗ'))

    toc_rows=[[P('ΣΕΛ.',sb('th',fontSize=7,textColor=WHITE,alignment=TA_CENTER)),
               P('ΚΩΔΙΚΟΣ',sb('th',fontSize=7,textColor=WHITE)),
               P('ΠΕΡΙΓΡΑΦΗ',sb('th',fontSize=7,textColor=WHITE)),
               P('ΤΥΠΟΣ',sb('th',fontSize=7,textColor=WHITE,alignment=TA_CENTER))]]
    for pg,code,desc,typ in toc_items:
        is_svc=(len(code)==5 and code[0]=='S')
        toc_rows.append([
            P(str(pg),sb('tp',fontSize=8,textColor=MGRAY,alignment=TA_CENTER)),
            P(f'<a href="#{code}" color="red"><u>{code}</u></a>' if (len(code)==5 and code[0]=='S') else code,
              sb('tc',fontSize=8,textColor=RED if is_svc else BGRAY)),
            P(desc,s('td',fontSize=8,textColor=DGRAY)),
            P(typ,sb('tt2',fontSize=7,textColor=WHITE if is_svc else MGRAY,alignment=TA_CENTER)),
        ])
    toc_t=Table(toc_rows,colWidths=[15*mm,22*mm,cw-72*mm,35*mm])
    toc_ts=TableStyle([
        ('BACKGROUND',(0,0),(-1,0),MGRAY),('GRID',(0,0),(-1,-1),0.25,BORDER),
        ('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6),
        ('LEFTPADDING',(0,0),(-1,-1),6),('VALIGN',(0,0),(-1,-1),'MIDDLE'),
        ('ROWBACKGROUNDS',(0,1),(-1,-1),[WHITE,XLGRAY]),
    ])
    for i,(pg,code,desc,typ) in enumerate(toc_items,1):
        is_svc=len(code)==5 and code[0]=='S'
        toc_ts.add('BACKGROUND',(3,i),(3,i),RED if is_svc else LGRAY)
    toc_t.setStyle(toc_ts)
    story.append(toc_t)
    story.append(Spacer(1,3*mm))
    story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER))
    story.append(ftr(offer_num,date,vstamp))
    story.append(PageBreak())

    # ── ΠΩΣ ΛΕΙΤΟΥΡΓΕΙ ──
    story.append(hdr(offer_num))
    story.append(Paragraph('<a name="how"/>',s('anc',fontSize=0.1)))
    story.append(sec('ΠΩΣ ΛΕΙΤΟΥΡΓΕΙ Ο ΤΙΜΟΚΑΤΑΛΟΓΟΣ'))
    story.append(Spacer(1,3*mm))
    for num,title,desc in [
        ('1','Βρείτε τον Προορισμό','Ανατρέξτε στον Κατάλογο Ζωνών. Κάθε χώρα = Ζώνη Z1-Z7 (AIR) ή Z1-Z3 (ROAD).'),
        ('2','Μετρήστε το Βάρος','Χρησιμοποιήστε το μεγαλύτερο: πραγματικό ή ογκομετρικό (Μ×Π×Υ cm / 5000).'),
        ('3','Βρείτε την Τιμή','Γραμμή βάρους × Στήλη ζώνης = βασική τιμή εκτός επίναυλου.'),
        ('4','Προσθέστε Επίναυλο',f"Τρέχουσα εβδομάδα ({fuel_week}): AIR {fuel_air}% · ROAD {fuel_road}%."),
        ('5','Βάρος >70kg','Χρησιμοποιήστε +1kg ή +5kg row × επιπλέον κιλά.'),
    ]:
        st=Table([[
            P(num,sb('hn',fontSize=13,textColor=WHITE,alignment=TA_CENTER)),
            Table([[P(title,sb('ht',fontSize=9,textColor=DGRAY))],
                   [P(desc,s('hd',fontSize=8,textColor=MGRAY,leading=12))]],colWidths=[cw-22*mm]),
        ]],colWidths=[18*mm,cw-18*mm])
        st.setStyle(TableStyle([
            ('BACKGROUND',(0,0),(0,-1),MGRAY),('VALIGN',(0,0),(-1,-1),'TOP'),
            ('TOPPADDING',(0,0),(-1,-1),7),('BOTTOMPADDING',(0,0),(-1,-1),7),
            ('LEFTPADDING',(0,0),(-1,-1),8),
            ('BOX',(0,0),(-1,-1),0.25,BORDER),('LINEAFTER',(0,0),(0,-1),0.5,BORDER),
        ]))
        story.append(st); story.append(Spacer(1,2*mm))

    story.append(Spacer(1,3*mm))
    story.append(Paragraph('<a name="track"/>',s('anc',fontSize=0.1)))
    story.append(sec('ΠΛΗΡΟΦΟΡΙΕΣ ΑΠΟΣΤΟΛΗΣ & TRACKING'))
    story.append(Spacer(1,3*mm))
    it=Table([[P(t,sb('it',fontSize=8,textColor=WHITE)),P(d,s('id',fontSize=8,textColor=MGRAY,leading=12))] for t,d in [
        ('TRACKING','Μοναδικός αριθμός AWB 12 ψηφία. Παρακολούθηση σε πραγματικό χρόνο στο www.4aexpress.com .'),
        ('ΠΑΡΑΔΟΣΗ','AIR: 1-3 εργάσιμες. ROAD: 2-5 εργάσιμες. Κύπρος: +1 εργάσιμη.'),
        ('ΑΣΦΑΛΕΙΑ','Βασική κάλυψη €100. Πρόσθετη ασφάλεια κατόπιν αιτήματος.'),
        ('ΑΠΑΓΟΡΕΥΜΕΝΑ','Επικίνδυνα υλικά, νομίσματα, ζώα. Επικοινωνήστε για διευκρινίσεις.'),
    ]],colWidths=[38*mm,cw-38*mm])
    it.setStyle(TableStyle([
        ('BACKGROUND',(0,0),(0,-1),MGRAY),('GRID',(0,0),(-1,-1),0.25,BORDER),
        ('TOPPADDING',(0,0),(-1,-1),7),('BOTTOMPADDING',(0,0),(-1,-1),7),
        ('LEFTPADDING',(0,0),(-1,-1),8),('VALIGN',(0,0),(-1,-1),'TOP'),
        ('ROWBACKGROUNDS',(1,0),(1,-1),[WHITE,XLGRAY]),
    ]))
    story.append(it)
    story.append(Spacer(1,3*mm))
    story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER))
    story.append(ftr(offer_num,date,vstamp))
    story.append(PageBreak())

    # ── ΔΙΚΤΥΟ ──
    story.append(hdr(offer_num))
    story.append(Paragraph('<a name="net"/>',s('anc',fontSize=0.1)))
    story.append(sec('ΔΙΚΤΥΟ ΓΡΑΦΕΙΩΝ 4A EXPRESS'))
    story.append(Spacer(1,4*mm))
    off_rows=[[P('PREFIX',sb('oh',fontSize=7,textColor=WHITE,alignment=TA_CENTER)),
               P('ΣΤΑΘΜΟΣ',sb('oh',fontSize=7,textColor=WHITE)),
               P('ΔΙΕΥΘΥΝΣΗ',sb('oh',fontSize=7,textColor=WHITE)),
               P('ΤΗΛΕΦΩΝΟ',sb('oh',fontSize=7,textColor=WHITE)),
               P('EMAIL',sb('oh',fontSize=7,textColor=WHITE))]]
    for code,off in OFFICES.items():
        is_cur=code==office_code
        off_rows.append([
            P(off['prefix'],sb('op',fontSize=10,textColor=RED if is_cur else MGRAY,alignment=TA_CENTER)),
            P(f"{'★ ' if is_cur else ''}{off['name']}",sb('on2',fontSize=10,textColor=RED if is_cur else DGRAY)),
            P(f'<a href="{off["maps"]}" color="#1565c0">{off["addr"]}</a>',s('oa',fontSize=8,textColor=MGRAY,leading=12)),
            P(off['tel'], sb('ot',fontSize=8,textColor=DGRAY)),
            P(off['email'],s('oe',fontSize=8,textColor=MGRAY)),
        ])
    oft=Table(off_rows,colWidths=[16*mm,28*mm,68*mm,36*mm,cw-148*mm])
    ofts=TableStyle([
        ('BACKGROUND',(0,0),(-1,0),MGRAY),('GRID',(0,0),(-1,-1),0.25,BORDER),
        ('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8),
        ('LEFTPADDING',(0,0),(-1,-1),6),('VALIGN',(0,0),(-1,-1),'MIDDLE'),
        ('ROWBACKGROUNDS',(0,1),(-1,-1),[WHITE,XLGRAY]),
    ])
    for i,(code,_) in enumerate(OFFICES.items(),1):
        if code==office_code:
            ofts.add('BACKGROUND',(0,i),(-1,i),colors.HexColor('#fff0f0'))
            ofts.add('LINEBEFORE',(0,i),(0,i),3,RED)
    oft.setStyle(ofts)
    story.append(oft)
    story.append(Spacer(1,5*mm))
    story.append(Table([[
        P("ΚΑΤΑΛΟΓΟΣ ΖΩΝΩΝ",sb('ztl',fontSize=8,textColor=MGRAY)),
        P("Ο κατάλογος ζωνών χωρών επισυνάπτεται στην παρούσα προσφορά.",
          s('ztb',fontSize=8,textColor=MGRAY,leading=12))
    ]],colWidths=[42*mm,cw-42*mm],style=[
        ('BACKGROUND',(0,0),(0,-1),LGRAY),('TOPPADDING',(0,0),(-1,-1),8),
        ('BOTTOMPADDING',(0,0),(-1,-1),8),('LEFTPADDING',(0,0),(-1,-1),10),
        ('BOX',(0,0),(-1,-1),0.5,BORDER),('LINEAFTER',(0,0),(0,-1),0.5,BORDER),
        ('VALIGN',(0,0),(-1,-1),'MIDDLE'),
    ]))
    story.append(Spacer(1,3*mm))
    story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER))
    story.append(ftr(offer_num,date,vstamp))
    story.append(PageBreak())

    # ── SERVICES ──
    zone_descs={1:'ΕΕ Δυτ.',2:'Ευρώπη',3:'ΕΕ Περιφ.',4:'Αμερική',5:'ΜΑ/Αφρ.',6:'Ασία/Ωκ.',7:'Υπόλοιπος',8:'',9:'Κύπρος'}
    for svc in services:
        svc_id=svc['service_id']
        info=SVC_INFO.get(svc_id,{})
        is_air=info.get('type')=='AIR'
        zones=7 if is_air else 3
        markup=float(svc.get('markup',0))
        markup_z9=float(svc.get('markup_z9',0)) or None
        print(f"DEBUG [{svc_id}] raw svc={svc}  →  markup={markup}  markup_z9_raw={svc.get('markup_z9',0)}", file=sys.stderr, flush=True)
        if svc_id in ('S1003_GR','S1012_GR'):
            markup_z9=None
        _DHL_SRC={'S1003_GR':'S1003','S1012_GR':'S1012'}
        entries=dhl_data.get(_DHL_SRC.get(svc_id,svc_id),[])
        print(f"DEBUG [{svc_id}] final markup={markup}  markup_z9={markup_z9}  entries={len(entries)}", file=sys.stderr, flush=True)
        saved_rows={float(r['weight']):float(r['price']) for r in svc.get('rows',[]) if 'weight' in r and 'price' in r}
        if saved_rows:
            dhl_costs={}
            for e in entries:
                w=e['weight_kg']; z=int(e['zone_code'].replace('z',''))
                dhl_costs.setdefault(w,{})[z]=e['cost']
            prices=apply_markup(entries,markup,zones,markup_z9)
            for w,saved_price in saved_rows.items():
                if w not in prices or w not in dhl_costs: continue
                if len(dhl_costs.get(w,{})) == 1:
                    prices[w][next(iter(prices[w]))]=saved_price
                    continue
                z1_cost=dhl_costs[w].get(1) or next(iter(dhl_costs[w].values()),None)
                if not z1_cost: continue
                derived=(saved_price/z1_cost-1)*100
                for z in prices[w]:
                    if z in dhl_costs[w]:
                        prices[w][z]=round(dhl_costs[w][z]*(1+derived/100),2)
        else:
            prices=apply_markup(entries,markup,zones,markup_z9)
        AIR_W=[0.5,1,1.5,2,2.5,3,3.5,4,4.5,5,6,7,8,9,10,12,14,16,18,20,22,24,26,28,30,32,34,36,38,40,42,44,46,48,50,55,60,65,70]
        ROAD_W=[1,2,3,4,5,6,7,8,9,10,15,20,25,30,35,40,45,50,55,60,65,70,75,80,85,90,95,100]
        target_w = AIR_W if is_air else ROAD_W
        weights = [w for w in sorted(saved_rows.keys()) if w in prices] if saved_rows else [w for w in target_w if w in prices]
        if not weights: continue
        fc=fuel_air if is_air else fuel_road

        story.append(hdr(offer_num))
        story.append(Paragraph(f'<a name="{svc_id}"/>',s('anc',fontSize=0.1)))
        sh=Table([[
            P('✈️' if is_air else '🚛',sb('ic2',fontSize=14,textColor=WHITE,alignment=TA_CENTER)),
            P(f"{svc_id}  ·  {info.get('name',svc_id)}",sb('sh',fontSize=13,textColor=WHITE)),
            P(info.get('desc',''),s('sd2',fontSize=8,textColor=colors.HexColor('#cccccc'))),
        ]],colWidths=[38*mm,80*mm,cw-118*mm])
        sh.setStyle(TableStyle([
            ('BACKGROUND',(0,0),(-1,-1),RED),('BACKGROUND',(0,0),(0,0),DRED),
            ('TOPPADDING',(0,0),(-1,-1),9),('BOTTOMPADDING',(0,0),(-1,-1),9),
            ('LEFTPADDING',(0,0),(-1,-1),12),('VALIGN',(0,0),(-1,-1),'MIDDLE'),
            ('LINEAFTER',(0,0),(0,-1),1,colors.HexColor('#dd3333')),
        ]))
        story.append(sh)
        story.append(Table([[P(
            f"Βρείτε ζώνη → βάρος → διασταύρωση = βασική τιμή. Επίναυλος {fc}% ({fuel_week}) ξεχωριστά.",
            s('hw',fontSize=7.5,textColor=MGRAY,leading=11))]],colWidths=[cw],style=[
            ('BACKGROUND',(0,0),(-1,-1),LGRAY),('TOPPADDING',(0,0),(-1,-1),5),
            ('BOTTOMPADDING',(0,0),(-1,-1),5),('LEFTPADDING',(0,0),(-1,-1),10),
            ('BOX',(0,0),(-1,-1),0.5,BORDER)]))
        story.append(Spacer(1,3*mm))

        zone_splits = [(1,zones)]

        for split_idx, (z_from, z_to) in enumerate(zone_splits):
            if split_idx > 0:
                story.append(hdr(offer_num))
                story.append(sh)
                story.append(Table([[P(
                    f"Βρείτε ζώνη → βάρος → διασταύρωση = βασική τιμή. Επίναυλος {fc}% ({fuel_week}) ξεχωριστά.",
                    s('hw',fontSize=7.5,textColor=MGRAY,leading=11))]],colWidths=[cw],style=[
                    ('BACKGROUND',(0,0),(-1,-1),LGRAY),('TOPPADDING',(0,0),(-1,-1),5),
                    ('BOTTOMPADDING',(0,0),(-1,-1),5),('LEFTPADDING',(0,0),(-1,-1),10),
                    ('BOX',(0,0),(-1,-1),0.5,BORDER)]))
                story.append(Spacer(1,2*mm))
            if svc_id in ('S1003','S1012'):
                z_range = list(range(z_from, z_to+1))
            elif svc_id in ('S1003_GR','S1012_GR'):
                z_range = [9]
            else:
                z_range = list(range(z_from, z_to+1)) + ([9] if is_air else [])
            split_cw = (cw-22*mm)/len(z_range)
            split_col_w = [22*mm]+[split_cw]*len(z_range)

            hdr_row=[P('ΒΑΡΟΣ\n(kg)',sb('zh',fontSize=7,textColor=WHITE,alignment=TA_CENTER))]
            for z in z_range:
                hdr_row.append(P(f"Z{z}\n{zone_descs[z]}",sb('zh2',fontSize=7,textColor=WHITE,alignment=TA_CENTER)))

            td=[hdr_row]
            for w in weights:
                row=[P(str(w),sb('tw',fontSize=7,textColor=MGRAY,alignment=TA_LEFT))]
                for z in z_range:
                    row.append(P(f"€{prices[w].get(z,0):.2f}",s('tp',fontSize=7,textColor=DGRAY,alignment=TA_CENTER)))
                td.append(row)

            if split_idx==len(zone_splits)-1:
                last_w=weights[-1]
                extra_label='+1 kg' if is_air else '+5 kg'
                extra_mult=1/last_w if is_air else 5/last_w
                td.append([P(extra_label,sb('ex',fontSize=7,textColor=RED,alignment=TA_LEFT))]+
                          [P(f"€{round(prices[last_w].get(z,0)*extra_mult,2):.2f}",
                             s('ep',fontSize=7,textColor=RED,alignment=TA_CENTER)) for z in z_range])

            pt=Table(td,colWidths=split_col_w,repeatRows=1)
            ts=TableStyle([
                ('BACKGROUND',(0,0),(-1,0),RED),
                ('FONTSIZE',(0,0),(-1,-1),6),
                ('ALIGN',(0,0),(-1,-1),'CENTER'),('ALIGN',(0,0),(0,-1),'LEFT'),
                ('VALIGN',(0,0),(-1,-1),'MIDDLE'),
                ('TOPPADDING',(0,0),(-1,-1),1),('BOTTOMPADDING',(0,0),(-1,-1),1),
                ('LEFTPADDING',(0,0),(0,-1),6),
                ('GRID',(0,0),(-1,-1),0.25,BORDER),
                ('BACKGROUND',(0,-1),(-1,-1),colors.HexColor('#fff0f0')),
                ('FONTNAME',(0,-1),(-1,-1),FB),
            ])
            for i in range(1,len(weights)+1):
                bg=WHITE if i%2==0 else XLGRAY
                ts.add('BACKGROUND',(0,i),(0,i),bg)
                for z_idx,z in enumerate(z_range,1):
                    ts.add('BACKGROUND',(z_idx,i),(z_idx,i),ZONE_BG[z] if i%2==0 else colors.HexColor('#f0f0f0'))
            pt.setStyle(ts)
            story.append(pt)
            story.append(Spacer(1,3*mm))
            zone_label = f"Z{z_range[0]}" if len(z_range)==1 else f"Z{z_from}-Z{z_to}"
            story.append(P(f"* Τιμές εξαιρούν ΦΠΑ και επίναυλο {fc}%. Ισχύουν έως {valid_until}. Ζώνες {zone_label}.",
                s('note',fontSize=7,textColor=BGRAY)))
            story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER,spaceBefore=3))
            story.append(ftr(offer_num,date,vstamp))
            story.append(PageBreak())

        # ── ΟΓΚΟΜΕΤΡΗΣΗ ΔΕΜΑΤΩΝ ──
    story.append(PageBreak())
    story.append(hdr(offer_num))
    story.append(sec('ΟΓΚΟΜΕΤΡΗΣΗ ΔΕΜΑΤΩΝ'))
    story.append(Spacer(1,5*mm))
    story.append(P(
        'Η χρέωση κάθε αποστολής εξαρτάται από το συνδυασμό βάρους και μεγέθους '
        '(Σύστημα ογκομέτρησης Διεθνούς Μεταφοράς).',
        s('tt',fontSize=9,textColor=MGRAY,leading=14,spaceAfter=4)))
    story.append(P(
        'Αν το ογκομετρικό βάρος της αποστολής είναι μεγαλύτερο του πραγματικού '
        '(περιπτώσεις ελαφρών δεμάτων με μεγάλο όγκο), τότε η χρέωση γίνεται με βάση το ογκομετρικό βάρος.',
        s('tt',fontSize=9,textColor=MGRAY,leading=14,spaceAfter=8)))
    story.append(P(
        'Τα Διεθνή Ογκομετρικά Βάρη υπολογίζονται με τη χρήση του ακόλουθου τύπου:',
        s('tt',fontSize=9,textColor=MGRAY,leading=14,spaceAfter=6)))
    story.append(Table([[
        P('Ογκομετρικό Βάρος σε kgr = Μήκος × Πλάτος × Ύψος / 5000',
          sb('volform',fontSize=11,textColor=DGRAY)),
    ]], colWidths=[cw], style=[
        ('BACKGROUND',(0,0),(-1,-1),XLGRAY),
        ('TOPPADDING',(0,0),(-1,-1),10),('BOTTOMPADDING',(0,0),(-1,-1),10),
        ('LEFTPADDING',(0,0),(-1,-1),15),
        ('BOX',(0,0),(-1,-1),0.5,BORDER),
    ]))
    story.append(Spacer(1,6*mm))
    story.append(P('Παράδειγμα:',sb('tt2',fontSize=9,textColor=DGRAY,spaceAfter=4)))
    story.append(Table([
        [P('ΔΙΑΣΤΑΣΕΙΣ',sb('vh',fontSize=7,textColor=WHITE)),
         P('ΤΥΠΟΣ',sb('vh',fontSize=7,textColor=WHITE)),
         P('ΟΓΚΟΜΕΤΡΙΚΟ ΒΑΡΟΣ',sb('vh',fontSize=7,textColor=WHITE))],
        [P('50 × 50 × 50 cm',s('vv',fontSize=9,textColor=DGRAY)),
         P('50 × 50 × 50 / 5000',s('vv',fontSize=9,textColor=MGRAY)),
         P('25 kg',sb('vr',fontSize=11,textColor=RED))],
        [P('60 × 40 × 30 cm',s('vv2',fontSize=9,textColor=DGRAY)),
         P('60 × 40 × 30 / 5000',s('vv2',fontSize=9,textColor=MGRAY)),
         P('14.4 kg',sb('vr2',fontSize=11,textColor=RED))],
        [P('100 × 30 × 20 cm',s('vv3',fontSize=9,textColor=DGRAY)),
         P('100 × 30 × 20 / 5000',s('vv3',fontSize=9,textColor=MGRAY)),
         P('12 kg',sb('vr3',fontSize=11,textColor=RED))],
    ], colWidths=[60*mm,80*mm,cw-140*mm], style=[
        ('BACKGROUND',(0,0),(-1,0),MGRAY),
        ('GRID',(0,0),(-1,-1),0.25,BORDER),
        ('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6),
        ('LEFTPADDING',(0,0),(-1,-1),8),
        ('ROWBACKGROUNDS',(0,1),(-1,-1),[WHITE,XLGRAY]),
        ('ALIGN',(2,0),(-1,-1),'CENTER'),
    ]))
    story.append(Spacer(1,6*mm))
    story.append(Table([[
        P('⚠️  Χρησιμοποιείτε πάντα το ΜΕΓΑΛΥΤΕΡΟ από: πραγματικό βάρος ή ογκομετρικό βάρος.',
          sb('volnote',fontSize=8,textColor=DGRAY)),
    ]], colWidths=[cw], style=[
        ('BACKGROUND',(0,0),(-1,-1),colors.HexColor('#fff3cd')),
        ('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8),
        ('LEFTPADDING',(0,0),(-1,-1),12),
        ('BOX',(0,0),(-1,-1),0.5,colors.HexColor('#ffc107')),
    ]))
    story.append(Spacer(1,5*mm))
    story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER))
    story.append(ftr(offer_num,date,vstamp))


    story.append(hdr(offer_num))
    story.append(Paragraph('<a name="terms"/>',s('anc',fontSize=0.1)))
    story.append(sec('ΟΡΟΙ, ΧΡΕΩΣΕΙΣ & ΕΠΙΝΑΥΛΟΣ ΚΑΥΣΙΜΩΝ'))
    story.append(Spacer(1,4*mm))
    story.append(P('Επίναυλος Καυσίμων',sb('etl',fontSize=9,textColor=DGRAY,spaceAfter=3)))
    story.append(Table([
        [P('SERVICE',sb('fl',fontSize=7,textColor=WHITE)),P('ΕΒΔΟΜΑΔΑ',sb('fl',fontSize=7,textColor=WHITE)),
         P('ΠΟΣΟΣΤΟ',sb('fl',fontSize=7,textColor=WHITE)),P('ΣΗΜΕΙΩΣΗ',sb('fl',fontSize=7,textColor=WHITE))],
        [P('AIR — S1003, S1012',sb('fv',fontSize=8,textColor=DGRAY)),P(fuel_week,s('fw',fontSize=8,textColor=DGRAY)),
         P(f"{fuel_air}%",sb('fp',fontSize=12,textColor=RED)),P('Εβδομαδιαία ανανέωση βάσει DHL',s('fn',fontSize=7,textColor=MGRAY))],
        [P('ROAD — S1010, S1041',sb('fv2',fontSize=8,textColor=DGRAY)),P(fuel_week,s('fw2',fontSize=8,textColor=DGRAY)),
         P(f"{fuel_road}%",sb('fp2',fontSize=12,textColor=MGRAY)),P('Εβδομαδιαία ανανέωση βάσει DHL',s('fn2',fontSize=7,textColor=MGRAY))],
    ],colWidths=[55*mm,40*mm,28*mm,cw-123*mm],style=[
        ('BACKGROUND',(0,0),(-1,0),MGRAY),('GRID',(0,0),(-1,-1),0.25,BORDER),
        ('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6),
        ('LEFTPADDING',(0,0),(-1,-1),8),('VALIGN',(0,0),(-1,-1),'MIDDLE'),
        ('ROWBACKGROUNDS',(0,1),(-1,-1),[WHITE,XLGRAY]),
    ]))
    story.append(Spacer(1,4*mm))
    story.append(P('Extra Χρεώσεις',sb('ectl',fontSize=9,textColor=DGRAY,spaceAfter=3)))
    story.append(Table(
        [[P('ΧΡΕΩΣΗ',sb('chl',fontSize=7,textColor=WHITE)),P('ΑΞΙΑ',sb('chl',fontSize=7,textColor=WHITE)),P('ΣΗΜΕΙΩΣΗ',sb('chl',fontSize=7,textColor=WHITE))]]+
        [[P(nm,sb('cn2',fontSize=8,textColor=DGRAY)),P(vl,sb('cv',fontSize=8,textColor=RED)),P(nt,s('cn3',fontSize=7,textColor=MGRAY))] for nm,vl,nt in [
            ('Επίναυλος Κύπρου','€1.20 / kg','Πραγματικό βάρος · COMBI services'),
            ('Ασφάλεια','Κατόπιν αιτήματος','Insurance on declared value'),
            ('Παραλαβή','Κατόπιν συνεννόησης','Pick-up service'),
            ('COD','Διαθέσιμο','Cash on delivery'),
            ('SMS','Συμπεριλαμβάνεται','Tracking notifications'),
        ]],colWidths=[60*mm,50*mm,cw-110*mm],style=[
            ('BACKGROUND',(0,0),(-1,0),MGRAY),('GRID',(0,0),(-1,-1),0.25,BORDER),
            ('TOPPADDING',(0,0),(-1,-1),5),('BOTTOMPADDING',(0,0),(-1,-1),5),
            ('LEFTPADDING',(0,0),(-1,-1),8),('ROWBACKGROUNDS',(0,1),(-1,-1),[WHITE,XLGRAY]),
        ]))
    story.append(Spacer(1,4*mm))
    story.append(P('Όροι & Προϋποθέσεις',sb('otl',fontSize=9,textColor=DGRAY,spaceAfter=3)))
    story.append(P("Οι τιμές εξαιρούν ΦΠΑ, επίναυλο καυσίμων και λοιπές χρεώσεις. "
        "Ο επίναυλος ανανεώνεται εβδομαδιαίως  "
        "Η προσφορά ισχύει 30 ημέρες από έκδοση. "
        "Ισχύουν για αποστολές με προέλευση Ελλάδα.",
        s('tt',fontSize=8,textColor=MGRAY,leading=13,spaceAfter=8)))
    story.append(Table([[
        P('____________________________',s('sl',fontSize=9,textColor=BGRAY,alignment=TA_CENTER)),
        P('',s('sp',fontSize=9)),
        P('____________________________',s('sl2',fontSize=9,textColor=BGRAY,alignment=TA_CENTER)),
    ],[
        P('Υπογραφή Πελάτη',s('sll',fontSize=7,textColor=MGRAY,alignment=TA_CENTER)),
        P('',s('sp2',fontSize=7)),
        P('4A Express',sb('slr',fontSize=7,textColor=RED,alignment=TA_CENTER)),
    ]],colWidths=[70*mm,cw-140*mm,70*mm],
    style=[('TOPPADDING',(0,0),(-1,-1),3),('BOTTOMPADDING',(0,0),(-1,-1),3)]))
    story.append(Spacer(1,5*mm))
    story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER))
    story.append(ftr(offer_num,date,vstamp))

    # ── ΑΠΟΔΟΧΗ ──
    svc_ids = ', '.join(svc['service_id'] for svc in services)
    contract_valid = f"31/12/{datetime.now().year}"
    story.append(PageBreak())
    story.append(hdr(offer_num))
    story.append(Paragraph('<a name="accept"/>',s('anc',fontSize=0.1)))
    story.append(Table([[
        P('ΑΠΟΔΟΧΗ ΠΡΟΣΦΟΡΑΣ', sb('at', fontSize=12, textColor=WHITE)),
        P('ΣΥΜΒΑΣΗ ΠΑΡΟΧΗΣ ΥΠΗΡΕΣΙΩΝ ΤΑΧΥΜΕΤΑΦΟΡΑΣ', s('at2', fontSize=9, textColor=colors.HexColor('#ffcccc'), alignment=TA_RIGHT)),
    ]], colWidths=[cw//2, cw//2], style=[
        ('BACKGROUND',(0,0),(-1,-1),DRED),
        ('TOPPADDING',(0,0),(-1,-1),9),('BOTTOMPADDING',(0,0),(-1,-1),9),
        ('LEFTPADDING',(0,0),(-1,-1),12),('VALIGN',(0,0),(-1,-1),'MIDDLE'),
    ]))
    story.append(Spacer(1,3*mm))

    # Contract parties info bar
    story.append(Table([[
        P('ΕΤΑΙΡΕΙΑ / ΠΕΛΑΤΗΣ:',sb('cpl',fontSize=7,textColor=BGRAY)),
        P(offer_data.get('name',''),sb('cpv',fontSize=9,textColor=DGRAY)),
        P('Α.Φ.Μ.:',sb('cpl2',fontSize=7,textColor=BGRAY)),
        P(offer_data.get('afm',''),sb('cpv2',fontSize=9,textColor=DGRAY)),
        P('ΥΠΗΡΕΣΙΕΣ:',sb('cpl3',fontSize=7,textColor=BGRAY)),
        P(svc_ids,sb('cpv3',fontSize=8,textColor=RED)),
    ]],colWidths=[32*mm,50*mm,16*mm,24*mm,24*mm,cw-146*mm],style=[
        ('BACKGROUND',(0,0),(-1,-1),LGRAY),('TOPPADDING',(0,0),(-1,-1),5),
        ('BOTTOMPADDING',(0,0),(-1,-1),5),('LEFTPADDING',(0,0),(-1,-1),6),
        ('BOX',(0,0),(-1,-1),0.5,BORDER),('LINEAFTER',(0,0),(4,-1),0.5,BORDER),
        ('VALIGN',(0,0),(-1,-1),'MIDDLE'),
    ]))
    story.append(Table([[
        P('ΗΜΕΡΟΜΗΝΙΑ:',sb('cdl',fontSize=7,textColor=BGRAY)),
        P(date,sb('cdv',fontSize=9,textColor=RED)),
        P('ΙΣΧΥΣ ΕΩΣ:',sb('cdl2',fontSize=7,textColor=BGRAY)),
        P(contract_valid,sb('cdv2',fontSize=9,textColor=RED)),
        P('ΠΛΗΡΩΜΗ:',sb('cdl3',fontSize=7,textColor=BGRAY)),
        P(f"{offer_data.get('payment','30')} ημέρες από την έκδοση του τιμολογίου",sb('cdv3',fontSize=9,textColor=DGRAY)),
    ]],colWidths=[26*mm,28*mm,22*mm,28*mm,22*mm,cw-126*mm],style=[
        ('BACKGROUND',(0,0),(-1,-1),XLGRAY),('TOPPADDING',(0,0),(-1,-1),5),
        ('BOTTOMPADDING',(0,0),(-1,-1),5),('LEFTPADDING',(0,0),(-1,-1),6),
        ('BOX',(0,0),(-1,-1),0.5,BORDER),('LINEAFTER',(0,0),(4,-1),0.5,BORDER),
        ('VALIGN',(0,0),(-1,-1),'MIDDLE'),
    ]))
    story.append(Spacer(1,3*mm))

    # Articles
    articles = [
        ('Άρθρο 1 — Αντικείμενο Σύμβασης',
         f"Η παρούσα Σύμβαση Παροχής Υπηρεσιών Ταχυμεταφοράς συνάπτεται μεταξύ της εταιρείας "
         f"«Απ. Ορφανίδης — 4A EXPRESS» (εφεξής «4A EXPRESS»), "
         f"Βελεστίνου 7, Τ.Κ. 11523 Αθήνα, ΑΦΜ 044978638, και της εταιρείας "
         f"«{offer_data.get('name','')}», ΑΦΜ {offer_data.get('afm','')} (εφεξής «Πελάτης»). "
         f"Αντικείμενο αποτελεί η παροχή υπηρεσιών ταχυμεταφοράς ({svc_ids}) "
         f"βάσει της προσφοράς {offer_num} ημερομηνίας {date}."),
        ('Άρθρο 2 — Υπηρεσίες & Τιμολόγιο',
         f"Η 4A EXPRESS παρέχει στον Πελάτη πρόσβαση στο διεθνές δίκτυο ταχυμεταφοράς για τις υπηρεσίες {svc_ids}. "
         "Οι τιμές βασίζονται στον επισυναπτόμενο τιμοκατάλογο και επιβαρύνονται με τον εκάστοτε ισχύοντα "
         "επίναυλο καυσίμων, ο οποίος ανανεώνεται εβδομαδιαίως. Η 4A EXPRESS διατηρεί το δικαίωμα αναθεώρησης "
         "τιμών κατόπιν 30ήμερης έγγραφης ειδοποίησης. Οι ειδικές τιμές ισχύουν αποκλειστικά για τον Πελάτη "
         "και δεν επιτρέπεται η εκχώρηση ή μεταβίβασή τους."),
        ('Άρθρο 3 — Τρόπος & Όροι Πληρωμής',
         f"Ο Πελάτης υποχρεούται να εξοφλεί πλήρως τα εκδιδόμενα τιμολόγια εντός "
         f"{offer_data.get('payment','30')} ημερών από την ημερομηνία έκδοσής τους. "
         "Η 4A EXPRESS διατηρεί το δικαίωμα αναστολής παροχής υπηρεσιών σε περίπτωση ληξιπρόθεσμων οφειλών."),
        ('Άρθρο 4 — Υποχρεώσεις Πελάτη',
         "Ο Πελάτης υποχρεούται: (α) να παραδίδει αποστολές σε κατάλληλη συσκευασία, "
         "(β) να δηλώνει ακριβώς το περιεχόμενο και την αξία κάθε αποστολής, "
         "(γ) να τηρεί τους εκάστοτε ισχύοντες κανονισμούς τελωνείου και αερομεταφορών, "
         "(δ) να μην αποστέλλει απαγορευμένα ή επικίνδυνα είδη χωρίς προηγούμενη γραπτή συγκατάθεση. "
         "Ο Πελάτης φέρει αποκλειστική ευθύνη για τυχόν παραβάσεις."),
        ('Άρθρο 5 — Ευθύνη & Αποζημίωση',
         "Η 4A EXPRESS ευθύνεται για απώλεια ή καταστροφή αποστολής έως €50 για έγγραφα και έως €120 για δέματα, "
         "εκτός εάν έχει δηλωθεί υψηλότερη αξία και καταβληθεί αντίστοιχη ασφάλιση. "
         "Αξιώσεις αποζημίωσης πρέπει να υποβάλλονται εγγράφως εντός 15 ημερών από την ημερομηνία έκδοσης "
         "της φορτωτικής. Η 4A EXPRESS δεν ευθύνεται για έμμεσες ζημίες, διαφυγόντα κέρδη ή καθυστερήσεις "
         "οφειλόμενες σε ανωτέρα βία, τελωνειακές διαδικασίες ή απεργίες."),
        ('Άρθρο 6 — Ασφάλεια Αποστολών & Απαγορευμένα Είδη',
         "Πρόσθετη ασφάλιση αποστολής παρέχεται κατόπιν αιτήματος και συνοδεύεται από πρωτότυπο τιμολόγιο "
         "και δήλωση ασφαλιζόμενης αξίας. Απαγορεύεται ρητώς η αποστολή: επικίνδυνων υλικών (IATA/ADR), "
         "νομισμάτων, πολύτιμων λίθων, ζώντων οργανισμών, ναρκωτικών ουσιών και κάθε είδους που απαγορεύεται "
         "από την ισχύουσα νομοθεσία. Πλήρης κατάλογος: www.skyath.wordpress.com/2008/10/14/dangerus-goods/"),
        ('Άρθρο 7 — Διάρκεια, Ανανέωση & Καταγγελία',
         f"Η παρούσα Σύμβαση αρχίζει να ισχύει από {date} και παραμένει σε ισχύ για ένα (1) έτος, "
         "ανανεούμενη αυτόματα για ισόχρονα διαστήματα εκτός εάν κάποιο μέρος γνωστοποιήσει εγγράφως την "
         "πρόθεση μη ανανέωσης τουλάχιστον τριάντα (30) ημέρες πριν από τη λήξη. Άμεση καταγγελία επιτρέπεται "
         "σε περίπτωση ουσιώδους παραβίασης όρων από οποιοδήποτε μέρος. Η λύση της Σύμβασης δεν επηρεάζει "
         "εκκρεμείς υποχρεώσεις πληρωμής."),
    ]
    for title, body in articles:
        story.append(Table([[
            P(title, sb('artt',fontSize=8,textColor=WHITE)),
        ]], colWidths=[cw], style=[
            ('BACKGROUND',(0,0),(-1,-1),MGRAY),
            ('TOPPADDING',(0,0),(-1,-1),4),('BOTTOMPADDING',(0,0),(-1,-1),4),
            ('LEFTPADDING',(0,0),(-1,-1),8),
        ]))
        story.append(Table([[
            P(body, s('artb',fontSize=7.5,textColor=MGRAY,leading=12)),
        ]], colWidths=[cw], style=[
            ('TOPPADDING',(0,0),(-1,-1),5),('BOTTOMPADDING',(0,0),(-1,-1),5),
            ('LEFTPADDING',(0,0),(-1,-1),8),('RIGHTPADDING',(0,0),(-1,-1),8),
            ('BOX',(0,0),(-1,-1),0.25,BORDER),
        ]))
        story.append(Spacer(1,1.5*mm))

    story.append(Spacer(1,4*mm))

    # Two signature blocks
    sig_w = (cw - 10*mm) / 2
    story.append(Table([[
        Table([
            [P('4A EXPRESS — Απ. Ορφανίδης', sb('s4a',fontSize=8,textColor=DGRAY,alignment=TA_CENTER))],
            [P('', s('sg',fontSize=28,textColor=LGRAY,alignment=TA_CENTER))],
            [P('____________________________', s('sl3',fontSize=9,textColor=BGRAY,alignment=TA_CENTER))],
            [P('Υπογραφή & Σφραγίδα', s('sla',fontSize=7,textColor=MGRAY,alignment=TA_CENTER))],
            [P('Ημερομηνία: _______________', s('sld',fontSize=7,textColor=BGRAY,alignment=TA_CENTER))],
        ], colWidths=[sig_w], style=[
            ('TOPPADDING',(0,0),(-1,-1),3),('BOTTOMPADDING',(0,0),(-1,-1),3),
            ('BOX',(0,0),(-1,-1),0.5,BORDER),('BACKGROUND',(0,0),(-1,0),LGRAY),
        ]),
        Table([
            [P(offer_data.get('name','ΠΕΛΑΤΗΣ'), sb('scl',fontSize=8,textColor=DGRAY,alignment=TA_CENTER))],
            [P('', s('sg2',fontSize=28,textColor=LGRAY,alignment=TA_CENTER))],
            [P('____________________________', s('sl4',fontSize=9,textColor=BGRAY,alignment=TA_CENTER))],
            [P('Υπογραφή & Σφραγίδα Πελάτη', s('slb',fontSize=7,textColor=MGRAY,alignment=TA_CENTER))],
            [P('Ημερομηνία: _______________', s('sle',fontSize=7,textColor=BGRAY,alignment=TA_CENTER))],
        ], colWidths=[sig_w], style=[
            ('TOPPADDING',(0,0),(-1,-1),3),('BOTTOMPADDING',(0,0),(-1,-1),3),
            ('BOX',(0,0),(-1,-1),0.5,BORDER),('BACKGROUND',(0,0),(-1,0),LGRAY),
        ]),
    ]], colWidths=[sig_w, sig_w], style=[
        ('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),0),
        ('TOPPADDING',(0,0),(-1,-1),0),('BOTTOMPADDING',(0,0),(-1,-1),0),
        ('LINEAFTER',(0,0),(0,-1),5*mm,WHITE),
    ]))
    story.append(Spacer(1,4*mm))
    story.append(Table([[P(
        'Βελεστίνου 7, 115 23 Αθήνα  ·  Τηλ: +30 210 9966661  ·  sales@4aexpress.com  ·  www.4aexpress.com',
        s('ft2',fontSize=7,textColor=BGRAY,alignment=TA_CENTER))]],colWidths=[cw],style=[
        ('TOPPADDING',(0,0),(-1,-1),4),('LINEABOVE',(0,0),(-1,0),0.5,BORDER)]))
    story.append(HRFlowable(width=cw,thickness=0.5,color=BORDER))
    story.append(ftr(offer_num,date,vstamp))

    doc.build(story)

    with open(tmpfile.name,'rb') as f:
        pdf_b64=base64.b64encode(f.read()).decode()
    os.unlink(tmpfile.name)
    return pdf_b64

if __name__=='__main__':
    data=json.loads(sys.stdin.read())
    result=generate(data['offer'],data['dhl'],data['fuel'])
    print(json.dumps({'ok':True,'pdf':result}))
