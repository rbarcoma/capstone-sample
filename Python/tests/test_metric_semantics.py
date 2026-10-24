"""Isolated evaluation metadata tests; no production dataset or artifact access."""
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
from test_algorithm_labels import synthetic_rows


class MetricSemanticsTest(unittest.TestCase):
    def evaluate(self, actual, predicted):
        with patch.object(train_model, "predict_row", side_effect=predicted):
            return train_model.evaluate({"model_type": "synthetic"},
                                        [{"consumption_kwh": value} for value in actual])

    def test_undefined_is_null_including_perfect_singleton_constant_and_empty_sets(self):
        for actual, predicted in [([1], [1]), ([1], [2]), ([3, 3], [3, 3]), ([3, 3], [1, 2]), ([], [])]:
            with self.subTest(actual=actual, predicted=predicted):
                metrics = self.evaluate(actual, predicted)
                self.assertIsNone(metrics["r2_score"])
                self.assertIsNone(json.loads(json.dumps(metrics))["r2_score"])
        self.assertEqual(self.evaluate([1], [1])["mae"], 0)
        self.assertIsNone(self.evaluate([], [])["mae"])

    def test_valid_zero_and_negative_r2_remain_numeric(self):
        self.assertEqual(self.evaluate([1, 3], [2, 2])["r2_score"], 0)
        self.assertEqual(self.evaluate([1, 3], [3, 1])["r2_score"], -3)
        self.assertEqual(self.evaluate([1, 3], [1, 3])["r2_score"], 1)

    def test_independently_calculated_mae_rmse_and_r2(self):
        metrics = self.evaluate([1, 2, 4], [2, 4, 2])
        self.assertEqual(metrics, {"mae": 1.6667, "rmse": 1.7321,
                                   "r2_score": -0.9286, "model_type": "synthetic"})

    def test_fold_averages_disclose_defined_counts_and_do_not_pool_or_fill_missing_scores(self):
        rows = monthly_features.supervised_rows(synthetic_rows())
        training, _ = train_model.split_train_test(rows)
        cv = train_model.cross_validate(training)
        self.assertEqual(cv["label"], "Cross-validation fold averages (not pooled)")
        self.assertEqual(cv["aggregation"], "unweighted_mean_of_defined_fold_scores")
        self.assertEqual(cv["defined_folds"], {"mae": 5, "rmse": 5, "r2_score": 1})
        self.assertEqual([fold["r2_score"] for fold in cv["fold_metrics"]], [None] * 4 + [-7.2446])
        self.assertEqual(cv["average_r2_score"], -7.2446)
        constant = [{**row, "consumption_kwh": 10} for row in training]
        cv = train_model.cross_validate(constant)
        self.assertEqual(cv["defined_folds"]["r2_score"], 0)
        self.assertIsNone(cv["average_r2_score"])
        self.assertEqual(train_model.average_metric([{"r2_score": None}, {"r2_score": 0}], "r2_score"), 0)

    def test_synthetic_fit_coefficients_features_scaling_and_split_stay_unchanged(self):
        # Frozen numerical reference for the existing 12-feature fixed-0.1 fit.
        rows = monthly_features.supervised_rows(synthetic_rows())
        model = train_model.train_model(rows)
        expected = [159.9999999999998, 3.2153052660571424, 8.813637883835142,
                    2.523542207626237, 7.980937063714518, -1.0276462147648708,
                    -1.0276462147648666, 2.5235422076261047, -27.461452861453783,
                    -0.7772567398119397, 10.810862328832066, 11.633386633770364,
                    -5.6031670619576435]
        self.assertEqual(model["features"], monthly_features.FEATURES)
        self.assertEqual(model["model_type"], "Ridge (penalty 0.1)")
        self.assertEqual(len(model["coefficients"]), 13)
        for actual, reference in zip(model["coefficients"], expected):
            self.assertAlmostEqual(actual, reference, places=8)
        training, testing = train_model.split_train_test(rows)
        self.assertEqual((len(training), len(testing)), (11, 4))
        self.assertEqual([train_model.period_label(row) for row in testing],
                         ["2026-05", "2026-06", "2026-07", "2026-08"])

    def test_temporary_artifact_stores_null_holdout_and_passes_metadata_without_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            dataset, model_path, metrics_path = [root / name for name in ("synthetic.csv", "model.pkl", "metrics.json")]
            rows = [{**row, "consumption_kwh": 100} for row in synthetic_rows()]
            with dataset.open("w", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=rows[0].keys())
                writer.writeheader()
                writer.writerows(rows)
            with patch.object(sys, "argv", ["train_model.py", str(dataset), str(model_path), str(metrics_path)]):
                with contextlib.redirect_stdout(io.StringIO()) as log:
                    train_model.main()
            self.assertIn("Cross-validation fold averages (not pooled):", log.getvalue())
            self.assertIn("R2 score: Unavailable", log.getvalue())
            self.assertIn("(0/5 defined R2 folds)", log.getvalue())
            metrics = json.loads(metrics_path.read_text())
            self.assertIsNone(metrics["r2_score"])
            self.assertEqual(metrics["metric_scope"], "chronological_holdout")
            self.assertEqual(metrics["metric_definitions"], train_model.metric_definitions())
            before = {path: path.read_bytes() for path in (dataset, model_path, metrics_path)}
            with patch.object(sys, "argv", ["predict_next_month.py", str(dataset), str(model_path), str(metrics_path)]):
                with patch.object(predict_next_month.subprocess, "run") as fallback, contextlib.redirect_stdout(io.StringIO()) as out:
                    predict_next_month.main()
                    fallback.assert_not_called()
            result = json.loads(out.getvalue())
            self.assertIsNone(result["r2_score"])
            self.assertEqual(result["metric_definitions"], metrics["metric_definitions"])
            self.assertEqual(result["metric_scope"], metrics["metric_scope"])
            self.assertEqual((result["year"], result["month"], result["predicted_consumption_kwh"]), (2026, 9, 100))
            self.assertEqual({path: path.read_bytes() for path in before}, before)
            self.assertEqual(pickle.loads(model_path.read_bytes())["model_type"], "Ridge (penalty 0.1)")


if __name__ == "__main__":
    unittest.main()
