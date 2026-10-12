#!/usr/bin/env python3
import argparse
import json
import re
import unicodedata
import zipfile
from pathlib import Path
from xml.etree import ElementTree


def norm(value):
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    text = "".join(char for char in text if not unicodedata.combining(char))
    return re.sub(r"[^a-z0-9]+", "", text)


def workbook_sheets(path):
    with zipfile.ZipFile(path) as book:
        xml = book.read("xl/workbook.xml")
    root = ElementTree.fromstring(xml)
    return [node.attrib.get("name", "") for node in root.iter() if node.tag.endswith("}sheet")]


def validate(path, kind):
    ext = path.suffix.lower()
    if ext not in {".xlsx", ".xlsm"}:
        return {"ok": True}
    if not zipfile.is_zipfile(path):
        return {"ok": False, "message": "El archivo Office esta danado o no corresponde a su extension."}
    try:
        sheets = workbook_sheets(path)
    except Exception:
        return {"ok": False, "message": "No se pudo leer la estructura interna del archivo Excel."}
    if not sheets:
        return {"ok": False, "message": "El archivo Excel no contiene hojas utilizables."}
    if kind == "excel":
        normalized = [norm(sheet) for sheet in sheets]
        for required in ("subestacion", "transformador", "barra"):
            if not any(required in sheet for sheet in normalized):
                return {
                    "ok": False,
                    "message": f"El Excel maestro no contiene la hoja requerida: {required.title()}.",
                }
    return {"ok": True, "sheets": sheets}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--path", required=True)
    parser.add_argument("--kind", required=True)
    args = parser.parse_args()
    path = Path(args.path)
    if not path.is_file():
        result = {"ok": False, "message": "El archivo temporal no esta disponible."}
    else:
        result = validate(path, args.kind)
    print(json.dumps(result, ensure_ascii=True))


if __name__ == "__main__":
    main()
