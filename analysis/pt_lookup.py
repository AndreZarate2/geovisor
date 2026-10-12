#!/usr/bin/env python3
"""Lightweight matcher for COES Transmission Plan anteproyecto PDFs."""
from __future__ import annotations

import argparse
import json
import re
import unicodedata
from pathlib import Path


STOPWORDS = {
    "de", "del", "la", "las", "los", "el", "en", "y", "a", "para", "por", "con",
    "sistema", "electrico", "electrica", "mejora", "nueva", "nuevo", "anteproyecto",
    "linea", "transmision", "subestacion", "set", "se", "lt", "kv", "pt", "coes",
    "sur", "norte", "este", "oeste", "centro", "luz",
}

LOCATION_DEPARTMENTS = {
    "ilo": "Moquegua",
    "moquegua": "Moquegua",
    "montalvo": "Moquegua",
    "colcabamba": "Huancavelica",
    "huancavelica": "Huancavelica",
    "cieneguilla": "Lima",
    "lima": "Lima",
    "carabayllo": "Lima",
    "planicie": "Lima",
    "chilca": "Lima",
    "poroma": "Ica",
    "ocona": "Arequipa",
    "san jose": "Arequipa",
    "antuquito": "Lima",
    "callahuanca": "Lima",
    "cantera": "Lima",
    "piura": "Piura",
    "bajo piura": "Piura",
    "bellavista": "San Martin",
    "juanjui": "San Martin",
    "aguaytia": "Ucayali",
    "pucallpa": "Ucayali",
    "pumiri": "Puno",
    "trujillo": "La Libertad",
    "cajamarca": "Cajamarca",
    "yaros": "Pasco",
    "huanuco": "Huanuco",
    "caclic": "Amazonas",
    "moyobamba": "San Martin",
}

DEPARTMENT_SET = {
    "amazonas", "ancash", "apurimac", "arequipa", "ayacucho", "cajamarca",
    "callao", "cusco", "huancavelica", "huanuco", "ica", "junin",
    "la libertad", "lambayeque", "lima", "loreto", "madre de dios",
    "moquegua", "pasco", "piura", "puno", "san martin", "tacna", "tumbes",
    "ucayali",
}


ALIASES = {
    "redesur": "red electrica del sur",
    "electrosur": "empresa regional de servicio publico de electricidad electrosur",
    "enosa": "electronoroeste",
    "hidrandina": "empresa regional de servicio publico de electricidad electronorte medio",
}


def norm(value: object) -> str:
    text = str(value or "").lower()
    text = unicodedata.normalize("NFD", text)
    text = "".join(ch for ch in text if unicodedata.category(ch) != "Mn")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def tokens(value: object) -> set[str]:
    base = norm(value)
    for src, dst in ALIASES.items():
        if src in base:
            base += " " + norm(dst)
    out = set()
    for token in base.split():
        if len(token) < 3 or token in STOPWORDS:
            continue
        out.add(token)
    return out


def asset_text(value: object) -> str:
    text = norm(value)
    text = re.sub(r"\b(l|lt|linea|transmision|set|se|subestacion|kv|enlace)\b", " ", text)
    text = re.sub(r"\b(33|60|66|138|220|500)\b", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def asset_tokens(value: object) -> set[str]:
    out = tokens(asset_text(value))
    for short in ("ilo",):
        if re.search(rf"\b{short}\b", asset_text(value)):
            out.add(short)
    return out


def endpoint_phrases(value: object) -> list[str]:
    raw = asset_text(value)
    parts = re.split(r"\s+(?:a|al|hacia|hasta)\s+|[-–—_/]+", raw)
    phrases = []
    for part in parts:
        phrase = re.sub(r"\s+", " ", part).strip()
        if len(phrase) >= 4 and asset_tokens(phrase):
            phrases.append(phrase)
    if raw and raw not in phrases and len(raw) >= 4:
        phrases.insert(0, raw)
    return phrases[:8]


def infer_departments(*values: object) -> set[str]:
    joined = norm(" ".join(str(v or "") for v in values))
    found: set[str] = set()
    for dept in DEPARTMENT_SET:
        if re.search(rf"\b{re.escape(dept)}\b", joined):
            found.add(dept.title())
    for place, dept in LOCATION_DEPARTMENTS.items():
        if re.search(rf"\b{re.escape(norm(place))}\b", joined):
            found.add(dept)
    return found


def is_new_project(text: str) -> bool:
    n = norm(text)
    return any(word in n for word in (
        "nueva", "nuevo", "ampliacion", "ampl", "segundo", "2do",
        "nuevo enlace", "enlace", "mejora confiab", "maniobras",
    ))


def clean_title(path: Path) -> str:
    stem = path.stem
    stem = re.sub(r"^\s*\d+\.\s*", "", stem)
    stem = re.sub(r"\s+", " ", stem).strip()
    return stem


def classify(title: str) -> str:
    n = norm(title)
    if "transform" in n:
        return "Transformador / SET"
    if "subestacion" in n or re.search(r"\bse\b", n):
        return "Subestacion"
    if "enlace" in n or "linea" in n or re.search(r"\blt\b", n):
        return "Linea de transmision"
    if "compens" in n or "eacr" in n:
        return "Compensacion reactiva"
    return "Anteproyecto"


def metadata(path: Path, root: Path) -> dict:
    title = clean_title(path)
    number = re.match(r"^\s*(\d+)", path.stem)
    itc = re.search(r"\bITC[-\s]*\d+\b", title, flags=re.I)
    kv = sorted({int(v) for v in re.findall(r"(?<!\d)(33|60|66|138|220|500)\s*kV", title, flags=re.I)})
    rel = path.relative_to(root).as_posix()
    departments = sorted(infer_departments(title, rel))
    return {
        "titulo": title,
        "codigo": itc.group(0).upper().replace(" ", "-") if itc else (number.group(1) if number else ""),
        "tipo": classify(title),
        "tension_kv": "/".join(map(str, kv)) if kv else "",
        "departamentos": departments,
        "proyecto_nuevo": is_new_project(title),
        "archivo": path.name,
        "archivo_rel": rel,
        "url": "uploads/pt_coes/" + rel,
        "size_mb": round(path.stat().st_size / 1048576, 2),
    }


def score_pdf(
    meta: dict,
    direct_tokens: set[str],
    raw_query: str,
    tipo: str,
    query_departments: set[str],
    allow_department_context: bool,
    codigo: str = "",
) -> tuple[float, list[str], str]:
    title_text = norm(" ".join(str(meta.get(k, "")) for k in ("titulo", "codigo", "tipo", "tension_kv")))
    title_tokens = asset_tokens(title_text)
    hits = sorted(direct_tokens & title_tokens)
    phrases = endpoint_phrases(raw_query)
    phrase_hits = [p for p in phrases if len(p) >= 4 and p in title_text]
    code_norm = norm(codigo)
    code_hit = bool(code_norm and len(code_norm) >= 4 and code_norm in title_text)
    title_departments = {str(d) for d in meta.get("departamentos", []) if str(d)}
    dept_overlap = sorted({norm(d).title() for d in query_departments} & {norm(d).title() for d in title_departments})
    match_type = ""
    score = 0.0
    reasons = []
    if code_hit:
        score += 86.0
        match_type = "codigo"
        reasons.append("Codigo de linea/instalacion en el titulo")
    if phrase_hits:
        score += 76.0
        match_type = match_type or "nombre_explicito"
        reasons.append("Nombre explicito: " + ", ".join(phrase_hits[:3]))
    strong_hits = [h for h in hits if len(h) >= 4 or h in {"ilo"}]
    if len(strong_hits) >= 2:
        score += 64.0 + min(12.0, len(strong_hits) * 4.0)
        match_type = match_type or "tokens_instalacion"
        reasons.append("Instalaciones coincidentes: " + ", ".join(strong_hits[:6]))
    location_hits = [h for h in strong_hits if h in LOCATION_DEPARTMENTS and h not in DEPARTMENT_SET]
    if not match_type and location_hits:
        score += 58.0
        match_type = match_type or "ubicacion_instalacion"
        reasons.append("Ubicacion/SET coincidente: " + ", ".join(location_hits[:4]))

    if not match_type and allow_department_context and query_departments and dept_overlap and meta.get("proyecto_nuevo"):
        score += 46.0
        match_type = "proyecto_nuevo_departamento"
        reasons.append("Proyecto nuevo en departamento consultado: " + ", ".join(dept_overlap[:3]))

    if not match_type:
        return 0.0, [], ""

    if tipo == "line" and meta.get("tipo") == "Linea de transmision":
        score += 10.0
        reasons.append("Tipo linea")
    if tipo == "set" and meta.get("tipo") in {"Subestacion", "Transformador / SET", "Linea de transmision"}:
        score += 6.0
        reasons.append("Relacionado a SET")
    if dept_overlap and match_type != "proyecto_nuevo_departamento":
        score += 8.0
        reasons.append("Departamento coincidente: " + ", ".join(dept_overlap[:3]))
    return score, reasons, match_type


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--nombre", default="")
    ap.add_argument("--empresa", default="")
    ap.add_argument("--codigo", default="")
    ap.add_argument("--departamento", default="")
    ap.add_argument("--tipo", choices=["set", "line", "generic"], default="generic")
    ap.add_argument("--pt-dir", default="uploads/pt_coes")
    ap.add_argument("--limit", type=int, default=8)
    args = ap.parse_args()

    root = Path(args.pt_dir)
    if not root.exists():
        print(json.dumps({"status": "unavailable", "message": "Carpeta PT COES no encontrada."}))
        return

    asset_query = " ".join([args.nombre, args.codigo])
    context_query = " ".join([args.nombre, args.codigo, args.departamento])
    has_asset_query = bool(asset_text(asset_query))
    direct_tokens = asset_tokens(asset_query)
    query_departments = infer_departments(context_query)
    allow_department_context = is_new_project(asset_query) or (not has_asset_query and bool(query_departments))
    pdfs = sorted(root.rglob("*.pdf"))
    matches = []
    for pdf in pdfs:
        meta = metadata(pdf, root)
        score, reasons, match_type = score_pdf(
            meta,
            direct_tokens,
            asset_query,
            args.tipo,
            query_departments,
            allow_department_context,
            args.codigo,
        )
        if score < 40:
            continue
        meta["score"] = round(min(score, 100), 1)
        meta["razones"] = reasons
        meta["match_type"] = match_type
        matches.append(meta)

    matches.sort(key=lambda m: (-float(m["score"]), m["titulo"]))
    explicit_matches = [m for m in matches if m.get("match_type") != "proyecto_nuevo_departamento"]
    department_context = [m for m in matches if m.get("match_type") == "proyecto_nuevo_departamento"]
    if explicit_matches:
        matches = explicit_matches + department_context[:2]
    else:
        matches = department_context[:3]
    status = "success" if matches else "no_data"
    message = "" if matches else "No se detectaron anteproyectos PT COES asociados."
    print(json.dumps({
        "status": status,
        "message": message,
        "file_count": len(pdfs),
        "criterio": "PT filtrado por coincidencia explicita de nombre/codigo de SET o LT; proyectos nuevos solo por departamento consultado.",
        "departamentos_consulta": sorted(query_departments),
        "contexto_departamento_activo": allow_department_context,
        "matches": matches[: max(1, args.limit)],
    }))


if __name__ == "__main__":
    main()
