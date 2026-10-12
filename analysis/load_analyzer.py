#!/usr/bin/env python3
"""
load_analyzer.py — Análisis de Disponibilidad de Carga
=======================================================
SIG Energético-Minero · OSINERGMIN · Perú

USO:
    python3 load_analyzer.py "Nombre Subestación" "ruta/archivo.xlsx"
    python3 load_analyzer.py "S.E. Antamina" "uploads/excel/datos.xlsx"
    python3 load_analyzer.py "S.E. Cerro Verde" "uploads/excel/"   # carpeta con múltiples libros

SALIDA: JSON estructurado con KPIs y perfil mensual de carga.

TOLERANCIA DE COLUMNAS:
    El script reconoce columnas con múltiples nombres alternativos
    (potencia/mva/kva/capacity, fp/factor_potencia/pf/cos_fi, etc.)
    y convierte automáticamente kVA → MVA cuando es necesario.
"""

import sys, os, re, json, math
from datetime import datetime

# ── Dependencias opcionales ──────────────────────────
PANDAS_OK = False
try:
    import pandas as pd
    PANDAS_OK = True
except ImportError:
    pass

# ══════════════════════════════════════════════════════
# CONSTANTES
# ══════════════════════════════════════════════════════
MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
         'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']

FACTORES = [0.85,0.82,0.78,0.72,0.68,0.75,0.82,0.88,0.80,0.75,0.78,0.83]

CATALOGO = {
    'antamina':    {'potencia':120.0,'fp':0.95},
    'cerro verde': {'potencia':250.0,'fp':0.96},
    'antapaccay':  {'potencia':100.0,'fp':0.95},
    'tintaya':     {'potencia':100.0,'fp':0.95},
    'las bambas':  {'potencia':130.0,'fp':0.97},
    'toquepala':   {'potencia': 80.0,'fp':0.90},
    'cuajone':     {'potencia': 75.0,'fp':0.93},
    'yanacocha':   {'potencia': 40.0,'fp':0.88},
    'uchuchacua':  {'potencia': 35.0,'fp':0.87},
    'paragsha':    {'potencia': 90.0,'fp':0.92},
    'marcona':     {'potencia':180.0,'fp':0.94},
    'constancia':  {'potencia': 55.0,'fp':0.91},
    'cobriza':     {'potencia': 60.0,'fp':0.89},
}

# Alias de columnas aceptados
COL_SUBS = ['subestacion','subestación','nombre','name','se','set','substation',
            'codigo','cod_se','id_se','denominacion','descripcion','unidad']
COL_POT  = ['potencia','mva','kva','capacity','capacidad','pot_nom','potencia_mva',
            'potencia_kva','nominal_mva','transformer_capacity','power_mva','power_kva',
            'pot_instalada','capacidad_instalada']
COL_FP   = ['fp','factor_potencia','power_factor','pf','cos_fi','cos phi',
            'factor de potencia','factorp','fpot','cos_phi']
COL_TEN  = ['tension','kv','voltaje','voltage','nivel_tension','tension_kv']


# ══════════════════════════════════════════════════════
# UTILIDADES
# ══════════════════════════════════════════════════════
def err(msg):
    print(json.dumps({'status':'error','message':msg},ensure_ascii=False))
    sys.exit(1)

def norm(t):
    t = str(t).lower().strip()
    for a,b in [('á','a'),('é','e'),('í','i'),('ó','o'),('ú','u'),('ñ','n')]:
        t = t.replace(a,b)
    return re.sub(r'\s+',' ',t)

def match_col(cols, aliases):
    nc = {norm(c):c for c in cols}
    for alias in aliases:
        na = norm(alias)
        if na in nc: return nc[na]
        for nc_key,orig in nc.items():
            if na in nc_key or nc_key in na: return orig
    return None

def to_float(v):
    if v is None: return None
    if isinstance(v,(int,float)) and not math.isnan(float(v)): return float(v)
    s = re.sub(r'[^\d\.,\-]','',str(v).replace(',','.'))
    try: return float(s)
    except: return None

def kva_to_mva(val, col_name):
    if any(k in norm(col_name) for k in ['kva','kw']): return val/1000.0
    return val

def catalog_lookup(name):
    n = norm(name)
    for key,vals in CATALOGO.items():
        if key in n or n in key: return vals.copy()
    return {'potencia':50.0,'fp':0.92}


# ══════════════════════════════════════════════════════
# LECTURA INTELIGENTE DE EXCEL
# ══════════════════════════════════════════════════════
def read_excel(path, sub_name):
    """Lee un Excel buscando parámetros de la subestación.
    Tolera múltiples hojas, nombres de columna variados y unidades kVA/MVA."""
    if not PANDAS_OK: return None
    ext = os.path.splitext(path)[1].lower()
    try:
        if ext == '.csv':
            sheets = {'Hoja1': pd.read_csv(path, encoding='latin-1',
                                           on_bad_lines='skip', nrows=5000)}
        elif ext == '.xls':
            xf = pd.ExcelFile(path, engine='xlrd')
            sheets = {s: pd.read_excel(xf, sheet_name=s, nrows=5000) for s in xf.sheet_names}
        else:
            xf = pd.ExcelFile(path, engine='openpyxl')
            sheets = {s: pd.read_excel(xf, sheet_name=s, nrows=5000) for s in xf.sheet_names}
    except Exception:
        return None

    n_sub = norm(sub_name)
    for sheet, df in sheets.items():
        if df.empty or len(df.columns) < 2: continue
        df.columns = [str(c).strip() for c in df.columns]
        c_sub = match_col(df.columns, COL_SUBS)
        c_pot = match_col(df.columns, COL_POT)
        c_fp  = match_col(df.columns, COL_FP)
        if not c_pot: continue

        for _, row in df.iterrows():
            found = False
            if c_sub:
                found = n_sub in norm(str(row.get(c_sub,''))) or norm(str(row.get(c_sub,''))) in n_sub
            else:
                for col in df.columns:
                    v = norm(str(row.get(col,'')))
                    if len(v) > 3 and (n_sub in v or v in n_sub):
                        found = True; break
            if not found: continue

            pot = to_float(row.get(c_pot))
            if pot is None or pot <= 0: continue
            pot = kva_to_mva(pot, c_pot)

            fp = None
            if c_fp:
                fp = to_float(row.get(c_fp))
                if fp and fp > 1.0: fp /= 100.0
                if fp and not (0.5 <= fp <= 1.0): fp = None

            return {'potencia':round(pot,3),'fp':round(fp,3) if fp else 0.92,
                    'hoja':sheet,'fuente':'excel_real'}
    return None

def read_folder(folder, sub_name):
    """Itera todos los Excel de una carpeta buscando la subestación."""
    if not os.path.isdir(folder): return None
    for fname in sorted(os.listdir(folder)):
        if os.path.splitext(fname)[1].lower() not in {'.xlsx','.xls','.csv'}: continue
        r = read_excel(os.path.join(folder,fname), sub_name)
        if r: r['archivo'] = fname; return r
    return None


# ══════════════════════════════════════════════════════
# CÁLCULO DE PERFIL MENSUAL
# Idéntico a la lógica JavaScript del frontend
# ══════════════════════════════════════════════════════
def seeded_rand(seed):
    s = seed & 0xFFFFFFFF
    def _r():
        nonlocal s
        s = (s*1664525+1013904223) & 0xFFFFFFFF
        return s/0xFFFFFFFF
    return _r

def str_seed(text):
    h = 5381
    for c in text: h = ((h<<5)+h^ord(c)) & 0xFFFFFFFF
    return h

def calcular(nombre, potencia, fp):
    rand = seeded_rand(str_seed(nombre))
    filas = []
    for i,f in enumerate(FACTORES):
        v  = 0.90 + rand()*0.20
        kW = f*v*potencia*fp*1000
        filas.append({
            'mes':         MESES[i],
            'mes_num':     i+1,
            'energia_kwh': round(kW*1000/4),
            'potencia_kw': round(kW,2),
            'potencia_mva':round(kW/(fp*1000),4),
        })
    max_mva  = max(x['potencia_mva'] for x in filas)
    disp_mva = potencia - max_mva
    peak     = next(i for i,x in enumerate(filas) if x['potencia_mva']==max_mva)
    return {'filas':filas,'max_mva':round(max_mva,4),'disp_mva':round(disp_mva,4),
            'peak':peak,'mes_pico':MESES[peak],'ut':round((max_mva/potencia)*100,2)}


# ══════════════════════════════════════════════════════
# MAIN
# ══════════════════════════════════════════════════════
def main():
    if len(sys.argv) < 2:
        err('Uso: python3 load_analyzer.py "Nombre SE" [ruta.xlsx | carpeta/]')

    nombre = sys.argv[1].strip()
    excel  = sys.argv[2].strip() if len(sys.argv)>2 else None

    if not nombre: err('El nombre de la subestación no puede estar vacío.')
    if not re.match(r'^[\w\s/\-\.°ñÑáéíóúÁÉÍÓÚ]+$', nombre):
        err('Nombre contiene caracteres no permitidos.')

    # Buscar parámetros técnicos
    params = None
    fuente = 'catalogo_demo'
    archivo = None

    if excel:
        if os.path.isfile(excel):
            params = read_excel(excel, nombre)
            archivo = os.path.basename(excel)
        elif os.path.isdir(excel):
            params = read_folder(excel, nombre)
            if params: archivo = params.get('archivo')

    if params:
        fuente  = f"excel_real (hoja: {params.get('hoja','?')})"
    else:
        params = catalog_lookup(nombre)

    potencia = float(params['potencia'])
    fp       = float(params['fp'])

    if potencia <= 0: err('Potencia debe ser mayor que cero.')
    if not (0.5 <= fp <= 1.0): err(f'Factor de potencia inválido: {fp}')

    p = calcular(nombre, potencia, fp)
    ok = p['disp_mva'] > 0

    print(json.dumps({
        'status':               'success',
        'fuente':               'python_analyzer',
        'fuente_datos':         fuente,
        'archivo_excel':        archivo,
        'pandas_disponible':    PANDAS_OK,
        'substation':           nombre,
        'potencia_nominal_mva': potencia,
        'factor_potencia':      fp,
        'peak_demand_mva':      p['max_mva'],
        'available_load_mva':   p['disp_mva'],
        'margin_percent':       round((p['disp_mva']/potencia)*100,2),
        'utilizacion_pct':      p['ut'],
        'mes_pico':             p['mes_pico'],
        'estado':               'DISPONIBLE' if ok else 'SIN DISPONIBILIDAD',
        'monthly_profile':      p['filas'],
        'analysis_timestamp':   datetime.now().strftime('%Y-%m-%dT%H:%M:%S'),
    }, ensure_ascii=False, indent=2))

if __name__ == '__main__':
    main()
