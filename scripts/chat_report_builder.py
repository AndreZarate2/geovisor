#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Build PDF reports for the territorial AI chat."""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.lib.utils import ImageReader
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

ROOT = Path(__file__).resolve().parents[1]
LOGO = ROOT / "assets" / "img" / "cvc-logo.png"


def clean_text(value: Any) -> str:
    text = str(value or "")
    text = re.sub(r"\*\*([^*]+)\*\*", r"\1", text)
    text = re.sub(r"__([^_]+)__", r"\1", text)
    text = re.sub(r"`{1,3}", "", text)
    text = re.sub(r"^\s{0,3}#{1,6}\s*", "", text, flags=re.MULTILINE)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1 (\2)", text)
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def clip_text(value: Any, limit: int = 360) -> str:
    text = clean_text(value)
    if len(text) <= limit:
        return text
    return text[: max(0, limit - 1)].rstrip() + "..."


def para(text: Any, style: ParagraphStyle) -> Paragraph:
    safe = escape(clean_text(text)).replace("\n", "<br/>")
    return Paragraph(safe or "-", style)


def cell(value: Any, style: ParagraphStyle, limit: int = 320) -> Paragraph:
    return Paragraph(escape(clip_text(value, limit)).replace("\n", "<br/>") or "-", style)


def table(rows: list[list[str]], styles: dict[str, ParagraphStyle], widths: list[float] | None = None) -> Table:
    cooked = []
    for row_idx, row in enumerate(rows):
        row_style = styles["CellHead"] if row_idx == 0 else styles["Cell"]
        cooked.append([cell(item, row_style, 180 if row_idx == 0 else 420) for item in row])
    t = Table(cooked, colWidths=widths, repeatRows=1, splitByRow=1)
    t.setStyle(TableStyle([
        ("GRID", (0, 0), (-1, -1), 0.3, colors.HexColor("#C8D2E1")),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#E8EEF5")),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return t


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
    canvas.drawRightString(doc.pagesize[0] - doc.rightMargin, 0.9 * cm, f"Pagina {doc.page}")
    canvas.restoreState()


def build_pdf(data: dict[str, Any], out: Path) -> None:
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle("TitleBlue", parent=styles["Title"], alignment=TA_CENTER, textColor=colors.HexColor("#1F4E79"), fontSize=18, leading=22))
    styles.add(ParagraphStyle("Sub", parent=styles["BodyText"], alignment=TA_CENTER, textColor=colors.HexColor("#475569"), fontSize=9, leading=12))
    styles.add(ParagraphStyle("H", parent=styles["Heading2"], textColor=colors.HexColor("#1F4E79"), spaceBefore=12, spaceAfter=6))
    styles.add(ParagraphStyle("BodyJ", parent=styles["BodyText"], alignment=TA_JUSTIFY, leading=13, fontSize=9.5))
    styles.add(ParagraphStyle("Small", parent=styles["BodyText"], leading=11, fontSize=8, textColor=colors.HexColor("#475569")))
    styles.add(ParagraphStyle("Cell", parent=styles["BodyText"], fontSize=6.5, leading=7.8, wordWrap="CJK", splitLongWords=True))
    styles.add(ParagraphStyle("CellHead", parent=styles["Cell"], fontName="Helvetica-Bold", textColor=colors.black, fontSize=6.7, leading=8.0))

    title = clean_text(data.get("title") or "Informe del Asistente IA territorial")
    question = clean_text(data.get("question") or "")
    answer = clean_text(data.get("answer") or "")
    generated = data.get("generated_at") or datetime.now().strftime("%Y-%m-%d %H:%M")

    story: list[Any] = [
        Paragraph("INFORME DEL ASISTENTE IA TERRITORIAL", styles["TitleBlue"]),
        Paragraph(escape(title), styles["Sub"]),
        Paragraph(f"Generado: {escape(str(generated))}", styles["Sub"]),
        Spacer(1, 0.35 * cm),
    ]

    story.append(Paragraph("Consulta", styles["H"]))
    story.append(para(question or "Consulta general del usuario.", styles["BodyJ"]))
    story.append(Paragraph("Respuesta / análisis", styles["H"]))
    story.append(para(answer or "Sin respuesta disponible.", styles["BodyJ"]))

    opportunities = data.get("opportunities") if isinstance(data.get("opportunities"), list) else []
    if opportunities:
        story.append(Paragraph("Polígonos y oportunidades graficadas", styles["H"]))
        rows = [["Zona", "Viabilidad", "Demanda", "Generacion", "SET / modo"]]
        for item in opportunities[:8]:
            alts = item.get("alternativas_generacion") if isinstance(item.get("alternativas_generacion"), list) else []
            gen = "; ".join(
                f"{a.get('nombre') or a.get('fuente')} {a.get('prioridad','')} {a.get('score','')}/100"
                for a in alts[:3]
            )
            set_ref = item.get("set_referencia") if isinstance(item.get("set_referencia"), dict) else {}
            rows.append([
                clean_text(item.get("nombre")),
                f"{item.get('viabilidad','')} {item.get('score','')}/100",
                f"{item.get('demanda_kw_referencia','-')} kW",
                gen or clean_text(item.get("fuente_generacion_recomendada")),
                clean_text(set_ref.get("nombre") or item.get("modo_suministro") or "-"),
            ])
        story.append(table(rows, styles, [3.8 * cm, 2.1 * cm, 2.0 * cm, 5.8 * cm, 3.7 * cm]))

    web = data.get("web") if isinstance(data.get("web"), dict) else {}
    story.append(Paragraph("Conectividad e internet", styles["H"]))
    internet_text = web.get("summary") or (
        "El asistente puede usar busqueda web mediante OpenAI cuando la API responde correctamente. "
        "Si el servicio devuelve límite de tasa, el informe conserva el análisis local del geovisor."
    )
    story.append(para(internet_text, styles["Small"]))

    sources = data.get("sources") if isinstance(data.get("sources"), list) else []
    if sources:
        story.append(Paragraph("Fuentes consultadas", styles["H"]))
        rows = [["Fuente", "URL"]]
        for src in sources[:10]:
            if isinstance(src, dict):
                rows.append([clean_text(src.get("title") or src.get("name") or "Fuente"), clean_text(src.get("url") or "")])
            else:
                rows.append(["Fuente", clean_text(src)])
        story.append(table(rows, styles, [4.5 * cm, 12.9 * cm]))

    story.append(Paragraph("Nota de alcance", styles["H"]))
    story.append(para(
        "Este documento es un preanálisis para decisión territorial. No reemplaza estudios de campo, "
        "medicion de recursos renovables, revision legal, factibilidad definitiva ni aprobaciones de autoridad competente.",
        styles["Small"],
    ))

    doc = SimpleDocTemplate(str(out), pagesize=A4, rightMargin=1.7 * cm, leftMargin=1.7 * cm, topMargin=2.15 * cm, bottomMargin=1.45 * cm)
    doc.build(story, onFirstPage=draw_page_header, onLaterPages=draw_page_header)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--out-pdf", required=True)
    args = parser.parse_args()
    data = json.loads(Path(args.input).read_text(encoding="utf-8"))
    build_pdf(data, Path(args.out_pdf))


if __name__ == "__main__":
    main()
