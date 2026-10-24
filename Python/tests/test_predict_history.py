"""Read-only fitted-output contract on synthetic actuals and temporary models."""
import contextlib
import csv
import io
import json
from pathlib import Path
import pickle
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from monthly_features import FEATURE_VERSION, FEATURES
import predict_history


class HistoricalFitTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.dataset = Path(self.temp.name) / "synthetic.csv"
        self.model_path = Path(self.temp.name) / "model.pkl"
        self.rows = [dict(year=2026, month=i + 1, consumption_kwh=value,
                          temperature=25, humidity=70, rainfall=10,
                          solar_irradiance=5, peak_demand_kw=20)
                     for i, value in enumerate([100, 0, 80, 60, 40, 20])]
        self.model = {"feature_version": FEATURE_VERSION, "features": FEATURES,
                      "means": {key: 0 for key in FEATURES}, "stds": {key: 1 for key in FEATURES},
                      "coefficients": [5] + [1 if key == "lag_1" else 0 for key in FEATURES]}

    def run_history(self):
        with self.dataset.open("w", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=self.rows[0].keys())
            writer.writeheader()
            writer.writerows(self.rows)
        self.model_path.write_bytes(pickle.dumps(self.model))
        before = {p: p.read_bytes() for p in (self.dataset, self.model_path)}
        with patch.object(sys, "argv", ["predict_history.py", str(self.dataset), str(self.model_path)]):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                predict_history.main()
        self.assertEqual({p: p.read_bytes() for p in before}, before)
        self.assertEqual(set(Path(self.temp.name).iterdir()), set(before))
        return json.loads(output.getvalue())

    def test_fitted_numbers_are_unchanged_and_never_named_forecasts_or_accuracy(self):
        rows = self.run_history()
        self.assertEqual([row["period"] for row in rows], ["2026-03", "2026-04", "2026-05", "2026-06"])
        self.assertEqual([row["fitted"] for row in rows], [5, 85, 65, 45])
        for row in rows:
            self.assertEqual(row["kind"], "in_sample_fit")
            self.assertNotIn("accuracy", row)
            self.assertNotIn("predicted", row)
            self.assertNotIn("issued_forecast", row)

    def test_zero_and_negative_fits_are_preserved_without_an_accuracy_complement(self):
        self.model["coefficients"][0] = 0
        rows = self.run_history()
        self.assertEqual(rows[0]["fitted"], 0)
        self.model["coefficients"][0] = -10
        rows = self.run_history()
        self.assertEqual(rows[0]["fitted"], -10)

    def test_calendar_gaps_are_not_filled_or_relabelled(self):
        self.rows.pop(3)  # No April actual: May/June lack required calendar lags.
        rows = self.run_history()
        self.assertEqual([row["period"] for row in rows], ["2026-03"])


if __name__ == "__main__":
    unittest.main()
