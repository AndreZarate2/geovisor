"""Electrical availability calculations for multi-winding transformers.

The module intentionally separates independent winding peaks from simultaneous
operating snapshots.  New-load headroom is calculated at every timestamp from
signed P/Q measurements and is limited by both the target winding and the
upstream primary winding.
"""

from __future__ import annotations

import math
from collections import defaultdict
from typing import Any

import numpy as np
import pandas as pd


DEFAULT_NEW_LOAD_PF = 0.95
PLANNING_FACTOR = 0.90
INCREMENTAL_EFFICIENCY = 0.99
MIN_FP_SAMPLES = 96

ROLE_ORDER = {"primario": 0, "secundario": 1, "terciario": 2, "otro": 9}
ROLE_LABEL = {
    "primario": "Primaria",
    "secundario": "Secundaria",
    "terciario": "Terciaria",
    "otro": "Otro devanado",
}


def _finite(value: Any) -> float | None:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) else None


def _round(value: Any, digits: int = 4) -> float | None:
    number = _finite(value)
    return round(number, digits) if number is not None else None


def _date_text(value: Any) -> str | None:
    if value is None or pd.isna(value):
        return None
    return pd.Timestamp(value).strftime("%Y-%m-%d %H:%M")


def _quantile(values: pd.Series, quantile: float) -> float | None:
    clean = pd.to_numeric(values, errors="coerce").dropna()
    if clean.empty:
        return None
    return float(clean.quantile(quantile, interpolation="linear"))


def _role_from_label(value: Any) -> str:
    text = str(value or "").strip().lower()
    if text.startswith("prim"):
        return "primario"
    if text.startswith("sec"):
        return "secundario"
    if text.startswith("ter"):
        return "terciario"
    return "otro"


def _headroom(p0: float, q0: float, limit_mva: float, pf: float, efficiency: float = 1.0) -> float:
    """Maximum incremental load MVA before |S0 + dS/efficiency| reaches limit."""
    if limit_mva <= 0 or efficiency <= 0:
        return 0.0
    if math.hypot(p0, q0) >= limit_mva - 1e-9:
        return 0.0
    pf = min(1.0, max(0.01, float(pf)))
    q_ratio = math.sqrt(max(0.0, 1.0 - pf * pf))
    dp = pf / efficiency
    dq = q_ratio / efficiency
    a = dp * dp + dq * dq
    b = 2.0 * (p0 * dp + q0 * dq)
    c = p0 * p0 + q0 * q0 - limit_mva * limit_mva
    discriminant = max(0.0, b * b - 4.0 * a * c)
    return max(0.0, (-b + math.sqrt(discriminant)) / (2.0 * a))


def _headroom_array(
    p0: np.ndarray,
    q0: np.ndarray,
    limit_mva: float,
    pf: float,
    efficiency: float = 1.0,
) -> np.ndarray:
    """Vectorized equivalent of ``_headroom`` for a complete time series."""
    if limit_mva <= 0 or efficiency <= 0:
        return np.zeros_like(p0, dtype=float)
    pf = min(1.0, max(0.01, float(pf)))
    q_ratio = math.sqrt(max(0.0, 1.0 - pf * pf))
    dp = pf / efficiency
    dq = q_ratio / efficiency
    a = dp * dp + dq * dq
    b = 2.0 * (p0 * dp + q0 * dq)
    c = p0 * p0 + q0 * q0 - limit_mva * limit_mva
    discriminant = np.maximum(0.0, b * b - 4.0 * a * c)
    result = np.maximum(0.0, (-b + np.sqrt(discriminant)) / (2.0 * a))
    return np.where((p0 * p0 + q0 * q0) >= (limit_mva * limit_mva - 1e-9), 0.0, result)


def _capacity_map(capacity_items: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    for item in capacity_items or []:
        code = str(item.get("codigo") or "").strip()
        if code:
            out[code.lower()] = item
    return out


def _match_capacity(code: str, capacity_items: list[dict[str, Any]]) -> dict[str, Any] | None:
    exact = _capacity_map(capacity_items).get(str(code).strip().lower())
    if exact:
        return exact
    compact = "".join(ch for ch in str(code).lower() if ch.isalnum())
    for item in capacity_items or []:
        candidate = "".join(ch for ch in str(item.get("codigo") or "").lower() if ch.isalnum())
        if compact and candidate and (compact == candidate or (min(len(compact), len(candidate)) >= 6 and (compact.endswith(candidate) or candidate.endswith(compact)))):
            return item
    return None


def _bar_voltage(group: pd.DataFrame, bar_code: str, tension_lookup: dict[str, Any]) -> float | None:
    measured = pd.to_numeric(group.get("tension_medida_kv"), errors="coerce") if "tension_medida_kv" in group else pd.Series(dtype=float)
    measured = measured[(measured > 0.1) & measured.notna()]
    if not measured.empty:
        return float(measured.median())
    raw = tension_lookup.get(str(bar_code).strip().lower())
    return _finite(raw)


def _winding_for_voltage(voltage: float | None, windings: list[dict[str, Any]]) -> dict[str, Any] | None:
    if voltage is None or not windings:
        return None
    candidates = [w for w in windings if _finite(w.get("tension_kv")) and _finite(w.get("potencia_mva"))]
    if not candidates:
        return None
    best = min(candidates, key=lambda w: abs(math.log(max(voltage, 0.001) / float(w["tension_kv"]))))
    nominal = float(best["tension_kv"])
    if abs(voltage - nominal) > max(2.0, nominal * 0.25):
        return None
    return best


def _infer_bar_meta(
    tr_df: pd.DataFrame,
    capacity: dict[str, Any] | None,
    tension_lookup: dict[str, Any],
) -> dict[str, dict[str, Any]]:
    windings = list((capacity or {}).get("devanados") or [])
    raw: list[dict[str, Any]] = []
    for bar_code, bar_df in tr_df.groupby("codigo_barra", dropna=False):
        code = str(bar_code).strip()
        voltage = _bar_voltage(bar_df, code, tension_lookup)
        winding = _winding_for_voltage(voltage, windings)
        p_nonzero = pd.to_numeric(bar_df["p_raw_mw"], errors="coerce")
        p_nonzero = p_nonzero[p_nonzero.abs() > 1e-6]
        p_median = float(p_nonzero.median()) if not p_nonzero.empty else 0.0
        raw.append({
            "barra": code,
            "tension_kv": voltage,
            "winding": winding,
            "polarity": 1.0 if p_median >= 0 else -1.0,
            "polarity_source": "mediana_p_activa" if not p_nonzero.empty else "sin_flujo_valido",
        })

    # If no capacity book match exists, voltage rank still identifies the primary.
    ranked = sorted([x for x in raw if x["tension_kv"] is not None], key=lambda x: x["tension_kv"], reverse=True)
    fallback_roles: dict[str, str] = {}
    for index, item in enumerate(ranked):
        fallback_roles[item["barra"]] = "primario" if index == 0 else ("secundario" if index == 1 else "terciario")

    out: dict[str, dict[str, Any]] = {}
    for item in raw:
        winding = item["winding"]
        role = _role_from_label(winding.get("lado")) if winding else fallback_roles.get(item["barra"], "otro")
        rating = _finite(winding.get("potencia_mva")) if winding else None
        nominal_voltage = _finite(winding.get("tension_kv")) if winding else item["tension_kv"]
        out[item["barra"]] = {
            **item,
            "rol": role,
            "lado": ROLE_LABEL.get(role, "Otro devanado"),
            "capacidad_mva": rating,
            "tension_nominal_kv": nominal_voltage,
        }
    return out


def _snapshot_at(normalized: pd.DataFrame, when: pd.Timestamp, bar_meta: dict[str, dict[str, Any]]) -> dict[str, Any]:
    rows = normalized[normalized["fecha_dt"] == when]
    snapshot_rows = []
    for bar_code, group in rows.groupby("codigo_barra", dropna=False):
        code = str(bar_code).strip()
        p = float(group["p_mw"].sum())
        q = float(group["q_mvar"].sum())
        s = math.hypot(p, q)
        meta = bar_meta.get(code, {})
        snapshot_rows.append({
            "barra": code,
            "rol_devanado": meta.get("rol"),
            "tension_kv": _round(meta.get("tension_nominal_kv"), 3),
            "p_mw": _round(p),
            "q_mvar": _round(q),
            "s_mva": _round(s),
            "factor_potencia": _round(abs(p) / s if s > 1e-9 else None),
        })
    snapshot_rows.sort(key=lambda row: (ROLE_ORDER.get(row.get("rol_devanado"), 9), -(row.get("tension_kv") or 0)))
    return {"fecha": _date_text(when), "barras": snapshot_rows}


def _critical_candidate(
    primary_series: pd.DataFrame,
    target_series: pd.DataFrame,
    primary_rating: float,
    target_rating: float,
    pf: float,
    factor: float,
) -> dict[str, Any] | None:
    joined = primary_series.join(target_series, how="inner", lsuffix="_pri", rsuffix="_tar").dropna()
    if joined.empty:
        return None
    p_primary = joined["p_mw_pri"].to_numpy(dtype=float, copy=False)
    q_primary = joined["q_mvar_pri"].to_numpy(dtype=float, copy=False)
    p_target = joined["p_mw_tar"].to_numpy(dtype=float, copy=False)
    q_target = joined["q_mvar_tar"].to_numpy(dtype=float, copy=False)
    primary_limit = primary_rating * factor
    target_limit = target_rating * factor
    h_primary = _headroom_array(p_primary, q_primary, primary_limit, pf, INCREMENTAL_EFFICIENCY)
    h_target = _headroom_array(p_target, q_target, target_limit, pf, 1.0)
    available = np.minimum(h_primary, h_target)
    utilization = np.maximum(
        np.hypot(p_primary, q_primary) / max(primary_limit, 1e-9),
        np.hypot(p_target, q_target) / max(target_limit, 1e-9),
    )
    minimum = float(np.nanmin(available))
    tied = np.flatnonzero(np.isclose(available, minimum, rtol=0.0, atol=1e-9))
    position = int(tied[np.nanargmax(utilization[tied])]) if len(tied) else int(np.nanargmin(available))
    return {
        "fecha": _date_text(joined.index[position]),
        "disponible_mva": float(available[position]),
        "disponible_mw": float(available[position] * pf),
        "restriccion": "primario" if h_primary[position] <= h_target[position] else "devanado_objetivo",
        "margen_primario_mva": float(h_primary[position]),
        "margen_devanado_mva": float(h_target[position]),
        "utilizacion_critica": float(utilization[position]),
    }


def _monthly_primary(primary_by_transformer: dict[str, pd.DataFrame]) -> tuple[list[dict[str, Any]], dict[str, Any] | None, dict[str, Any] | None]:
    if not primary_by_transformer:
        return [], None, None
    combined: pd.DataFrame | None = None
    for frame in primary_by_transformer.values():
        current = frame.rename(columns={"p_mw": "p", "q_mvar": "q"})[["p", "q"]]
        combined = current if combined is None else combined.add(current, fill_value=0.0)
    if combined is None or combined.empty:
        return [], None, None
    combined = combined.sort_index()
    combined["mva"] = (combined["p"].pow(2) + combined["q"].pow(2)).pow(0.5)
    monthly = []
    for period, group in combined.groupby(combined.index.to_period("M")):
        when = group["mva"].idxmax()
        row = group.loc[when]
        monthly.append({
            "periodo": str(period),
            "mes": pd.Timestamp(when).strftime("%m/%Y"),
            "fecha": _date_text(when),
            "mva": _round(row["mva"]),
            "p_mw": _round(row["p"]),
            "q_mvar": _round(row["q"]),
            "criterio": "Suma vectorial de devanados primarios; no incluye salidas",
        })
    peak_when = combined["mva"].idxmax()
    peak_row = combined.loc[peak_when]
    latest_when = combined.index.max()
    latest_row = combined.loc[latest_when]
    peak = {"fecha": _date_text(peak_when), "mva": _round(peak_row["mva"]), "p_mw": _round(peak_row["p"]), "q_mvar": _round(peak_row["q"])}
    latest = {"fecha": _date_text(latest_when), "mva": _round(latest_row["mva"]), "p_mw": _round(latest_row["p"]), "q_mvar": _round(latest_row["q"])}
    return monthly, peak, latest


def _monthly_by_voltage(voltage_parts: dict[float, list[pd.DataFrame]]) -> list[dict[str, Any]]:
    """Return measured monthly maxima for every nominal voltage level.

    P and Q are summed vectorially at each timestamp across the measured
    windings that share the same nominal voltage.  Each voltage then keeps its
    own monthly maximum; no primary-load allocation or interpolation is used.
    """
    output: list[dict[str, Any]] = []
    for voltage, frames in sorted(voltage_parts.items(), key=lambda item: item[0], reverse=True):
        combined: pd.DataFrame | None = None
        for frame in frames:
            current = frame.rename(columns={"p_mw": "p", "q_mvar": "q"})[["p", "q"]]
            combined = current if combined is None else combined.add(current, fill_value=0.0)
        if combined is None or combined.empty:
            continue
        combined = combined.sort_index()
        combined["mva"] = (combined["p"].pow(2) + combined["q"].pow(2)).pow(0.5)
        if float(combined["mva"].max()) <= 0.0001:
            continue
        monthly: list[dict[str, Any]] = []
        for period, group in combined.groupby(combined.index.to_period("M")):
            if group.empty or float(group["mva"].max()) <= 0.0001:
                continue
            when = group["mva"].idxmax()
            row = group.loc[when]
            monthly.append({
                "periodo": str(period),
                "mes": pd.Timestamp(when).strftime("%m/%Y"),
                "fecha": _date_text(when),
                "mva": _round(row["mva"]),
                "p_mw": _round(row["p"]),
                "q_mvar": _round(row["q"]),
                "criterio": "Maximo mensual del nivel; suma vectorial P+jQ de devanados medidos",
            })
        if monthly:
            output.append({
                "tension_kv": _round(voltage, 3),
                "etiqueta": f"{float(voltage):g} kV",
                "mensual": monthly,
                "criterio": "Medicion real por nivel de tension; no se reparte la entrada primaria",
            })
    return output


def enrich_primary_peak_reference(
    detail_rows: list[dict[str, Any]],
    transformer_summaries: list[dict[str, Any]],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Attach the easy-to-read operating point requested for the UI.

    Every winding is read at the exact timestamp of its transformer's maximum
    primary loading.  The all-period conservative headroom already present in
    ``disponible_tecnico_mva`` is intentionally preserved for reports.
    """
    summary_by_key: dict[tuple[str, str], dict[str, Any]] = {}
    for summary in transformer_summaries or []:
        transformer = str(summary.get("transformador") or "").strip()
        source = str(summary.get("archivo_quinceminutal") or "").strip()
        if transformer:
            summary_by_key[(source, transformer)] = summary
            summary_by_key.setdefault(("", transformer), summary)

    for row in detail_rows or []:
        transformer = str(row.get("transformador") or "").strip()
        source = str(row.get("archivo_quinceminutal") or "").strip()
        summary = summary_by_key.get((source, transformer)) or summary_by_key.get(("", transformer))
        snapshot = (summary or {}).get("condicion_simultanea_pico_primario") or {}
        snapshot_rows = snapshot.get("barras") if isinstance(snapshot.get("barras"), list) else []
        primary = next((item for item in snapshot_rows if item.get("rol_devanado") == "primario"), None)
        target = next((item for item in snapshot_rows if str(item.get("barra") or "") == str(row.get("barra") or "")), None)
        row["fecha_referencia_simultanea"] = snapshot.get("fecha") or (summary or {}).get("fecha_pico_primario")
        row["pico_primario_mva"] = _round((summary or {}).get("pico_primario_mva"))
        row["p_simultaneo_mw"] = _round((target or {}).get("p_mw"))
        row["q_simultaneo_mvar"] = _round((target or {}).get("q_mvar"))
        row["demanda_simultanea_mva"] = _round((target or {}).get("s_mva"))
        row["fp_simultaneo"] = _round((target or {}).get("factor_potencia"))
        row["disponible_referencia_mva"] = None
        row["disponible_planificacion_referencia_mva"] = None
        row["restriccion_referencia"] = None

        if (
            not primary
            or not target
            or str(row.get("estado") or "").lower().startswith("sin medic")
        ):
            continue
        primary_rating = _finite(row.get("capacidad_total_mva"))
        target_rating = _finite(row.get("capacidad_mva"))
        p_primary = _finite(primary.get("p_mw"))
        q_primary = _finite(primary.get("q_mvar"))
        p_target = _finite(target.get("p_mw"))
        q_target = _finite(target.get("q_mvar"))
        if None in {primary_rating, target_rating, p_primary, q_primary, p_target, q_target}:
            continue
        pf = _finite(row.get("fp_referencia_nueva_carga")) or DEFAULT_NEW_LOAD_PF
        primary_margin = _headroom(p_primary, q_primary, primary_rating, pf, INCREMENTAL_EFFICIENCY)
        target_margin = _headroom(p_target, q_target, target_rating, pf, 1.0)
        primary_planning = _headroom(p_primary, q_primary, primary_rating * PLANNING_FACTOR, pf, INCREMENTAL_EFFICIENCY)
        target_planning = _headroom(p_target, q_target, target_rating * PLANNING_FACTOR, pf, 1.0)
        row["disponible_referencia_mva"] = _round(min(primary_margin, target_margin))
        row["disponible_planificacion_referencia_mva"] = _round(min(primary_planning, target_planning))
        row["restriccion_referencia"] = "primario" if primary_margin <= target_margin else "devanado_objetivo"

    for summary in transformer_summaries or []:
        transformer = str(summary.get("transformador") or "").strip()
        source = str(summary.get("archivo_quinceminutal") or "").strip()
        candidates = [
            row for row in detail_rows or []
            if str(row.get("transformador") or "").strip() == transformer
            and (not source or str(row.get("archivo_quinceminutal") or "").strip() == source)
            and row.get("rol_devanado") in {"secundario", "terciario"}
            and row.get("disponible_referencia_mva") is not None
        ]
        best = max(candidates, key=lambda item: float(item.get("disponible_referencia_mva") or 0.0)) if candidates else None
        summary["disponible_referencia_mva"] = best.get("disponible_referencia_mva") if best else None
        summary["disponible_planificacion_referencia_mva"] = best.get("disponible_planificacion_referencia_mva") if best else None
        summary["barra_referencia"] = best.get("barra") if best else None
        summary["tension_referencia_kv"] = best.get("tension_kv") if best else None
        summary["restriccion_referencia"] = best.get("restriccion_referencia") if best else None
    return detail_rows, transformer_summaries


def analyze_substation_records(
    sub_df: pd.DataFrame,
    capacity_items: list[dict[str, Any]],
    tension_lookup: dict[str, Any],
) -> dict[str, Any]:
    """Return cache-safe analysis for one substation contained in one source file."""
    transformers: list[dict[str, Any]] = []
    detail_rows: list[dict[str, Any]] = []
    primary_by_transformer: dict[str, pd.DataFrame] = {}
    voltage_parts: dict[float, list[pd.DataFrame]] = defaultdict(list)

    for transformer_code, raw_tr_df in sub_df.groupby("codigo_transformador", dropna=False):
        code = str(transformer_code).strip()
        if not code:
            continue
        capacity = _match_capacity(code, capacity_items)
        bar_meta = _infer_bar_meta(raw_tr_df, capacity, tension_lookup)
        normalized_parts = []
        for bar_code, bar_df in raw_tr_df.groupby("codigo_barra", dropna=False):
            bar = str(bar_code).strip()
            meta = bar_meta.get(bar, {})
            part = bar_df.copy()
            polarity = float(meta.get("polarity") or 1.0)
            part["p_mw"] = pd.to_numeric(part["p_raw_mw"], errors="coerce") * polarity
            part["q_mvar"] = pd.to_numeric(part["q_raw_mvar"], errors="coerce") * polarity
            part["s_mva"] = (part["p_mw"].pow(2) + part["q_mvar"].pow(2)).pow(0.5)
            part["factor_potencia"] = part["p_mw"].abs() / part["s_mva"].replace(0, pd.NA)
            part["rol_devanado"] = meta.get("rol")
            normalized_parts.append(part)
        if not normalized_parts:
            continue
        normalized = pd.concat(normalized_parts, ignore_index=True)
        transformer_has_measurement = bool((normalized["s_mva"].fillna(0).abs() > 0.0001).any())

        for bar_code, bar_df in normalized.groupby("codigo_barra", dropna=False):
            bar = str(bar_code).strip()
            voltage = _finite((bar_meta.get(bar) or {}).get("tension_nominal_kv"))
            if voltage is None:
                continue
            series = bar_df.dropna(subset=["fecha_dt"]).groupby("fecha_dt")[["p_mw", "q_mvar"]].sum().sort_index()
            if not series.empty:
                voltage_parts[round(voltage, 3)].append(series)

        role_series: dict[str, pd.DataFrame] = {}
        for role, role_df in normalized.groupby("rol_devanado", dropna=False):
            if not role or role == "otro":
                continue
            role_series[str(role)] = role_df.groupby("fecha_dt")[["p_mw", "q_mvar"]].sum().sort_index()
        primary_series = role_series.get("primario")
        if primary_series is not None and not primary_series.empty:
            primary_by_transformer[code] = primary_series

        windings = list((capacity or {}).get("devanados") or [])
        ratings = {_role_from_label(w.get("lado")): _finite(w.get("potencia_mva")) for w in windings}
        rating_display = "/".join(f"{float(w['potencia_mva']):g}" for w in sorted(windings, key=lambda w: ROLE_ORDER.get(_role_from_label(w.get("lado")), 9)) if _finite(w.get("potencia_mva")))
        primary_rating = ratings.get("primario")

        primary_peak = None
        primary_snapshot = None
        if primary_series is not None and not primary_series.empty:
            primary_mva = (primary_series["p_mw"].pow(2) + primary_series["q_mvar"].pow(2)).pow(0.5)
            peak_when = primary_mva.idxmax()
            peak_row = primary_series.loc[peak_when]
            primary_peak = {
                "fecha": _date_text(peak_when),
                "p_mw": _round(peak_row["p_mw"]),
                "q_mvar": _round(peak_row["q_mvar"]),
                "s_mva": _round(primary_mva.loc[peak_when]),
                "factor_potencia": _round(abs(peak_row["p_mw"]) / primary_mva.loc[peak_when] if primary_mva.loc[peak_when] > 1e-9 else None),
            }
            primary_snapshot = _snapshot_at(normalized, peak_when, bar_meta)

        measured_output_roles = [role for role in ("secundario", "terciario") if role in role_series]
        balance = {"estado": "sin_balance", "intervalos": 0}
        if transformer_has_measurement and primary_series is not None and measured_output_roles:
            joined = primary_series.rename(columns={"p_mw": "p_primary", "q_mvar": "q_primary"})
            for role in measured_output_roles:
                joined = joined.join(role_series[role].rename(columns={"p_mw": f"p_{role}", "q_mvar": f"q_{role}"}), how="inner")
            if not joined.empty:
                p_outputs = sum((joined[f"p_{role}"] for role in measured_output_roles), start=pd.Series(0.0, index=joined.index))
                q_outputs = sum((joined[f"q_{role}"] for role in measured_output_roles), start=pd.Series(0.0, index=joined.index))
                dp = joined["p_primary"] - p_outputs
                dq = joined["q_primary"] - q_outputs
                expected_output_roles = [role for role in ("secundario", "terciario") if ratings.get(role)]
                complete = set(expected_output_roles).issubset(measured_output_roles)
                balance = {
                    "estado": "completo" if complete else "parcial",
                    "intervalos": int(len(joined)),
                    "roles_medidos": measured_output_roles,
                    "roles_esperados": expected_output_roles,
                    "residuo_p_mediana_mw": _round(dp.median()),
                    "residuo_p_p05_mw": _round(dp.quantile(0.05)),
                    "residuo_p_p95_mw": _round(dp.quantile(0.95)),
                    "residuo_q_mediana_mvar": _round(dq.median()),
                    "residuo_q_p05_mvar": _round(dq.quantile(0.05)),
                    "residuo_q_p95_mvar": _round(dq.quantile(0.95)),
                }

        transformer_rows = []
        for bar_code, bar_df in normalized.groupby("codigo_barra", dropna=False):
            bar = str(bar_code).strip()
            meta = bar_meta.get(bar, {})
            role = meta.get("rol") or "otro"
            rating = _finite(meta.get("capacidad_mva"))
            valid = bar_df.dropna(subset=["fecha_dt", "s_mva"])
            if valid.empty:
                continue
            peak_record = valid.loc[valid["s_mva"].idxmax()]
            threshold = max(0.5, (rating or 0.0) * 0.05)
            fp_values = valid.loc[valid["s_mva"] >= threshold, "factor_potencia"].dropna()
            fp_p05 = _quantile(fp_values, 0.05)
            fp_median = _quantile(fp_values, 0.50)
            fp_p95 = _quantile(fp_values, 0.95)
            if len(fp_values) >= MIN_FP_SAMPLES and fp_p05 is not None and 0.80 <= fp_p05 <= 1.0:
                fp_reference = fp_p05
                fp_source = "percentil_5_historico"
            else:
                fp_reference = DEFAULT_NEW_LOAD_PF
                fp_source = "supuesto_0_95_por_datos_insuficientes"

            technical = planning = None
            critical_snapshot = None
            target_series = valid.groupby("fecha_dt")[["p_mw", "q_mvar"]].sum().sort_index()
            bar_has_measurement = bool((valid["s_mva"].fillna(0).abs() > 0.0001).any())
            if bar_has_measurement and transformer_has_measurement and rating and role == "primario" and primary_series is not None:
                technical = _critical_candidate(primary_series, primary_series, rating, rating, fp_reference, 1.0)
                planning = _critical_candidate(primary_series, primary_series, rating, rating, fp_reference, PLANNING_FACTOR)
                if technical:
                    technical["restriccion"] = "primario"
            elif bar_has_measurement and transformer_has_measurement and rating and primary_rating and primary_series is not None and role in {"secundario", "terciario"}:
                technical = _critical_candidate(primary_series, target_series, primary_rating, rating, fp_reference, 1.0)
                planning = _critical_candidate(primary_series, target_series, primary_rating, rating, fp_reference, PLANNING_FACTOR)
            if technical and technical.get("fecha"):
                critical_snapshot = _snapshot_at(normalized, pd.Timestamp(technical["fecha"]), bar_meta)

            peak_s = float(peak_record["s_mva"])
            local_margin = rating - peak_s if rating is not None else None
            available_technical = technical.get("disponible_mva") if technical else None
            available_planning = planning.get("disponible_mva") if planning else None
            if not bar_has_measurement:
                state = "Sin medicion"
            elif rating is None:
                state = "Sin capacidad nominal"
            elif peak_s > rating + 1e-6:
                state = "Sobrecarga observada"
            elif available_technical is None:
                state = "Datos insuficientes"
            elif available_technical <= 1e-6:
                state = "Sin margen"
            else:
                state = "Disponible preliminar"

            row = {
                "transformador": code,
                "transformador_mapa": (capacity or {}).get("codigo"),
                "empresa_capacidad": (capacity or {}).get("empresa"),
                "subestacion_capacidad": (capacity or {}).get("subestacion"),
                "barra": bar,
                "tension_kv": str(_round(meta.get("tension_nominal_kv"), 3) or ""),
                "rol_devanado": role,
                "lado_transformador": meta.get("lado"),
                "capacidad_mva": _round(rating),
                "capacidad_total_mva": _round(primary_rating),
                "capacidad_modo": "primary_limited_per_winding",
                "capacidad_nota": "Potencia nominal del devanado; no se suma con otros devanados",
                "demanda_max_mva": _round(peak_s),
                "demanda_max_mw": _round(peak_record["p_mw"]),
                "demanda_max_mvar": _round(peak_record["q_mvar"]),
                "factor_potencia": _round(peak_record["factor_potencia"]),
                "fecha_maxima": _date_text(peak_record["fecha_dt"]),
                "margen_local_mva": _round(local_margin),
                "disponible_tecnico_mva": _round(available_technical),
                "disponible_planificacion_mva": _round(available_planning),
                "disponible_mva": _round(available_technical),
                "disponible_mw": _round(technical.get("disponible_mw") if technical else None),
                "fecha_critica": technical.get("fecha") if technical else None,
                "restriccion": technical.get("restriccion") if technical else None,
                "margen_primario_critico_mva": _round(technical.get("margen_primario_mva") if technical else None),
                "margen_devanado_critico_mva": _round(technical.get("margen_devanado_mva") if technical else None),
                "fp_referencia_nueva_carga": _round(fp_reference),
                "fp_referencia_fuente": fp_source,
                "fp_historico_p05": _round(fp_p05),
                "fp_historico_mediana": _round(fp_median),
                "fp_historico_p95": _round(fp_p95),
                "muestras_fp": int(len(fp_values)),
                "polaridad_medidor": int(meta.get("polarity") or 1),
                "polaridad_fuente": meta.get("polarity_source"),
                "estado": state,
                "fuente_capacidad": "EquiposTransformadores.xlsx" if capacity else "Sin coincidencia de capacidad",
                "sin_medicion_quinceminutal": False,
                "calidad_balance": balance.get("estado"),
                "condicion_critica": critical_snapshot,
            }
            transformer_rows.append(row)
            detail_rows.append(row)

        output_rows = [row for row in transformer_rows if row.get("rol_devanado") in {"secundario", "terciario"} and row.get("disponible_tecnico_mva") is not None]
        best_row = max(output_rows, key=lambda row: row.get("disponible_tecnico_mva") or 0.0) if output_rows else None
        overloaded = any(str(row.get("estado", "")).startswith("Sobrecarga") for row in transformer_rows)
        missing_capacity = primary_rating is None
        if not transformer_rows or not transformer_has_measurement:
            transformer_state = "Sin medicion"
        elif missing_capacity:
            transformer_state = "Sin capacidad nominal"
        elif overloaded:
            transformer_state = "Sobrecarga observada"
        elif best_row is None:
            transformer_state = "Datos insuficientes"
        else:
            transformer_state = "Disponible preliminar"
        transformers.append({
            "transformador": code,
            "transformador_mapa": (capacity or {}).get("codigo"),
            "potencias_nominales_mva": rating_display or None,
            "capacidad_primaria_mva": _round(primary_rating),
            "pico_primario_mva": _round((primary_peak or {}).get("s_mva")),
            "fecha_pico_primario": (primary_peak or {}).get("fecha"),
            "disponible_tecnico_mva": _round(best_row.get("disponible_tecnico_mva") if best_row else None),
            "disponible_planificacion_mva": _round(best_row.get("disponible_planificacion_mva") if best_row else None),
            "barra_recomendada": best_row.get("barra") if best_row else None,
            "tension_recomendada_kv": best_row.get("tension_kv") if best_row else None,
            "restriccion": best_row.get("restriccion") if best_row else None,
            "fp_referencia": best_row.get("fp_referencia_nueva_carga") if best_row else None,
            "estado": transformer_state,
            "criterio": "Mejor margen conectable en una barra individual; no suma capacidades de devanados",
            "capacidad_total_mva": _round(primary_rating),
            "demanda_total_mva": _round((primary_peak or {}).get("s_mva")),
            "disponible_total_mva": _round(best_row.get("disponible_tecnico_mva") if best_row else None),
            "balance": balance,
            "pico_primario": primary_peak,
            "condicion_simultanea_pico_primario": primary_snapshot,
        })

    detail_rows, transformers = enrich_primary_peak_reference(detail_rows, transformers)
    monthly, set_peak, latest = _monthly_primary(primary_by_transformer)
    monthly_by_voltage = _monthly_by_voltage(voltage_parts)
    candidate_rows = [row for row in detail_rows if row.get("rol_devanado") in {"secundario", "terciario"} and row.get("disponible_tecnico_mva") is not None]
    best_set_row = max(candidate_rows, key=lambda row: row.get("disponible_tecnico_mva") or 0.0) if candidate_rows else None
    reference_rows = [row for row in detail_rows if row.get("rol_devanado") in {"secundario", "terciario"} and row.get("disponible_referencia_mva") is not None]
    best_reference_row = max(reference_rows, key=lambda row: row.get("disponible_referencia_mva") or 0.0) if reference_rows else None
    primary_capacity_sum = sum(float(item.get("capacidad_primaria_mva") or 0) for item in transformers)
    no_measurement = not any(float(row.get("demanda_max_mva") or 0) > 0.0001 for row in detail_rows)
    set_summary = {
        "capacidad_primaria_instalada_mva": _round(primary_capacity_sum) if primary_capacity_sum else None,
        "pico_entrada_set_mva": _round((set_peak or {}).get("mva")),
        "fecha_pico_entrada_set": (set_peak or {}).get("fecha"),
        "disponible_tecnico_mva": _round(best_set_row.get("disponible_tecnico_mva") if best_set_row else None),
        "disponible_planificacion_mva": _round(best_set_row.get("disponible_planificacion_mva") if best_set_row else None),
        "transformador_recomendado": best_set_row.get("transformador") if best_set_row else None,
        "barra_recomendada": best_set_row.get("barra") if best_set_row else None,
        "tension_recomendada_kv": best_set_row.get("tension_kv") if best_set_row else None,
        "restriccion": best_set_row.get("restriccion") if best_set_row else None,
        "disponible_referencia_mva": _round(best_reference_row.get("disponible_referencia_mva") if best_reference_row else None),
        "disponible_planificacion_referencia_mva": _round(best_reference_row.get("disponible_planificacion_referencia_mva") if best_reference_row else None),
        "transformador_referencia": best_reference_row.get("transformador") if best_reference_row else None,
        "barra_referencia": best_reference_row.get("barra") if best_reference_row else None,
        "tension_referencia_kv": best_reference_row.get("tension_kv") if best_reference_row else None,
        "restriccion_referencia": best_reference_row.get("restriccion_referencia") if best_reference_row else None,
        "criterio": "Mejor barra individual medida; no suma margenes entre transformadores sin topologia de acoplamiento",
        "capacidad_firme_n_1_mva": None,
        "estado": "Sin medicion" if no_measurement else ("Disponible preliminar" if best_set_row and (best_set_row.get("disponible_tecnico_mva") or 0) > 0 else "Sin margen demostrado"),
        # Legacy aliases consumed by zone analysis and chat.
        "capacidad_total_mva": _round(primary_capacity_sum) if primary_capacity_sum else None,
        "demanda_total_mva": _round((set_peak or {}).get("mva")),
        "disponible_total_mva": _round(best_set_row.get("disponible_tecnico_mva") if best_set_row else None),
    }
    return {
        "metodologia_version": 2,
        "metodologia": {
            "descripcion": "Balance P+jQ sincronizado por transformador y restriccion simultanea de primario y devanado objetivo",
            "fp_nueva_carga": "Percentil 5 historico por barra cuando es valido; respaldo 0.95 inductivo",
            "factor_planificacion": PLANNING_FACTOR,
            "eficiencia_incremental": INCREMENTAL_EFFICIENCY,
            "advertencia": "Resultado preliminar de cargabilidad; no reemplaza flujo de carga, tension, cortocircuito ni N-1",
        },
        "transformers": transformers,
        "rows": detail_rows,
        "monthly": monthly,
        "monthly_by_voltage": monthly_by_voltage,
        "set_peak": set_peak,
        "ultimo_registro": latest,
        "set_summary": set_summary,
        "sin_medicion": no_measurement,
    }
