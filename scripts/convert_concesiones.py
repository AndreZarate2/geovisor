import json
import struct
from pathlib import Path


BASE = Path(__file__).resolve().parents[1]
SRC = BASE / "data" / "shapes" / "Distribucion" / "Distribucion"
SOURCE_NAME = "Shapefile.zip"
OUT_DIR = BASE / "data" / "geojson"


def read_dbf(path):
    data = path.read_bytes()
    total = struct.unpack("<I", data[4:8])[0]
    header_len = struct.unpack("<H", data[8:10])[0]
    record_len = struct.unpack("<H", data[10:12])[0]

    fields = []
    offset = 32
    cursor = 1
    while data[offset] != 0x0D:
        raw = data[offset:offset + 32]
        name = raw[:11].split(b"\0", 1)[0].decode("latin1").strip()
        field_type = chr(raw[11])
        length = raw[16]
        decimals = raw[17]
        fields.append((name, field_type, cursor, length, decimals))
        cursor += length
        offset += 32

    rows = []
    for idx in range(total):
        record = data[header_len + idx * record_len:header_len + (idx + 1) * record_len]
        if not record or record[0:1] == b"*":
            continue
        item = {}
        for name, field_type, start, length, decimals in fields:
            raw_value = record[start:start + length].decode("utf-8", "replace").strip()
            if field_type in {"N", "F"} and raw_value:
                try:
                    item[name] = int(raw_value) if decimals == 0 else float(raw_value)
                except ValueError:
                    item[name] = raw_value
            else:
                item[name] = raw_value
        rows.append(item)
    return rows


def close_ring(points):
    if points and points[0] != points[-1]:
        return points + [points[0]]
    return points


def read_polygons(path):
    data = path.read_bytes()
    records = []
    pos = 100
    while pos < len(data):
        if pos + 8 > len(data):
            break
        rec_num, rec_words = struct.unpack(">2i", data[pos:pos + 8])
        pos += 8
        content_len = rec_words * 2
        content = data[pos:pos + content_len]
        pos += content_len
        if len(content) < 4:
            continue
        shape_type = struct.unpack("<i", content[:4])[0]
        if shape_type == 0:
            records.append(None)
            continue
        if shape_type not in {5, 15, 25}:
            raise ValueError(f"Tipo de geometría no soportado: {shape_type} en registro {rec_num}")

        num_parts = struct.unpack("<i", content[36:40])[0]
        num_points = struct.unpack("<i", content[40:44])[0]
        parts_offset = 44
        points_offset = parts_offset + num_parts * 4
        parts = list(struct.unpack(f"<{num_parts}i", content[parts_offset:points_offset]))
        xy = []
        for i in range(num_points):
            x, y = struct.unpack("<2d", content[points_offset + i * 16:points_offset + (i + 1) * 16])
            xy.append([round(x, 7), round(y, 7)])

        rings = []
        for idx, start in enumerate(parts):
            end = parts[idx + 1] if idx + 1 < len(parts) else len(xy)
            ring = close_ring(xy[start:end])
            if len(ring) >= 4:
                rings.append(ring)

        if not rings:
            records.append(None)
        elif len(rings) == 1:
            records.append({"type": "Polygon", "coordinates": [rings[0]]})
        else:
            records.append({"type": "MultiPolygon", "coordinates": [[ring] for ring in rings]})
    return records


def normalize_status(row):
    value = " ".join(str(row.get("SITUACION_", "")).upper().split())
    if "TRAM" in value:
        return "tramite"
    return "vigente"


def feature(row, geom):
    props = dict(row)
    props["ESTADO_VISOR"] = "En tramite" if normalize_status(row) == "tramite" else "Vigente"
    props["FUENTE"] = SOURCE_NAME
    return {"type": "Feature", "properties": props, "geometry": geom}


def main():
    rows = read_dbf(SRC.with_suffix(".dbf"))
    geometries = read_polygons(SRC.with_suffix(".shp"))
    if len(rows) != len(geometries):
        raise SystemExit(f"No coinciden registros DBF ({len(rows)}) y SHP ({len(geometries)})")

    buckets = {"vigente": [], "tramite": []}
    for row, geom in zip(rows, geometries):
        if not geom:
            continue
        buckets[normalize_status(row)].append(feature(row, geom))

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for key, features in buckets.items():
        payload = {"type": "FeatureCollection", "features": features}
        target = OUT_DIR / f"Concesiones_Distribucion_{key}.geojson"
        target.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
        print(f"{target.name}: {len(features)} elementos")


if __name__ == "__main__":
    main()
