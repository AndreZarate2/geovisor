"""Genera capas MIDAGRI individuales por departamento desde Departamento.zip."""

from __future__ import annotations

import json
import tempfile
from pathlib import Path

from import_geodata_archives import (
    CACHE_DIR,
    REGISTRY,
    SOURCE_DIR,
    convert_shapefiles,
    extract_shapefile_group,
    layer_id,
    load_registry,
    save_registry,
    shapefiles_in_zip,
    upsert_layer,
)


def main() -> int:
    zip_path = SOURCE_DIR / "Departamento.zip"
    if not zip_path.exists():
        raise FileNotFoundError(f"No se encontro {zip_path}")

    registry = load_registry()
    report = []
    with tempfile.TemporaryDirectory(prefix="cvc_midagri_dep_") as tmp_raw:
        tmp = Path(tmp_raw)
        for entry in shapefiles_in_zip(zip_path):
            dep_name = Path(entry).stem.upper()
            dep_id = layer_id(dep_name)
            lid = f"MIDAGRI_Dep_{dep_id}"
            shp = extract_shapefile_group(zip_path, entry, tmp / "Departamento")
            count = convert_shapefiles(
                [shp],
                CACHE_DIR / f"{lid}.geojson.gz",
                {"FUENTE": "MIDAGRI", "DEPARTAMENTO": dep_name},
            )
            upsert_layer(
                registry,
                {
                    "id": lid,
                    "nombre": dep_name.title(),
                    "tipo": "polygon",
                    "color": "#22c55e",
                    "grupo": "MIDAGRI / Departamentos",
                    "menu": "Agricola y Acuicultura",
                    "activa": False,
                },
            )
            report.append({"id": lid, "departamento": dep_name, "features": count})

    save_registry(registry)
    print(json.dumps({"status": "ok", "layers": report}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
