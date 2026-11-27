import csv,re,json,sys,unicodedata
def N(v): return re.sub(r'\s+',' ',unicodedata.normalize('NFC',v)).strip()
# Usage : python build_data.py "Catalogue des incidents.csv" "Catalogue des demandes de service.csv"
INC_CSV, SVC_CSV = sys.argv[1], sys.argv[2]
# incidents
inc=[];seen=set()
for x in list(csv.reader(open(INC_CSV,encoding='utf-8-sig'),delimiter=';'))[1:]:
    if not x: continue
    n,p=N(x[0]),N(x[1])
    parts=p.split('/')
    if len(parts)<3 or p in seen: continue
    seen.add(p)
    if n in ('Sujet par défaut','Connaissance','Front Office'): continue
    # skip pure container nodes w/o workflow
    if not x[2] and not x[3] and n in ('Accès','Equipement central','Equipement de proximité','Logiciel','Serveur distant'): continue
    fam={'Logiciel':'log','Equipement de proximité':'eqp','Equipement central':'eqc','Accès':'acc','Cybersécurité':'cyb'}.get(parts[2],'autre')
    inc.append({'n':n,'p':'/'.join(parts[2:-1]),'f':fam})
# services
rows={}
for x in list(csv.reader(open(SVC_CSV,encoding='utf-8-sig'),delimiter=';'))[1:]:
    if x: rows.setdefault(N(x[0]),x)
pat=[(r'^Demander à acc[eéè]der (?:à |au |aux )(.*)$',1),(r"^Demander la révocation d'un accès (?:à |au |aux )(.*)$",2),(r"^Demander la modification d'un accès (?:à |au |aux )(.*)$",4),(r'^Demander un r[eé]l[eé]v[eé] des accès (?:à |au |aux )(.*)$',8)]
acc={};svc=[]
skip=re.compile(r'(laboratoire|\(Test\)|^Laboratoire$|^Clonage|- 2024$|Mandarine)',re.I)
for s,x in rows.items():
    if skip.search(s): continue
    m=None
    for p,b in pat:
        m=re.match(p,s)
        if m:
            r=m.group(1).strip(); r=re.sub(r"^(l'|la |le |les )","",r)
            acc[r]=acc.get(r,0)|b; break
    if m: continue
    svc.append({'t':s,'d':x[3],'g':x[2],'q':x[6]})
def rtype(r):
    if '@' in r: return 'bal'
    if r.startswith('Q:'): return 'q'
    if r.lower().startswith('espace projet'): return 'proj'
    if re.search(r'univers BO|tableau(x)? de bord|Business Object',r,re.I): return 'bo'
    return 'app'
accl=[{'r':r,'t':rtype(r),'a':a} for r,a in sorted(acc.items(),key=lambda k:k[0].lower())]
def fam(t):
    T=t.lower()
    rules=[('resa',r'^(réserver|réservation|planifier une réunion|annuler une réservation|modifier une réservation)|salle de formation'),
     ('log',r"installation|^installer|référencement d'un nouveau logiciel|réforme d'un logiciel|bouquet"),
     ('mat',r'^(demander|emprunter) (un|une|des) .*(ordinateur|portable|écran|souris|clavier|casque|micro|smartphone|téléphone|imprimante|scanner|câble|carte|clef|sacoche|sac |valise|chargeur|kit|lecteur|galet|pieuvre|unité centrale|tablette|copieur)|réhausseur|ultra-portable|point d.impression|portable en estran'),
     ('four',r'^commander|courrier'),
     ('fin',r'sinistre|coriolis|mandat|véhicule|aiden'),
     ('rh',r'retraite|carrière|médaille|proche en cas|arrivée|départ|chef\.fe|qvt|ma photo|gestionnaire d.absence'),
     ('secu',r'certificat|firewall|bastion|téléintervenir|déblocage|informatique et libertés|zed|compte informatique|boite (aux lettres )?fonctionnelle|espace geco|espace projet|nouveau q:|service applicatif|restauration'),
     ('reception',r'réception|logistique'),
    ]
    for f,r in rules:
        if re.search(r,T): return f
    return 'proj'
for s in svc: s['f']=fam(s['t'])
json.dump({'inc':inc,'svc':svc,'acc':accl},open('data.json','w'),ensure_ascii=False,separators=(',',':'))
import collections
print(len(inc),len(svc),len(accl),collections.Counter(a['t'] for a in accl),collections.Counter(s['f'] for s in svc))

# Injecte les données dans le gabarit pour produire index.html
tpl=open('template.html',encoding='utf-8').read().replace('__DATA__',open('data.json',encoding='utf-8').read())
open('../index.html','w',encoding='utf-8').write('<!doctype html>\n<html lang="fr">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n</head>\n<body>\n'+tpl+'\n</body>\n</html>\n')
print('index.html régénéré')
