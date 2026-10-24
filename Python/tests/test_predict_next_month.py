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
import predict_next_month as predictor
from monthly_features import FEATURE_VERSION


class NextMonthPredictionTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.dataset = self.root / "actual.csv"
        self.model = self.root / "model.pkl"
        self.metrics = self.root / "metrics.json"
        # A deterministic model makes it possible to verify that lag_1 is actual.
        with self.model.open("wb") as handle:
            pickle.dump({
                "feature_version": FEATURE_VERSION, "features": ["lag_1"], "means": {"lag_1": 0},
                "stds": {"lag_1": 1}, "coefficients": [10, 1],
            }, handle)

    def write_rows(self, periods, **last_overrides):
        rows = [dict(
            year=year, month=month, consumption_kwh=100 + index,
            temperature=25, humidity=70, rainfall=10,
            solar_irradiance=5, peak_demand_kw=20,
        ) for index, (year, month) in enumerate(periods)]
        rows[-1].update(last_overrides)
        with self.dataset.open("w", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=rows[0].keys())
            writer.writeheader()
            writer.writerows(rows)

    def predict(self):
        output = io.StringIO()
        with patch.object(sys, "argv", ["predict_next_month.py", str(self.dataset), str(self.model), str(self.metrics)]):
            with contextlib.redirect_stdout(output):
                predictor.main()
        return json.loads(output.getvalue())

    def test_september_forecasts_only_october_and_repeated_runs_do_not_advance(self):
        self.write_rows([(2026, month) for month in range(4, 10)])
        first = self.predict()
        self.assertEqual((first["year"], first["month"]), (2026, 10))
        self.assertEqual(first["previous_consumption_kwh"], 105)
        self.assertEqual(first["predicted_consumption_kwh"], 115)
        self.assertEqual(self.predict(), first)

    def test_october_actual_allows_november(self):
        self.write_rows([(2026, month) for month in range(4, 11)])
        result = self.predict()
        self.assertEqual((result["year"], result["month"]), (2026, 11))

    def test_missing_historical_months_do_not_change_the_horizon(self):
        self.write_rows([(2026, 1), (2026, 4), (2026, 9)])
        # The target stays October, but August actuals are required for lag_2.
        with self.assertRaisesRegex(ValueError, "Cannot forecast 2026-10"):
            self.predict()

    def test_latest_period_is_chronological_not_last_csv_row(self):
        self.write_rows([(2026, 9), (2026, 7), (2026, 8)])
        result = self.predict()
        self.assertEqual((result["year"], result["month"]), (2026, 10))
        self.assertEqual(result["previous_consumption_kwh"], 100)

    def test_december_rolls_over_to_january(self):
        self.write_rows([(2026, 10), (2026, 11), (2026, 12)])
        result = self.predict()
        self.assertEqual((result["year"], result["month"]), (2027, 1))

    def test_incomplete_or_invalid_october_cannot_advance_period(self):
        for value in ("", "nan", "inf", "invalid", "-1"):
            with self.subTest(consumption=value):
                self.write_rows([(2026, 7), (2026, 8), (2026, 9), (2026, 10)], consumption_kwh=value)
                result = self.predict()
                self.assertEqual((result["year"], result["month"]), (2026, 10))

    def test_zero_consumption_is_a_valid_actual(self):
        self.write_rows([(2026, 8), (2026, 9), (2026, 10)], consumption_kwh=0)
        result = self.predict()
        self.assertEqual((result["year"], result["month"]), (2026, 11))
        self.assertEqual(result["previous_consumption_kwh"], 0)

    def test_missing_label_uses_ridge_fallback_without_changing_prediction_or_artifacts(self):
        self.write_rows([(2026, month) for month in range(4, 10)])
        before = {path: path.read_bytes() for path in [self.dataset, self.model]}
        prediction = self.predict()
        self.assertEqual(prediction["model_type"], "Ridge (penalty 0.1)")
        self.assertEqual(prediction["predicted_consumption_kwh"], 115)
        self.assertEqual((prediction["year"], prediction["month"]), (2026, 10))
        self.assertEqual({path: path.read_bytes() for path in before}, before)
        self.assertFalse(self.metrics.exists())

    def test_saved_legacy_labels_are_not_rewritten_or_replaced_by_the_new_fallback(self):
        self.write_rows([(2026, month) for month in range(4, 10)])
        model = pickle.loads(self.model.read_bytes())
        model["model_type"] = "Linear Regression"
        self.model.write_bytes(pickle.dumps(model))
        for metrics in [None, {"model_type": "Legacy saved label", "mae": 7, "rmse": 8, "r2_score": -9}]:
            with self.subTest(metrics=metrics):
                if metrics is not None:
                    self.metrics.write_text(json.dumps(metrics))
                paths = [self.dataset, self.model] + ([self.metrics] if self.metrics.exists() else [])
                before = {path: path.read_bytes() for path in paths}
                prediction = self.predict()
                self.assertEqual(prediction["model_type"], metrics["model_type"] if metrics else "Linear Regression")
                self.assertEqual(prediction["predicted_consumption_kwh"], 115)
                self.assertEqual({path: path.read_bytes() for path in before}, before)


if __name__ == "__main__":
    unittest.main()
