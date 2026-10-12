#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Build PDF reports for transmission line load analysis."""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any
from xml.sax.saxutils import escape

from zone_report_builder import add_body_paragraph, add_data_table, add_heading, add_kv_table, apply_dynamic_template_index, ceraunico_rows, load_doc, style_doc, template_active
from report_charts import demand_history_chart

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.lib.utils import ImageReader
from reportlab.platypus import PageBreak, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle


ROOT = Path(__file__).resolve().parents[1]
LOGO = ROOT / "assets" / "img" / "cvc-logo.png"


def clean_text(value: Any, limit: int | None = None) -> str:
    text = str(value or "")
    text = re.sub(r"\r\n?", "\n", text)
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text).strip()
    if limit and len(text) > limit:
        return text[: max(0, limit - 1)].rstrip() + "..."
    return text


def fmt(value: Any, digits: int = 2, dash: str = "-") -> str:
    try:
        if value is None or value == "":
            return dash
        return f"{float(value):,.{digits}f}"
    except Exception:
        return clean_text(value) or dash


def paragraph(value: Any, style: ParagraphStyle, limit: int | None = None) -> Paragraph:
    text = escape(clean_text(value, limit)).replace("\n", "<br/>")
    return Paragraph(text or "-", style)


def pdf_table(rows: list[list[Any]], styles: dict[str, ParagraphStyle], widths: list[float], header: bool = True) -> Table:
    cooked = []
    for idx, row in enumerate(rows):
        style = styles["CellHead"] if header and idx == 0 else styles["Cell"]
        cooked.append([paragraph(cell, style, 260) for cell in row])
    table = Table(cooked, colWidths=widths, repeatRows=1 if header else 0, splitByRow=1)
    table.setStyle(TableStyle([
        ("GRID", (0, 0), (-1, -1), 0.3, colors.HexColor("#C8D2E1")),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#E8EEF5")) if header else ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#E8EEF5")),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return table


def draw_page_header(canvas, doc) -> None:
    canvas.saveState()
    if LOGO.is_file():
        try:
            img = ImageReader(str(LOGO))
            canvas.drawImage(
                img,
                doc.pagesize[0] - doc.rightMargin - 3.8 * cm,
                doc.pagesize[1] - 1.35 * cm,
                width=3.8 * cm,
                height=1.0 * cm,
                preserveAspectRatio=True,
                mask="auto",
                anchor="ne",
            )
        except Exception:
            pass
    canvas.setStrokeColor(colors.HexColor("#D8E1EF"))
    canvas.setLineWidth(0.4)
    canvas.line(doc.leftMargin, doc.pagesize[1] - 1.55 * cm, doc.pagesize[0] - doc.rightMargin, doc.pagesize[1] - 1.55 * cm)
    canvas.setFont("Helvetica", 7)
    canvas.setFillColor(colors.HexColor("#64748B"))
    canvas.drawRightString(doc.pagesize[0] - doc.rightMargin, 0.85 * cm, f"Pagina {doc.page}")
    canvas.restoreState()


def build_pdf(data: dict[str, Any], out: Path) -> None:
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle("TitleBlue", parent=styles["Title"], alignment=TA_CENTER, textColor=colors.HexColor("#1F4E79"), fontSize=16, leading=20))
    styles.add(ParagraphStyle("Sub", parent=styles["BodyText"], alignment=TA_CENTER, textColor=colors.HexColor("#475569"), fontSize=8.7, leading=11))
    styles.add(ParagraphStyle("H", parent=styles["Heading2"], textColor=colors.HexColor("#1F4E79"), spaceBefore=10, spaceAfter=5, fontSize=12, leading=14))
    styles.add(ParagraphStyle("Small", parent=styles["BodyText"], leading=10, fontSize=7.8, textColor=colors.HexColor("#475569")))
    styles.add(ParagraphStyle("Cell", parent=styles["BodyText"], fontSize=7.0, leading=8.2, wordWrap="CJK", splitLongWords=True))
    styles.add(ParagraphStyle("CellHead", parent=styles["Cell"], fontName="Helvetica-Bold", textColor=colors.black, fontSize=7.0, leading=8.2))

    title_name = clean_text(data.get("codigo_consulta") or data.get("nombre_consulta") or "LT")
    generated = data.get("generated_at") or datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    operative = data.get("operativa") if isinstance(data.get("operativa"), dict) else {}
    latest = data.get("ultima") if isinstance(data.get("ultima"), dict) else {}
    max_record = data.get("maxima_registrada") if isinstance(data.get("maxima_registrada"), dict) else {}

    story: list[Any] = [
        Paragraph(f"INFORME DE CARGA OPERATIVA DE LINEA - {escape(title_name)}", styles["TitleBlue"]),
        Paragraph(f"Generado: {escape(str(generated))}", styles["Sub"]),
        Paragraph(f"Fuente: {escape(clean_text(data.get('archivo') or 'Potencia Aparente LLTT_2025'))}", styles["Sub"]),
        Spacer(1, 0.25 * cm),
    ]

    story.append(Paragraph("Resumen de consulta", styles["H"]))
    story.append(pdf_table([
        ["Codigo consultado", data.get("codigo_consulta"), "Nombre consultado", data.get("nombre_consulta")],
        ["Empresa", data.get("empresa_consulta"), "Registros evaluados", data.get("registros")],
        ["Criterio", data.get("criterio"), "Alerta", data.get("alerta") or "-"],
    ], styles, [3.1 * cm, 6.7 * cm, 3.1 * cm, 6.7 * cm], header=False))

    story.append(Paragraph("Carga operativa utilizada", styles["H"]))
    story.append(pdf_table([
        ["Nombre línea", "Código línea", "kV", "MVA nominal", "Carga MVA", "Uso %", "Fecha", "Observación"],
        [
            operative.get("nombre_linea"),
            operative.get("codigo_linea"),
            fmt(operative.get("tension_nominal_kv"), 2),
            fmt(operative.get("mva_nominal"), 2),
            fmt(operative.get("carga_mva"), 2),
            fmt(operative.get("fac_uso_pot"), 1),
            operative.get("fecha_maxima_demanda") or operative.get("periodo"),
            operative.get("observacion") or operative.get("estado_operativo"),
        ],
    ], styles, [4.2 * cm, 2.3 * cm, 1.3 * cm, 2.0 * cm, 2.0 * cm, 1.5 * cm, 2.2 * cm, 4.1 * cm]))

    if max_record:
        story.append(Paragraph("Carga maxima registrada", styles["H"]))
        story.append(pdf_table([
            ["Nombre linea", "Codigo linea", "Periodo", "Fecha maxima", "Carga MVA", "MVA nominal", "Uso %"],
            [
                max_record.get("nombre_linea"),
                max_record.get("codigo_linea"),
                max_record.get("periodo"),
                max_record.get("fecha_maxima_demanda"),
                fmt(max_record.get("carga_mva"), 2),
                fmt(max_record.get("mva_nominal"), 2),
                fmt(max_record.get("fac_uso_pot"), 1),
            ],
        ], styles, [4.4 * cm, 2.4 * cm, 2.3 * cm, 2.5 * cm, 2.0 * cm, 2.0 * cm, 2.0 * cm]))
        if data.get("mensaje_maxima"):
            story.append(paragraph(data.get("mensaje_maxima"), styles["Small"], 700))

    if latest and latest != operative:
        story.append(Paragraph("Último registro disponible", styles["H"]))
        story.append(pdf_table([
            ["Periodo", "Fecha", "Carga MVA", "Estado", "Observación"],
            [latest.get("periodo"), latest.get("fecha_maxima_demanda"), fmt(latest.get("carga_mva"), 2), latest.get("estado_operativo"), latest.get("observacion")],
        ], styles, [3.2 * cm, 3.2 * cm, 2.6 * cm, 3.2 * cm, 7.4 * cm]))

    excluded = data.get("excluidos_recientes") if isinstance(data.get("excluidos_recientes"), list) else []
    if excluded:
        story.append(Paragraph("Registros recientes excluidos", styles["H"]))
        rows = [["Periodo", "Fecha", "Carga MVA", "Estado", "Observación"]]
        for item in excluded:
            if isinstance(item, dict):
                rows.append([item.get("periodo"), item.get("fecha_maxima_demanda"), fmt(item.get("carga_mva"), 2), item.get("estado_operativo"), item.get("observacion")])
        story.append(pdf_table(rows, styles, [3.2 * cm, 3.2 * cm, 2.6 * cm, 3.2 * cm, 7.4 * cm]))

    series = data.get("serie") if isinstance(data.get("serie"), list) else []
    story.append(PageBreak())
    story.append(Paragraph("Gráfica de máxima demanda", styles["H"]))
    story.append(demand_history_chart(
        series,
        f"Máxima demanda mensual - {title_name}",
        "La curva azul usa registros validos; la linea naranja corresponde a la capacidad nominal de la linea.",
        capacity_mva=operative.get("mva_nominal") or max_record.get("mva_nominal"),
        width=18.0 * cm,
        height=8.8 * cm,
        max_points=24,
    ))
    story.append(Spacer(1, 0.12 * cm))
    story.append(paragraph(
        "Los puntos no validos se marcan en rojo y no se conectan con la curva operativa. La tabla conserva el detalle y la observacion de cada registro.",
        styles["Small"],
        700,
    ))
    if series:
        story.append(Paragraph("Serie historica de carga", styles["H"]))
        rows = [["Periodo", "Fecha", "MVA", "Valido", "Estado / observacion"]]
        for item in series[-36:]:
            if isinstance(item, dict):
                rows.append([
                    item.get("periodo"),
                    item.get("fecha"),
                    fmt(item.get("mva"), 2),
                    "Si" if item.get("valido") else "No",
                    item.get("observacion") or item.get("estado_operativo"),
                ])
        story.append(pdf_table(rows, styles, [3.0 * cm, 3.0 * cm, 2.0 * cm, 1.8 * cm, 9.8 * cm]))

    pt = data.get("pt_coes") if isinstance(data.get("pt_coes"), dict) else {}
    matches = pt.get("matches") if isinstance(pt.get("matches"), list) else []
    story.append(Paragraph("Plan de Transmisión COES 2027-2036", styles["H"]))
    if matches:
        rows = [["Anteproyecto", "Codigo", "Tipo", "kV", "Coincidencia", "Archivo"]]
        for item in matches[:10]:
            if isinstance(item, dict):
                rows.append([item.get("titulo"), item.get("codigo"), item.get("tipo"), item.get("tension_kv"), fmt(item.get("score"), 0) + "/100", item.get("archivo")])
        story.append(pdf_table(rows, styles, [6.0 * cm, 2.0 * cm, 3.0 * cm, 1.4 * cm, 2.4 * cm, 4.8 * cm]))
    else:
        story.append(paragraph(pt.get("message") or "Sin anteproyectos PT COES asociados.", styles["Small"]))

    story.append(Paragraph("Descargas atmosfericas CERAUNICO", styles["H"]))
    story.append(pdf_table(
        [["Indicador", "Valor"]] + ceraunico_rows(data),
        styles,
        [5.0 * cm, 14.6 * cm],
    ))

    story.append(Paragraph("Metodologia", styles["H"]))
    story.append(paragraph(
        "La carga principal se toma del ultimo registro operativo valido disponible. "
        "Los registros abiertos, fuera de servicio, desenergizados o dudosos se muestran como alerta y no reemplazan la carga operativa.",
        styles["Small"],
    ))

    doc = SimpleDocTemplate(
        str(out),
        pagesize=A4,
        rightMargin=1.4 * cm,
        leftMargin=1.4 * cm,
        topMargin=2.05 * cm,
        bottomMargin=1.35 * cm,
    )
    doc.build(story, onFirstPage=draw_page_header, onLaterPages=draw_page_header)


def build_docx(data: dict[str, Any], out: Path) -> None:
    title_name = clean_text(data.get("codigo_consulta") or data.get("nombre_consulta") or "LT")
    generated = data.get("generated_at") or datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    operative = data.get("operativa") if isinstance(data.get("operativa"), dict) else {}
    latest = data.get("ultima") if isinstance(data.get("ultima"), dict) else {}
    max_record = data.get("maxima_registrada") if isinstance(data.get("maxima_registrada"), dict) else {}
    template = clean_text(data.get("template")) or str(ROOT / "uploads" / "templates" / "zona_reporte.docx")
    project = f"Carga operativa de línea - {title_name}"
    meta = {
        "template": template,
        "project": project,
        "author": clean_text(data.get("author")) or "CVC Energía",
        "generated_at": generated,
        "preserve_template_body": True,
        "report_kind": "line",
    }
    doc = load_doc(meta)
    doc_data = dict(data)
    doc_data["meta"] = meta
    apply_dynamic_template_index(doc, doc_data, "line")
    style_doc(doc, project, preserve_header=template_active(meta))

    add_heading(doc, "INFORME DE CARGA OPERATIVA DE LÍNEA", 1)
    add_body_paragraph(doc, f"Línea evaluada: {title_name}. Generado: {generated}.")
    add_body_paragraph(
        doc,
        "Este informe resume la carga operativa usada para la linea consultada, el ultimo registro disponible, "
        "la maxima demanda registrada, las referencias PT COES asociadas y la exposicion referencial a descargas "
        "atmosfericas CERAUNICO cuando existe geometria de la linea.",
    )

    add_heading(doc, "1. Resumen de consulta", 2)
    add_kv_table(doc, [
        ("Codigo consultado", clean_text(data.get("codigo_consulta")) or "-"),
        ("Nombre consultado", clean_text(data.get("nombre_consulta")) or "-"),
        ("Empresa", clean_text(data.get("empresa_consulta")) or "-"),
        ("Registros evaluados", clean_text(data.get("registros")) or "-"),
        ("Criterio", clean_text(data.get("criterio")) or "-"),
        ("Alerta", clean_text(data.get("alerta")) or "-"),
        ("Fuente", clean_text(data.get("archivo") or "Potencia Aparente LLTT_2025")),
    ])

    add_heading(doc, "2. Carga operativa utilizada", 2)
    add_data_table(doc, ["Nombre linea", "Codigo", "kV", "MVA nominal", "Carga MVA", "Uso %", "Fecha", "Observacion"], [[
        clean_text(operative.get("nombre_linea")),
        clean_text(operative.get("codigo_linea")),
        fmt(operative.get("tension_nominal_kv"), 2),
        fmt(operative.get("mva_nominal"), 2),
        fmt(operative.get("carga_mva"), 2),
        fmt(operative.get("fac_uso_pot"), 1),
        clean_text(operative.get("fecha_maxima_demanda") or operative.get("periodo")),
        clean_text(operative.get("observacion") or operative.get("estado_operativo")),
    ]])

    add_heading(doc, "3. Carga maxima registrada", 2)
    if max_record:
        add_data_table(doc, ["Nombre linea", "Codigo", "Periodo", "Fecha maxima", "Carga MVA", "MVA nominal", "Uso %"], [[
            clean_text(max_record.get("nombre_linea")),
            clean_text(max_record.get("codigo_linea")),
            clean_text(max_record.get("periodo")),
            clean_text(max_record.get("fecha_maxima_demanda")),
            fmt(max_record.get("carga_mva"), 2),
            fmt(max_record.get("mva_nominal"), 2),
            fmt(max_record.get("fac_uso_pot"), 1),
        ]])
        if data.get("mensaje_maxima"):
            add_body_paragraph(doc, data.get("mensaje_maxima"))
    else:
        add_body_paragraph(doc, "No se encontro una carga maxima registrada para la linea consultada.")

    if latest and latest != operative:
        add_heading(doc, "4. Ultimo registro disponible", 2)
        add_data_table(doc, ["Periodo", "Fecha", "Carga MVA", "Estado", "Observacion"], [[
            clean_text(latest.get("periodo")),
            clean_text(latest.get("fecha_maxima_demanda")),
            fmt(latest.get("carga_mva"), 2),
            clean_text(latest.get("estado_operativo")),
            clean_text(latest.get("observacion")),
        ]])

    series = data.get("serie") if isinstance(data.get("serie"), list) else []
    add_heading(doc, "5. Serie historica de carga", 2)
    add_data_table(doc, ["Periodo", "Fecha", "MVA", "Valido", "Estado / observacion"], [[
        clean_text(item.get("periodo")),
        clean_text(item.get("fecha")),
        fmt(item.get("mva"), 2),
        "Si" if item.get("valido") else "No",
        clean_text(item.get("observacion") or item.get("estado_operativo")),
    ] for item in series[-36:] if isinstance(item, dict)] or [[
        "Sin serie", "-", "-", "-", "No se encontro serie historica para la linea consultada.",
    ]])

    pt = data.get("pt_coes") if isinstance(data.get("pt_coes"), dict) else {}
    matches = pt.get("matches") if isinstance(pt.get("matches"), list) else []
    add_heading(doc, "6. Plan de Transmision COES", 2)
    add_data_table(doc, ["Anteproyecto", "Codigo", "Tipo", "kV", "Coincidencia", "Archivo"], [[
        clean_text(item.get("titulo")),
        clean_text(item.get("codigo")),
        clean_text(item.get("tipo")),
        clean_text(item.get("tension_kv")),
        fmt(item.get("score"), 0) + "/100",
        clean_text(item.get("archivo")),
    ] for item in matches[:12] if isinstance(item, dict)] or [[
        "Sin registro PT", "-", "-", "-", "-", clean_text(pt.get("message")) or "No se detectaron anteproyectos PT COES asociados.",
    ]])

    add_heading(doc, "7. Descargas atmosfericas CERAUNICO", 2)
    add_kv_table(doc, [(row[0], row[1]) for row in ceraunico_rows(data)])

    add_heading(doc, "8. Metodologia", 2)
    add_body_paragraph(
        doc,
        "La carga principal se toma del ultimo registro operativo valido disponible. Los registros abiertos, fuera "
        "de servicio, desenergizados o dudosos se conservan como alerta y no reemplazan la carga operativa.",
    )
    doc.save(out)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--out-pdf")
    parser.add_argument("--out-docx")
    args = parser.parse_args()
    data = json.loads(Path(args.input).read_text(encoding="utf-8-sig"))
    if args.out_pdf:
        build_pdf(data, Path(args.out_pdf))
    if args.out_docx:
        build_docx(data, Path(args.out_docx))


if __name__ == "__main__":
    main()
