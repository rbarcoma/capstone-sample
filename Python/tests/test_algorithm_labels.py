"""Synthetic label-only regressions; never read or write beneficiary artifacts."""

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
import monthly_features
import predict_next_month
import train_model

LABEL = "Ridge (penalty 0.1)"


def synthetic_rows():
    values = [100, 160, 130, 180, 110, 170, 140, 190, 120, 180, 150, 200, 130, 190, 160, 210, 140]
    return [dict(year=2025 + (3 + i) // 12, month=(3 + i) % 12 + 1,
                 consumption_kwh=value, temperature=25 + i % 4, humidity=60 + i % 5,
                 rainfall=10 + i % 3, solar_irradiance=4 + (i % 3) * 0.5,
                 peak_demand_kw=15 + i % 4) for i, value in enumerate(values)]


class AlgorithmLabelTest(unittest.TestCase):
    def test_correct_label_preserves_prechange_synthetic_fit_holdout_and_cv_numbers(self):
        # References captured from the pre-label-change trainer in Docker, in
        # memory on these synthetic rows, not from beneficiary evaluation data.
        rows = monthly_features.supervised_rows(synthetic_rows())
        model = train_model.train_model(rows)
        self.assertEqual(model["model_type"], LABEL)
        for row, expected in zip(rows[-3:], [158.98420334649705, 205.88921184180006, 139.29247125805875]):
            self.assertAlmostEqual(train_model.predict_row(model, row), expected, places=8)
        training, testing = train_model.split_train_test(rows)
        self.assertEqual(train_model.evaluate(train_model.train_model(training), testing), {
            "mae": 57.7547, "rmse": 67.0422, "r2_score": -5.1995, "model_type": LABEL,
        })
        # Priority 3.4b-i changes only undefined/fold-average R² semantics.
        cv = train_model.cross_validate(training)
        self.assertEqual((cv["folds"], cv["average_mae"], cv["average_rmse"], cv["average_r2_score"]),
                         (5, 35.6764, 37.8021, -7.2446))
        self.assertEqual([(r["training_rows"], r["validation_rows"], r["mae"], r["rmse"], r["r2_score"])
                          for r in cv["fold_metrics"]], [
            (3, 1, 24.9092, 24.9092, None), (4, 1, 1.4401, 1.4401, None),
            (5, 1, 33.5403, 33.5403, None), (6, 1, 51.8077, 51.8077, None),
            (7, 4, 66.6847, 77.313, -7.2446),
        ])

    def test_trainer_model_metrics_and_prediction_link_correct_labels_in_temporary_storage(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            dataset, model_path, metrics_path = [root / name for name in ["synthetic.csv", "model.pkl", "metrics.json"]]
            rows = synthetic_rows()
            with dataset.open("w", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=rows[0].keys())
                writer.writeheader()
                writer.writerows(rows)
            with patch.object(sys, "argv", ["train_model.py", str(dataset), str(model_path), str(metrics_path)]):
                with contextlib.redirect_stdout(io.StringIO()):
                    train_model.main()
            model = pickle.loads(model_path.read_bytes())
            metrics = json.loads(metrics_path.read_text())
            self.assertEqual(model["model_type"], LABEL)
            self.assertEqual(metrics["model_type"], LABEL)
            self.assertEqual([metrics[k] for k in ["mae", "rmse", "r2_score"]], [57.7547, 67.0422, -5.1995])
            self.assertEqual(metrics["evaluation_periods"], ["2026-05", "2026-06", "2026-07", "2026-08"])
            before = {path: path.read_bytes() for path in [dataset, model_path, metrics_path]}
            output = io.StringIO()
            with patch.object(sys, "argv", ["predict_next_month.py", str(dataset), str(model_path), str(metrics_path)]):
                with patch.object(predict_next_month.subprocess, "run") as fallback, contextlib.redirect_stdout(output):
                    predict_next_month.main()
                    fallback.assert_not_called()
            prediction = json.loads(output.getvalue())
            self.assertEqual(prediction["model_type"], LABEL)
            self.assertEqual((prediction["year"], prediction["month"]), (2026, 9))
            self.assertEqual([prediction[k] for k in ["mae", "rmse", "r2_score"]], [57.7547, 67.0422, -5.1995])
            self.assertEqual({path: path.read_bytes() for path in before}, before)


if __name__ == "__main__":
    unittest.main()
