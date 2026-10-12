#!/usr/bin/env python3
import argparse
import json
import math
import hashlib
import re
import sys
import unicodedata
from functools import lru_cache
from pathlib import Path

import pandas as pd

from load_availability import analyze_substation_records, enrich_primary_peak_reference


ROOT = Path(__file__).resolve().parents[1]
EXCEL_DIR = ROOT / "uploads" / "excel"
MASTER = EXCEL_DIR / "DATOS DE SUB ESTACIONES MAESTRO.xlsx"
QUINCE_DIR = EXCEL_DIR / "QUINCEMINUTALES EXCEL"
CAPACITY_JSON = ROOT / "data" / "osinergmin_capacity.json"
TRANSFORMER_CAPACITY = EXCEL_DIR / "EquiposTransformadores.xlsx"
PDF_DIR = ROOT / "uploads" / "pdf"
CACHE_INDEX_DIR = ROOT / "cache" / "analysis_index"
QUINCE_INDEX_VERSION = 10
QUINCE_EXTENSIONS = {".xlsx", ".xlsm", ".xls"}
NO_MEASUREMENT_NOTE = (
    "No se pudo calcular la demanda real porque los registros quinceminutales "
    "no tienen mediciones utiles. Se muestra capacidad nominal, PIT, PT y datos "
    "maestros disponibles para mantener el analisis tecnico."
)
NO_QUINCE_NOTE = (
    "No se encontraron registros quinceminutales utiles para esta subestacion. "
    "Se muestra capacidad nominal, PIT, PT y datos maestros disponibles."
)

MESES = {
    1: "Enero", 2: "Febrero", 3: "Marzo", 4: "Abril",
    5: "Mayo", 6: "Junio", 7: "Julio", 8: "Agosto",
    9: "Setiembre", 10: "Octubre", 11: "Noviembre", 12: "Diciembre",
}

ALIAS_EMPRESAS = {
    "adinelsa": ["adinelsa"],
    "aymaraes": ["aymaraes"],
    "cemento andino": ["cemento andino"],
    "chavimochic": ["chavimochic"],
    "coelvisac": ["coelvisac", "coelvisa"],
    "concesionaria lt ccncm": ["concesionaria lt ccncm", "ccncm"],
    "conelsur lt": ["conelsur", "conelsur lt"],
    "egasa": ["egasa"],
    "egemsa": ["egemsa"],
    "electronoroeste": ["enosa", "electronoroeste", "electro noroeste"],
    "electronorte": ["ensa", "electronorte", "electro norte"],
    "electro dunas": ["electro dunas", "electrodunas"],
    "electro oriente": ["electro oriente"],
    "electro puno": ["electro puno", "electropuno"],
    "electro sur este": ["electro sur este"],
    "electro ucayali": ["electro ucayali"],
    "electrocentro": ["electrocentro"],
    "electrodunas": ["electrodunas"],
    "electroperu": ["electroperu", "electroperú"],
    "electrosur": ["electrosur"],
    "enel distribucion peru": ["enel", "enel distribucion peru", "enel distribución perú"],
    "engie": ["engie", "engine"],
    "hidrandina": ["hidrandina"],
    "isa peru": ["isa peru", "isa perú"],
    "luz del sur": ["luz del sur"],
    "olmos": ["olmos", "peot"],
    "puerto maldonado transmisora": ["puerto maldonado transmisora", "pmt"],
    "red de energia del peru": ["rep", "red de energia del peru"],
    "red electrica del sur": [
        "redesur",
        "red electrica del sur",
        "red electrica del sur s a",
        "red electrica del sur s a a",
        "red eléctrica del sur",
    ],
    "redesur": ["redesur", "red electrica del sur", "red electrica del sur s a"],
    "transmantaro": ["transmantaro"],
    "consorcio transmataro": ["transmantaro", "consorcio transmataro"],
    "consorcio transmantaros": ["transmantaro"],
    "consorcio transmantar": ["transmantaro"],
    "seal": ["seal"],
    "statkraft": ["statkraft"],
    "tesur 3": ["tesur 3", "tesur"],
    "transmisora norperuana": ["transmisora norperuana", "norperuana"],
}


def repair_mojibake(text):
    if not any(mark in text for mark in ("Ã", "Â", "â")):
        return text
    for encoding in ("latin1", "cp1252"):
        try:
            candidate = text.encode(encoding).decode("utf-8")
        except (UnicodeEncodeError, UnicodeDecodeError):
            continue
        if sum(candidate.count(mark) for mark in ("Ã", "Â", "â")) < sum(text.count(mark) for mark in ("Ã", "Â", "â")):
            return candidate
    return text


def norm(value):
    text = "" if pd.isna(value) else repair_mojibake(str(value))
    text = unicodedata.normalize("NFKD", text.strip().lower())
    text = "".join(c for c in text if not unicodedata.combining(c))
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


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


def excel_engine(path):
    return "xlrd" if Path(path).suffix.lower() == ".xls" else "openpyxl"


def safe_stem(path):
    raw = f"{path.stem}_{path.stat().st_size}_{int(path.stat().st_mtime)}"
    digest = hashlib.sha1(raw.encode("utf-8", "ignore")).hexdigest()[:12]
    slug = re.sub(r"[^a-z0-9]+", "_", norm(path.stem))[:42].strip("_")
    return f"{slug}_{digest}"


def read_cache(path, expected_source):
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    if data.get("version") != QUINCE_INDEX_VERSION:
        return None
    if data.get("source") != expected_source:
        return None
    return data


def write_cache(path, data):
    CACHE_INDEX_DIR.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    tmp.replace(path)


def clean_table(df):
    df = df.dropna(how="all").dropna(axis=1, how="all").copy()
    df.columns = [str(c).strip() for c in df.columns]
    return df


def get_col(df, options):
    columns = {norm(c): c for c in df.columns}
    for option in options:
        key = norm(option)
        if key in columns:
            return columns[key]
    for option in options:
        key = norm(option)
        for col_norm, real in columns.items():
            if key in col_norm:
                return real
    raise KeyError(f"No se encontro columna {options}")


def get_sheet(sheets, wanted):
    wanted_norm = norm(wanted)
    for name, df in sheets.items():
        if wanted_norm in norm(name):
            return clean_table(df)
    raise KeyError(f"No se encontro hoja {wanted}")


def read_master():
    if not MASTER.exists():
        raise FileNotFoundError(f"No se encontro maestro: {MASTER}")
    return pd.read_excel(MASTER, sheet_name=None, engine=excel_engine(MASTER), dtype=str)


def find_substation(df, name):
    col_code = get_col(df, ["Codigo Subestacion", "Codigo de Subestacion", "Codigo SET"])
    col_name = get_col(df, ["Nombre", "Nombre Subestacion", "Subestacion"])
    needle = norm(name)
    names = df[col_name].map(norm)
    result = df[names == needle]
    if result.empty:
        tokens = [t for t in needle.split() if len(t) > 2]
        mask = names.map(lambda x: all(t in x for t in tokens) if tokens else False)
        result = df[mask]
    if result.empty:
        result = df[names.str.contains(needle, na=False, regex=False)]
    if result.empty:
        raise ValueError(f"No se encontro la subestacion en maestro: {name}")
    row = result.iloc[0]
    return {"codigo": str(row[col_code]).strip(), "nombre": str(row[col_name]).strip()}


def find_transformers(df, sub_code, sub_name, company_hint):
    col_code = get_col(df, ["Cod Transformador", "Codigo Transformador", "Transformador"])
    col_sub = get_col(df, ["Subestacion", "Codigo Subestacion", "Nombre Subestacion"])
    col_emp = get_col(df, ["Empresa", "Empresa Propietaria", "Propietario"])
    sub_code_norm, sub_name_norm = norm(sub_code), norm(sub_name)
    subs = df[col_sub].map(norm)
    mask = (subs == sub_code_norm) | (subs == sub_name_norm) | subs.str.contains(sub_name_norm, na=False, regex=False)
    result = df.loc[mask, [col_code, col_sub, col_emp]].drop_duplicates()
    return result.rename(columns={col_code: "codigo_transformador", col_sub: "subestacion", col_emp: "empresa"})


def find_bars(df, sub_name):
    col_code = get_col(df, ["Codigo Barra", "Codigo de Barra", "Barra"])
    col_name = get_col(df, ["Nombre", "Nombre Barra"])
    col_tension = get_col(df, ["Tension", "Tension kV", "kV"])
    name_norm = norm(sub_name)
    names = df[col_name].map(norm)
    tokens = [t for t in name_norm.split() if len(t) > 2]
    mask = names.str.contains(name_norm, na=False, regex=False)
    if not mask.any() and tokens:
        mask = names.map(lambda x: all(t in x for t in tokens[:2]))
    result = df.loc[mask, [col_code, col_name, col_tension]].drop_duplicates()
    return result.rename(columns={col_code: "codigo_barra", col_name: "nombre_barra", col_tension: "tension"})


def tension_map(df_barra):
    col_code = get_col(df_barra, ["Codigo Barra", "Codigo de Barra", "Barra"])
    col_tension = get_col(df_barra, ["Tension", "Tension kV", "kV"])
    out = {}
    for _, row in df_barra[[col_code, col_tension]].dropna(how="all").iterrows():
        out[norm(row[col_code])] = str(row[col_tension]).strip()
    return out


@lru_cache(maxsize=1)
def analysis_reference_maps():
    """Small master lookups used while building the persistent quince index."""
    try:
        sheets = read_master()
        df_sub = get_sheet(sheets, "Subestacion")
        df_bar = get_sheet(sheets, "Barra")
        col_code = get_col(df_sub, ["Codigo Subestacion", "Codigo de Subestacion", "Codigo SET"])
        col_name = get_col(df_sub, ["Nombre", "Nombre Subestacion", "Subestacion"])
        substations = {
            norm(row[col_code]): str(row[col_name]).strip()
            for _, row in df_sub[[col_code, col_name]].dropna(how="all").iterrows()
            if norm(row.get(col_code))
        }
        return {"substations": substations, "tensions": tension_map(df_bar)}
    except Exception:
        return {"substations": {}, "tensions": {}}


def company_aliases(companies):
    aliases = set()
    for company in companies:
        c = norm(company)
        if c:
            aliases.add(c)
        for key, vals in ALIAS_EMPRESAS.items():
            group = {norm(key), *(norm(v) for v in vals)}
            if c and any(c == item or c in item or item in c for item in group):
                aliases.update(group)
    return {a for a in aliases if a}


def company_alias_groups(companies):
    groups = []
    seen = set()
    for company in companies:
        group = company_aliases([company])
        key = tuple(sorted(group))
        if group and key not in seen:
            groups.append(group)
            seen.add(key)
    return groups


def quince_file_groups(companies):
    if not QUINCE_DIR.exists():
        return [], []
    files = [
        p for p in QUINCE_DIR.iterdir()
        if p.is_file() and p.suffix.lower() in QUINCE_EXTENSIONS and not p.name.startswith("~$")
    ]
    alias_groups = company_alias_groups(companies)
    priority, rest = [], []
    for file in files:
        stem = norm(file.stem)
        rank = None
        for idx, aliases in enumerate(alias_groups):
            if any(a and (a in stem or stem in a) for a in aliases):
                rank = idx
                break
        if rank is not None:
            priority.append((rank, file))
        else:
            rest.append(file)
    priority = [file for _, file in sorted(priority, key=lambda item: (item[0], item[1].name.lower()))]
    return priority, sorted(rest)


def quince_files(companies, include_rest=True):
    priority, rest = quince_file_groups(companies)
    return priority + rest if include_rest else priority


def read_quince_sheet(path, sheet):
    engine = excel_engine(path)
    headers = pd.read_excel(path, sheet_name=sheet, engine=engine, nrows=0)
    mapping = {
        "codigo_subestacion": get_col(headers, ["Codigo de subestacion", "Codigo Subestacion"]),
        "codigo_transformador": get_col(headers, ["Codigo de transformador", "Codigo Transformador"]),
        "codigo_barra": get_col(headers, ["Codigo de barra", "Codigo Barra"]),
        "fecha": get_col(headers, ["Fecha AAAAMMDDHHMM", "Fecha"]),
        "energia_activa": get_col(headers, ["Registro de energia activa kWh", "Energia activa", "kWh"]),
        "energia_reactiva": get_col(headers, ["Registro de energia reactiva kVARh", "Energia reactiva", "kVARh"]),
    }
    try:
        mapping["tension_medida_kv"] = get_col(headers, ["Registro de tension kV", "Tension medida", "Tension kV"])
    except Exception:
        pass
    df = pd.read_excel(path, sheet_name=sheet, engine=engine, usecols=list(mapping.values()), dtype=str)
    return df.rename(columns={v: k for k, v in mapping.items()})


def find_quince_records(sub_code, companies):
    if not QUINCE_DIR.exists():
        return pd.DataFrame(), None, []
    code_norm = norm(sub_code)
    for file in quince_files(companies):
        try:
            book = pd.ExcelFile(file, engine=excel_engine(file))
        except Exception:
            continue
        matches, sheets = [], []
        for sheet in book.sheet_names:
            try:
                df = read_quince_sheet(file, sheet)
            except Exception:
                continue
            hit = df[df["codigo_subestacion"].map(norm) == code_norm].copy()
            if not hit.empty:
                matches.append(hit)
                sheets.append(sheet)
        if matches:
            return pd.concat(matches, ignore_index=True), file, sheets
    return pd.DataFrame(), None, []


def prepare_records_fast(records):
    df = records.copy()
    for col in ["codigo_subestacion", "codigo_transformador", "codigo_barra", "fecha"]:
        df[col] = df[col].astype(str).str.strip()
    df["fecha_texto"] = df["fecha"].astype(str).str.replace(r"\.0$", "", regex=True).str.extract(r"(\d{12})", expand=False)
    df["fecha_dt"] = pd.to_datetime(df["fecha_texto"], format="%Y%m%d%H%M", errors="coerce")
    active = pd.to_numeric(df["energia_activa"].astype(str).str.replace(",", ".", regex=False), errors="coerce")
    reactive = pd.to_numeric(df["energia_reactiva"].astype(str).str.replace(",", ".", regex=False), errors="coerce").fillna(0)
    df["p_raw_mw"] = active * 4 / 1000
    df["q_raw_mvar"] = reactive * 4 / 1000
    if "tension_medida_kv" in df:
        df["tension_medida_kv"] = pd.to_numeric(df["tension_medida_kv"].astype(str).str.replace(",", ".", regex=False), errors="coerce")
    else:
        df["tension_medida_kv"] = pd.NA
    df["demanda_mw"] = df["p_raw_mw"].abs()
    df["demanda_mvar"] = df["q_raw_mvar"].abs()
    df["demanda_mva"] = (df["p_raw_mw"].pow(2) + df["q_raw_mvar"].pow(2)).pow(0.5)
    df["factor_potencia"] = df["p_raw_mw"].abs() / df["demanda_mva"].replace(0, pd.NA)
    df = df.dropna(subset=["fecha_dt", "p_raw_mw", "demanda_mva"])
    df = df[df["fecha_dt"].dt.year.between(2000, 2100)]
    return df[(df["codigo_subestacion"] != "") & (df["codigo_transformador"] != "") & (df["codigo_barra"] != "")]


def compact_series(points, limit=180):
    if len(points) <= limit:
        return points
    step = max(1, math.ceil(len(points) / limit))
    sampled = points[::step]
    if sampled[-1]["fecha"] != points[-1]["fecha"]:
        sampled.append(points[-1])
    return sampled


def build_quince_index(path):
    book = pd.ExcelFile(path, engine=excel_engine(path))
    frames, used_sheets = [], []
    for sheet in book.sheet_names:
        try:
            frame = prepare_records_fast(read_quince_sheet(path, sheet))
        except Exception:
            continue
        if not frame.empty:
            frames.append(frame)
            used_sheets.append(sheet)
    if not frames:
        return {"sheets": [], "substations": {}}

    df = pd.concat(frames, ignore_index=True)
    references = analysis_reference_maps()
    out = {}
    for sub_code, sub_df in df.groupby("codigo_subestacion", dropna=False):
        sub_df = sub_df.dropna(subset=["demanda_mva"])
        if sub_df.empty:
            continue
        sub_name = references.get("substations", {}).get(norm(sub_code), str(sub_code))
        transformer_codes = sub_df["codigo_transformador"].dropna().astype(str).str.strip().unique().tolist()
        capacities = transformer_capacity_candidates(sub_name, [], transformer_codes)
        modern = analyze_substation_records(sub_df, capacities, references.get("tensions", {}))
        peaks = []
        for row in modern.get("rows", []):
            peaks.append({
                "transformador": row.get("transformador"),
                "barra": row.get("barra"),
                "fecha": row.get("fecha_maxima"),
                "demanda_max_mva": row.get("demanda_max_mva"),
                "demanda_max_mw": row.get("demanda_max_mw"),
                "factor_potencia": row.get("factor_potencia"),
            })
        out[norm(sub_code)] = {
            "peaks": peaks,
            "series": [],
            "monthly": modern.get("monthly", []),
            "monthly_by_voltage": modern.get("monthly_by_voltage", []),
            "ultimo_registro": modern.get("ultimo_registro"),
            "sin_medicion": modern.get("sin_medicion", True),
            "analysis_v2": modern,
        }
    return {"sheets": used_sheets, "substations": out}


def load_quince_index(path):
    source = file_sig(path)
    cache = CACHE_INDEX_DIR / f"quince_{safe_stem(path)}.json"
    cached = read_cache(cache, source)
    if cached:
        return cached
    payload = build_quince_index(path)
    data = {
        "version": QUINCE_INDEX_VERSION,
        "source": source,
        "archivo": path.name,
        "sheets": payload["sheets"],
        "substations": payload["substations"],
    }
    write_cache(cache, data)
    return data


def cached_quince_index(path):
    cache = CACHE_INDEX_DIR / f"quince_{safe_stem(path)}.json"
    return read_cache(cache, file_sig(path)) if cache.exists() else None


def find_quince_payload(sub_code, companies):
    if not QUINCE_DIR.exists():
        return None, [], []
    code_norm = norm(sub_code)
    hits = []
    priority, rest = quince_file_groups(companies)
    if priority:
        files = priority
    else:
        cached = [file for file in rest if cached_quince_index(file)]
        uncached = [file for file in rest if file not in cached]
        files = cached + uncached

    for file in files:
        try:
            index = load_quince_index(file)
        except Exception:
            continue
        hit = index.get("substations", {}).get(code_norm)
        if hit:
            hits.append({
                "archivo": file.name,
                "path": str(file),
                "sheets": index.get("sheets", []),
                "payload": hit,
            })
    if not hits and priority:
        # Compatibilidad con archivos antiguos cuyo nombre no coincide con la empresa:
        # solo se consultan indices ya construidos para no volver a leer gigabytes.
        for file in rest:
            try:
                index = cached_quince_index(file)
            except Exception:
                continue
            if not index:
                continue
            hit = index.get("substations", {}).get(code_norm)
            if hit:
                hits.append({
                    "archivo": file.name,
                    "path": str(file),
                    "sheets": index.get("sheets", []),
                    "payload": hit,
                })
    if not hits:
        return None, [], []
    has_measured_demand = any(not item["payload"].get("sin_medicion") for item in hits)

    modern_hits = [item for item in hits if isinstance(item["payload"].get("analysis_v2"), dict)]
    if modern_hits:
        modern_rows = []
        modern_summaries = []
        monthly_by_file = []
        monthly_candidates = {}
        monthly_voltage_candidates = {}
        latest = None
        for item in modern_hits:
            modern = item["payload"]["analysis_v2"]
            for source_row in modern.get("rows", []):
                row = dict(source_row)
                row["archivo_quinceminutal"] = item["archivo"]
                modern_rows.append(row)
            for source_summary in modern.get("transformers", []):
                summary = dict(source_summary)
                summary["archivo_quinceminutal"] = item["archivo"]
                modern_summaries.append(summary)
            file_monthly = [dict(month) for month in modern.get("monthly", [])]
            if file_monthly:
                monthly_by_file.append({"archivo": item["archivo"], "mensual": file_monthly})
            for month in file_monthly:
                period = month.get("periodo") or month.get("mes") or ""
                if not period:
                    continue
                candidate = dict(month)
                candidate["archivo"] = item["archivo"]
                current = monthly_candidates.get(period)
                if current is None or float(candidate.get("mva") or 0) > float(current.get("mva") or 0):
                    monthly_candidates[period] = candidate
            for voltage_group in modern.get("monthly_by_voltage", []):
                try:
                    voltage = round(float(voltage_group.get("tension_kv")), 3)
                except (TypeError, ValueError):
                    continue
                voltage_label = voltage_group.get("etiqueta") or f"{voltage:g} kV"
                for month in voltage_group.get("mensual", []):
                    period = month.get("periodo") or month.get("mes") or ""
                    if not period:
                        continue
                    candidate = dict(month)
                    candidate["archivo"] = item["archivo"]
                    key = (voltage, period)
                    current = monthly_voltage_candidates.get(key)
                    if current is None or float(candidate.get("mva") or 0) > float(current["month"].get("mva") or 0):
                        monthly_voltage_candidates[key] = {
                            "month": candidate,
                            "label": voltage_label,
                            "criterio": voltage_group.get("criterio"),
                        }
            candidate_latest = modern.get("ultimo_registro")
            if candidate_latest:
                candidate_latest = dict(candidate_latest)
                candidate_latest["archivo"] = item["archivo"]
                if latest is None or str(candidate_latest.get("fecha") or "") > str(latest.get("fecha") or ""):
                    latest = candidate_latest

        modern_rows, modern_summaries = enrich_primary_peak_reference(modern_rows, modern_summaries)

        monthly = [monthly_candidates[key] for key in sorted(monthly_candidates)]
        if len(modern_hits) > 1:
            for month in monthly:
                month["criterio"] = "Mayor entrada primaria entre archivos; no se suman maximos no simultaneos"
        voltage_groups = {}
        for (voltage, period), item in monthly_voltage_candidates.items():
            group = voltage_groups.setdefault(voltage, {
                "tension_kv": voltage,
                "etiqueta": item.get("label") or f"{voltage:g} kV",
                "mensual": [],
                "criterio": item.get("criterio") or "Medicion real por nivel de tension",
            })
            month = dict(item["month"])
            if len(modern_hits) > 1:
                month["criterio"] = "Mayor medicion del nivel entre archivos; no se suman archivos potencialmente duplicados"
            group["mensual"].append(month)
        monthly_by_voltage = []
        for voltage in sorted(voltage_groups, reverse=True):
            group = voltage_groups[voltage]
            group["mensual"].sort(key=lambda month: str(month.get("periodo") or month.get("mes") or ""))
            monthly_by_voltage.append(group)

        eligible = [
            row for row in modern_rows
            if row.get("rol_devanado") in {"secundario", "terciario"}
            and row.get("disponible_tecnico_mva") is not None
        ]
        best = max(eligible, key=lambda row: float(row.get("disponible_tecnico_mva") or 0)) if eligible else None
        reference_eligible = [
            row for row in modern_rows
            if row.get("rol_devanado") in {"secundario", "terciario"}
            and row.get("disponible_referencia_mva") is not None
        ]
        best_reference = max(reference_eligible, key=lambda row: float(row.get("disponible_referencia_mva") or 0)) if reference_eligible else None
        installed = sum(float(item.get("capacidad_primaria_mva") or 0) for item in modern_summaries)
        source_set_summaries = [item["payload"]["analysis_v2"].get("set_summary") or {} for item in modern_hits]
        set_peak_candidates = [float(s.get("pico_entrada_set_mva") or 0) for s in source_set_summaries if s.get("pico_entrada_set_mva") is not None]
        set_peak = max(set_peak_candidates) if set_peak_candidates else None
        set_summary = {
            "capacidad_primaria_instalada_mva": round(installed, 4) if installed else None,
            "pico_entrada_set_mva": round(set_peak, 4) if set_peak is not None else None,
            "disponible_tecnico_mva": best.get("disponible_tecnico_mva") if best else None,
            "disponible_planificacion_mva": best.get("disponible_planificacion_mva") if best else None,
            "transformador_recomendado": best.get("transformador") if best else None,
            "barra_recomendada": best.get("barra") if best else None,
            "tension_recomendada_kv": best.get("tension_kv") if best else None,
            "restriccion": best.get("restriccion") if best else None,
            "disponible_referencia_mva": best_reference.get("disponible_referencia_mva") if best_reference else None,
            "disponible_planificacion_referencia_mva": best_reference.get("disponible_planificacion_referencia_mva") if best_reference else None,
            "transformador_referencia": best_reference.get("transformador") if best_reference else None,
            "barra_referencia": best_reference.get("barra") if best_reference else None,
            "tension_referencia_kv": best_reference.get("tension_kv") if best_reference else None,
            "restriccion_referencia": best_reference.get("restriccion_referencia") if best_reference else None,
            "criterio": "Mejor barra individual medida; no suma margenes entre transformadores ni archivos sin topologia",
            "capacidad_firme_n_1_mva": None,
            "estado": "Disponible preliminar" if best and float(best.get("disponible_tecnico_mva") or 0) > 0 else ("Sin medicion" if not has_measured_demand else "Sin margen demostrado"),
            "capacidad_total_mva": round(installed, 4) if installed else None,
            "demanda_total_mva": round(set_peak, 4) if set_peak is not None else None,
            "disponible_total_mva": best.get("disponible_tecnico_mva") if best else None,
        }
        payload = {
            "metodologia_version": 2,
            "metodologia": modern_hits[0]["payload"]["analysis_v2"].get("metodologia", {}),
            "peaks": [],
            "rows": modern_rows,
            "transformers": modern_summaries,
            "set_summary": set_summary,
            "series": [],
            "monthly": monthly,
            "monthly_by_voltage": monthly_by_voltage,
            "monthly_by_file": monthly_by_file,
            "ultimo_registro": latest,
            "archivos": [{"archivo": x["archivo"], "hojas": x["sheets"]} for x in modern_hits],
            "sin_medicion": not has_measured_demand,
        }
        files = [Path(x["path"]) for x in modern_hits]
        sheets = []
        for item in modern_hits:
            sheets.extend([f"{item['archivo']}::{sheet}" for sheet in item["sheets"]])
        return payload, files, sheets

    peaks = []
    for item in hits:
        for peak in item["payload"].get("peaks", []):
            p = dict(peak)
            p["archivo"] = item["archivo"]
            peaks.append(p)

    monthly_by_period = {}
    monthly_by_file = []
    for item in hits:
        file_monthly = []
        for month in item["payload"].get("monthly", []):
            file_monthly.append(dict(month))
            period = month.get("periodo") or month.get("mes") or ""
            if not period:
                continue
            slot = monthly_by_period.setdefault(period, {
                "periodo": period,
                "mes": month.get("mes"),
                "fecha": month.get("fecha"),
                "mva": 0.0,
                "archivos": [],
            })
            slot["mva"] += float(month.get("mva") or 0)
            slot["archivos"].append(item["archivo"])
            if str(month.get("fecha") or "") > str(slot.get("fecha") or ""):
                slot["fecha"] = month.get("fecha")
                slot["mes"] = month.get("mes") or slot.get("mes")
        if file_monthly:
            monthly_by_file.append({
                "archivo": item["archivo"],
                "mensual": file_monthly,
            })
    monthly = []
    for period, item in sorted(monthly_by_period.items()):
        item["mva"] = round(float(item.get("mva") or 0), 4)
        item["archivos"] = sorted(set(item.get("archivos") or []))
        monthly.append(item)

    latest = None
    for item in hits:
        ult = item["payload"].get("ultimo_registro") or {}
        if not ult:
            continue
        cand = dict(ult)
        cand["archivo"] = item["archivo"]
        if latest is None or str(cand.get("fecha") or "") > str(latest.get("fecha") or ""):
            latest = cand

    payload = {
        "peaks": peaks,
        "series": [],
        "monthly": monthly,
        "monthly_by_voltage": [],
        "monthly_by_file": monthly_by_file,
        "ultimo_registro": latest,
        "archivos": [{"archivo": x["archivo"], "hojas": x["sheets"]} for x in hits],
        "sin_medicion": not has_measured_demand,
    }
    files = [Path(x["path"]) for x in hits]
    sheets = []
    for item in hits:
        sheets.extend([f"{item['archivo']}::{sheet}" for sheet in item["sheets"]])
    return payload, files, sheets


def prepare_records(records):
    df = records.copy()
    df["codigo_transformador"] = df["codigo_transformador"].astype(str).str.strip()
    df["codigo_barra"] = df["codigo_barra"].astype(str).str.strip()
    df["fecha_texto"] = df["fecha"].astype(str).str.replace(r"\.0$", "", regex=True).str.extract(r"(\d{12})", expand=False)
    df["mes_num"] = pd.to_numeric(df["fecha_texto"].str.slice(4, 6), errors="coerce")
    df["energia_activa_num"] = pd.to_numeric(df["energia_activa"].astype(str).str.replace(",", ".", regex=False), errors="coerce")
    df["energia_reactiva_num"] = pd.to_numeric(df["energia_reactiva"].astype(str).str.replace(",", ".", regex=False), errors="coerce")
    df = df.dropna(subset=["mes_num", "energia_activa_num"])
    df["mes_num"] = df["mes_num"].astype(int)
    return df[(df["mes_num"].between(1, 12)) & (df["codigo_transformador"] != "") & (df["codigo_barra"] != "")]


def demand_from_row(row):
    active = float(row["energia_activa_num"])
    reactive = float(row["energia_reactiva_num"]) if pd.notna(row["energia_reactiva_num"]) else 0.0
    mw = active * 4 / 1000
    if active == 0:
        fp = math.nan
        mva = math.nan
    else:
        fp = math.cos(math.atan(reactive / active))
        mva = mw / fp if fp else math.nan
    return round(mw, 4), round(fp, 4) if pd.notna(fp) else None, round(mva, 4) if pd.notna(mva) else None


def split_nums(value):
    vals = []
    for part in re.split(r"[/,; ]+", str(value or "")):
        x = num(part)
        if not math.isnan(x):
            vals.append(x)
    return vals


@lru_cache(maxsize=1)
def read_transformer_capacity_book():
    if not TRANSFORMER_CAPACITY.exists():
        return []
    book = pd.read_excel(TRANSFORMER_CAPACITY, sheet_name=None, engine=excel_engine(TRANSFORMER_CAPACITY), dtype=str)
    rows = []
    for sheet, raw in book.items():
        df = clean_table(raw)
        if df.empty:
            continue
        try:
            col_emp = get_col(df, ["Empresa"])
            col_sub = get_col(df, ["Sub-Estacion", "Sub Estacion", "Subestacion", "Sub-Estación"])
            col_code = get_col(df, ["Cod Transformador Empresa", "Codigo Transformador Empresa", "Cod. Transformador Empresa"])
            col_tp = get_col(df, ["Tension Primaria", "Tensión Primaria"])
            col_ts = get_col(df, ["Tension Secundaria", "Tensión Secundaria"])
            col_tt = get_col(df, ["Tension Terciaria", "Tensión Terciaria"])
            col_pp = get_col(df, ["Potencia Primaria"])
            col_ps = get_col(df, ["Potencia Secundaria"])
            col_pt = get_col(df, ["Potencia Terciaria"])
        except Exception:
            continue
        for _, row in df.iterrows():
            company = str(row.get(col_emp, "") or "").strip()
            substation = str(row.get(col_sub, "") or "").strip()
            code = str(row.get(col_code, "") or "").strip()
            if not substation and not code:
                continue
            windings = []
            for label, t_col, p_col in [
                ("Primaria", col_tp, col_pp),
                ("Secundaria", col_ts, col_ps),
                ("Terciaria", col_tt, col_pt),
            ]:
                tension_raw = row.get(t_col, "")
                power_raw = row.get(p_col, "")
                tension = num(tension_raw)
                power = num(power_raw)
                if math.isnan(tension) or math.isnan(power) or power <= 0 or tension <= 0:
                    continue
                windings.append({
                    "lado": label,
                    "tension_kv": tension,
                    "tension_texto": str(tension_raw).strip(),
                    "potencia_mva": power,
                    "potencia_texto": str(power_raw).strip(),
                })
            if not windings:
                continue
            rows.append({
                "empresa": company,
                "empresa_norm": norm(company),
                "subestacion": substation,
                "subestacion_norm": norm(substation),
                "codigo": code,
                "codigo_norm": norm(code),
                "devanados": windings,
                "hoja": sheet,
            })
    return rows


def transformer_code_matches(left, right):
    left_norm = norm(left).replace(" ", "")
    right_norm = norm(right).replace(" ", "")
    if not left_norm or not right_norm:
        return False
    if left_norm == right_norm:
        return True
    return min(len(left_norm), len(right_norm)) >= 6 and (
        left_norm.endswith(right_norm) or right_norm.endswith(left_norm)
    )


def substation_name_aliases(value):
    raw = repair_mojibake(str(value or ""))
    parts = [raw, *re.split(r"\s+-\s+|[/\\()]", raw)]
    aliases = set()
    for part in parts:
        candidate = norm(part)
        candidate = re.sub(r"^(?:s\s*e|se|set|subestacion|ct|c\s*h|ch)(?:\s+electrica)?\s+", "", candidate).strip()
        if candidate:
            aliases.add(candidate)
    return aliases


def substation_name_matches(left, right):
    left_aliases = substation_name_aliases(left)
    right_aliases = substation_name_aliases(right)
    if left_aliases.intersection(right_aliases):
        return True
    for a in left_aliases:
        for b in right_aliases:
            if set(a.split()) == set(b.split()):
                return True
            if min(len(a), len(b)) >= 6 and (
                f" {a} " in f" {b} " or f" {b} " in f" {a} "
            ):
                return True
    return False


def transformer_capacity_candidates(name, company, transformer_codes=None):
    records = read_transformer_capacity_book()
    if not records:
        return []
    n = norm(name)
    company_values = [company] if isinstance(company, str) else list(company or [])
    company_norms = {norm(value) for value in company_values if norm(value)}
    company_terms = company_aliases(company_values) if company_norms else set()
    expected_codes = [code for code in (transformer_codes or []) if norm(code)]

    if expected_codes:
        code_matches = [
            item for item in records
            if any(transformer_code_matches(code, item.get("codigo_norm", "")) for code in expected_codes)
            and substation_name_matches(name, item.get("subestacion", ""))
        ]
        if not code_matches:
            return []
        company_matches = [
            item for item in code_matches
            if not company_norms or item.get("empresa_norm", "") in company_norms
            or item.get("empresa_norm", "") in company_terms
            or any(alias and (
                alias in item.get("empresa_norm", "")
                or item.get("empresa_norm", "") in alias
            ) for alias in company_terms)
        ]
        if company_matches:
            return company_matches
        by_code = {}
        for item in code_matches:
            by_code.setdefault(item.get("codigo_norm", ""), []).append(item)
        return [items[0] for items in by_code.values() if len(items) == 1]

    tokens = [t for t in n.split() if len(t) > 2]
    candidates = []
    for item in records:
        item_n = item.get("subestacion_norm", "")
        item_c = item.get("empresa_norm", "")
        if not substation_name_matches(name, item.get("subestacion", "")):
            continue
        score = 0
        if n and item_n:
            if item_n == n:
                score += 100
            elif n in item_n or item_n in n:
                score += 78
            elif tokens and all(t in item_n for t in tokens[:2]):
                score += 52
            elif tokens and any(t in item_n for t in tokens):
                score += 24
        company_match = not company_norms
        if company_norms and item_c:
            if item_c in company_norms or item_c in company_terms:
                score += 42
                company_match = True
            elif any(c and (c in item_c or item_c in c) for c in company_norms) or any(a and (a in item_c or item_c in a) for a in company_terms):
                score += 28
                company_match = True
        if score >= 70 and company_match:
            candidates.append((score, item))
    if not candidates:
        return []
    candidates.sort(key=lambda pair: pair[0], reverse=True)
    best = candidates[0][0]
    return [item for score, item in candidates if score >= max(70, best - 15)]


def transformer_capacity_for_bar(candidates, tr_code, bar_tension, transformer_index=0):
    if not candidates:
        return None
    tr_norm = norm(tr_code)
    ranked = []
    has_code_match = False
    for idx, item in enumerate(candidates):
        score = max(1, len(candidates) - idx)
        code = item.get("codigo_norm", "")
        if tr_norm and code:
            if tr_norm == code:
                score += 140
                has_code_match = True
            elif transformer_code_matches(tr_norm, code):
                score += 90
                has_code_match = True
            else:
                tr_tokens = {t for t in tr_norm.split() if len(t) > 1}
                code_tokens = {t for t in code.split() if len(t) > 1}
                if tr_tokens and code_tokens and tr_tokens.intersection(code_tokens):
                    score += 35
                    has_code_match = True
        ranked.append((score, item))
    ranked.sort(key=lambda pair: pair[0], reverse=True)
    if tr_norm and not has_code_match:
        return None
    selected = ranked[0][1] if has_code_match and ranked else candidates[transformer_index % len(candidates)]
    windings = selected.get("devanados", [])
    if not windings:
        return None
    tension = num(bar_tension)
    winding = None
    if not math.isnan(tension):
        best = min(windings, key=lambda w: abs(float(w["tension_kv"]) - tension))
        tolerance = max(1.0, abs(tension) * 0.06)
        if abs(float(best["tension_kv"]) - tension) <= tolerance:
            winding = best
        else:
            return None
    if not winding:
        winding = windings[transformer_index % len(windings)]
    primary = next((w for w in windings if norm(w.get("lado")).startswith("prim")), None)
    if primary is None:
        primary = max(windings, key=lambda w: float(w.get("tension_kv") or 0))
    total = float(primary.get("potencia_mva") or 0)
    return {
        "capacidad": float(winding["potencia_mva"]),
        "total": total if total > 0 else float(winding["potencia_mva"]),
        "source": "EquiposTransformadores.xlsx",
        "mode": "excel_per_winding",
        "lado": winding.get("lado"),
        "transformador_excel": selected,
        "tension_transformador_kv": winding.get("tension_texto") or winding.get("tension_kv"),
        "potencia_transformador_mva": winding.get("potencia_texto") or winding.get("potencia_mva"),
    }


def tension_close(a, b, rel=0.06, abs_tol=1.0):
    a_num, b_num = num(a), num(b)
    if math.isnan(a_num) or math.isnan(b_num):
        return False
    return abs(a_num - b_num) <= max(abs_tol, abs(a_num) * rel)


def row_matches_capacity_winding(row, transformer_code, tension):
    row_tr = norm(row.get("transformador") or row.get("transformador_mapa") or "")
    cap_tr = norm(transformer_code)
    if row_tr and cap_tr and row_tr != cap_tr and row_tr not in cap_tr and cap_tr not in row_tr:
        return False
    return tension_close(row.get("tension_kv") or row.get("tension_transformador_kv"), tension)


def primary_winding_info(item):
    windings = list((item or {}).get("devanados") or [])
    if not windings:
        return None, None
    primary = next((w for w in windings if norm(w.get("lado")).startswith("prim")), None)
    if primary is None:
        primary = max(windings, key=lambda w: float(w.get("tension_kv") or 0))
    display = "/".join(
        f"{float(w.get('potencia_mva')):g}"
        for w in windings if w.get("potencia_mva") is not None
    )
    return float(primary.get("potencia_mva") or 0) or None, display or None


def append_missing_capacity_windings(rows, transformer_capacity):
    if not transformer_capacity:
        return rows
    out = list(rows)
    for item in transformer_capacity:
        tr_code = str(item.get("codigo") or "").strip()
        windings = item.get("devanados") or []
        primary_capacity, rating_display = primary_winding_info(item)
        for winding in windings:
            tension = winding.get("tension_kv")
            if any(row_matches_capacity_winding(row, tr_code, tension) for row in out):
                continue
            label = winding.get("lado") or "Devanado"
            tension_text = str(winding.get("tension_texto") or tension or "").strip()
            out.append({
                "transformador": tr_code or "Transformador",
                "transformador_mapa": tr_code,
                "empresa_capacidad": item.get("empresa"),
                "subestacion_capacidad": item.get("subestacion"),
                "barra": f"Sin medicion {tension_text} kV".strip(),
                "tension_kv": tension_text,
                "tension_transformador_kv": tension_text,
                "potencia_transformador_mva": winding.get("potencia_texto") or winding.get("potencia_mva"),
                "capacidad_mva": round(float(winding.get("potencia_mva") or 0), 4) if winding.get("potencia_mva") else None,
                "capacidad_total_mva": round(primary_capacity, 4) if primary_capacity else None,
                "capacidad_modo": "excel_per_winding",
                "rol_devanado": norm(label).replace("primaria", "primario").replace("secundaria", "secundario").replace("terciaria", "terciario"),
                "lado_transformador": label,
                "capacidad_nota": f"{label} {tension_text} kV sin medicion quinceminutal",
                "potencias_nominales_mva": rating_display,
                "demanda_max_mva": None,
                "demanda_max_mw": None,
                "factor_potencia": None,
                "fecha_maxima": None,
                "archivo_quinceminutal": None,
                "disponible_mva": None,
                "disponible_tecnico_mva": None,
                "disponible_planificacion_mva": None,
                "estado": "Sin medicion",
                "fuente_capacidad": "EquiposTransformadores.xlsx",
                "sin_medicion_quinceminutal": True,
            })
    return out


def append_missing_master_transformers(rows, tr_master):
    if tr_master.empty:
        return rows
    out = list(rows)
    represented = {norm(row.get("transformador")) for row in out if norm(row.get("transformador"))}
    for idx, row in tr_master.fillna("").iterrows():
        code = str(row.get("codigo_transformador") or f"TR-{idx + 1}").strip()
        if not code or norm(code) in represented:
            continue
        out.append({
            "transformador": code,
            "transformador_mapa": "",
            "empresa_capacidad": str(row.get("empresa") or ""),
            "subestacion_capacidad": str(row.get("subestacion") or ""),
            "barra": "Sin medicion",
            "tension_kv": "",
            "capacidad_mva": None,
            "capacidad_total_mva": None,
            "capacidad_modo": "master_unmeasured",
            "capacidad_nota": "Transformador registrado en el maestro sin medicion quinceminutal asociada",
            "demanda_max_mva": None,
            "demanda_max_mw": None,
            "factor_potencia": None,
            "fecha_maxima": None,
            "archivo_quinceminutal": None,
            "disponible_mva": None,
            "estado": "Sin medicion",
            "fuente_capacidad": "DATOS DE SUB ESTACIONES MAESTRO.xlsx",
            "sin_medicion_quinceminutal": True,
        })
        represented.add(norm(code))
    return out


def apply_master_company_labels(rows, tr_master):
    if tr_master.empty:
        return rows
    companies_by_code = {}
    for _, item in tr_master.fillna("").iterrows():
        code = norm(item.get("codigo_transformador"))
        company = str(item.get("empresa") or "").strip()
        if code and company:
            companies_by_code.setdefault(code, set()).add(company)
    out = []
    for source in rows:
        row = dict(source)
        code = norm(row.get("transformador") or row.get("transformador_mapa"))
        companies = companies_by_code.get(code, set())
        if len(companies) == 1:
            master_company = next(iter(companies))
            source_company = str(row.get("empresa_capacidad") or "").strip()
            if source_company and norm(source_company) != norm(master_company):
                row["empresa_fuente_capacidad"] = source_company
            row["empresa_capacidad"] = master_company
        out.append(row)
    return out


def load_capacity(name, company):
    if not CAPACITY_JSON.exists():
        return None
    data = json.loads(CAPACITY_JSON.read_text(encoding="utf-8"))
    n = norm(name)
    c = norm(company)
    company_terms = company_aliases([company]) if c else set()
    tokens = [t for t in n.split() if len(t) > 2]
    candidates = []
    for item in data.get("subestaciones", []):
        item_n = item.get("nombre_norm") or norm(item.get("nombre"))
        item_c = item.get("empresa_norm") or norm(item.get("empresa"))
        if not substation_name_matches(name, item.get("nombre", "")):
            continue
        score = 0
        if item_n == n:
            score += 100
        elif n in item_n or item_n in n:
            score += 75
        elif tokens and all(t in item_n for t in tokens[:2]):
            score += 45
        if c and item_c:
            if c == item_c or item_c in company_terms:
                score += 35
            elif c in item_c or item_c in c or any(a and (a in item_c or item_c in a) for a in company_terms):
                score += 20
        if score:
            candidates.append((score, item))
    if not candidates:
        return None
    candidates.sort(key=lambda x: x[0], reverse=True)
    return candidates[0][1]


def capacity_for_bar(capacity_item, tr_code, bar_tension, bar_index, bars_count, transformer_index=0):
    if not capacity_item:
        return {"capacidad": None, "total": None, "source": "Sin capacidad MapaSEIN", "mode": "missing"}
    trfs = capacity_item.get("transformadores", [])
    tr_norm = norm(tr_code)
    selected = None
    for tr in trfs:
        code = norm(tr.get("codigo"))
        if code and transformer_code_matches(code, tr_norm):
            selected = tr
            break
    if not selected:
        coded_transformers = [tr for tr in trfs if norm(tr.get("codigo"))]
        if tr_norm and coded_transformers:
            return {"capacidad": None, "total": None, "source": "Codigo de transformador sin coincidencia en MapaSEIN", "mode": "missing"}
        if len(trfs) == 1:
            selected = trfs[0]
        elif trfs:
            selected = trfs[transformer_index % len(trfs)]
    if not selected:
        return {"capacidad": None, "total": None, "source": "Sin transformador MapaSEIN", "mode": "missing"}

    pots = split_nums(selected.get("potencia_mva"))
    rels = split_nums(selected.get("tension_kv"))
    tension = num(bar_tension)
    source = selected.get("fuente", "MapaSEIN OSINERGMIN")
    if not pots:
        return {"capacidad": None, "total": None, "source": source, "mode": "missing", "transformador_mapa": selected}
    if len(pots) == 1:
        mode = "shared_total" if bars_count > 1 else "single"
        return {"capacidad": pots[0], "total": pots[0], "source": source, "mode": mode, "transformador_mapa": selected}
    if rels and not math.isnan(tension):
        best_idx = min(range(min(len(rels), len(pots))), key=lambda i: abs(rels[i] - tension))
        return {"capacidad": pots[best_idx], "total": pots[0], "source": source, "mode": "per_tension", "transformador_mapa": selected}
    if bars_count == len(pots):
        return {"capacidad": pots[bar_index], "total": pots[0], "source": source, "mode": "per_bar", "transformador_mapa": selected}
    return {"capacidad": max(pots), "total": max(pots), "source": source, "mode": "shared_total", "transformador_mapa": selected}


def zero_monthly_series():
    return [{
        "periodo": "Sin medicion",
        "mes": "Sin medicion",
        "fecha": "Sin registro quinceminutal util",
        "mva": 0.0,
    }]


def unmeasured_rows_from_capacity(transformer_capacity, capacity_item, tr_master):
    rows = []
    if transformer_capacity:
        for idx, item in enumerate(transformer_capacity):
            windings = item.get("devanados", []) or []
            primary_capacity, rating_display = primary_winding_info(item)
            for w_idx, winding in enumerate(windings):
                cap = float(winding.get("potencia_mva") or 0)
                if cap <= 0:
                    continue
                rows.append({
                    "transformador": item.get("codigo") or f"TR-{idx + 1}",
                    "transformador_mapa": item.get("codigo"),
                    "empresa_capacidad": item.get("empresa"),
                    "subestacion_capacidad": item.get("subestacion"),
                    "barra": winding.get("lado") or f"Barra {w_idx + 1}",
                    "tension_kv": str(winding.get("tension_texto") or winding.get("tension_kv") or ""),
                    "tension_transformador_kv": winding.get("tension_texto") or winding.get("tension_kv"),
                    "potencia_transformador_mva": winding.get("potencia_texto") or winding.get("potencia_mva"),
                    "capacidad_mva": round(cap, 4),
                    "capacidad_total_mva": round(primary_capacity or cap, 4),
                    "capacidad_modo": "excel_per_winding",
                    "rol_devanado": norm(winding.get("lado")).replace("primaria", "primario").replace("secundaria", "secundario").replace("terciaria", "terciario"),
                    "lado_transformador": winding.get("lado"),
                    "capacidad_nota": "Potencia nominal sin demanda quinceminutal util",
                    "potencias_nominales_mva": rating_display,
                    "demanda_max_mva": None,
                    "demanda_max_mw": None,
                    "factor_potencia": None,
                    "fecha_maxima": None,
                    "archivo_quinceminutal": None,
                    "disponible_mva": None,
                    "disponible_tecnico_mva": None,
                    "disponible_planificacion_mva": None,
                    "estado": "Sin medicion",
                    "fuente_capacidad": "EquiposTransformadores.xlsx",
                    "sin_medicion_quinceminutal": True,
                })
        if rows:
            return append_missing_master_transformers(rows, tr_master)

    if capacity_item:
        for idx, tr in enumerate(capacity_item.get("transformadores", []) or []):
            pots = split_nums(tr.get("potencia_mva"))
            rels = split_nums(tr.get("tension_kv"))
            if not pots:
                rows.append({
                    "transformador": tr.get("codigo") or f"TR-{idx + 1}",
                    "transformador_mapa": tr.get("codigo"),
                    "barra": "Sin medicion",
                    "tension_kv": str(tr.get("tension_kv") or ""),
                    "tension_transformador_kv": tr.get("tension_kv"),
                    "potencia_transformador_mva": tr.get("potencia_mva"),
                    "capacidad_mva": None,
                    "capacidad_total_mva": None,
                    "capacidad_modo": "missing",
                    "capacidad_nota": "Sin potencia nominal estructurada",
                    "demanda_max_mva": None,
                    "demanda_max_mw": None,
                    "factor_potencia": None,
                    "fecha_maxima": None,
                    "archivo_quinceminutal": None,
                    "disponible_mva": None,
                    "estado": "Sin medicion",
                    "fuente_capacidad": tr.get("fuente", "MapaSEIN OSINERGMIN"),
                    "sin_medicion_quinceminutal": True,
                })
                continue
            primary_capacity = pots[0] if pots else None
            for p_idx, power in enumerate(pots):
                tension = rels[p_idx] if p_idx < len(rels) else (rels[0] if rels else "")
                rows.append({
                    "transformador": tr.get("codigo") or f"TR-{idx + 1}",
                    "transformador_mapa": tr.get("codigo"),
                    "barra": f"Tension {tension} kV" if tension != "" else f"Barra {p_idx + 1}",
                    "tension_kv": str(tension),
                    "tension_transformador_kv": tr.get("tension_kv"),
                    "potencia_transformador_mva": tr.get("potencia_mva"),
                    "capacidad_mva": round(float(power), 4),
                    "capacidad_total_mva": round(float(primary_capacity), 4) if primary_capacity else None,
                    "capacidad_modo": "mapasein_unmeasured",
                    "capacidad_nota": "Potencia nominal sin demanda quinceminutal util",
                    "demanda_max_mva": None,
                    "demanda_max_mw": None,
                    "factor_potencia": None,
                    "fecha_maxima": None,
                    "archivo_quinceminutal": None,
                    "disponible_mva": None,
                    "disponible_tecnico_mva": None,
                    "disponible_planificacion_mva": None,
                    "estado": "Sin medicion",
                    "fuente_capacidad": tr.get("fuente", "MapaSEIN OSINERGMIN"),
                    "sin_medicion_quinceminutal": True,
                })
        if rows:
            return append_missing_master_transformers(rows, tr_master)

    if not tr_master.empty:
        for idx, row in tr_master.fillna("").iterrows():
            rows.append({
                "transformador": str(row.get("codigo_transformador") or f"TR-{idx + 1}"),
                "transformador_mapa": "",
                "empresa_capacidad": str(row.get("empresa") or ""),
                "subestacion_capacidad": str(row.get("subestacion") or ""),
                "barra": "Sin medicion",
                "tension_kv": "",
                "capacidad_mva": None,
                "capacidad_total_mva": None,
                "capacidad_modo": "master_unmeasured",
                "capacidad_nota": "Transformador del maestro sin medicion quinceminutal",
                "demanda_max_mva": None,
                "demanda_max_mw": None,
                "factor_potencia": None,
                "fecha_maxima": None,
                "archivo_quinceminutal": None,
                "disponible_mva": None,
                "estado": "Sin medicion",
                "fuente_capacidad": "DATOS DE SUB ESTACIONES MAESTRO.xlsx",
                "sin_medicion_quinceminutal": True,
            })
    return rows


def summarize_rows(rows):
    by_tr = {}
    for row in rows:
        by_tr.setdefault(row["transformador"], []).append(row)
    resumen = []
    for tr_code, items in by_tr.items():
        no_measurement = all(x.get("sin_medicion_quinceminutal") for x in items)
        def role(item):
            value = norm(item.get("rol_devanado") or item.get("lado_transformador"))
            if value.startswith("prim"):
                return "primario"
            if value.startswith("sec"):
                return "secundario"
            if value.startswith("ter"):
                return "terciario"
            return ""
        primary_rows = [item for item in items if role(item) == "primario"]
        if not primary_rows:
            with_voltage = [item for item in items if not math.isnan(num(item.get("tension_kv")))]
            if with_voltage:
                primary_rows = [max(with_voltage, key=lambda item: num(item.get("tension_kv")))]
        primary_row = primary_rows[0] if primary_rows else None
        cap_total = None
        if primary_row and primary_row.get("capacidad_mva") is not None:
            cap_total = float(primary_row["capacidad_mva"])
        elif next((item.get("capacidad_total_mva") for item in items if item.get("capacidad_total_mva") is not None), None) is not None:
            cap_total = float(next(item.get("capacidad_total_mva") for item in items if item.get("capacidad_total_mva") is not None))
        dem_total = float(primary_row.get("demanda_max_mva")) if primary_row and primary_row.get("demanda_max_mva") is not None else None
        candidates = [
            item for item in items
            if role(item) in {"secundario", "terciario"}
            and (item.get("disponible_tecnico_mva") is not None or item.get("disponible_mva") is not None)
        ]
        best = max(candidates, key=lambda item: float(item.get("disponible_tecnico_mva") if item.get("disponible_tecnico_mva") is not None else item.get("disponible_mva") or 0)) if candidates else None
        disp = None if no_measurement or best is None else float(best.get("disponible_tecnico_mva") if best.get("disponible_tecnico_mva") is not None else best.get("disponible_mva"))
        planning = None if best is None else best.get("disponible_planificacion_mva")
        overloaded = any("sobrecarga" in norm(item.get("estado")) for item in items)
        if cap_total is None:
            resumen.append({
                "transformador": tr_code,
                "transformador_mapa": next((x.get("transformador_mapa") for x in items if x.get("transformador_mapa")), None),
                "capacidad_total_mva": None,
                "demanda_total_mva": dem_total,
                "disponible_total_mva": None,
                "estado": "Sin capacidad nominal" if not no_measurement else "Sin medicion",
                "criterio": "No se suma la potencia de devanados; falta identificar el limite primario",
            })
            continue
        estado = "Sin medicion" if no_measurement else ("Sobrecarga observada" if overloaded else ("Disponible preliminar" if disp is not None and disp > 0 else "Datos insuficientes"))
        resumen.append({
            "transformador": tr_code,
            "transformador_mapa": next((x.get("transformador_mapa") for x in items if x.get("transformador_mapa")), None),
            "potencias_nominales_mva": next((x.get("potencias_nominales_mva") for x in items if x.get("potencias_nominales_mva")), None),
            "capacidad_primaria_mva": round(cap_total, 4),
            "pico_primario_mva": round(dem_total, 4) if dem_total is not None else None,
            "disponible_tecnico_mva": round(disp, 4) if disp is not None else None,
            "disponible_planificacion_mva": round(float(planning), 4) if planning is not None else None,
            "barra_recomendada": best.get("barra") if best else None,
            "tension_recomendada_kv": best.get("tension_kv") if best else None,
            "restriccion": best.get("restriccion") if best else None,
            "capacidad_total_mva": round(cap_total, 4),
            "demanda_total_mva": round(dem_total, 4) if dem_total is not None else None,
            "disponible_total_mva": round(disp, 4) if disp is not None else None,
            "estado": estado,
            "criterio": "Mejor margen conectable en una barra individual; no suma capacidades de devanados",
        })
    return resumen


def pdf_info(name):
    pdfs = sorted(PDF_DIR.glob("*.pdf"))
    if not pdfs:
        return None
    pdf = pdfs[-1]
    return {
        "archivo": pdf.name,
        "url": f"uploads/pdf/{pdf.name}",
        "nota": "PDF unifilar disponible como referencia visual. El plano es grafico y no entrega texto confiable para recorte automatico.",
        "busqueda": name,
    }


def modern_analysis_response(
    payload,
    files,
    sheets_used,
    sub,
    company,
    tr_master,
    bars_master,
    transformer_capacity,
    capacity,
):
    rows = [dict(row) for row in payload.get("rows", [])]
    rows = append_missing_capacity_windings(rows, transformer_capacity)
    rows = append_missing_master_transformers(rows, tr_master)
    rows = apply_master_company_labels(rows, tr_master)

    summaries = [dict(item) for item in payload.get("transformers", [])]
    represented = {norm(item.get("transformador")) for item in summaries if norm(item.get("transformador"))}
    missing_rows = [row for row in rows if norm(row.get("transformador")) not in represented]
    summaries.extend(summarize_rows(missing_rows))
    summaries = sorted(summaries, key=lambda item: norm(item.get("transformador")))

    has_measured_demand = any(
        not row.get("sin_medicion_quinceminutal")
        and abs(float(row.get("demanda_max_mva") or 0)) > 0.0001
        for row in rows
    )
    measurement_status = "ok" if has_measured_demand else "sin_medicion"
    measurement_message = None if has_measured_demand else NO_MEASUREMENT_NOTE
    set_summary = dict(payload.get("set_summary") or {})
    if not set_summary:
        eligible = [
            row for row in rows
            if norm(row.get("rol_devanado")) in {"secundario", "terciario"}
            and row.get("disponible_tecnico_mva") is not None
        ]
        best = max(eligible, key=lambda row: float(row.get("disponible_tecnico_mva") or 0)) if eligible else None
        set_summary = {
            "disponible_tecnico_mva": best.get("disponible_tecnico_mva") if best else None,
            "disponible_planificacion_mva": best.get("disponible_planificacion_mva") if best else None,
            "transformador_recomendado": best.get("transformador") if best else None,
            "barra_recomendada": best.get("barra") if best else None,
            "tension_recomendada_kv": best.get("tension_kv") if best else None,
            "criterio": "Mejor barra individual medida; no suma margenes sin topologia",
            "capacidad_firme_n_1_mva": None,
        }
    registered_primary = sum(
        float(primary_winding_info(item)[0] or 0)
        for item in transformer_capacity or []
    )
    measured_primary = sum(
        float(item.get("capacidad_primaria_mva") or 0)
        for item in summaries
        if item.get("pico_primario_mva") is not None
    )
    set_summary["capacidad_primaria_registrada_mva"] = round(registered_primary, 4) if registered_primary else None
    set_summary["capacidad_primaria_con_medicion_mva"] = round(measured_primary, 4) if measured_primary else None
    return {
        "status": "success",
        "subestacion": sub,
        "empresa_consulta": company,
        "archivo_quinceminutal": "; ".join(f.name for f in files) if files else None,
        "archivos_quinceminutales": [f.name for f in files] if files else [],
        "hojas": sheets_used,
        "filas": rows,
        "resumen_transformadores": summaries,
        "resumen_set": set_summary,
        "metodologia_version": 2,
        "metodologia": payload.get("metodologia", {}),
        "transformadores_maestro": tr_master.fillna("").to_dict("records"),
        "barras_maestro": bars_master.fillna("").to_dict("records"),
        "capacidad_excel_transformadores": transformer_capacity,
        "capacidad_mapa_sein": capacity,
        "consumo_serie": [],
        "consumo_mensual": payload.get("monthly") or zero_monthly_series(),
        "consumo_mensual_por_tension": payload.get("monthly_by_voltage", []),
        "consumo_mensual_por_archivo": payload.get("monthly_by_file", []),
        "ultimo_registro": payload.get("ultimo_registro"),
        "medicion_quinceminutal": measurement_status,
        "mensaje_medicion": measurement_message,
        "mensaje": measurement_message,
        "advertencia_tecnica": (
            "La tabla muestra todas las barras en la misma fecha y hora del pico primario de cada transformador. "
            "No se suman devanados ni margenes entre transformadores. El informe exportado conserva maximos "
            "independientes y el margen conservador de todo el periodo. La capacidad firme N-1 "
            "requiere topologia, estado de acopladores y estudio de flujo de carga."
        ),
        "pdf": pdf_info(sub["nombre"]),
    }


def analyze(name, company="", cap_hint=None, pot_hint=None):
    sheets = read_master()
    df_sub = get_sheet(sheets, "Subestacion")
    df_tr = get_sheet(sheets, "Transformador")
    df_bar = get_sheet(sheets, "Barra")
    sub = find_substation(df_sub, name)
    tr_master = find_transformers(df_tr, sub["codigo"], sub["nombre"], company)
    bars_master = find_bars(df_bar, sub["nombre"])
    tmap = tension_map(df_bar)
    companies = []
    if company:
        companies.append(company)
    if not tr_master.empty and "empresa" in tr_master:
        companies += tr_master["empresa"].dropna().astype(str).unique().tolist()

    payload, files, sheets_used = find_quince_payload(sub["codigo"], companies)
    file_names = [f.name for f in files] if files else []
    capacity = load_capacity(sub["nombre"], company or (companies[0] if companies else ""))
    master_transformer_codes = tr_master["codigo_transformador"].dropna().astype(str).tolist() if not tr_master.empty else []
    transformer_capacity = transformer_capacity_candidates(
        sub["nombre"],
        companies,
        master_transformer_codes,
    )
    if payload and int(payload.get("metodologia_version") or 0) >= 2:
        return modern_analysis_response(
            payload,
            files,
            sheets_used,
            sub,
            company,
            tr_master,
            bars_master,
            transformer_capacity,
            capacity,
        )
    if not payload:
        rows = unmeasured_rows_from_capacity(transformer_capacity, capacity, tr_master)
        rows = apply_master_company_labels(rows, tr_master)
        resumen_transformadores = summarize_rows(rows)
        return {
            "status": "success",
            "subestacion": sub,
            "empresa_consulta": company,
            "archivo_quinceminutal": None,
            "archivos_quinceminutales": [],
            "hojas": [],
            "filas": rows,
            "resumen_transformadores": resumen_transformadores,
            "mensaje": NO_QUINCE_NOTE,
            "mensaje_medicion": NO_QUINCE_NOTE,
            "medicion_quinceminutal": "sin_registros",
            "transformadores_maestro": tr_master.fillna("").to_dict("records"),
            "barras_maestro": bars_master.fillna("").to_dict("records"),
            "capacidad_excel_transformadores": transformer_capacity,
            "capacidad_mapa_sein": capacity,
            "consumo_serie": [],
            "consumo_mensual": zero_monthly_series(),
            "consumo_mensual_por_tension": [],
            "consumo_mensual_por_archivo": [],
            "ultimo_registro": None,
            "pdf": pdf_info(sub["nombre"]),
        }

    rows = []
    peaks = payload.get("peaks", [])
    by_peak_tr = {}
    for peak in peaks:
        by_peak_tr.setdefault(str(peak.get("transformador", "")), []).append(peak)
    for tr_idx, (tr_code, peak_group) in enumerate(sorted(by_peak_tr.items())):
        peak_group = sorted(peak_group, key=lambda item: str(item.get("barra", "")))
        for idx, peak in enumerate(peak_group):
            bar_code = str(peak.get("barra", ""))
            mw = peak.get("demanda_max_mw")
            fp = peak.get("factor_potencia")
            mva = peak.get("demanda_max_mva")
            tension = tmap.get(norm(bar_code), "")
            cap_info = transformer_capacity_for_bar(transformer_capacity, tr_code, tension, tr_idx)
            if not cap_info:
                cap_info = capacity_for_bar(capacity, tr_code, tension, idx, len(peak_group), tr_idx)
            cap = cap_info.get("capacidad")
            cap_source = cap_info.get("source")
            cap_mode = cap_info.get("mode")
            tr_mapa = cap_info.get("transformador_mapa") or {}
            tr_excel = cap_info.get("transformador_excel") or {}
            if cap is None or math.isnan(cap):
                cap = cap_hint or pot_hint
                cap_source = "Atributo OSINERGMIN del visor" if cap else cap_source
                cap_mode = "attribute_total" if cap and len(peak_group) > 1 else "attribute"
            shared = cap_mode in {"shared_total", "attribute_total"}
            cap_note = ""
            if shared:
                cap_note = "Total compartido del transformador"
            elif cap_mode == "excel_per_winding":
                cap_note = f"Potencia nominal por barra ({cap_info.get('lado')})"
            elif cap_mode in {"per_tension", "per_bar"}:
                cap_note = "Capacidad independiente por barra/tension"
            disponible = None if shared else (round(float(cap) - float(mva), 4) if cap and mva is not None else None)
            rows.append({
                "transformador": str(tr_code),
                "transformador_mapa": tr_excel.get("codigo") or tr_mapa.get("codigo"),
                "empresa_capacidad": tr_excel.get("empresa"),
                "subestacion_capacidad": tr_excel.get("subestacion"),
                "barra": str(bar_code),
                "tension_kv": str(tension),
                "tension_transformador_kv": cap_info.get("tension_transformador_kv") or tr_mapa.get("tension_kv"),
                "potencia_transformador_mva": cap_info.get("potencia_transformador_mva") or tr_mapa.get("potencia_mva"),
                "capacidad_mva": round(float(cap), 4) if cap else None,
                "capacidad_total_mva": round(float(cap_info.get("total") or cap), 4) if (cap_info.get("total") or cap) else None,
                "capacidad_modo": cap_mode,
                "lado_transformador": cap_info.get("lado"),
                "capacidad_nota": cap_note,
                "demanda_max_mva": mva,
                "demanda_max_mw": mw,
                "factor_potencia": fp,
                "fecha_maxima": peak.get("fecha"),
                "archivo_quinceminutal": peak.get("archivo"),
                "disponible_mva": disponible,
                "estado": ("Ver resumen" if shared else ("Disponible" if disponible is not None and disponible >= 0 else ("Sobrecarga" if disponible is not None else "Sin capacidad"))),
                "fuente_capacidad": cap_source,
            })

    rows = append_missing_capacity_windings(rows, transformer_capacity)
    rows = append_missing_master_transformers(rows, tr_master)
    rows = apply_master_company_labels(rows, tr_master)

    has_measured_demand = any(abs(float(row.get("demanda_max_mva") or 0)) > 0.0001 for row in rows)
    if payload.get("sin_medicion") or not has_measured_demand:
        has_measured_demand = False
        for row in rows:
            row["estado"] = "Sin medicion"
            row["disponible_mva"] = None
            row["sin_medicion_quinceminutal"] = True
            if not row.get("capacidad_nota"):
                row["capacidad_nota"] = "Potencia nominal sin demanda quinceminutal util"

    resumen_transformadores = summarize_rows(rows)
    measurement_status = "ok" if has_measured_demand else "sin_medicion"
    measurement_message = None if has_measured_demand else NO_MEASUREMENT_NOTE
    monthly = payload.get("monthly", [])
    if not monthly:
        monthly = zero_monthly_series()

    return {
        "status": "success",
        "subestacion": sub,
        "empresa_consulta": company,
        "archivo_quinceminutal": "; ".join(file_names) if file_names else None,
        "archivos_quinceminutales": file_names,
        "hojas": sheets_used,
        "filas": rows,
        "resumen_transformadores": resumen_transformadores,
        "transformadores_maestro": tr_master.fillna("").to_dict("records"),
        "barras_maestro": bars_master.fillna("").to_dict("records"),
        "capacidad_excel_transformadores": transformer_capacity,
        "capacidad_mapa_sein": capacity,
        "consumo_serie": [],
        "consumo_mensual": monthly,
        "consumo_mensual_por_tension": payload.get("monthly_by_voltage", []),
        "consumo_mensual_por_archivo": payload.get("monthly_by_file", []),
        "ultimo_registro": payload.get("ultimo_registro"),
        "medicion_quinceminutal": measurement_status,
        "mensaje_medicion": measurement_message,
        "mensaje": measurement_message,
        "pdf": pdf_info(sub["nombre"]),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--nombre")
    parser.add_argument("--empresa", default="")
    parser.add_argument("--capacidad", type=float, default=None)
    parser.add_argument("--pot-inst", type=float, default=None)
    parser.add_argument("--warm-cache", action="store_true")
    args = parser.parse_args()
    try:
        if args.warm_cache:
            built = []
            for file in quince_files([]):
                index = load_quince_index(file)
                built.append({
                    "archivo": file.name,
                    "subestaciones": len(index.get("substations", {})),
                    "hojas": len(index.get("sheets", [])),
                })
            print(json.dumps({"status": "success", "cache": built}, ensure_ascii=True))
            return
        if not args.nombre:
            raise ValueError("Parametro --nombre requerido")
        print(json.dumps(analyze(args.nombre, args.empresa, args.capacidad, args.pot_inst), ensure_ascii=True))
    except Exception as exc:
        print(json.dumps({"status": "error", "message": str(exc)}, ensure_ascii=True))
        sys.exit(1)


if __name__ == "__main__":
    main()
