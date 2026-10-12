"""Importa ZIP/KMZ geograficos al proyecto como GeoJSON comprimido."""

from __future__ import annotations

import argparse
import gzip
import io
import json
import math
import re
import shutil
import tempfile
import zipfile
from datetime import date, datetime
from pathlib import Path
from xml.etree import ElementTree as ET

import shapefile


ROOT = Path(__file__).resolve().parents[1]
CACHE_DIR = ROOT / "cache"
UPLOADS_DIR = ROOT / "uploads"
REGISTRY = UPLOADS_DIR / "layer_registry.json"
SOURCE_DIR = ROOT / "data" / "source_archives"


def layer_id(value: str) -> str:
    name = Path(value).stem
    return re.sub(r"[^A-Za-z0-9_-]+", "_", name).strip("_")


def clean_value(value, field_name: str = ""):
    if value is None:
        return ""
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if isinstance(value, float):
        if math.isnan(value):
            return ""
        key = field_name.upper()
        if "KM" in key or "LONG" in key or "LENGTH" in key:
            return round(value, 2)
        return round(value, 6)
    if isinstance(value, bytes):
        for enc in ("utf-8", "latin1", "cp1252"):
            try:
                return value.decode(enc).strip()
            except Exception:
                pass
        return ""
    text = str(value).strip()
    return re.sub(r"\s+", " ", text)


def write_feature(out, feature: dict, first: bool) -> bool:
    if not first:
        out.write(",\n")
    out.write(json.dumps(feature, ensure_ascii=False, separators=(",", ":")))
    return False


def open_shapefile(shp: Path) -> shapefile.Reader:
    cpg = shp.with_suffix(".cpg")
    enc = "utf-8"
    if cpg.exists():
        raw = cpg.read_text(errors="ignore").strip()
        if raw:
            enc = "utf-8" if raw.lower() in {"utf8", "utf-8"} else raw
    try:
        return shapefile.Reader(str(shp), encoding=enc)
    except UnicodeDecodeError:
        return shapefile.Reader(str(shp), encoding="latin1")


def convert_shapefiles(shps: list[Path], out_path: Path, extra_props: dict | None = None) -> int:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    first = True
    with gzip.open(out_path, "wt", encoding="utf-8", compresslevel=5) as out:
        out.write('{"type":"FeatureCollection","features":[\n')
        for shp in shps:
            reader = open_shapefile(shp)
            try:
                fields = [f[0] for f in reader.fields[1:]]
                src_props = {"FUENTE_ARCHIVO": shp.name}
                if extra_props:
                    src_props.update(extra_props)
                if shp.parent.name.upper() == "DEPARTAMENTO":
                    src_props["DEPARTAMENTO"] = shp.stem
                for sr in reader.iterShapeRecords():
                    if sr.shape.shapeType == shapefile.NULL:
                        continue
                    try:
                        geom = sr.shape.__geo_interface__
                    except Exception:
                        continue
                    if not geom or geom.get("type") == "Null":
                        continue
                    props = dict(src_props)
                    for idx, field in enumerate(fields):
                        props[field] = clean_value(sr.record[idx] if idx < len(sr.record) else "", field)
                    first = write_feature(out, {"type": "Feature", "properties": props, "geometry": geom}, first)
                    count += 1
            finally:
                reader.close()
        out.write("\n]}\n")
    return count


def extract_shapefile_group(zip_path: Path, shp_entry: str, dest_root: Path) -> Path:
    base = Path(shp_entry).with_suffix("")
    target_dir = dest_root / layer_id(str(base))
    target_dir.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path) as zf:
        for entry in zf.namelist():
            p = Path(entry)
            if p.with_suffix("").as_posix().lower() == base.as_posix().lower():
                zf.extract(entry, target_dir)
    nested = target_dir / shp_entry
    if nested.exists():
        for f in nested.parent.iterdir():
            if f.is_file():
                shutil.move(str(f), target_dir / f.name)
        shutil.rmtree(target_dir / Path(shp_entry).parts[0], ignore_errors=True)
    shp = target_dir / (base.name + ".shp")
    if not shp.exists():
        candidates = list(target_dir.rglob(base.name + ".shp"))
        if candidates:
            shp = candidates[0]
    return shp


def shapefiles_in_zip(zip_path: Path, include: list[str] | None = None, exclude: list[str] | None = None) -> list[str]:
    include = [s.lower() for s in (include or [])]
    exclude = [s.lower() for s in (exclude or [])]
    with zipfile.ZipFile(zip_path) as zf:
        names = [n for n in zf.namelist() if n.lower().endswith(".shp")]
    out = []
    for name in names:
        low = name.lower()
        if include and not any(token in low for token in include):
            continue
        if exclude and any(token in low for token in exclude):
            continue
        out.append(name)
    return sorted(out)


def parse_kml_coords(text: str) -> list[list[float]]:
    pts = []
    for raw in re.split(r"\s+", (text or "").strip()):
        if not raw:
            continue
        parts = raw.split(",")
        if len(parts) < 2:
            continue
        try:
            lng = float(parts[0])
            lat = float(parts[1])
        except ValueError:
            continue
        if -90 <= lat <= 90 and -180 <= lng <= 180:
            pts.append([lng, lat])
    return pts


def first_text(node, suffix: str) -> str:
    for child in node.iter():
        if child.tag.endswith(suffix) and child.text:
            return clean_value(child.text)
    return ""


def placemark_props(pm) -> dict:
    props = {"FUENTE_ARCHIVO": "CULTURA.kmz"}
    name = first_text(pm, "name")
    desc = first_text(pm, "description")
    if name:
        props["NOMBRE"] = name
    if desc:
        props["DESCRIPCION"] = desc
    for data in pm.iter():
        tag = data.tag.split("}")[-1]
        if tag not in {"Data", "SimpleData"}:
            continue
        key = data.attrib.get("name") or data.attrib.get("displayName") or ""
        val = ""
        for child in data:
            if child.tag.endswith("value") and child.text:
                val = child.text
                break
        if not val and data.text:
            val = data.text
        if key and val:
            props[clean_value(key)] = clean_value(val)
    return props


def convert_kmz(kmz: Path, out_path: Path) -> int:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    first = True
    with zipfile.ZipFile(kmz) as zf:
        kml_names = [n for n in zf.namelist() if n.lower().endswith(".kml")]
        if not kml_names:
            return 0
        raw_kml = zf.read(kml_names[0]).decode("utf-8", errors="ignore")
        raw_kml = re.sub(r"<(/?)([A-Za-z_][\w.-]*):", r"<\1", raw_kml)
        raw_kml = re.sub(r"(\s)([A-Za-z_][\w.-]*):([A-Za-z_][\w.-]*)=", r"\1\3=", raw_kml)
        with io.StringIO(raw_kml) as kml, gzip.open(out_path, "wt", encoding="utf-8", compresslevel=5) as out:
            out.write('{"type":"FeatureCollection","features":[\n')
            for event, elem in ET.iterparse(kml, events=("end",)):
                if not elem.tag.endswith("Placemark"):
                    continue
                props = placemark_props(elem)
                polygons = []
                lines = []
                points = []
                for poly in [n for n in elem.iter() if n.tag.endswith("Polygon")]:
                    ring = first_text(poly, "coordinates")
                    coords = parse_kml_coords(ring)
                    if len(coords) >= 3:
                        if coords[0] != coords[-1]:
                            coords.append(coords[0])
                        polygons.append([coords])
                for line in [n for n in elem.iter() if n.tag.endswith("LineString")]:
                    coords = parse_kml_coords(first_text(line, "coordinates"))
                    if len(coords) >= 2:
                        lines.append(coords)
                for point in [n for n in elem.iter() if n.tag.endswith("Point")]:
                    coords = parse_kml_coords(first_text(point, "coordinates"))
                    if coords:
                        points.append(coords[0])
                geom = None
                if polygons:
                    geom = {"type": "Polygon", "coordinates": polygons[0]} if len(polygons) == 1 else {"type": "MultiPolygon", "coordinates": polygons}
                elif lines:
                    geom = {"type": "LineString", "coordinates": lines[0]} if len(lines) == 1 else {"type": "MultiLineString", "coordinates": lines}
                elif points:
                    geom = {"type": "Point", "coordinates": points[0]}
                if geom:
                    first = write_feature(out, {"type": "Feature", "properties": props, "geometry": geom}, first)
                    count += 1
                elem.clear()
            out.write("\n]}\n")
    return count


def load_registry() -> list[dict]:
    if REGISTRY.exists():
        try:
            data = json.loads(REGISTRY.read_text(encoding="utf-8"))
            if isinstance(data, list):
                return data
        except Exception:
            pass
    return []


def save_registry(items: list[dict]) -> None:
    UPLOADS_DIR.mkdir(parents=True, exist_ok=True)
    REGISTRY.write_text(json.dumps(items, ensure_ascii=False, indent=2), encoding="utf-8")


def upsert_layer(registry: list[dict], item: dict) -> None:
    for idx, existing in enumerate(registry):
        if existing.get("id") == item.get("id"):
            merged = {**existing, **item}
            registry[idx] = merged
            return
    registry.append(item)


def copy_archive(src: Path) -> None:
    dest = SOURCE_DIR / src.name
    SOURCE_DIR.mkdir(parents=True, exist_ok=True)
    if not dest.exists() or dest.stat().st_size != src.stat().st_size:
        shutil.copy2(src, dest)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--departamento", type=Path, required=True)
    ap.add_argument("--acuicultura", type=Path, required=True)
    ap.add_argument("--mayo", type=Path, required=True)
    ap.add_argument("--cultura", type=Path, required=True)
    ap.add_argument("--copy-archives", action="store_true")
    args = ap.parse_args()

    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    registry = load_registry()
    report = []
    if args.copy_archives:
        for src in [args.departamento, args.acuicultura, args.mayo, args.cultura]:
            copy_archive(src)

    with tempfile.TemporaryDirectory(prefix="cvc_geo_") as tmp_raw:
        tmp = Path(tmp_raw)

        dep_shps = shapefiles_in_zip(args.departamento)
        dep_paths = [extract_shapefile_group(args.departamento, entry, tmp / "Departamento") for entry in dep_shps]
        count = convert_shapefiles(dep_paths, CACHE_DIR / "MIDAGRI_Departamentos.geojson.gz", {"FUENTE": "MIDAGRI"})
        upsert_layer(registry, {
            "id": "MIDAGRI_Departamentos", "nombre": "MIDAGRI por departamentos",
            "tipo": "polygon", "color": "#22c55e", "grupo": "MIDAGRI",
            "menu": "Agricola y Acuicultura", "activa": False,
        })
        report.append(("MIDAGRI_Departamentos", count))

        for entry in shapefiles_in_zip(args.acuicultura):
            shp = extract_shapefile_group(args.acuicultura, entry, tmp / "Acuicultura")
            lid = "PRODUCE_" + layer_id(entry)
            count = convert_shapefiles([shp], CACHE_DIR / f"{lid}.geojson.gz", {"FUENTE": "PRODUCE"})
            upsert_layer(registry, {
                "id": lid, "nombre": "PRODUCE " + layer_id(entry).replace("_", " "),
                "tipo": "polygon", "color": "#06b6d4", "grupo": "PRODUCE Acuicultura",
                "menu": "Agricola y Acuicultura", "activa": False,
            })
            report.append((lid, count))

        selected_mayo = [
            "Mayo_2026/SET/SET_Operacion.shp",
            "Mayo_2026/SET/SET_Operacion_join.shp",
            "Mayo_2026/SET/SET_Proyectadas.shp",
            "Mayo_2026/Transmision/LT_Operacionm.shp",
            "Mayo_2026/Transmision/LT_Proyectadas.shp",
            "Mayo_2026/Distribucion/Tramo_media_tension.shp",
        ]
        meta = {
            "SET_Operacion": ("SET en Operacion", "point", "#00d4ff", "Subestaciones", True),
            "SET_Operacion_join": ("SET en Operacion join", "point", "#38bdf8", "Subestaciones", False),
            "SET_Proyectadas": ("SET Proyectadas", "point", "#ff9d00", "Subestaciones", False),
            "LT_Operacionm": ("LT en Operacion", "line", "#00ff88", "Transmision", True),
            "LT_Proyectadas": ("LT Proyectadas", "line", "#ffcc00", "Transmision", False),
            "Tramo_media_tension": ("Tramos Media Tension", "line", "#fb923c", "Distribucion", False),
        }
        for entry in selected_mayo:
            shp = extract_shapefile_group(args.mayo, entry, tmp / "Mayo_2026")
            lid = layer_id(entry)
            count = convert_shapefiles([shp], CACHE_DIR / f"{lid}.geojson.gz", {"FUENTE": "Mayo 2026"})
            nombre, tipo, color, grupo, active = meta.get(lid, (lid.replace("_", " "), "polygon", "#38bdf8", "Electricidad", False))
            upsert_layer(registry, {
                "id": lid, "nombre": nombre, "tipo": tipo, "color": color,
                "grupo": grupo, "menu": "Electricidad", "activa": active,
            })
            report.append((lid, count))

        count = convert_kmz(args.cultura, CACHE_DIR / "CULTURA.geojson.gz")
        upsert_layer(registry, {
            "id": "CULTURA", "nombre": "Zonas arqueologicas CULTURA",
            "tipo": "polygon", "color": "#a855f7", "grupo": "Zonas arqueologicas",
            "menu": "Zonas arqueologicas", "activa": False,
        })
        report.append(("CULTURA", count))

    save_registry(registry)
    print(json.dumps({"status": "ok", "layers": [{"id": k, "features": v} for k, v in report]}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
