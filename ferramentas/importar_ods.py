#!/usr/bin/env python3
"""Importa calendario.ods para o SQLite do sistema."""
import zipfile, re, sqlite3, sys, unicodedata
import xml.etree.ElementTree as ET
from datetime import date

ODS = '/home/robson/Downloads/calendario.ods'
DB  = '/var/www/html/calendario-academico/data/calendario.sqlite'
ANO = 2026

NS = {'office':'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
      'style':'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
      'fo':'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
      'table':'urn:oasis:names:tc:opendocument:xmlns:table:1.0'}
S='{%s}'%NS['style']; FO='{%s}'%NS['fo']; T='{%s}'%NS['table']

CURSOS = {
    '_CALENDÁRIO_SP_2026':   ('SUPERIOR EM ENGENHARIA AGRONÔMICA', 'superior'),
    'CALENDÁRIO_MI_2026':    ('INTEGRADO EM AGRICULTURA', 'integrado'),
    'CALENDÁRIO_CONC_2026':  ('TÉCNICO CONCOMITANTE E SUBSEQUENTE EM AGRICULTURA', 'concomitante'),
}

COR_CAT = {
    '#ff0000': 'Feriado Nacional',   # refinado por texto em origem_do_feriado()
    '#e452cf': 'Férias', '#ff00ff': 'Férias',
    '#7767d7': 'Exame Final',
    '#ffff00': 'Período de culminância de Projetos Pedagógicos',
    '#d0cece': 'Dias Escolares Não Letivos',
    '#00b050': 'Ponto Facultativo', '#6aa84f': 'Ponto Facultativo',
    '#1155cc': 'Planejamento Pedagógico',
    '#9bc2e6': 'Início ou Fim de semestre letivo',
}
DOW = {'domingo':0,'segunda':1,'terça':2,'terca':2,'quarta':3,'quinta':4,'sexta':5,'sábado':6,'sabado':6}


def carregar_estilos(z):
    sty = {}
    for part in ('styles.xml', 'content.xml'):
        r = ET.fromstring(z.read(part))
        for st in r.iter(S+'style'):
            n = st.get(S+'name')
            if not n:
                continue
            d = sty.setdefault(n, {'bg': '', 'b': 0})
            for ch in st:
                if ch.tag.endswith('table-cell-properties'):
                    bg = ch.get(FO+'background-color')
                    if bg:
                        d['bg'] = bg.lower()
                elif ch.tag.endswith('text-properties'):
                    if ch.get(FO+'font-weight') == 'bold':
                        d['b'] = 1
    return sty


def linhas(node):
    for ch in node:
        if ch.tag == T+'table-row':
            yield ch
        elif ch.tag in (T+'table-row-group', T+'table-header-rows', T+'table-rows'):
            yield from linhas(ch)


def ler_planilha(z, sty, nome):
    """Devolve lista de linhas; cada linha é lista de (texto, cor)."""
    root = ET.fromstring(z.read('content.xml'))
    body = root.find('office:body/office:spreadsheet', NS)
    tbl = next(t for t in body.findall('table:table', NS) if t.get(T+'name') == nome)
    out = []
    for row in linhas(tbl):
        rep = min(int(row.get(T+'number-rows-repeated', '1')), 5)
        cells = []
        for c in list(row):
            if c.tag not in (T+'table-cell', T+'covered-table-cell'):
                continue
            crep = min(int(c.get(T+'number-columns-repeated', '1')), 40)
            st = sty.get(c.get(T+'style-name'), {'bg': '', 'b': 0})
            txt = ''.join(c.itertext()).replace('\xa0', ' ').replace('\u200b', '')
            txt = re.sub(r'\s+', ' ', txt).strip()
            for k in range(crep):
                cells.append(((txt if k == 0 else ''), st['bg'], st['b']))
        for _ in range(rep):
            out.append(cells)
    return out


COLS = [1, 10, 19]   # início de cada bloco de mês (B, K, T)


MESES = ['JANEIRO','FEVEREIRO','MARÇO','ABRIL','MAIO','JUNHO',
         'JULHO','AGOSTO','SETEMBRO','OUTUBRO','NOVEMBRO','DEZEMBRO']


def extrair(rows):
    """Localiza os blocos pela linha com os nomes dos meses.
    -> (cores {(mes,dia): cor}, eventos [(mes, texto, negrito)])"""
    cores, eventos = {}, []

    # linhas que contêm nome de mês na primeira coluna de bloco
    blocos = []
    for i, r in enumerate(rows):
        if len(r) > COLS[0] and r[COLS[0]][0].upper() in MESES:
            blocos.append(i)
    if not blocos:
        return cores, eventos

    for bi, m in enumerate(blocos):
        fim = blocos[bi+1] if bi+1 < len(blocos) else len(rows)
        for ci, col in enumerate(COLS):
            if col >= len(rows[m]):
                continue
            nome = rows[m][col][0].upper()
            if nome not in MESES:
                continue
            mes = MESES.index(nome) + 1
            for r in range(m+2, min(m+8, len(rows))):        # 6 linhas de dias
                for k in range(7):
                    if col+k >= len(rows[r]):
                        continue
                    txt, bg, _ = rows[r][col+k]
                    if txt.isdigit():
                        cores[(mes, int(txt))] = bg
            for r in range(m+10, fim):                        # lista de eventos
                if r >= len(rows) or col >= len(rows[r]):
                    continue
                txt, _, neg = rows[r][col]
                if txt and not txt.startswith('MINISTÉRIO'):
                    eventos.append((mes, txt, neg))
    return cores, eventos


TOKEN = r'\d{1,2}(?:\s*/\s*\d{1,2})?'
RE_EV = re.compile(
    rf'^\s*(?P<lab>{TOKEN}(?:\s*(?:a|e|,)\s*{TOKEN})*)\s*(?:[-–—]\s*)?(?P<desc>[A-Za-zÀ-ÿ(“"].*)$')


def parse_evento(mes, texto):
    texto = re.sub(r'/0(\d\d)\b', r'/\1', texto)          # 17/011 -> 17/11
    m = RE_EV.match(texto)
    if not m:                                             # "1-4 - Desc" / "2 - 4 - Desc"
        alt = re.sub(r'^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*-\s*', r'\1 a \2 - ', texto)
        m = RE_EV.match(alt)
    if not m:
        return None
    lab, desc = m.group('lab'), m.group('desc').strip()
    faixas = []
    for seg in re.split(r'\s+e\s+|,\s*', lab):
        pontos = [p.strip() for p in re.split(r'\s+a\s+', seg) if p.strip()]
        datas = []
        for p in pontos:
            if '/' in p:
                d, mm = [int(x) for x in p.split('/')]
            else:
                d, mm = int(p), mes
            try:
                datas.append(date(ANO, mm, d))
            except ValueError:
                return None
        if not datas:
            return None
        faixas.append((datas[0], datas[-1]))
    if not faixas:
        return None
    return lab.strip(), desc, faixas


def sem_acento(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s.lower())
                   if unicodedata.category(c) != 'Mn')


FDS_COR = '#ccc1da'   # cor de fim de semana: não conta para a inferência


def origem_do_feriado(d):
    """A planilha pinta todo feriado de vermelho; quem diz a origem é o texto
    — "(Feriado Estadual)", "(Feriado municipal)". Sem pista, vale o nacional."""
    if 'municipal' in d or 'municipio' in d:  return 'Feriado Municipal'
    if 'estadual' in d:                       return 'Feriado Estadual'
    return 'Feriado Nacional'


def categoria_de(desc, faixas, cores):
    """A cor só vale se a faixa INTEIRA tiver a mesma cor no original —
    senão períodos longos (ex.: 17/11 a 16/12) herdariam a cor de um dia solto."""
    d = sem_acento(desc)
    if 'sabado letivo' in d or 'com horario de' in d:
        return 'Representação de Dia Letivo'

    achadas = set()
    for a, b in faixas:
        dia = a
        while dia <= b:
            c = cores.get((dia.month, dia.day), '')
            if c != FDS_COR:
                achadas.add(c)
            dia = date.fromordinal(dia.toordinal() + 1)
    if len(achadas) == 1:
        cor = achadas.pop()
        if cor in COR_CAT:
            cat = COR_CAT[cor]
            return origem_do_feriado(d) if cat.startswith('Feriado') else cat

    # Só sinais fortes por texto. Categorias como Planejamento e Culminância
    # dependem de a planilha ter pintado os dias — quando ela não pinta, o dia
    # segue letivo e o evento fica apenas como nota na lista.
    if 'feriado' in d:                      return origem_do_feriado(d)
    if 'ponto facultativo' in d:            return 'Ponto Facultativo'
    if 'ferias' in d:                       return 'Férias'
    if 'nao letivo' in d or 'recesso' in d: return 'Dias Escolares Não Letivos'
    return None


def repoe_de(desc):
    m = re.search(r'hor[áa]rio\s+de\s+([A-Za-zÀ-ÿ]+)', desc, re.I)
    if not m:
        return None
    return DOW.get(sem_acento(m.group(1)))


# ------------------------------------------------------------------ execução

z = zipfile.ZipFile(ODS)
sty = carregar_estilos(z)

planilhas = {}
for aba in CURSOS:
    rows = ler_planilha(z, sty, aba)
    cores, brutos = extrair(rows)
    evs = {}
    ignorados = []
    for mes, txt, neg in brutos:
        p = parse_evento(mes, txt)
        if not p:
            ignorados.append(txt)
            continue
        lab, desc, faixas = p
        evs[(lab, desc)] = {
            'lab': lab, 'desc': desc, 'faixas': faixas,
            'cat': categoria_de(desc, faixas, cores),
            'repoe': repoe_de(desc),
            'negrito': int(neg),
        }
    planilhas[aba] = evs
    print(f'{aba}: {len(evs)} eventos lidos, {len(ignorados)} ignorados')
    for i in ignorados:
        print('   ! ', i[:90])

# eventos presentes nas três abas viram base comum
chaves = [set(v) for v in planilhas.values()]
comuns = set.intersection(*chaves)
print(f'\nbase comum: {len(comuns)} eventos; específicos: '
      + ', '.join(f'{a}={len(v)-len(comuns)}' for a, v in planilhas.items()))

db = sqlite3.connect(DB)
db.execute('PRAGMA foreign_keys=ON')
cats = {n: i for i, n in db.execute('SELECT id, nome FROM categorias')}


def inserir(ev, calendario_id):
    cur = db.execute(
        'INSERT INTO eventos (ano, calendario_id, categoria_id, descricao, negrito, rotulo, repoe_dow)'
        ' VALUES (?,?,?,?,?,?,?)',
        (ANO, calendario_id, cats.get(ev['cat']), ev['desc'], ev['negrito'], None, ev['repoe']))
    eid = cur.lastrowid
    for a, b in ev['faixas']:
        db.execute('INSERT INTO evento_datas (evento_id, inicio, fim) VALUES (?,?,?)',
                   (eid, a.isoformat(), b.isoformat()))


def limites_semestres(evs, comuns, ref):
    """Deriva o início/fim de cada semestre dos próprios eventos da aba."""
    marcos = {}
    fontes = list(evs.values()) + [ref[k] for k in comuns if k not in evs]
    for ev in fontes:
        d = sem_acento(ev['desc'])
        if 'semestre letivo' not in d:
            continue
        sem = 1 if '1º semestre' in ev['desc'].lower() or '1o semestre' in d else 2
        if 'inicio' in d:
            marcos[(sem, 'i')] = ev['faixas'][0][0]
        elif 'fim' in d:
            marcos[(sem, 'f')] = ev['faixas'][-1][1]
    padrao = {(1, 'i'): date(ANO, 2, 2),  (1, 'f'): date(ANO, 7, 1),
              (2, 'i'): date(ANO, 7, 30), (2, 'f'): date(ANO, 12, 16)}
    out = []
    for n in (1, 2):
        ini = marcos.get((n, 'i'), padrao[(n, 'i')])
        fim = marcos.get((n, 'f'), padrao[(n, 'f')])
        out.append((n, ini.isoformat(), fim.isoformat()))
    return out


ref = planilhas['_CALENDÁRIO_SP_2026']
for k in sorted(comuns, key=lambda k: ref[k]['faixas'][0][0]):
    inserir(ref[k], None)

for aba, (nome, nivel) in CURSOS.items():
    cur = db.execute('INSERT INTO cursos (nome, nivel) VALUES (?,?)', (nome, nivel))
    curso_id = cur.lastrowid
    cur = db.execute(
        'INSERT INTO calendarios (curso_id, ano, situacao, local_texto) VALUES (?,?,?,?)',
        (curso_id, ANO, 'Aguardando homologação', 'Lagoa da Confusão, outubro de 2025'))
    cal_id = cur.lastrowid
    for n, ini, fim in limites_semestres(planilhas[aba], comuns, ref):
        db.execute("INSERT INTO periodos (calendario_id, tipo, numero, inicio, fim)"
                   " VALUES (?, 'semestre', ?, ?, ?)", (cal_id, n, ini, fim))
    proprios = [k for k in planilhas[aba] if k not in comuns]
    for k in sorted(proprios, key=lambda k: planilhas[aba][k]['faixas'][0][0]):
        inserir(planilhas[aba][k], cal_id)
    print(f'{nome}: calendário {cal_id}, {len(proprios)} eventos próprios')

db.commit()
print('\nOK')
