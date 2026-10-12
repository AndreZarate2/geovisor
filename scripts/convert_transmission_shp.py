from __future__ import annotations

import datetime as dt
import json
from pathlib import Path
from typing import Any

import shapefile


ROOT = Path(__file__).resolve().parents[1]
SHP_PATH = ROOT / "data" / "shapes" / "Transmision" / "Linea_transmision.shp"
OUT_PATH = ROOT / "data" / "geojson" / "LT_Operacionm.geojson"


def cpg_encoding(path: Path) -> str:
    cpg = path.with_suffix(".CPG")
    if not cpg.exists():
        cpg = path.with_suffix(".cpg")
    if cpg.exists():
        value = cpg.read_text(encoding="ascii", errors="ignore").strip()
        if value:
            return value
    return "utf-8"


def clean_value(value: Any) -> Any:
    if isinstance(value, (dt.date, dt.datetime)):
        return value.isoformat()
    if isinstance(value, str):
        return value.strip()
    return value


def shape_geometry(shape: shapefile.Shape) -> dict[str, Any] | None:
    points = shape.points
    if not points:
        return None

    parts = list(shape.parts) + [len(points)]
    lines = []
    for start, end in zip(parts, parts[1:]):
        line = [[float(x), float(y)] for x, y in points[start:end]]
        if len(line) >= 2:
            lines.append(line)

    if not lines:
        return None
    if len(lines) == 1:
        return {"type": "LineString", "coordinates": lines[0]}
    return {"type": "MultiLineString", "coordinates": lines}


def main() -> None:
    if not SHP_PATH.exists():
        raise FileNotFoundError(f"No existe el shapefile: {SHP_PATH}")

    reader = shapefile.Reader(str(SHP_PATH), encoding=cpg_encoding(SHP_PATH))
    fields = [field[0] for field in reader.fields[1:]]
    features = []

    for shape_record in reader.iterShapeRecords():
        geometry = shape_geometry(shape_record.shape)
        if geometry is None:
            continue

        properties = {
            key: clean_value(value)
            for key, value in zip(fields, list(shape_record.record))
        }

        if properties.get("COD_LINEA"):
            properties["CODIGO"] = properties["COD_LINEA"]
        if properties.get("NOMBRE"):
            properties["NOMBRE_LINEA"] = properties["NOMBRE"]
        if properties.get("TENSION_NO") not in (None, ""):
            properties["TENSION_KV"] = properties["TENSION_NO"]

        features.append({
            "type": "Feature",
            "properties": properties,
            "geometry": geometry,
        })

    collection = {
        "type": "FeatureCollection",
        "name": "LT_Operacionm",
        "source": "data/shapes/Transmision/Linea_transmision.shp",
        "generated_by": "scripts/convert_transmission_shp.py",
        "features": features,
    }

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(
        json.dumps(collection, ensure_ascii=False, separators=(",", ":")),
        encoding="utf-8",
    )
    print(f"GeoJSON generado: {OUT_PATH}")
    print(f"Features: {len(features)}")


if __name__ == "__main__":
    main()
