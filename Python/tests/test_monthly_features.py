import copy
import csv
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import monthly_features as features
import predict_history
import predict_next_month
import train_model


def actuals(periods):
    return [dict(year=year, month=month, consumption_kwh=100 + 7 * i,
                 temperature=20 + i, humidity=60 + i, rainfall=10 + i,
                 solar_irradiance=4 + i / 10, peak_demand_kw=15 + i)
            for i, (year, month) in enumerate(periods)]


class MonthlyFeaturesTest(unittest.TestCase):
    def test_initial_lags_stay_missing_instead_of_using_the_target(self):
        rows = features.add_derived_features(actuals([(2026, 1), (2026, 2), (2026, 3)]))
        self.assertIsNone(rows[0]["lag_1"])
        self.assertIsNone(rows[0]["lag_2"])
        self.assertEqual(rows[1]["lag_1"], 100)
        self.assertIsNone(rows[1]["lag_2"])
        eligible = features.supervised_rows(rows)
        self.assertEqual(len(eligible), 1)
        self.assertEqual((eligible[0]["lag_1"], eligible[0]["lag_2"]), (107, 100))

    def test_gaps_use_calendar_months_and_keep_the_same_trend_origin(self):
        rows = features.add_derived_features(actuals([(2025, 12), (2026, 2), (2026, 3)]))
        self.assertIsNone(rows[1]["lag_1"])
        self.assertEqual(rows[1]["lag_2"], 100)
        self.assertEqual(rows[1]["trend"], 3)
        self.assertEqual(rows[2]["lag_1"], 107)
        self.assertIsNone(rows[2]["lag_2"])
        self.assertEqual(features.supervised_rows(rows), [])
        target = features.build_forecast_input(rows, 2026, 4)
        self.assertEqual((target["lag_1"], target["lag_2"], target["trend"]), (114, 107, 5))

    def test_december_to_january_and_zero_actuals_are_valid(self):
        rows = actuals([(2025, 11), (2025, 12), (2026, 1)])
        rows[0]["consumption_kwh"] = 0
        target = features.supervised_rows(rows)[0]
        self.assertEqual((target["lag_1"], target["lag_2"], target["trend"]), (107, 0, 3))

    def test_unsorted_actuals_produce_identical_features(self):
        rows = actuals([(2025, 11), (2025, 12), (2026, 1)])
        self.assertEqual(features.supervised_rows(rows), features.supervised_rows(list(reversed(rows))))

    def test_duplicate_months_are_rejected(self):
        with self.assertRaisesRegex(ValueError, "Duplicate actual months"):
            features.add_derived_features(actuals([(2026, 1), (2026, 1)]))

    def test_legacy_exported_feature_values_are_recomputed_in_every_reader(self):
        rows = actuals([(2026, 1), (2026, 2), (2026, 3)])
        for row in rows:
            row.update(lag_1=999999, lag_2=999999, trend=999, month_sin=999, month_cos=999)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "legacy.csv"
            with path.open("w", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=rows[0].keys())
                writer.writeheader()
                writer.writerows(rows)
            loaded = [module.read_rows(path) for module in (train_model, predict_next_month, predict_history)]
        self.assertEqual(loaded[0], loaded[1])
        self.assertEqual(loaded[1], loaded[2])
        self.assertIsNone(loaded[0][0]["lag_1"])
        self.assertEqual(loaded[0][2]["lag_2"], 100)
        self.assertEqual(loaded[0][2]["trend"], 3)

    def test_historical_and_next_month_inputs_are_identical_at_each_origin(self):
        rows = actuals([(2025, month) for month in range(1, 13)] + [(2026, 1), (2026, 2)])
        for row in features.supervised_rows(rows):
            origin = features.month_index(row["year"], row["month"])
            history = [item for item in rows if features.month_index(item["year"], item["month"]) < origin]
            target = predict_next_month.build_forecast_input(history, row["year"], row["month"])
            self.assertEqual(target, {key: row[key] for key in features.FEATURES})
        # January uses last year's January context, from before the origin.
        self.assertEqual(features.supervised_rows(rows)[10]["temperature"], 20)

    def test_target_month_actual_context_is_never_used_as_its_input(self):
        rows = actuals([(2026, month) for month in range(1, 7)])
        before = features.build_forecast_input(rows, 2026, 6)
        rows[-1]["consumption_kwh"] = 999999
        for key in features.CONTEXT_FEATURES:
            rows[-1][key] = 999999
        self.assertEqual(before, features.build_forecast_input(rows, 2026, 6))

    def test_later_actual_perturbations_leave_earlier_features_scaling_and_predictions_unchanged(self):
        rows = actuals([(2025, month) for month in range(1, 13)] + [(2026, month) for month in range(1, 7)])
        altered = copy.deepcopy(rows)
        for row in altered[10:]:
            row["consumption_kwh"] *= 1000
            for key in features.CONTEXT_FEATURES:
                row[key] *= 1000
        original_features = features.supervised_rows(rows)
        altered_features = features.supervised_rows(altered)
        # Predict October 2025 using only data available through September.
        self.assertEqual(original_features[:8], altered_features[:8])
        original_training = [row for row in original_features if row["year"] == 2025 and row["month"] < 10]
        altered_training = [row for row in altered_features if row["year"] == 2025 and row["month"] < 10]
        original_model = train_model.train_model(original_training)
        altered_model = train_model.train_model(altered_training)
        self.assertEqual(original_model, altered_model)
        original_input = features.build_forecast_input(rows, 2025, 10)
        altered_input = features.build_forecast_input(altered, 2025, 10)
        self.assertEqual(original_input, altered_input)
        self.assertEqual(predict_next_month.predict_row(original_model, original_input),
                         predict_next_month.predict_row(altered_model, altered_input))

    def test_missing_preceding_month_fails_for_the_requested_period(self):
        rows = actuals([(2026, 1), (2026, 4), (2026, 9)])
        with self.assertRaisesRegex(ValueError, "Cannot forecast 2026-10"):
            features.build_forecast_input(rows, 2026, 10)

    def test_too_little_eligible_history_fails_before_creating_model(self):
        import contextlib
        import io
        from unittest.mock import patch
        rows = actuals([(2026, month) for month in range(1, 7)])
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "short.csv"
            model = Path(directory) / "model.pkl"
            with path.open("w", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=rows[0].keys())
                writer.writeheader()
                writer.writerows(rows)
            with patch.object(sys, "argv", ["train_model.py", str(path), str(model), str(model.with_suffix('.json'))]):
                with contextlib.redirect_stdout(io.StringIO()):
                    with self.assertRaisesRegex(ValueError, "At least 6 eligible monthly rows"):
                        train_model.main()
            self.assertFalse(model.exists())


if __name__ == "__main__":
    unittest.main()
