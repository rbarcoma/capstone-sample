"""Synthetic tests for the offline, date-frozen Priority 3.3 policy."""
import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import evaluation
import model_candidates
import ridge_tuning
from monthly_features import build_forecast_input, supervised_rows
from test_evaluation import full_rank_actuals, independent_decimal_fit, write_csv


def fixture():
    rows = full_rank_actuals(17)
    for index, row in enumerate(rows):
        month_index = 2025 * 12 + 3 + index
        row.update(year=month_index // 12, month=month_index % 12 + 1)
    return rows


def report_for(rows):
    report = evaluation.compare_model_variants(rows)
    evaluation.add_tuned_evaluation(report, rows)
    return report


class RidgeTuningTest(unittest.TestCase):
    def test_inner_scores_and_choice_match_independent_reference_fits(self):
        rows = fixture()[:15]  # Through June, before the reserved pair.
        tuning = ridge_tuning.tune_penalty(rows)
        expected = {}
        for penalty in ridge_tuning.PENALTIES:
            errors = []
            for index in range(9, len(rows)):
                target = rows[index]
                inputs = build_forecast_input(rows[:index], target["year"], target["month"])
                prediction = independent_decimal_fit(supervised_rows(rows[:index]), inputs, penalty)
                errors.append(abs(target["consumption_kwh"] - prediction))
            expected[penalty] = sum(errors) / len(errors)
            self.assertAlmostEqual(tuning["objective_scores"][str(penalty)], expected[penalty], places=7)
        self.assertEqual(tuning["selected_penalty"], min(expected, key=lambda penalty: (expected[penalty], penalty)))

    def test_inner_fits_and_scaling_receive_only_their_chronological_prefix(self):
        rows = fixture()[:15]
        fitted = []
        original = model_candidates.fit_ridge

        def fit(training, penalty):
            fitted.append((copy.deepcopy(training), penalty))
            return original(training, penalty)

        with patch.object(model_candidates, "fit_ridge", side_effect=fit):
            tuning = ridge_tuning.tune_penalty(list(reversed(rows)))
        for index, origin in enumerate(tuning["origins"]):
            self.assertTrue(all(target < origin["target_period"] for target in origin["training_periods"]))
            self.assertLess(origin["training_cutoff"], origin["target_period"])
            for offset, penalty in enumerate(ridge_tuning.PENALTIES):
                self.assertEqual(fitted[index * 4 + offset], (supervised_rows(rows[:9 + index]), penalty))

    def test_target_and_future_changes_cannot_affect_earlier_penalty_or_prediction(self):
        rows = fixture()
        changed = copy.deepcopy(rows)
        for row in changed[12:]:
            row["consumption_kwh"] *= 100
        before, after = report_for(rows), report_for(changed)
        for first, second in zip(before["origins"], after["origins"]):
            if first["target_period"] <= "2026-04":
                self.assertEqual(first["candidate_details"][ridge_tuning.TUNED], second["candidate_details"][ridge_tuning.TUNED])
                self.assertEqual(first["predictions"][ridge_tuning.TUNED], second["predictions"][ridge_tuning.TUNED])

    def test_reserved_actuals_never_enter_penalty_selection_but_later_refit_can_use_them(self):
        rows = fixture()
        changed = copy.deepcopy(rows)
        changed[-2]["consumption_kwh"] *= 100
        changed[-1]["consumption_kwh"] *= 200
        before, after = report_for(rows), report_for(changed)
        for index in (-2, -1):
            first = before["origins"][index]
            second = after["origins"][index]
            detail = first["candidate_details"][ridge_tuning.TUNED]
            self.assertEqual(detail["tuning"], second["candidate_details"][ridge_tuning.TUNED]["tuning"])
            self.assertEqual(detail["tuning"]["available_actual_cutoff"], "2026-06")
            self.assertTrue(all(origin["target_period"] < "2026-07" for origin in detail["tuning"]["origins"]))
        self.assertEqual(before["origins"][-2]["predictions"][ridge_tuning.TUNED], after["origins"][-2]["predictions"][ridge_tuning.TUNED])
        self.assertNotEqual(before["origins"][-1]["predictions"][ridge_tuning.TUNED], after["origins"][-1]["predictions"][ridge_tuning.TUNED])

    def test_grid_exact_ties_and_insufficient_inner_history_have_no_hidden_fallback(self):
        self.assertEqual(ridge_tuning.PENALTIES, (0.01, 0.1, 1.0, 10.0))
        with patch.object(model_candidates, "predict_row", return_value=0):
            tuning = ridge_tuning.tune_penalty(fixture()[:12])
        self.assertEqual(tuning["selected_penalty"], 0.01)
        with patch.object(model_candidates, "fit_ridge") as fit:
            short = ridge_tuning.tune_penalty(fixture()[:9])
        fit.assert_not_called()
        self.assertEqual(short["status"], "insufficient_inner_history")
        self.assertIsNone(short["selected_penalty"])
        self.assertEqual(short["skipped_origins"][-1]["training_rows"], 6)
        report = report_for(fixture())
        january = next(row for row in report["origins"] if row["target_period"] == "2026-01")
        self.assertIsNone(january["predictions"][ridge_tuning.TUNED])
        self.assertEqual(january["candidate_details"][ridge_tuning.TUNED]["reason"], "insufficient_inner_history")

    def test_rank_skip_discards_whole_inner_fold_and_preserves_identifiability_rule(self):
        original = model_candidates.fit_ridge

        def fit(training, penalty):
            if penalty == 0.1:
                raise model_candidates.CandidateUnavailable("rank_deficient_design", "synthetic rank failure")
            return original(training, penalty)

        with patch.object(model_candidates, "fit_ridge", side_effect=fit):
            tuning = ridge_tuning.tune_penalty(fixture()[:11])
        self.assertIsNone(tuning["selected_penalty"])
        self.assertEqual(tuning["origins"], [])
        self.assertEqual(tuning["skipped_origins"][-1]["reason"], "rank_deficient_design")
        with self.assertRaises(model_candidates.CandidateUnavailable):
            original(supervised_rows(fixture()[:8]), 10)
        for penalty in (0, -1, float("inf"), float("nan")):
            with self.assertRaises(ValueError):
                original(supervised_rows(fixture()[:11]), penalty)

    def test_subsets_have_identical_common_periods_and_keep_all_original_tables(self):
        rows = fixture()
        original = evaluation.compare_model_variants(rows)
        report = report_for(rows)
        self.assertEqual(report["comparisons"], original["comparisons"])
        for first, second in zip(original["origins"], report["origins"]):
            for candidate in (evaluation.MODEL, *model_candidates.CANDIDATES):
                self.assertEqual(first["predictions"][candidate], second["predictions"][candidate])
        subsets = report["selection_evaluation"]["subsets"]
        self.assertEqual(subsets["development"]["evaluation_periods"], list(ridge_tuning.DEVELOPMENT[1:]))
        self.assertEqual(subsets["development"]["excluded_periods"], ["2026-01"])
        self.assertEqual(subsets["reserved"]["evaluation_periods"], list(ridge_tuning.RESERVED))
        for subset in subsets.values():
            common = [row for row in report["origins"] if row["target_period"] in subset["evaluation_periods"]]
            for candidate, metrics in subset["metrics"].items():
                self.assertEqual(metrics, evaluation.pooled_metrics([row["actual"] for row in common],
                                                                   [row["predictions"][candidate] for row in common]))
        self.assertFalse(report["model_selection"]["conditions"]["complete_coverage"])
        self.assertEqual(report["model_selection"]["conclusion"], "retain the baseline / seek more genuine history")

    def test_missing_periods_and_constant_series_cannot_qualify_or_fabricate_r2(self):
        rows = fixture()
        del rows[-2]
        report = report_for(rows)
        self.assertEqual(report["selection_evaluation"]["subsets"]["reserved"]["evaluation_periods"], [])
        self.assertFalse(report["model_selection"]["condition_met"])
        rows = fixture()
        for row in rows:
            row["consumption_kwh"] = 0
        report = report_for(rows)
        self.assertFalse(report["model_selection"]["condition_met"])
        self.assertTrue(all(metrics["r2_score"] is None
                            for subset in report["selection_evaluation"]["subsets"].values()
                            for metrics in subset["metrics"].values()))

    def test_cli_is_reproducible_fingerprints_policy_and_writes_no_artifacts(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.csv"
            write_csv(path, fixture())
            original = path.read_bytes()
            command = [sys.executable, evaluation.__file__, str(path)]
            env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}
            first = subprocess.run(command, env=env, cwd=directory, check=True, capture_output=True, text=True)
            second = subprocess.run(command, env=env, cwd=directory, check=True, capture_output=True, text=True)
            self.assertEqual(first.stdout, second.stdout)
            report = json.loads(first.stdout)
            self.assertEqual(report["provenance"]["configuration"]["tuning_and_selection"], ridge_tuning.configuration())
            self.assertEqual(report["provenance"]["tuning_signature"], hashlib.sha256(Path(ridge_tuning.__file__).read_bytes()).hexdigest())
            self.assertEqual(path.read_bytes(), original)
            self.assertEqual(list(Path(directory).iterdir()), [path])


class SelectionPolicyTest(unittest.TestCase):
    def rows(self, periods, error=1):
        return [{"target_period": period, "errors": {"challenger": error, "other": 1.5,
                **{name: 2 for name in evaluation.NONSEASONAL[1:]}}} for period in periods]

    def decision(self, development, reserved):
        return evaluation.selection_decision(development, reserved, ("challenger", "other", *evaluation.NONSEASONAL[1:]))

    def test_complete_condition_met_still_authorizes_no_production_change(self):
        decision = self.decision(self.rows(ridge_tuning.DEVELOPMENT), self.rows(ridge_tuning.RESERVED))
        self.assertTrue(decision["condition_met"])
        self.assertTrue(all(decision["conditions"].values()))
        self.assertFalse(decision["production_change_authorized"])

    def test_missing_coverage_ties_insufficient_wins_and_reserved_reversal_fail(self):
        development = self.rows(ridge_tuning.DEVELOPMENT)
        reserved = self.rows(ridge_tuning.RESERVED)
        self.assertFalse(self.decision(development[1:], reserved)["condition_met"])
        self.assertFalse(self.decision([], [])["condition_met"])
        for row in development:
            row["errors"].update(challenger=2, other=2)
        decision = self.decision(development, reserved)
        self.assertFalse(decision["conditions"]["development_mae_and_rmse"])
        self.assertFalse(decision["conditions"]["development_absolute_error_wins_at_least_four"])
        development = self.rows(ridge_tuning.DEVELOPMENT)
        reserved[0]["errors"]["challenger"] = 2.1
        decision = self.decision(development, reserved)
        self.assertFalse(decision["conditions"]["no_reserved_absolute_error_reversal"])
        self.assertEqual(decision["challenger_frozen_before_reserved"], "challenger")
        self.assertFalse(decision["condition_met"])  # Do not switch to 'other' after seeing the pair.

    def test_rmse_and_raw_precision_are_used_not_only_rounded_mae(self):
        development = self.rows(ridge_tuning.DEVELOPMENT, 0)
        for row in development:
            row["errors"]["other"] = 10
        development[0]["errors"]["challenger"] = 7
        decision = self.decision(development, self.rows(ridge_tuning.RESERVED))
        self.assertFalse(decision["conditions"]["development_mae_and_rmse"])
        development = self.rows(ridge_tuning.DEVELOPMENT, 1.9999999)
        for row in development:
            row["errors"]["other"] = 3
        decision = self.decision(development, self.rows(ridge_tuning.RESERVED))
        self.assertTrue(decision["conditions"]["development_mae_and_rmse"])


if __name__ == "__main__":
    unittest.main()
