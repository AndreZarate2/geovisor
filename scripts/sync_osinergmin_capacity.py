import html
import json
import re
import unicodedata
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "data" / "osinergmin_capacity.json"
BASE_URL = "https://www.osinergmin.gob.pe/newweb/uploads/Publico/MapaSEIN/"
FALLBACK_DB_URL = BASE_URL + "js/DB.js?v=1842654"


def norm(value):
    text = html.unescape(str(value or "")).lower()
    text = unicodedata.normalize("NFKD", text)
    text = "".join(c for c in text if not unicodedata.combining(c))
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def parse_taffy_var(source, var_name):
    match = re.search(rf"var\s+{re.escape(var_name)}\s*=\s*TAFFY\(\[(.*?)\]\);", source, re.S)
    if not match:
        return []
    body = "[" + match.group(1).strip().rstrip(",") + "]"
    return json.loads(body)


def resolve_db_url():
    try:
        html_text = urllib.request.urlopen(BASE_URL, timeout=30).read().decode("utf-8", "ignore")
        match = re.search(r'["\']([^"\']*js/DB\.js(?:\?v=\d+)?)[ "\']', html_text)
        if match:
            path = html.unescape(match.group(1))
            return urllib.request.urljoin(BASE_URL, path)
    except Exception:
        pass
    return FALLBACK_DB_URL


def main():
    url = resolve_db_url()
    raw = urllib.request.urlopen(url, timeout=40).read().decode("utf-8", "ignore")
    substations = parse_taffy_var(raw, "se")
    transformers = parse_taffy_var(raw, "se_trf")
    companies = {str(x.get("id_emp")): html.unescape(str(x.get("name", ""))) for x in parse_taffy_var(raw, "emp")}

    by_se = {}
    for item in substations:
        record = {
            "id_se": str(item.get("id_se", "")),
            "nombre": html.unescape(str(item.get("name", ""))),
            "empresa": companies.get(str(item.get("id_emp", "")), ""),
            "tension": item.get("tension", ""),
            "lat": item.get("lat", ""),
            "lng": item.get("long", ""),
            "transformadores": [],
        }
        by_se[record["id_se"]] = record

    for item in transformers:
        se = by_se.get(str(item.get("id_se", "")))
        if not se:
            continue
        se["transformadores"].append({
            "codigo": html.unescape(str(item.get("cod", ""))),
            "tipo": html.unescape(str(item.get("type", ""))),
            "anio_poc": str(item.get("year", "")),
            "tension_kv": str(item.get("rel", "")),
            "potencia_mva": str(item.get("pot", "")),
            "fuente": "MapaSEIN OSINERGMIN",
        })

    records = [v for v in by_se.values() if v["transformadores"]]
    for rec in records:
        rec["nombre_norm"] = norm(rec["nombre"])
        rec["empresa_norm"] = norm(rec["empresa"])

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({
        "source": url,
        "total_subestaciones": len(records),
        "subestaciones": records,
    }, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"{OUT}: {len(records)} subestaciones con transformadores")


if __name__ == "__main__":
    main()
