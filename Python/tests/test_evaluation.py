import copy
import csv
import hashlib
import json
import math
import os
import random
from decimal import Decimal, localcontext
from unittest.mock import patch
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import evaluation
import model_candidates
import train_model
from monthly_features import build_forecast_input, supervised_rows


def actuals(count=20):
    return [dict(year=2024 + index // 12, month=1 + index % 12,
                 consumption_kwh=100 + index * 7 + (index % 3) * 11,
                 temperature=20 + index / 10, humidity=70 + index / 10,
                 rainfall=15 + index, solar_irradiance=4 + index / 100,
                 peak_demand_kw=25 + index)
            for index in range(count)]


def write_csv(path, rows):
    with path.open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=rows[0].keys())
        writer.writeheader()
        writer.writerows(rows)


class PooledMetricsTest(unittest.TestCase):
    def test_independent_pooled_error_calculation(self):
        result = evaluation.pooled_metrics([1, 2, 4], [1, 4, 7])
        self.assertEqual(result["count"], 3)
        self.assertEqual(result["mae"], round(5 / 3, 4))
        self.assertEqual(result["rmse"], round(math.sqrt(13 / 3), 4))
        self.assertEqual(result["r2_score"], round(1 - 13 / (14 / 3), 4))
        self.assertLess(result["r2_score"], 0)

    def test_single_constant_and_empty_r2_are_unavailable(self):
        for actual, predicted in (([5], [0]), ([5, 5], [0, 1]), ([], [])):
            with self.subTest(actual=actual):
                result = evaluation.pooled_metrics(actual, predicted)
                self.assertIsNone(result["r2_score"])
                if not actual:
                    self.assertIsNone(result["mae"])
                    self.assertIsNone(result["rmse"])

    def test_metrics_reject_nonfinite_values_and_unmatched_counts(self):
        for actual, predicted in (([1], []), ([float("nan")], [1]), ([1], [float("inf")])):
            with self.subTest(actual=actual):
                with self.assertRaises(ValueError):
                    evaluation.pooled_metrics(actual, predicted)


class BaselineTest(unittest.TestCase):
    def test_baselines_use_only_available_history_and_exact_calendar_windows(self):
        rows = actuals(15)
        target = evaluation.baseline_predictions(rows, 2025, 1)
        self.assertEqual(target["last_observation"], rows[11]["consumption_kwh"])
        self.assertAlmostEqual(target["history_mean"], sum(row["consumption_kwh"] for row in rows[:12]) / 12)
        self.assertAlmostEqual(target["recent_mean_3"], sum(row["consumption_kwh"] for row in rows[9:12]) / 3)
        self.assertAlmostEqual(target["recent_mean_6"], sum(row["consumption_kwh"] for row in rows[6:12]) / 6)
        self.assertEqual(target["seasonal_naive"], rows[0]["consumption_kwh"])
        altered = copy.deepcopy(rows)
        for row in altered[12:]:
            row["consumption_kwh"] = 999999
        self.assertEqual(target, evaluation.baseline_predictions(altered, 2025, 1))

    def test_gaps_do_not_substitute_row_averages_or_fabricate_seasonality(self):
        rows = actuals(12)
        del rows[10]  # November missing before the January target.
        result = evaluation.baseline_predictions(rows, 2025, 1)
        self.assertIsNone(result["recent_mean_3"])
        self.assertIsNone(result["recent_mean_6"])
        self.assertEqual(result["seasonal_naive"], rows[0]["consumption_kwh"])
        self.assertIsNone(evaluation.baseline_predictions(rows[1:], 2025, 1)["seasonal_naive"])
        self.assertTrue(all(value is None for value in evaluation.baseline_predictions([], 2025, 1).values()))


class WalkForwardTest(unittest.TestCase):
    def test_every_origin_refits_on_earlier_rows_and_reuses_shared_inputs(self):
        rows = actuals(17)
        fitted, predicted_inputs = [], []

        def fit(training):
            fitted.append(copy.deepcopy(training))
            return train_model.train_model(training)

        def predict(model, inputs):
            predicted_inputs.append(inputs.copy())
            return train_model.predict_row(model, inputs)

        report = evaluation.walk_forward(rows, fit_model=fit, predict_model=predict)
        self.assertEqual(len(fitted), 9)
        self.assertEqual(report["origins"][0]["target_period"], "2024-09")
        self.assertEqual(report["origins"][-1]["target_period"], "2025-05")
        for offset, origin in enumerate(report["origins"]):
            history = rows[:8 + offset]
            self.assertEqual(fitted[offset], supervised_rows(history))
            self.assertEqual(origin["training_actual_rows"], 8 + offset)
            self.assertEqual(origin["training_eligible_rows"], 6 + offset)
            self.assertEqual(origin["training_cutoff"], evaluation.period(history[-1]))
            self.assertTrue(all(period < origin["target_period"] for period in origin["training_periods"]))
            target = rows[8 + offset]
            self.assertEqual(predicted_inputs[offset], build_forecast_input(history, target["year"], target["month"]))

    def test_common_origin_comparisons_have_identical_periods_and_pooled_metrics(self):
        report = evaluation.walk_forward(actuals(17))
        main = report["comparisons"]["nonseasonal_common"]
        seasonal = report["comparisons"]["seasonal_common"]
        self.assertEqual(len(main["evaluation_periods"]), 9)
        self.assertEqual(len(seasonal["evaluation_periods"]), 5)
        self.assertEqual(seasonal["evaluation_periods"], [f"2025-{month:02d}" for month in range(1, 6)])
        self.assertEqual(len(seasonal["excluded_periods"]), 4)
        for name, comparison in (("nonseasonal_common", main), ("seasonal_common", seasonal)):
            for candidate, metrics in comparison["metrics"].items():
                origins = [row for row in report["origins"] if row["target_period"] in comparison["evaluation_periods"]]
                self.assertEqual(metrics["count"], len(origins))
                errors = [row["actual"] - row["predictions"][candidate] for row in origins]
                self.assertEqual(metrics["mae"], round(sum(abs(error) for error in errors) / len(errors), 4))
                self.assertEqual(metrics["rmse"], round(math.sqrt(sum(error * error for error in errors) / len(errors)), 4))
                self.assertEqual(comparison, report["comparisons"][name])
        self.assertFalse(report["model_selection"]["performed"])

    def test_pooled_r2_is_defined_even_when_individual_origins_have_one_target(self):
        report = evaluation.walk_forward(actuals(11))
        origins = report["origins"]
        self.assertEqual(len(origins), 3)
        for origin in origins:
            self.assertIsNone(evaluation.pooled_metrics([origin["actual"]], [origin["predictions"]["last_observation"]])["r2_score"])
        pooled = report["comparisons"]["nonseasonal_common"]["metrics"]["last_observation"]
        self.assertIsNotNone(pooled["r2_score"])
        squared_errors = sum(row["errors"]["last_observation"] ** 2 for row in origins)
        mean = sum(row["actual"] for row in origins) / len(origins)
        variance = sum((row["actual"] - mean) ** 2 for row in origins)
        self.assertEqual(pooled["r2_score"], round(1 - squared_errors / variance, 4))

    def test_changing_target_or_future_actuals_cannot_change_an_earlier_prediction(self):
        rows = actuals(18)
        altered = copy.deepcopy(rows)
        for row in altered[12:]:
            for key in ("consumption_kwh", "temperature", "humidity", "rainfall", "solar_irradiance", "peak_demand_kw"):
                row[key] *= 1000
        original = evaluation.walk_forward(rows)
        changed = evaluation.walk_forward(altered)
        before = {row["target_period"]: row for row in original["origins"]}
        after = {row["target_period"]: row for row in changed["origins"]}
        for target in before:
            if target <= "2025-01":
                self.assertEqual(before[target]["inputs"], after[target]["inputs"])
                self.assertEqual(before[target]["predictions"], after[target]["predictions"])
        # Once the changed January observation becomes available, later forecasts may change.
        self.assertNotEqual(before["2025-02"]["predictions"]["last_observation"], after["2025-02"]["predictions"]["last_observation"])

    def test_gap_skips_are_explicit_and_recent_means_require_complete_windows(self):
        rows = actuals(20)
        del rows[10]
        report = evaluation.walk_forward(rows)
        skipped = {row["target_period"]: row for row in report["skipped_origins"]}
        self.assertEqual(skipped["2024-12"]["reason"], "missing_calendar_lags")
        self.assertEqual(skipped["2025-01"]["reason"], "missing_calendar_lags")
        main = report["comparisons"]["nonseasonal_common"]
        self.assertIn("2025-02", main["excluded_periods"])
        self.assertNotIn("2025-02", main["evaluation_periods"])
        self.assertTrue(all(value["count"] == len(main["evaluation_periods"]) for value in main["metrics"].values()))

    def test_short_and_constant_series_report_unavailable_r2_honestly(self):
        short = evaluation.walk_forward(actuals(8))
        self.assertEqual(short["status"], "insufficient_history")
        self.assertEqual(short["origins"], [])
        self.assertTrue(all(value["count"] == 0 and value["r2_score"] is None
                            for value in short["comparisons"]["nonseasonal_common"]["metrics"].values()))
        rows = actuals(11)
        for row in rows:
            row["consumption_kwh"] = 0
        constant = evaluation.walk_forward(rows)
        for metrics in constant["comparisons"]["nonseasonal_common"]["metrics"].values():
            self.assertEqual(metrics["mae"], 0)
            self.assertEqual(metrics["rmse"], 0)
            self.assertIsNone(metrics["r2_score"])

    def test_no_outcome_driven_tuning_and_nonfinite_predictions_abort(self):
        count = 0

        def fit(training):
            nonlocal count
            count += 1
            return {}

        report = evaluation.walk_forward(actuals(11), fit_model=fit, predict_model=lambda model, inputs: 0)
        self.assertEqual(count, len(report["origins"]))
        with self.assertRaisesRegex(ValueError, "Nonfinite prediction"):
            evaluation.walk_forward(actuals(11), predict_model=lambda model, inputs: float("nan"))

    def test_invalid_minimum_and_duplicate_periods_are_rejected(self):
        for minimum in (0, -1, 1.5, True):
            with self.assertRaises(ValueError):
                evaluation.walk_forward(actuals(), minimum)
        rows = actuals()
        rows.append(rows[0].copy())
        with self.assertRaisesRegex(ValueError, "Duplicate actual months"):
            evaluation.walk_forward(rows)

    def test_shuffled_input_is_deterministic(self):
        rows = actuals(17)
        self.assertEqual(evaluation.walk_forward(rows), evaluation.walk_forward(list(reversed(rows))))


class EvaluationCommandTest(unittest.TestCase):
    def test_cli_provenance_is_complete_reproducible_and_writes_no_artifacts(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "dataset.csv"
            write_csv(path, actuals(17))
            before = path.read_bytes()
            env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}
            command = [sys.executable, str(Path(evaluation.__file__).resolve()), str(path)]
            first = subprocess.run(command, cwd=directory, env=env, check=True, capture_output=True, text=True)
            second = subprocess.run(command, cwd=directory, env=env, check=True, capture_output=True, text=True)
            self.assertEqual(first.stdout, second.stdout)
            report = json.loads(first.stdout)
            provenance = report["provenance"]
            self.assertEqual(provenance["dataset_sha256"], hashlib.sha256(before).hexdigest())
            self.assertEqual(provenance["available_actual_cutoff"], "2025-05")
            self.assertEqual(provenance["input_rows"], 17)
            self.assertEqual(provenance["rejected_input_rows"], 0)
            self.assertEqual(provenance["feature_version"], "calendar-past-only-v1")
            for key in ("feature_signature", "evaluation_signature", "training_signature", "configuration_sha256"):
                self.assertEqual(len(provenance[key]), 64)
            self.assertEqual(provenance["runtime"]["python"], __import__("platform").python_version())
            self.assertEqual(path.read_bytes(), before)
            self.assertEqual(list(Path(directory).iterdir()), [path])
            changed_config = evaluation.evaluate_dataset(path, 7)
            self.assertNotEqual(provenance["configuration_sha256"], changed_config["provenance"]["configuration_sha256"])

    def test_rejected_input_rows_are_disclosed(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "dataset.csv"
            rows = actuals(17)
            rows[-1]["consumption_kwh"] = ""
            write_csv(path, rows)
            report = evaluation.evaluate_dataset(path)
            self.assertEqual(report["provenance"]["input_rows"], 17)
            self.assertEqual(report["provenance"]["rejected_input_rows"], 1)
            self.assertEqual(report["actual_rows"], 16)

    def test_cli_invalid_configuration_fails_without_writing(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "dataset.csv"
            write_csv(path, actuals(17))
            result = subprocess.run([sys.executable, evaluation.__file__, str(path), "--min-training-rows", "0"],
                                    env={**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}, capture_output=True, text=True)
            self.assertEqual(result.returncode, 2)
            self.assertIn("positive integer", result.stderr)
            self.assertEqual(result.stdout, "")
            self.assertEqual(list(Path(directory).iterdir()), [path])


def full_rank_actuals(count=20):
    rows = actuals(count)
    random_source = random.Random(342)
    for index, row in enumerate(rows):
        row["consumption_kwh"] = 180 + 2 * index + random_source.uniform(-40, 40)
    return rows


def independent_decimal_fit(rows, inputs, penalty):
    """Independent 50-digit normal equations reference; production uses QR."""
    features = ("lag_1", "lag_2", "month_sin", "month_cos", "trend")
    with localcontext() as context:
        context.prec = 50
        decimal = lambda value: Decimal(str(value))
        means = {feature: sum(decimal(row[feature]) for row in rows) / len(rows) for feature in features}
        scales = {feature: (sum((decimal(row[feature]) - means[feature]) ** 2 for row in rows) / len(rows)).sqrt() or Decimal(1)
                  for feature in features}
        x = [[Decimal(1), *[(decimal(row[feature]) - means[feature]) / scales[feature] for feature in features]] for row in rows]
        y = [decimal(row["consumption_kwh"]) for row in rows]
        size = len(features) + 1
        augmented = [[sum(row[left] * row[right] for row in x) + (decimal(penalty) if left == right and left > 0 else 0)
                      for right in range(size)] + [sum(row[left] * target for row, target in zip(x, y))]
                     for left in range(size)]
        for column in range(size):
            pivot = max(range(column, size), key=lambda row: abs(augmented[row][column]))
            augmented[column], augmented[pivot] = augmented[pivot], augmented[column]
            divisor = augmented[column][column]
            augmented[column] = [value / divisor for value in augmented[column]]
            for row in range(size):
                if row != column:
                    factor = augmented[row][column]
                    augmented[row] = [left - factor * right for left, right in zip(augmented[row], augmented[column])]
        coefficients = [row[-1] for row in augmented]
        values = [Decimal(1), *[(decimal(inputs[feature]) - means[feature]) / scales[feature] for feature in features]]
        return float(sum(coefficient * value for coefficient, value in zip(coefficients, values)))


class ReducedCandidateTest(unittest.TestCase):
    def test_candidate_predictions_match_independent_ols_and_ridge_fits(self):
        rows = full_rank_actuals(18)
        training = supervised_rows(rows[:-1])
        inputs = build_forecast_input(rows[:-1], rows[-1]["year"], rows[-1]["month"])
        for name, penalty, label in ((model_candidates.OLS, 0, "Linear Regression"),
                                     (model_candidates.RIDGE, 0.1, "Ridge (penalty 0.1)")):
            with self.subTest(candidate=name):
                model = model_candidates.fit_candidate(training, name)
                self.assertEqual(model["model_type"], label)
                self.assertEqual(model["penalty"], penalty)
                self.assertEqual(model["features"], ["lag_1", "lag_2", "month_sin", "month_cos", "trend"])
                prediction = model_candidates.predict_row(model, inputs)
                self.assertAlmostEqual(prediction, independent_decimal_fit(training, inputs, penalty), places=7)

    def test_identifiability_rule_applies_to_ols_and_ridge_before_solving(self):
        rows = supervised_rows(full_rank_actuals(8))
        self.assertEqual(len(rows), 6)
        for name in model_candidates.CANDIDATES:
            with self.subTest(candidate=name):
                with patch.object(model_candidates, "_least_squares", side_effect=AssertionError("must not fit")):
                    with self.assertRaises(model_candidates.CandidateUnavailable) as error:
                        model_candidates.fit_candidate(rows, name)
                self.assertEqual(error.exception.reason, "insufficient_residual_degrees_of_freedom")

    def test_rank_deficient_ols_is_explicitly_unavailable_without_hidden_penalty(self):
        rows = [{**{feature: float(index) for feature in model_candidates.FEATURES}, "consumption_kwh": 100.0}
                for index in range(9)]
        with self.assertRaises(model_candidates.CandidateUnavailable) as error:
            model_candidates.fit_candidate(rows, model_candidates.OLS)
        self.assertEqual(error.exception.reason, "rank_deficient_design")
        ridge = model_candidates.fit_candidate(rows, model_candidates.RIDGE)
        self.assertAlmostEqual(model_candidates.predict_row(ridge, rows[-1]), 100, places=9)

    def test_intercept_is_unpenalized_and_training_values_must_be_finite(self):
        training = supervised_rows(full_rank_actuals(17))
        for row in training:
            row["consumption_kwh"] = 123.0
        for name in model_candidates.CANDIDATES:
            model = model_candidates.fit_candidate(training, name)
            self.assertAlmostEqual(model["coefficients"][0], 123, places=9)
            self.assertAlmostEqual(model_candidates.predict_row(model, training[-1]), 123, places=9)
        training[-1]["lag_1"] = float("nan")
        with self.assertRaisesRegex(ValueError, "finite"):
            model_candidates.fit_candidate(training, model_candidates.RIDGE)

    def test_candidate_fits_receive_only_the_origin_training_rows(self):
        rows = full_rank_actuals(17)
        original_fit = model_candidates.fit_candidate
        fitted = {name: [] for name in model_candidates.CANDIDATES}

        def fit(training, name):
            model = original_fit(training, name)
            fitted[name].append((copy.deepcopy(training), model))
            return model

        with patch.object(model_candidates, "fit_candidate", side_effect=fit):
            report = evaluation.compare_model_variants(rows)
        for name, fits in fitted.items():
            self.assertEqual(len(fits), 8)
            periods = report["candidate_coverage"][name]["evaluation_periods"]
            for offset, ((training, model), target) in enumerate(zip(fits, periods)):
                history = rows[:9 + offset]
                self.assertEqual(training, supervised_rows(history))
                self.assertTrue(all(evaluation.period(row) < target for row in training))
                for feature in model_candidates.FEATURES:
                    self.assertAlmostEqual(model["means"][feature], sum(row[feature] for row in training) / len(training))

    def test_reduced_candidates_ignore_environment_perturbations_but_incumbent_does_not(self):
        rows = full_rank_actuals(17)
        changed = copy.deepcopy(rows)
        random_source = random.Random(156)
        for row in changed:
            for field in ("temperature", "humidity", "rainfall", "solar_irradiance", "peak_demand_kw"):
                row[field] += random_source.uniform(0, 50)
        before = evaluation.compare_model_variants(rows)
        after = evaluation.compare_model_variants(changed)
        incumbent_changes = []
        for original, altered in zip(before["origins"], after["origins"]):
            for name in model_candidates.CANDIDATES:
                self.assertEqual(original["predictions"][name], altered["predictions"][name])
            incumbent_changes.append(abs(original["predictions"][evaluation.MODEL] - altered["predictions"][evaluation.MODEL]))
        self.assertTrue(any(change > 1e-6 for change in incumbent_changes))

    def test_benchmark_is_preserved_and_reduced_common_origins_and_skips_are_disclosed(self):
        rows = full_rank_actuals(17)
        baseline = evaluation.walk_forward(rows)
        report = evaluation.compare_model_variants(rows)
        for key in ("nonseasonal_common", "seasonal_common"):
            self.assertEqual(report["comparisons"][key], baseline["comparisons"][key])
        common = report["comparisons"]["reduced_common"]
        self.assertEqual(common["evaluation_periods"], [row["target_period"] for row in report["origins"]][1:])
        self.assertEqual(common["excluded_periods"], ["2024-09"])
        self.assertEqual(len(common["metrics"]), 7)
        self.assertTrue(all(metrics["count"] == 8 for metrics in common["metrics"].values()))
        for name in model_candidates.CANDIDATES:
            skipped = report["candidate_coverage"][name]["skipped_origins"]
            first = next(row for row in skipped if row["target_period"] == "2024-09")
            self.assertEqual(first["reason"], "insufficient_residual_degrees_of_freedom")
            self.assertEqual((first["training_rows"], first["parameter_count"]), (6, 6))
            self.assertIsNone(report["origins"][0]["predictions"][name])
            self.assertEqual(report["origins"][0]["candidate_details"][name]["status"], "skipped")
        seasonal = report["comparisons"]["reduced_seasonal_common"]
        self.assertTrue(all(metrics["count"] == len(seasonal["evaluation_periods"]) for metrics in seasonal["metrics"].values()))
        self.assertFalse(report["model_selection"]["performed"])

    def test_later_actuals_leave_earlier_reduced_predictions_unchanged(self):
        rows = full_rank_actuals(18)
        changed = copy.deepcopy(rows)
        for row in changed[12:]:
            row["consumption_kwh"] *= 100
        before = evaluation.compare_model_variants(rows)
        after = evaluation.compare_model_variants(changed)
        for original, altered in zip(before["origins"], after["origins"]):
            if original["target_period"] <= "2025-01":
                for name in model_candidates.CANDIDATES:
                    self.assertEqual(original["predictions"][name], altered["predictions"][name])

    def test_constant_series_discloses_rank_skips_without_inventing_r2(self):
        rows = full_rank_actuals(17)
        for row in rows:
            row["consumption_kwh"] = 100.0
        report = evaluation.compare_model_variants(rows)
        skips = report["candidate_coverage"][model_candidates.OLS]["skipped_origins"]
        self.assertTrue(any(row["reason"] == "rank_deficient_design" for row in skips))
        common = report["comparisons"]["reduced_common"]
        self.assertEqual(common["status"], "no_common_origins")
        self.assertTrue(all(metrics["count"] == 0 and metrics["r2_score"] is None for metrics in common["metrics"].values()))

    def test_cli_candidate_provenance_is_deterministic_and_artifacts_are_not_written(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "dataset.csv"
            write_csv(path, full_rank_actuals(17))
            original = path.read_bytes()
            command = [sys.executable, evaluation.__file__, str(path)]
            env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}
            first = subprocess.run(command, cwd=directory, env=env, capture_output=True, text=True, check=True)
            second = subprocess.run(command, cwd=directory, env=env, capture_output=True, text=True, check=True)
            self.assertEqual(first.stdout, second.stdout)
            report = json.loads(first.stdout)
            provenance = report["provenance"]
            self.assertEqual(provenance["candidate_feature_set"], "reduced_consumption_calendar_v1")
            self.assertEqual(provenance["candidate_signature"], hashlib.sha256(Path(model_candidates.__file__).read_bytes()).hexdigest())
            self.assertEqual(provenance["candidate_feature_set_signature"], model_candidates.configuration()["feature_set_signature"])
            self.assertEqual(report["model_variants"]["candidates"][model_candidates.OLS]["model_type"], "Linear Regression")
            self.assertEqual(report["model_variants"]["candidates"][model_candidates.RIDGE]["model_type"], "Ridge (penalty 0.1)")
            self.assertEqual(path.read_bytes(), original)
            self.assertEqual(list(Path(directory).iterdir()), [path])


if __name__ == "__main__":
    unittest.main()
