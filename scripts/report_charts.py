#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Compact ReportLab charts shared by the SET and line-load PDF reports."""

from __future__ import annotations

import math
import re
from typing import Any

from reportlab.graphics.shapes import Circle, Drawing, Line, PolyLine, Rect, String
from reportlab.lib import colors


BLUE = colors.HexColor("#2F75B5")
BLUE_DARK = colors.HexColor("#1F4E79")
GREEN = colors.HexColor("#70AD47")
ORANGE = colors.HexColor("#ED7D31")
RED = colors.HexColor("#C00000")
GRAY = colors.HexColor("#A5A5A5")
GRID = colors.HexColor("#D9E2F3")
TEXT = colors.HexColor("#334155")
MUTED = colors.HexColor("#64748B")
PANEL = colors.HexColor("#F8FAFC")
SERIES_COLORS = [
    colors.HexColor("#2F75B5"),
    colors.HexColor("#70AD47"),
    colors.HexColor("#ED7D31"),
    colors.HexColor("#7030A0"),
    colors.HexColor("#00A6A6"),
    colors.HexColor("#C00000"),
]


def number(value: Any) -> float | None:
    try:
        if value is None or value == "":
            return None
        out = float(value)
        return out if math.isfinite(out) else None
    except (TypeError, ValueError):
        return None


def short(value: Any, limit: int = 34) -> str:
    text = " ".join(str(value or "").split())
    return text if len(text) <= limit else text[: max(1, limit - 3)].rstrip() + "..."


def nice_axis_max(value: float) -> tuple[float, float]:
    value = max(1.0, float(value))
    raw_step = value / 5.0
    magnitude = 10 ** math.floor(math.log10(raw_step))
    normalized = raw_step / magnitude
    if normalized <= 1:
        step = magnitude
    elif normalized <= 2:
        step = 2 * magnitude
    elif normalized <= 5:
        step = 5 * magnitude
    else:
        step = 10 * magnitude
    axis_max = math.ceil(value / step) * step
    return axis_max, step


def add_y_grid(
    drawing: Drawing,
    left: float,
    bottom: float,
    width: float,
    height: float,
    axis_max: float,
    step: float,
) -> None:
    ticks = max(1, int(round(axis_max / step)))
    for idx in range(ticks + 1):
        value = idx * step
        y = bottom + height * value / axis_max
        drawing.add(Line(left, y, left + width, y, strokeColor=GRID, strokeWidth=0.45))
        label = f"{value:.0f}" if step >= 1 else f"{value:.1f}"
        drawing.add(String(left - 6, y - 2.5, label, fontName="Helvetica", fontSize=6.2, fillColor=MUTED, textAnchor="end"))
    drawing.add(Line(left, bottom, left, bottom + height, strokeColor=GRAY, strokeWidth=0.7))
    drawing.add(Line(left, bottom, left + width, bottom, strokeColor=GRAY, strokeWidth=0.7))


def empty_chart(title: str, message: str, width: float, height: float) -> Drawing:
    drawing = Drawing(width, height)
    drawing.add(Rect(0.5, 0.5, width - 1, height - 1, fillColor=PANEL, strokeColor=GRID, strokeWidth=0.8))
    drawing.add(String(width / 2, height - 20, short(title, 92), fontName="Helvetica-Bold", fontSize=10.5, fillColor=BLUE_DARK, textAnchor="middle"))
    drawing.add(String(width / 2, height / 2, short(message, 100), fontName="Helvetica-Bold", fontSize=9, fillColor=MUTED, textAnchor="middle"))
    return drawing


def demand_history_chart(
    points: list[dict[str, Any]],
    title: str,
    subtitle: str = "",
    capacity_mva: Any = None,
    width: float = 520,
    height: float = 250,
    max_points: int = 24,
) -> Drawing:
    """Line chart for monthly maximum demand with an optional nominal limit."""
    normalized: list[dict[str, Any]] = []
    for item in points or []:
        if not isinstance(item, dict):
            continue
        value = number(item.get("mva") if item.get("mva") is not None else item.get("carga_mva"))
        label = short(item.get("periodo") or item.get("mes") or item.get("fecha") or "-", 16)
        valid = item.get("valido") is not False
        normalized.append({"value": value, "label": label, "valid": valid})
    if max_points > 0:
        normalized = normalized[-max_points:]
    measured = [item["value"] for item in normalized if item["value"] is not None and item["valid"]]
    capacity = number(capacity_mva)
    if not measured:
        return empty_chart(title, "Sin mediciones históricas válidas para graficar.", width, height)

    highest = max(measured + ([capacity] if capacity is not None else []))
    axis_max, step = nice_axis_max(highest * 1.12)
    drawing = Drawing(width, height)
    drawing.add(Rect(0.5, 0.5, width - 1, height - 1, fillColor=colors.white, strokeColor=GRID, strokeWidth=0.8))
    drawing.add(String(width / 2, height - 18, short(title, 105), fontName="Helvetica-Bold", fontSize=11, fillColor=BLUE_DARK, textAnchor="middle"))
    if subtitle:
        drawing.add(String(width / 2, height - 32, short(subtitle, 125), fontName="Helvetica", fontSize=6.8, fillColor=MUTED, textAnchor="middle"))

    left, right, bottom, top = 42.0, 15.0, 43.0, 62.0
    plot_width = width - left - right
    plot_height = height - bottom - top
    add_y_grid(drawing, left, bottom, plot_width, plot_height, axis_max, step)
    drawing.add(String(12, bottom + plot_height / 2, "MVA", fontName="Helvetica-Bold", fontSize=6.5, fillColor=TEXT, angle=90, textAnchor="middle"))

    count = len(normalized)
    x_step = plot_width / max(1, count - 1)
    x_positions = [left + (idx * x_step if count > 1 else plot_width / 2) for idx in range(count)]
    y_of = lambda value: bottom + plot_height * float(value) / axis_max

    if capacity is not None:
        y_capacity = y_of(capacity)
        cap_line = Line(left, y_capacity, left + plot_width, y_capacity, strokeColor=ORANGE, strokeWidth=1.8)
        cap_line.strokeDashArray = [5, 3]
        drawing.add(cap_line)
        drawing.add(String(left + plot_width - 2, y_capacity + 4, f"Capacidad nominal {capacity:.2f} MVA", fontName="Helvetica-Bold", fontSize=6.2, fillColor=ORANGE, textAnchor="end"))

    previous: tuple[float, float] | None = None
    valid_positions: list[tuple[int, float, float, float]] = []
    for idx, item in enumerate(normalized):
        value = item["value"]
        x = x_positions[idx]
        if value is None:
            previous = None
            continue
        y = y_of(value)
        if item["valid"]:
            if previous is not None:
                drawing.add(Line(previous[0], previous[1], x, y, strokeColor=BLUE, strokeWidth=2.0))
            drawing.add(Circle(x, y, 2.6, fillColor=BLUE, strokeColor=colors.white, strokeWidth=0.55))
            previous = (x, y)
            valid_positions.append((idx, x, y, value))
        else:
            drawing.add(Line(x - 3, y - 3, x + 3, y + 3, strokeColor=RED, strokeWidth=1.2))
            drawing.add(Line(x - 3, y + 3, x + 3, y - 3, strokeColor=RED, strokeWidth=1.2))
            previous = None

    label_every = max(1, math.ceil(count / 12))
    for idx, item in enumerate(normalized):
        if idx % label_every != 0 and idx != count - 1:
            continue
        drawing.add(String(x_positions[idx] + 1, bottom - 8, item["label"], fontName="Helvetica", fontSize=5.8, fillColor=MUTED, angle=35, textAnchor="end"))

    max_point = max(valid_positions, key=lambda item: item[3])
    last_point = valid_positions[-1]
    for point in {max_point, last_point}:
        _, x, y, value = point
        label_y = min(bottom + plot_height - 2, y + 7)
        drawing.add(String(x, label_y, f"{value:.2f}", fontName="Helvetica-Bold", fontSize=6.4, fillColor=BLUE_DARK, textAnchor="middle"))

    legend_y = height - 49
    drawing.add(Line(left, legend_y, left + 18, legend_y, strokeColor=BLUE, strokeWidth=2.0))
    drawing.add(Circle(left + 9, legend_y, 2.3, fillColor=BLUE, strokeColor=colors.white, strokeWidth=0.4))
    drawing.add(String(left + 23, legend_y - 2.2, "Máxima demanda válida", fontName="Helvetica", fontSize=6.4, fillColor=TEXT))
    if any(not item["valid"] and item["value"] is not None for item in normalized):
        x_legend = left + 130
        drawing.add(Line(x_legend - 3, legend_y - 3, x_legend + 3, legend_y + 3, strokeColor=RED, strokeWidth=1.1))
        drawing.add(Line(x_legend - 3, legend_y + 3, x_legend + 3, legend_y - 3, strokeColor=RED, strokeWidth=1.1))
        drawing.add(String(x_legend + 8, legend_y - 2.2, "Registro no válido", fontName="Helvetica", fontSize=6.4, fillColor=TEXT))
    return drawing


def voltage_demand_history_chart(
    groups: list[dict[str, Any]],
    title: str,
    subtitle: str = "",
    width: float = 740,
    height: float = 250,
    max_points: int = 24,
) -> Drawing:
    """Monthly maximum-demand curves, one measured series per voltage level."""
    normalized: list[dict[str, Any]] = []
    period_labels: dict[str, str] = {}
    period_order: list[str] = []
    for group in groups or []:
        if not isinstance(group, dict):
            continue
        voltage = number(group.get("tension_kv"))
        label = short(group.get("etiqueta") or (f"{voltage:g} kV" if voltage is not None else "Tensión s/d"), 22)
        values: dict[str, float] = {}
        for item in group.get("mensual", group.get("serie", [])) or []:
            if not isinstance(item, dict):
                continue
            period = str(item.get("periodo") or item.get("mes") or "").strip()
            value = number(item.get("mva"))
            if not period or value is None:
                continue
            values[period] = value
            period_labels[period] = short(item.get("mes") or period, 14)
            if period not in period_order:
                period_order.append(period)
        if values and max(values.values()) > 0.0001:
            normalized.append({"voltage": voltage, "label": label, "values": values})
    if not normalized:
        return empty_chart(title, "Sin mediciones mensuales por nivel de tensión para graficar.", width, height)

    def period_key(value: str) -> tuple[int, int, str]:
        match = re.match(r"^(\d{4})-(\d{1,2})$", value)
        if match:
            return int(match.group(1)), int(match.group(2)), value
        match = re.match(r"^(\d{1,2})/(\d{4})$", value)
        if match:
            return int(match.group(2)), int(match.group(1)), value
        return 9999, 99, value

    periods = sorted(set(period_order), key=period_key)
    if max_points > 0:
        periods = periods[-max_points:]
    all_values = [group["values"][period] for group in normalized for period in periods if period in group["values"]]
    if not all_values:
        return empty_chart(title, "Sin mediciones mensuales por nivel de tensión para el periodo mostrado.", width, height)

    axis_max, step = nice_axis_max(max(all_values) * 1.15)
    drawing = Drawing(width, height)
    drawing.add(Rect(0.5, 0.5, width - 1, height - 1, fillColor=colors.white, strokeColor=GRID, strokeWidth=0.8))
    drawing.add(String(width / 2, height - 18, short(title, 105), fontName="Helvetica-Bold", fontSize=11, fillColor=BLUE_DARK, textAnchor="middle"))
    if subtitle:
        drawing.add(String(width / 2, height - 32, short(subtitle, 130), fontName="Helvetica", fontSize=6.8, fillColor=MUTED, textAnchor="middle"))

    left, right, bottom, top = 42.0, 15.0, 43.0, 65.0
    plot_width = width - left - right
    plot_height = height - bottom - top
    add_y_grid(drawing, left, bottom, plot_width, plot_height, axis_max, step)
    drawing.add(String(12, bottom + plot_height / 2, "MVA", fontName="Helvetica-Bold", fontSize=6.5, fillColor=TEXT, angle=90, textAnchor="middle"))
    count = len(periods)
    x_step = plot_width / max(1, count - 1)
    x_positions = [left + (idx * x_step if count > 1 else plot_width / 2) for idx in range(count)]
    y_of = lambda value: bottom + plot_height * float(value) / axis_max

    legend_x = left
    legend_y = height - 49
    for series_idx, group in enumerate(normalized):
        color = SERIES_COLORS[series_idx % len(SERIES_COLORS)]
        drawing.add(Line(legend_x, legend_y, legend_x + 18, legend_y, strokeColor=color, strokeWidth=2.0))
        drawing.add(Circle(legend_x + 9, legend_y, 2.4, fillColor=color, strokeColor=colors.white, strokeWidth=0.4))
        drawing.add(String(legend_x + 23, legend_y - 2.2, group["label"], fontName="Helvetica-Bold", fontSize=6.5, fillColor=color))
        legend_x += max(82, 38 + len(group["label"]) * 4.2)

        previous: tuple[float, float] | None = None
        points: list[tuple[int, float, float, float]] = []
        for period_idx, period in enumerate(periods):
            value = group["values"].get(period)
            if value is None:
                previous = None
                continue
            x = x_positions[period_idx]
            y = y_of(value)
            if previous is not None:
                drawing.add(Line(previous[0], previous[1], x, y, strokeColor=color, strokeWidth=2.0))
            drawing.add(Circle(x, y, 2.6, fillColor=color, strokeColor=colors.white, strokeWidth=0.55))
            previous = (x, y)
            points.append((period_idx, x, y, value))
        if points:
            max_point = max(points, key=lambda item: item[3])
            last_point = points[-1]
            label_points = [(max_point, "max")]
            if last_point != max_point:
                label_points.append((last_point, "last"))
            for point, label_kind in label_points:
                _, x, y, value = point
                # Keep endpoint labels away from the Y-axis and separate adjacent
                # voltage series at the last month (for example 220/60 kV).
                offset = 7 if label_kind == "max" or series_idx % 2 == 0 else -9
                label_y = max(bottom + 2, min(bottom + plot_height - 1, y + offset))
                if x <= left + 1:
                    label_x, anchor = x + 5, "start"
                elif x >= left + plot_width - 1:
                    label_x, anchor = x - 5, "end"
                else:
                    label_x, anchor = x, "middle"
                drawing.add(String(label_x, label_y, f"{value:.2f}", fontName="Helvetica-Bold", fontSize=5.9, fillColor=color, textAnchor=anchor))

    label_every = max(1, math.ceil(count / 12))
    for idx, period in enumerate(periods):
        if idx % label_every != 0 and idx != count - 1:
            continue
        drawing.add(String(x_positions[idx] + 1, bottom - 8, period_labels.get(period, period), fontName="Helvetica", fontSize=5.8, fillColor=MUTED, angle=35, textAnchor="end"))
    return drawing


def transformer_availability_chart(
    summary: dict[str, Any],
    rows: list[dict[str, Any]],
    width: float = 740,
    height: float = 190,
) -> Drawing:
    """Grouped comparison of nameplate, simultaneous demand and incremental headroom."""
    transformer = short(summary.get("transformador") or "Transformador", 48)
    sorted_rows = sorted(
        [row for row in rows or [] if isinstance(row, dict)],
        key=lambda row: (-(number(row.get("tension_kv")) or -1), short(row.get("barra"))),
    )
    if not sorted_rows:
        return empty_chart(f"Disponibilidad de carga - {transformer}", "Sin barras asociadas para graficar.", width, height)

    values: list[float] = []
    for row in sorted_rows:
        for key in ("capacidad_mva", "demanda_simultanea_mva", "disponible_referencia_mva"):
            value = number(row.get(key))
            if value is not None:
                values.append(max(0.0, value))
    if not values:
        return empty_chart(f"Disponibilidad de carga - {transformer}", "Sin capacidad ni mediciones para graficar.", width, height)

    axis_max, step = nice_axis_max(max(values) * 1.16)
    drawing = Drawing(width, height)
    drawing.add(Rect(0.5, 0.5, width - 1, height - 1, fillColor=colors.white, strokeColor=GRID, strokeWidth=0.8))
    drawing.add(String(width / 2, height - 17, f"Disponibilidad de carga - {transformer}", fontName="Helvetica-Bold", fontSize=10.8, fillColor=BLUE_DARK, textAnchor="middle"))

    state = short(summary.get("estado") or "Estado por validar", 36)
    date = short(summary.get("fecha_pico_primario") or "sin fecha de pico primario", 32)
    transformer_without_measurement = "sin medic" in state.lower()
    subtitle = "Sin medición quinceminutal; se conserva la capacidad de placa." if transformer_without_measurement else f"Condición simultánea al pico primario: {date} | {state}"
    drawing.add(String(width / 2, height - 31, subtitle, fontName="Helvetica", fontSize=6.8, fillColor=MUTED, textAnchor="middle"))

    legend_y = height - 45
    legend = [(GRAY, "Capacidad"), (BLUE, "Demanda S"), (GREEN, "Disponible")]
    legend_x = 44
    for color, label in legend:
        drawing.add(Rect(legend_x, legend_y - 3.5, 9, 7, fillColor=color, strokeColor=color))
        drawing.add(String(legend_x + 13, legend_y - 2.2, label, fontName="Helvetica", fontSize=6.3, fillColor=TEXT))
        legend_x += 80

    left, right, bottom, top = 40.0, 12.0, 39.0, 57.0
    plot_width = width - left - right
    plot_height = height - bottom - top
    add_y_grid(drawing, left, bottom, plot_width, plot_height, axis_max, step)
    drawing.add(String(11, bottom + plot_height / 2, "MVA", fontName="Helvetica-Bold", fontSize=6.4, fillColor=TEXT, angle=90, textAnchor="middle"))

    group_width = plot_width / max(1, len(sorted_rows))
    bar_width = max(5.0, min(15.0, (group_width - 14.0) / 3.0))
    gap = 2.0
    bars_total = 3 * bar_width + 2 * gap
    y_of = lambda value: plot_height * max(0.0, float(value)) / axis_max

    for idx, row in enumerate(sorted_rows):
        center = left + group_width * (idx + 0.5)
        start = center - bars_total / 2
        capacity = number(row.get("capacidad_mva"))
        demand = number(row.get("demanda_simultanea_mva"))
        available = number(row.get("disponible_referencia_mva"))
        row_state = short(row.get("estado")).lower()
        without_measurement = (
            transformer_without_measurement
            or bool(row.get("sin_medicion_quinceminutal"))
            or "sin medic" in row_state
            or demand is None
        )
        series = [
            (capacity, GRAY),
            (None if without_measurement else demand, RED if demand is not None and capacity is not None and demand > capacity + 1e-6 else BLUE),
            (None if without_measurement else available, GREEN),
        ]
        for series_idx, (value, color) in enumerate(series):
            x = start + series_idx * (bar_width + gap)
            if value is None:
                if without_measurement and series_idx == 1:
                    drawing.add(String(center, bottom + 4, "Sin medición", fontName="Helvetica-Bold", fontSize=5.8, fillColor=MUTED, textAnchor="middle"))
                continue
            bar_height = y_of(value)
            drawing.add(Rect(x, bottom, bar_width, bar_height, fillColor=color, strokeColor=color, strokeWidth=0.5))
            label_y = min(bottom + plot_height - 1, bottom + bar_height + 3)
            drawing.add(String(x + bar_width / 2, label_y, f"{value:.2f}", fontName="Helvetica-Bold", fontSize=5.5, fillColor=color if color != GRAY else TEXT, textAnchor="middle"))

        tension = number(row.get("tension_kv"))
        tension_text = f"{tension:g} kV" if tension is not None else "kV s/d"
        bar_text = short(row.get("barra") or "sin barra", 19)
        drawing.add(String(center, 20, tension_text, fontName="Helvetica-Bold", fontSize=6.3, fillColor=TEXT, textAnchor="middle"))
        drawing.add(String(center, 10, bar_text, fontName="Helvetica", fontSize=5.9, fillColor=MUTED, textAnchor="middle"))
    return drawing
