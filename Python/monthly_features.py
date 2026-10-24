"""Calendar features shared by preprocessing, training, history, and forecasting.

Missing calendar lags stay None. Supervised rows without both lags are excluded;
future forecasts fail for the same target month rather than inventing history.
Observed context stays in raw rows, but model inputs use past-only estimates.
"""
import csv
import json
import math
import sys

FEATURE_VERSION = "calendar-past-only-v1"
CONTEXT_FEATURES = ["temperature", "humidity", "rainfall", "solar_irradiance", "peak_demand_kw"]
FEATURES = ["year", "month", *CONTEXT_FEATURES, "lag_1", "lag_2", "trend", "month_sin", "month_cos"]


def month_index(year, month):
    return int(year) * 12 + int(month) - 1


def ordered_actuals(rows):
    ordered = sorted((row.copy() for row in rows), key=lambda row: month_index(row["year"], row["month"]))
    periods = [month_index(row["year"], row["month"]) for row in ordered]
    if len(periods) != len(set(periods)):
        raise ValueError("Duplicate actual months are not supported.")
    return ordered


def calendar_features(rows, target_year, target_month):
    target = month_index(target_year, target_month)
    history = [row for row in rows if month_index(row["year"], row["month"]) < target]
    actuals = {month_index(row["year"], row["month"]): row for row in history}
    # Keep the earliest actual as the common origin, including warm-up months.
    origin = min((month_index(row["year"], row["month"]) for row in rows), default=target)
    return {
        "lag_1": actuals[target - 1]["consumption_kwh"] if target - 1 in actuals else None,
        "lag_2": actuals[target - 2]["consumption_kwh"] if target - 2 in actuals else None,
        "trend": float(target - origin + 1),
        "month_sin": math.sin(2 * math.pi * int(target_month) / 12),
        "month_cos": math.cos(2 * math.pi * int(target_month) / 12),
    }


def add_derived_features(rows):
    ordered = ordered_actuals(rows)
    # Always rebuild: legacy CSV feature columns may contain target leakage.
    return [{**row, **calendar_features(ordered, row["year"], row["month"])} for row in ordered]


def read_rows(dataset_path):
    required = ["year", "month", "consumption_kwh", *CONTEXT_FEATURES]
    rows = []
    with open(dataset_path, newline="", encoding="utf-8-sig") as handle:
        for row in csv.DictReader(handle):
            try:
                parsed = {key: float(row[key]) for key in required}
            except (KeyError, TypeError, ValueError):
                continue
            if not all(math.isfinite(value) for value in parsed.values()):
                continue
            if (not parsed["year"].is_integer() or not 1 <= parsed["year"] <= 9998
                    or not parsed["month"].is_integer() or not 1 <= parsed["month"] <= 12
                    or parsed["consumption_kwh"] < 0):
                continue
            rows.append(parsed)
    return add_derived_features(rows)


def build_forecast_input(rows, target_year, target_month):
    target = month_index(target_year, target_month)
    ordered = ordered_actuals(rows)
    history = [row for row in ordered if month_index(row["year"], row["month"]) < target]
    # Future rows must not set the origin, context estimates, or lag values.
    calendar = calendar_features(history, target_year, target_month)
    if calendar["lag_1"] is None or calendar["lag_2"] is None:
        raise ValueError(
            f"Cannot forecast {int(target_year):04d}-{int(target_month):02d}: "
            "actual consumption for both preceding calendar months is required."
        )
    same_month = [row for row in history if int(row["month"]) == int(target_month)]
    source = same_month or history[-12:]
    context = {feature: sum(row[feature] for row in source) / len(source) for feature in CONTEXT_FEATURES}
    return {"year": float(target_year), "month": float(target_month), **context, **calendar}


def supervised_rows(rows):
    ordered = ordered_actuals(rows)
    result = []
    for row in ordered:
        calendar = calendar_features(ordered, row["year"], row["month"])
        if calendar["lag_1"] is None or calendar["lag_2"] is None:
            continue
        features = build_forecast_input(ordered, int(row["year"]), int(row["month"]))
        result.append({**features, "consumption_kwh": row["consumption_kwh"]})
    return result


if __name__ == "__main__":
    # Laravel preprocessing uses the same derived-feature implementation.
    print(json.dumps(add_derived_features(json.load(sys.stdin)), allow_nan=False))
