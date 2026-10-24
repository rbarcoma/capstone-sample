"""Read-only, stdlib walk-forward diagnostics; never publishes a production model.

Run: python evaluation.py DATASET.csv [--min-training-rows 6]
JSON goes to stdout. Model fits are temporary and each target is predicted from
strictly earlier actuals. Tuned Ridge and selection diagnostics stay offline.
"""
import argparse
import csv
import hashlib
import json
import math
from pathlib import Path
import platform

import monthly_features
import model_candidates
import ridge_tuning
from monthly_features import FEATURES, build_forecast_input, month_index, ordered_actuals, read_rows, supervised_rows
import train_model
from train_model import regression_metrics as pooled_metrics

EVALUATION_VERSION = "walk-forward-training-only-tuning-v3"
MODEL = "current_fixed_penalty_0_1"
NONSEASONAL = (MODEL, "last_observation", "history_mean", "recent_mean_3", "recent_mean_6")
CANDIDATES = (*NONSEASONAL, "seasonal_naive")


def period(row):
    return f"{int(row['year']):04d}-{int(row['month']):02d}"


def baseline_predictions(rows, target_year, target_month):
    """Recent means require complete calendar windows; no gap substitution."""
    target = month_index(target_year, target_month)
    history = [row for row in ordered_actuals(rows) if month_index(row["year"], row["month"]) < target]
    actuals = {month_index(row["year"], row["month"]): row["consumption_kwh"] for row in history}
    predictions = {
        "last_observation": history[-1]["consumption_kwh"] if history else None,
        "history_mean": math.fsum(actuals.values()) / len(actuals) if actuals else None,
        "seasonal_naive": actuals.get(target - 12),
    }
    for window in (3, 6):
        indices = range(target - window, target)
        predictions[f"recent_mean_{window}"] = (
            math.fsum(actuals[index] for index in indices) / window
            if all(index in actuals for index in indices) else None
        )
    return predictions


def comparison(origins, candidates):
    # Every score in a comparison uses exactly the same target periods.
    common = [row for row in origins if all(row["predictions"].get(name) is not None for name in candidates)]
    periods = [row["target_period"] for row in common]
    return {
        "status": "evaluated" if common else "no_common_origins",
        "evaluation_periods": periods,
        "excluded_periods": [row["target_period"] for row in origins if row["target_period"] not in periods],
        "metrics": {
            name: pooled_metrics([row["actual"] for row in common], [row["predictions"][name] for row in common])
            for name in candidates
        },
    }


def walk_forward(actuals, min_training_rows=6, *, fit_model=None, predict_model=None, model_id=MODEL):
    """Refit at each origin using the same fixed, predeclared configuration."""
    if type(min_training_rows) is not int or min_training_rows < 1:
        raise ValueError("min_training_rows must be a positive integer.")
    fit_model = fit_model or train_model.train_model
    predict_model = predict_model or train_model.predict_row
    ordered = ordered_actuals(actuals)
    origins, skipped = [], []
    for index, target in enumerate(ordered):
        # Neither fitting nor feature construction receives the target/future rows.
        history = ordered[:index]
        training = supervised_rows(history)
        target_period = period(target)
        cutoff = period(history[-1]) if history else None
        if len(training) < min_training_rows:
            skipped.append({"target_period": target_period, "reason": "insufficient_eligible_training_rows",
                            "training_rows": len(training), "training_cutoff": cutoff})
            continue
        try:
            inputs = build_forecast_input(history, target["year"], target["month"])
        except ValueError as error:
            skipped.append({"target_period": target_period, "reason": "missing_calendar_lags",
                            "detail": str(error), "training_cutoff": cutoff})
            continue
        try:
            model = fit_model(training)
        except model_candidates.CandidateUnavailable as error:
            skipped.append({"target_period": target_period, "reason": error.reason,
                            "detail": str(error), "training_cutoff": cutoff,
                            "training_rows": len(training), "parameter_count": model_candidates.PARAMETER_COUNT,
                            "candidate_id": model_id})
            continue
        predicted = float(predict_model(model, inputs))
        predictions = {model_id: predicted, **baseline_predictions(history, target["year"], target["month"])}
        if not all(value is None or math.isfinite(value) for value in predictions.values()):
            raise ValueError(f"Nonfinite prediction for {target_period}; evaluation aborted.")
        # Reveal the held-out actual only after all predictions are generated.
        actual = float(target["consumption_kwh"])
        if not math.isfinite(actual):
            raise ValueError(f"Nonfinite actual for {target_period}.")
        origins.append({
            "target_period": target_period,
            "training_cutoff": cutoff,
            "training_actual_rows": len(history),
            "training_eligible_rows": len(training),
            "training_periods": [period(row) for row in training],
            "feature_origin": period(history[0]),
            "inputs": inputs,
            "actual": actual,
            "predictions": predictions,
            "errors": {name: actual - value if value is not None else None for name, value in predictions.items()},
            "unavailable_baselines": {
                name: ("prior-year same-month actual unavailable" if name == "seasonal_naive"
                       else f"complete preceding {name.rsplit('_', 1)[-1]} calendar months unavailable")
                for name, value in predictions.items() if value is None
            },
        })
    return {
        "status": "evaluated" if origins else "insufficient_history",
        "actual_rows": len(ordered),
        "eligible_rows": len(supervised_rows(ordered)),
        "actual_periods": [period(row) for row in ordered],
        "origins": origins,
        "skipped_origins": skipped,
        "comparisons": {
            "nonseasonal_common": comparison(origins, (model_id, *NONSEASONAL[1:])),
            "seasonal_common": comparison(origins, (model_id, *NONSEASONAL[1:], "seasonal_naive")),
        },
        "model_selection": {"performed": False, "reason": "Fixed incumbent and naive baselines only; selection and tuning remain pending."},
    }


def compare_model_variants(actuals, min_training_rows=6):
    """Keep the original benchmark intact; compare reduced fits on common origins."""
    report = walk_forward(actuals, min_training_rows)
    candidate_config = model_candidates.configuration()
    report["model_variants"] = candidate_config
    report["candidate_coverage"] = {}
    for candidate in model_candidates.CANDIDATES:
        candidate_report = walk_forward(
            actuals, min_training_rows,
            fit_model=lambda training, name=candidate: model_candidates.fit_candidate(training, name),
            predict_model=model_candidates.predict_row,
            model_id=candidate,
        )
        evaluated = {row["target_period"]: row for row in candidate_report["origins"]}
        skipped = {row["target_period"]: row for row in candidate_report["skipped_origins"]}
        report["candidate_coverage"][candidate] = {
            "evaluation_periods": list(evaluated),
            "skipped_origins": candidate_report["skipped_origins"],
        }
        for row in report["origins"]:
            result = evaluated.get(row["target_period"])
            prediction = result["predictions"][candidate] if result else None
            row["predictions"][candidate] = prediction
            row["errors"][candidate] = row["actual"] - prediction if prediction is not None else None
            details = ({"status": "evaluated", "training_rows": result["training_eligible_rows"],
                        "parameter_count": model_candidates.PARAMETER_COUNT,
                        "inputs": {feature: result["inputs"][feature] for feature in model_candidates.FEATURES}}
                       if result else {"status": "skipped", **skipped[row["target_period"]]})
            row.setdefault("candidate_details", {})[candidate] = details
    candidates = (MODEL, *model_candidates.CANDIDATES, *NONSEASONAL[1:])
    report["comparisons"]["reduced_common"] = comparison(report["origins"], candidates)
    report["comparisons"]["reduced_seasonal_common"] = comparison(report["origins"], (*candidates, "seasonal_naive"))
    report["model_selection"] = {"performed": False, "reason": "Fixed OLS/Ridge and baseline comparisons only; tuning, selection, and promotion remain pending."}
    return report


def selection_decision(development, reserved, candidates):
    """Apply the frozen policy to common origins using unrounded errors."""
    def loss(rows, name, squared=False):
        if not rows:
            return math.inf
        return math.fsum((row["errors"][name] ** 2 if squared else abs(row["errors"][name]))
                         for row in rows) / len(rows)

    baseline = min(NONSEASONAL[1:], key=lambda name: (loss(development, name), name)) if development else None
    challengers = [name for name in candidates if name not in NONSEASONAL[1:]]
    ordered = sorted(challengers, key=lambda name: (loss(development, name), name)) if development else []
    # Freeze the challenger on development evidence; never try another after a reserved failure.
    challenger = ordered[0] if ordered else None
    complete = ([row["target_period"] for row in development] == list(ridge_tuning.DEVELOPMENT)
                and [row["target_period"] for row in reserved] == list(ridge_tuning.RESERVED))
    checks = {"complete_coverage": complete, "development_mae_and_rmse": False,
              "development_absolute_error_wins_at_least_four": False,
              "reserved_mae_and_rmse": False, "no_reserved_absolute_error_reversal": False}
    if challenger and baseline:
        checks["development_mae_and_rmse"] = all(loss(development, challenger, squared) < loss(development, baseline, squared)
                                                 for squared in (False, True))
        checks["development_absolute_error_wins_at_least_four"] = sum(
            abs(row["errors"][challenger]) < abs(row["errors"][baseline]) for row in development) >= 4
        checks["reserved_mae_and_rmse"] = bool(reserved) and all(
            loss(reserved, challenger, squared) < loss(reserved, baseline, squared) for squared in (False, True))
        checks["no_reserved_absolute_error_reversal"] = bool(reserved) and all(
            abs(row["errors"][challenger]) <= abs(row["errors"][baseline]) for row in reserved)
    met = all(checks.values())
    return {"performed": True, "best_naive_on_development": baseline,
            "development_challenger_order": ordered, "challenger_frozen_before_reserved": challenger,
            "conditions": checks, "condition_met": met,
            "conclusion": ("candidate meets retrospective diagnostic conditions; seek fresh validation"
                           if met else "retain the baseline / seek more genuine history"),
            "production_change_authorized": False}


def add_tuned_evaluation(report, actuals):
    """Append tuned diagnostics without changing any existing comparison table."""
    ordered = ordered_actuals(actuals)
    candidates = (MODEL, *model_candidates.CANDIDATES, ridge_tuning.TUNED, *NONSEASONAL[1:])
    coverage, skipped = [], []
    # Frozen once, before either reserved outcome can enter penalty search.
    frozen = ridge_tuning.tune_penalty([row for row in ordered if period(row) < ridge_tuning.RESERVED[0]])
    for origin in report["origins"]:
        target = origin["target_period"]
        origin["predictions"][ridge_tuning.TUNED] = None
        origin["errors"][ridge_tuning.TUNED] = None
        if target not in (*ridge_tuning.DEVELOPMENT, *ridge_tuning.RESERVED):
            detail = {"status": "skipped", "reason": "outside_predeclared_selection_periods"}
        else:
            history = [row for row in ordered if period(row) < target]
            tuning = frozen if target in ridge_tuning.RESERVED else ridge_tuning.tune_penalty(history)
            detail = {"status": "skipped", "reason": tuning["status"], "tuning": tuning}
            if tuning["selected_penalty"] is not None:
                try:
                    model = model_candidates.fit_ridge(supervised_rows(history), tuning["selected_penalty"])
                    prediction = model_candidates.predict_row(model, origin["inputs"])
                    if not math.isfinite(prediction):
                        raise ValueError("Nonfinite tuned prediction; evaluation aborted.")
                    origin["predictions"][ridge_tuning.TUNED] = prediction
                    origin["errors"][ridge_tuning.TUNED] = origin["actual"] - prediction
                    detail = {"status": "evaluated", "selected_penalty": tuning["selected_penalty"],
                              "training_rows": model["training_rows"], "parameter_count": model_candidates.PARAMETER_COUNT,
                              "tuning": tuning}
                    coverage.append(target)
                except model_candidates.CandidateUnavailable as error:
                    detail.update(reason=error.reason, detail=str(error))
        origin.setdefault("candidate_details", {})[ridge_tuning.TUNED] = detail
        if detail["status"] == "skipped":
            skipped.append({"target_period": target, **detail})
    report["candidate_coverage"][ridge_tuning.TUNED] = {"evaluation_periods": coverage, "skipped_origins": skipped}
    subsets = {}
    common_rows = {}
    for name, periods in (("development", ridge_tuning.DEVELOPMENT), ("reserved", ridge_tuning.RESERVED)):
        rows = [row for row in report["origins"] if row["target_period"] in periods]
        subset = comparison(rows, candidates)
        subset["scheduled_periods"] = list(periods)
        subset["excluded_periods"] = [target for target in periods if target not in subset["evaluation_periods"]]
        subsets[name] = subset
        common_rows[name] = [row for row in rows if row["target_period"] in subset["evaluation_periods"]]
    report["selection_evaluation"] = {"policy": ridge_tuning.configuration(), "subsets": subsets}
    report["model_selection"] = selection_decision(common_rows["development"], common_rows["reserved"], candidates)


def evaluate_dataset(dataset_path, min_training_rows=6):
    path = Path(dataset_path).resolve()
    fingerprint = hashlib.sha256(path.read_bytes()).hexdigest()
    actuals = read_rows(path)
    with path.open(newline="", encoding="utf-8-sig") as handle:
        input_count = sum(1 for row in csv.DictReader(handle) if any(value not in (None, "") for value in row.values()))
    if hashlib.sha256(path.read_bytes()).hexdigest() != fingerprint:
        raise ValueError("Dataset changed while reading; evaluation aborted.")
    configuration = {
        "protocol": "expanding_window_one_month_ahead_refit",
        "min_eligible_training_rows": min_training_rows,
        "feature_set": "current_12_features",
        "features": FEATURES,
        "incumbent": {"id": MODEL, "penalty": 0.1, "standardization": "fit_on_origin_training_rows_only"},
        "recent_average_windows": [3, 6],
        "recent_average_policy": "complete_preceding_calendar_months",
        "seasonal_policy": "prior_year_same_month_actual_only",
        "comparison_policy": "common_origins_within_each_comparison",
        "metric_policy": "pooled_errors_r2_null_when_undefined",
        "model_variants": model_candidates.configuration(),
        "tuning_and_selection": ridge_tuning.configuration(),
    }
    report = compare_model_variants(actuals, min_training_rows)
    add_tuned_evaluation(report, actuals)
    report["provenance"] = {
        "evaluation_version": EVALUATION_VERSION,
        "dataset_path": str(path),
        "dataset_sha256": fingerprint,
        "input_rows": input_count,
        "rejected_input_rows": input_count - len(actuals),
        "feature_version": monthly_features.FEATURE_VERSION,
        "feature_signature": hashlib.sha256(Path(monthly_features.__file__).read_bytes()).hexdigest(),
        "evaluation_signature": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "training_signature": hashlib.sha256(Path(train_model.__file__).read_bytes()).hexdigest(),
        "candidate_signature": hashlib.sha256(Path(model_candidates.__file__).read_bytes()).hexdigest(),
        "tuning_signature": hashlib.sha256(Path(ridge_tuning.__file__).read_bytes()).hexdigest(),
        "candidate_feature_set": model_candidates.FEATURE_SET,
        "candidate_feature_set_signature": model_candidates.configuration()["feature_set_signature"],
        "configuration": configuration,
        "configuration_sha256": hashlib.sha256(json.dumps(configuration, sort_keys=True).encode()).hexdigest(),
        "runtime": {"python": platform.python_version(), "implementation": platform.python_implementation()},
        "available_actual_cutoff": period(actuals[-1]) if actuals else None,
    }
    report["limitations"] = [
        "Retrospective one-step evaluation assumes each previous actual was available at that origin; billing release times are unverified.",
        "Each comparison uses common periods internally; seasonal/reduced coverage may be shorter and must not be ranked against different periods.",
        "Reduced OLS/Ridge use a fixed five-feature set and n > 6 rule; candidate rank/coverage skips are explicit and no original benchmark periods are removed.",
        "Selection dates are fixed to January–June and July–August 2026; other datasets may have no selection coverage.",
        "January has no inner fold under n > 6; absent evidence is skipped rather than replaced by a default penalty.",
        "Reserved outcomes cannot enter penalty search, but were inspected in earlier diagnostics; they are not a newly blinded/prospective holdout.",
        "Reserved coefficient refits and lag inputs may use earlier revealed reserved actuals; penalties remain frozen before July.",
        "A small number of observations cannot establish robust accuracy or prospective forecast reliability.",
        "This report is separate from saved production metrics and does not update forecasts, artifacts, Analytics, or DSS.",
    ]
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("dataset", type=Path)
    parser.add_argument("--min-training-rows", type=int, default=6,
                        help="Minimum eligible past supervised rows before the first evaluated origin (default: 6).")
    args = parser.parse_args()
    try:
        report = evaluate_dataset(args.dataset, args.min_training_rows)
        print(json.dumps(report, indent=2, allow_nan=False))
    except (ValueError, OSError) as error:
        parser.error(str(error))


if __name__ == "__main__":
    main()
