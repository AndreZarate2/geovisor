import math
import sys
import unittest
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from load_availability import analyze_substation_records


def capacity(code, ratings=(100, 100, 60)):
    return {
        "codigo": code,
        "empresa": "REP",
        "subestacion": "PIURA OESTE",
        "devanados": [
            {"lado": "Primaria", "tension_kv": 220, "potencia_mva": ratings[0]},
            {"lado": "Secundaria", "tension_kv": 60, "potencia_mva": ratings[1]},
            {"lado": "Terciaria", "tension_kv": 10, "potencia_mva": ratings[2]},
        ],
    }


def record(code, bus, when, p, q, voltage):
    return {
        "codigo_subestacion": "S00212",
        "codigo_transformador": code,
        "codigo_barra": bus,
        "fecha_dt": pd.Timestamp(when),
        "p_raw_mw": p,
        "q_raw_mvar": q,
        "tension_medida_kv": voltage,
        "demanda_mva": math.hypot(p, q),
    }


class AvailabilityTests(unittest.TestCase):
    def test_three_winding_balance_and_primary_limit(self):
        rows = [
            record("T117-261", "B0002", "2025-05-06 15:00", 72.373008, 32.730420, 222.6),
            record("T117-261", "B0084", "2025-05-06 15:00", -58.763248, -23.293096, 62.8),
            record("T117-261", "B0172", "2025-05-06 15:00", 13.366184, 3.932732, 10.4),
            record("T117-261", "B0002", "2025-05-15 19:45", 63.000824, 19.346928, 222.4),
            record("T117-261", "B0084", "2025-05-15 19:45", -41.648100, -9.178872, 62.5),
            record("T117-261", "B0172", "2025-05-15 19:45", 21.158428, 5.580256, 10.3),
        ]
        result = analyze_substation_records(pd.DataFrame(rows), [capacity("T117-261")], {})
        summary = result["transformers"][0]
        self.assertEqual(summary["potencias_nominales_mva"], "100/100/60")
        self.assertAlmostEqual(summary["capacidad_total_mva"], 100.0, places=3)
        self.assertAlmostEqual(summary["pico_primario_mva"], 79.4300, places=3)
        tertiary = next(row for row in result["rows"] if row["rol_devanado"] == "terciario")
        self.assertEqual(tertiary["restriccion"], "primario")
        self.assertEqual(tertiary["fecha_referencia_simultanea"], "2025-05-06 15:00")
        self.assertAlmostEqual(tertiary["demanda_simultanea_mva"], 13.9327, places=3)
        self.assertEqual(tertiary["restriccion_referencia"], "primario")
        self.assertAlmostEqual(tertiary["disponible_tecnico_mva"], 20.4574, places=3)
        self.assertAlmostEqual(tertiary["disponible_referencia_mva"], 20.4574, places=3)
        self.assertAlmostEqual(tertiary["disponible_planificacion_mva"], 10.5175, places=3)
        self.assertAlmostEqual(result["monthly"][0]["mva"], 79.4300, places=3)
        monthly_by_voltage = {float(item["tension_kv"]): item["mensual"] for item in result["monthly_by_voltage"]}
        self.assertEqual(set(monthly_by_voltage), {220.0, 60.0, 10.0})
        self.assertAlmostEqual(monthly_by_voltage[220.0][0]["mva"], 79.4300, places=3)
        self.assertAlmostEqual(monthly_by_voltage[60.0][0]["mva"], math.hypot(58.763248, 23.293096), places=3)
        self.assertAlmostEqual(monthly_by_voltage[10.0][0]["mva"], math.hypot(21.158428, 5.580256), places=3)
        self.assertEqual(monthly_by_voltage[10.0][0]["fecha"], "2025-05-15 19:45")
        self.assertAlmostEqual(result["set_summary"]["disponible_total_mva"], 20.4574, places=3)

    def test_independent_tertiary_peak_does_not_replace_simultaneous_condition(self):
        rows = [
            record("T117-261", "B0002", "2025-05-06 15:00", 72.373008, 32.730420, 222.6),
            record("T117-261", "B0084", "2025-05-06 15:00", -58.763248, -23.293096, 62.8),
            record("T117-261", "B0172", "2025-05-06 15:00", 13.366184, 3.932732, 10.4),
            record("T117-261", "B0002", "2025-05-15 19:45", 63.000824, 19.346928, 222.4),
            record("T117-261", "B0084", "2025-05-15 19:45", -41.648100, -9.178872, 62.5),
            record("T117-261", "B0172", "2025-05-15 19:45", 21.158428, 5.580256, 10.3),
        ]
        result = analyze_substation_records(pd.DataFrame(rows), [capacity("T117-261")], {})
        tertiary = next(row for row in result["rows"] if row["rol_devanado"] == "terciario")
        self.assertEqual(tertiary["fecha_maxima"], "2025-05-15 19:45")
        self.assertEqual(tertiary["fecha_critica"], "2025-05-06 15:00")
        self.assertEqual(tertiary["fecha_referencia_simultanea"], "2025-05-06 15:00")
        self.assertLess(tertiary["demanda_simultanea_mva"], tertiary["demanda_max_mva"])
        self.assertLess(result["monthly"][0]["mva"], 100.0)

    def test_overload_and_missing_tertiary_are_not_reported_as_available(self):
        rows = [
            record("T15-261", "B0002", "2025-10-25 15:15", 39.452860, 16.826756, 222.5),
            record("T15-261", "B0084", "2025-10-25 15:15", -38.507708, -13.786460, 61.2),
        ]
        result = analyze_substation_records(pd.DataFrame(rows), [capacity("T15-261", (40, 40, 24))], {})
        summary = result["transformers"][0]
        self.assertEqual(summary["estado"], "Sobrecarga observada")
        self.assertAlmostEqual(summary["pico_primario_mva"], 42.89135, places=3)
        secondary = next(row for row in result["rows"] if row["rol_devanado"] == "secundario")
        self.assertEqual(secondary["disponible_tecnico_mva"], 0.0)
        self.assertEqual(summary["balance"]["estado"], "parcial")

    def test_zero_filled_meter_is_not_interpreted_as_full_availability(self):
        rows = [
            record("T32-261", "B0002", "2025-05-01 00:15", 0.0, 0.0, 220.0),
            record("T32-261", "B0084", "2025-05-01 00:15", 0.0, 0.0, 60.0),
        ]
        result = analyze_substation_records(pd.DataFrame(rows), [capacity("T32-261", (40, 40, 24))], {})
        summary = result["transformers"][0]
        self.assertEqual(summary["estado"], "Sin medicion")
        self.assertIsNone(summary["disponible_total_mva"])
        self.assertTrue(all(row["disponible_tecnico_mva"] is None for row in result["rows"]))


if __name__ == "__main__":
    unittest.main()
