"""Offline, predeclared training-only Ridge search. No saved model interface."""
import hashlib
import json
import math

import model_candidates
from monthly_features import build_forecast_input, ordered_actuals, supervised_rows

TUNED = "reduced_consumption_calendar_ridge_tuned"
PENALTIES = (0.01, 0.1, 1.0, 10.0)
DEVELOPMENT = tuple(f"2026-{month:02d}" for month in range(1, 7))
RESERVED = ("2026-07", "2026-08")


def period(row):
    return f"{int(row['year']):04d}-{int(row['month']):02d}"


def configuration():
    policy = {
        "candidate_id": TUNED,
        "penalty_grid": list(PENALTIES),
        "inner_protocol": "expanding_one_month_ahead_training_only_scaling_n_gt_6",
        "minimum_inner_origins": 1,
        "objective": "unrounded_pooled_mae",
        "tie_break": "smallest_penalty_exact_ties",
        "missing_inner_evidence": "skip_without_default_penalty",
        "development_periods": list(DEVELOPMENT),
        "reserved_periods": list(RESERVED),
        "reserved_tuning_policy": "freeze_to_actuals_strictly_before_2026_07",
        "reserved_refit_policy": "earlier_revealed_actuals_allowed_for_coefficients_and_lags_only",
        "baseline_selection": "lowest_unrounded_development_mae_then_identifier_four_nonseasonal_naives",
        "recommendation_conditions": [
            "complete_six_development_and_two_reserved_common_origins",
            "strictly_lower_pooled_mae_and_rmse_than_selected_baseline_in_both_subsets",
            "strictly_lower_absolute_error_on_at_least_four_development_origins",
            "no_worse_absolute_error_on_either_reserved_origin",
        ],
        "challenger_order": "unrounded_development_mae_then_identifier_before_reserved_scoring",
        "failure_conclusion": "retain the baseline / seek more genuine history",
        "publication_authorized": False,
        "holdout_caveat": "Reserved outcomes were inspected in earlier diagnostics; this is not a newly blinded or prospective holdout.",
    }
    return {**policy, "policy_signature": hashlib.sha256(json.dumps(policy, sort_keys=True).encode()).hexdigest()}


def tune_penalty(history):
    """Caller supplies past actuals only; each inner target is revealed after fit."""
    rows = ordered_actuals(history)
    origins, skipped = [], []
    for index, target in enumerate(rows):
        past = rows[:index]
        training = supervised_rows(past)
        detail = {"target_period": period(target), "training_rows": len(training),
                  "training_cutoff": period(past[-1]) if past else None,
                  "parameter_count": model_candidates.PARAMETER_COUNT}
        if len(training) <= model_candidates.PARAMETER_COUNT:
            skipped.append({**detail, "reason": "insufficient_residual_degrees_of_freedom"})
            continue
        try:
            inputs = build_forecast_input(past, target["year"], target["month"])
        except ValueError as error:
            skipped.append({**detail, "reason": "missing_calendar_lags", "detail": str(error)})
            continue
        predictions = {}
        try:
            for penalty in PENALTIES:
                model = model_candidates.fit_ridge(training, penalty)
                predictions[str(penalty)] = model_candidates.predict_row(model, inputs)
        except model_candidates.CandidateUnavailable as error:
            # Discard the whole fold so every penalty uses identical outcomes.
            skipped.append({**detail, "reason": error.reason, "detail": str(error)})
            continue
        if not all(math.isfinite(value) for value in predictions.values()):
            raise ValueError("Nonfinite inner prediction; tuning aborted.")
        actual = float(target["consumption_kwh"])
        if not math.isfinite(actual):
            raise ValueError("Nonfinite inner actual; tuning aborted.")
        origins.append({**detail, "training_periods": [period(row) for row in training],
                        "actual": actual, "predictions": predictions,
                        "errors": {key: actual - value for key, value in predictions.items()}})
    scores = {str(penalty): math.fsum(abs(row["errors"][str(penalty)]) for row in origins) / len(origins)
              if origins else None for penalty in PENALTIES}
    chosen = min(PENALTIES, key=lambda penalty: (scores[str(penalty)], penalty)) if origins else None
    return {"status": "evaluated" if origins else "insufficient_inner_history",
            "selected_penalty": chosen, "objective_scores": scores,
            "available_actual_cutoff": period(rows[-1]) if rows else None,
            "evaluation_periods": [row["target_period"] for row in origins],
            "origins": origins, "skipped_origins": skipped}
