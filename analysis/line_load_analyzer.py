#!/usr/bin/env python3
import argparse
import hashlib
import json
import math
import re
import sys
import unicodedata
from pathlib import Path

import pandas as pd


ROOT = Path(__file__).resolve().parents[1]
LINE_DIR = ROOT / "uploads" / "excel" / "lineas"
LINE_CSV = LINE_DIR / "Potencia Aparente LLTT_2025.csv"
LINE_XLS = LINE_DIR / "Potencia Aparente LLTT_2025.xls"
CACHE_FILE = ROOT / "cache" / "line_load_index.json"
VERSION = 4
NON_OPERATIONAL_TERMS = [
    "estado abierto",
    "opera en estado abierto",
    "abierto",
    "fuera de servicio",
    "f s",
    "fs",
    "no opera",
    "sin servicio",
    "desenergizado",
    "desenergizada",
]


def norm(value):
    text = "" if pd.isna(value) else str(value)
    text = unicodedata.normalize("NFKD", text.strip().lower())
    text = "".join(c for c in text if not unicodedata.combining(c))
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def code_key(value):
    return re.sub(r"[^A-Z0-9]", "", str(value or "").upper())


def num(value):
    if value is None or value == "":
        return math.nan
    text = re.sub(r"[^\d,.\-]", "", str(value)).replace(",", ".")
    try:
        return float(text)
    except ValueError:
        return math.nan


def file_sig(path):
    st = path.stat()
    return {"size": st.st_size, "mtime": int(st.st_mtime)}


def source_file():
    if LINE_CSV.exists():
        return LINE_CSV
    if LINE_XLS.exists():
        return LINE_XLS
    return LINE_CSV


def read_cache():
    src = source_file()
    if not CACHE_FILE.exists() or not src.exists():
        return None
    try:
        data = json.loads(CACHE_FILE.read_text(encoding="utf-8"))
    except Exception:
        return None
    if data.get("version") != VERSION or data.get("source") != file_sig(src):
        return None
    return data


def write_cache(data):
    CACHE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = CACHE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    tmp.replace(CACHE_FILE)


def load_line_table():
    src = source_file()
    if src == LINE_CSV and src.exists():
        return pd.read_csv(src, encoding="utf-8-sig"), src
    if src.exists():
        return pd.read_excel(src, engine="xlrd", dtype=str), src
    raise FileNotFoundError(f"No se encontro archivo de lineas en: {LINE_DIR}")


def date_value(row):
    raw = f"{row.get('Fecha máxima demanda', '')} {row.get('Hora máxima demanda', '')}".strip()
    dt = pd.to_datetime(raw, dayfirst=True, errors="coerce")
    if pd.isna(dt):
        period = str(row.get("Periodo", "")).strip()
        dt = pd.to_datetime("01/" + period, dayfirst=True, errors="coerce") if period else pd.NaT
    return dt


def clean_record(row):
    dt = date_value(row)
    periodo = "" if pd.isna(row.get("Periodo")) else str(row.get("Periodo")).strip()
    return {
        "codigo_empresa": "" if pd.isna(row.get("Código empresa")) else str(row.get("Código empresa")).strip(),
        "nombre_linea": "" if pd.isna(row.get("Nombre línea")) else str(row.get("Nombre línea")).strip(),
        "codigo_linea": "" if pd.isna(row.get("Código línea")) else str(row.get("Código línea")).strip(),
        "tension_nominal_kv": None if math.isnan(num(row.get("Tensión nominal"))) else num(row.get("Tensión nominal")),
        "mva_nominal": None if math.isnan(num(row.get("MVA nominal"))) else num(row.get("MVA nominal")),
        "carga_mva": None if math.isnan(num(row.get("Carga MVA"))) else num(row.get("Carga MVA")),
        "carga_amperaje": None if math.isnan(num(row.get("Carga amperaje"))) else num(row.get("Carga amperaje")),
        "fac_uso_pot": None if math.isnan(num(row.get("FAC USO POT"))) else num(row.get("FAC USO POT")),
        "fecha_maxima_demanda": None if pd.isna(dt) else dt.strftime("%Y-%m-%d"),
        "hora_maxima_demanda": "" if pd.isna(row.get("Hora máxima demanda")) else str(row.get("Hora máxima demanda")).strip(),
        "periodo": periodo,
        "observacion": "" if pd.isna(row.get("Observación")) else str(row.get("Observación")).strip(),
        "sort_key": "" if pd.isna(dt) else dt.strftime("%Y%m%d%H%M%S"),
    }


def classify_record(record):
    obs = norm(record.get("observacion", ""))
    carga = record.get("carga_mva")
    for term in NON_OPERATIONAL_TERMS:
        if term in obs:
            return "no_operativa"
    if carga is not None and carga <= 0.05 and "ampcar" not in obs:
        return "dudosa"
    return "valida"


def public_record(record):
    out = record.copy()
    out.pop("_sort", None)
    return out


def build_index():
    df, src = load_line_table()
    needed = ["Código empresa", "Nombre línea", "Código línea", "Carga MVA", "Fecha máxima demanda", "Hora máxima demanda", "Periodo"]
    missing = [c for c in needed if c not in df.columns]
    if missing:
        raise ValueError("Faltan columnas en el CSV de lineas: " + ", ".join(missing))

    items = []
    by_code = {}
    for _, row in df.iterrows():
        rec = clean_record(row)
        key = code_key(rec["codigo_linea"])
        if not key:
            continue
        rec["_sort"] = rec.pop("sort_key", "")
        rec["estado_operativo"] = classify_record(rec)
        rec["registro_valido"] = rec["estado_operativo"] == "valida"
        items.append(rec)
        by_code.setdefault(key, []).append(len(items) - 1)

    return {
        "version": VERSION,
        "source": file_sig(src),
        "hash": hashlib.sha1(src.read_bytes()).hexdigest()[:12],
        "archivo": src.name,
        "items": items,
        "by_code": by_code,
    }


def get_index():
    cached = read_cache()
    if cached:
        return cached
    data = build_index()
    write_cache(data)
    return data


def match_records(index, codigo="", nombre=""):
    keys = []
    if codigo:
        keys.append(code_key(codigo))
    for token in re.findall(r"L[\-\s]?\d+[A-Z]?", str(nombre or "").upper()):
        keys.append(code_key(token))
    for key in keys:
        if key and key in index["by_code"]:
            return [index["items"][i] for i in index["by_code"][key]]

    name_norm = norm(nombre)
    if name_norm:
        tokens = [t for t in name_norm.split() if len(t) > 2]
        matches = []
        for item in index["items"]:
            item_text = norm(item.get("nombre_linea", ""))
            if name_norm in item_text or (tokens and all(t in item_text for t in tokens[:3])):
                matches.append(item)
        if matches:
            return matches
    return []


def analyze(codigo="", nombre="", empresa=""):
    index = get_index()
    matches = match_records(index, codigo, nombre)
    if not matches:
        return {
            "status": "no_data",
            "codigo_consulta": codigo,
            "nombre_consulta": nombre,
            "mensaje": "No se encontro carga MVA para esta linea en el archivo Potencia Aparente LLTT_2025.",
            "archivo": index.get("archivo"),
        }

    matches = sorted(matches, key=lambda x: x.get("_sort", ""))
    latest_sort = matches[-1].get("_sort", "")
    latest_candidates = [row for row in matches if row.get("_sort", "") == latest_sort]
    latest = max(latest_candidates, key=lambda x: x.get("carga_mva") or -1)
    valid_matches = [row for row in matches if row.get("registro_valido") and row.get("carga_mva") is not None]
    max_candidates = [row for row in matches if row.get("carga_mva") is not None]
    max_record = None
    if max_candidates:
        max_record = max(max_candidates, key=lambda x: (x.get("carga_mva") or -1, x.get("_sort", "")))
    operative = None
    if valid_matches:
        operative_sort = valid_matches[-1].get("_sort", "")
        operative_candidates = [row for row in valid_matches if row.get("_sort", "") == operative_sort]
        operative = max(operative_candidates, key=lambda x: x.get("carga_mva") or -1)

    ref_sort = operative.get("_sort", "") if operative else ""
    excluded_recent = [
        public_record(row)
        for row in matches
        if not row.get("registro_valido") and (not ref_sort or row.get("_sort", "") >= ref_sort)
    ][-6:]
    series = []
    for row in matches:
        if row.get("periodo") and row.get("carga_mva") is not None:
            series.append({
                "periodo": row["periodo"],
                "fecha": row.get("fecha_maxima_demanda"),
                "mva": row.get("carga_mva"),
                "valido": bool(row.get("registro_valido")),
                "estado_operativo": row.get("estado_operativo"),
                "observacion": row.get("observacion"),
            })
    alerta = None
    if latest and not latest.get("registro_valido"):
        alerta = "El registro mas reciente no se usa para carga principal porque figura abierto, fuera de servicio o dudoso."
    latest_is_max = bool(
        latest
        and max_record
        and latest.get("_sort", "") == max_record.get("_sort", "")
        and latest.get("carga_mva") == max_record.get("carga_mva")
    )
    return {
        "status": "success",
        "codigo_consulta": codigo,
        "nombre_consulta": nombre,
        "empresa_consulta": empresa,
        "archivo": index.get("archivo"),
        "ultima": public_record(latest),
        "operativa": public_record(operative) if operative else None,
        "maxima_registrada": public_record(max_record) if max_record else None,
        "ultima_es_maxima": latest_is_max,
        "mensaje_maxima": "La ultima lectura tambien es la maxima registrada para ese mes." if latest_is_max else None,
        "excluidos_recientes": excluded_recent,
        "alerta": alerta,
        "serie": series,
        "registros": len(matches),
        "criterio": "Carga principal tomada del ultimo registro operativo valido; registros abiertos, fuera de servicio o dudosos quedan como alerta.",
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--codigo", default="")
    parser.add_argument("--nombre", default="")
    parser.add_argument("--empresa", default="")
    args = parser.parse_args()
    try:
        print(json.dumps(analyze(args.codigo, args.nombre, args.empresa), ensure_ascii=True))
    except Exception as exc:
        print(json.dumps({"status": "error", "message": str(exc)}, ensure_ascii=True))
        sys.exit(1)


if __name__ == "__main__":
    main()
