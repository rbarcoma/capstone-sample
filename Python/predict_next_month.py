import csv
import json
import math
import os
import pickle
import subprocess
import sys
from pathlib import Path




SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent
STORAGE_ML_DIR = PROJECT_ROOT / "storage" / "app" / "private" / "ml"




def default_path(filename):
    return SCRIPT_DIR / filename


def default_dataset_path():
    candidates = [
        STORAGE_ML_DIR / "processed_dataset.csv",
        SCRIPT_DIR / "qc_final_merged_dataset.csv",
        SCRIPT_DIR / "sample-data" / "qc_final_merged_dataset.csv",
    ]

    for candidate in candidates:
        if candidate.exists():
            return candidate

    return candidates[0]


def default_model_path():
    return SCRIPT_DIR / "qc_energy_model_final.pkl"


def default_metrics_path():
    return SCRIPT_DIR / "generated_metrics.json"




def read_rows(dataset_path):
    with open(dataset_path, newline="", encoding="utf-8") as file:
        reader = csv.DictReader(file)
        rows = []
        for row in reader:
            required = (
                "year", "month", "consumption_kwh", "temperature", "humidity",
                "rainfall", "solar_irradiance", "peak_demand_kw",
            )
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
            for key in ("lag_1", "lag_2", "trend", "month_sin", "month_cos"):
                if row.get(key) not in (None, ""):
                    parsed[key] = float(row[key])
            rows.append(parsed)


    return add_derived_features(sorted(rows, key=lambda item: (item["year"], item["month"])))




def add_derived_features(rows):
    for index, row in enumerate(rows):
        month = int(row["month"])
        previous_row = rows[index - 1] if index > 0 else row
        second_previous_row = rows[index - 2] if index > 1 else row


        row.setdefault("lag_1", previous_row["consumption_kwh"])
        row.setdefault("lag_2", second_previous_row["consumption_kwh"])
        row.setdefault("trend", float(index + 1))
        row.setdefault("month_sin", math.sin(2 * math.pi * month / 12))
        row.setdefault("month_cos", math.cos(2 * math.pi * month / 12))


    return rows




def predict_row(model, row):
    values = [1.0]
    for feature in model["features"]:
        values.append((row[feature] - model["means"][feature]) / model["stds"][feature])


    return sum(coefficient * value for coefficient, value in zip(model["coefficients"], values))




def next_period(year, month):
    if month == 12:
        return year + 1, 1


    return year, month + 1




def trend_for_period(rows, target_year, target_month):
    first_row = rows[0]
    first_year = int(first_row["year"])
    first_month = int(first_row["month"])
    target_index = ((target_year - first_year) * 12) + (target_month - first_month)


    return float(target_index + 1)




def average_context_for_month(rows, target_month):
    context_features = [
        "temperature",
        "humidity",
        "rainfall",
        "solar_irradiance",
        "peak_demand_kw",
    ]
    same_month_rows = [row for row in rows if int(row["month"]) == target_month]
    source_rows = same_month_rows or rows[-12:] or rows


    return {
        feature: sum(row[feature] for row in source_rows) / len(source_rows)
        for feature in context_features
    }




def build_forecast_input(rows, target_year, target_month):
    context = average_context_for_month(rows, target_month)
    last_row = rows[-1]
    second_last_row = rows[-2]


    return {
        "year": float(target_year),
        "month": float(target_month),
        "temperature": context["temperature"],
        "humidity": context["humidity"],
        "rainfall": context["rainfall"],
        "solar_irradiance": context["solar_irradiance"],
        "peak_demand_kw": context["peak_demand_kw"],
        "lag_1": last_row["consumption_kwh"],
        "lag_2": second_last_row["consumption_kwh"],
        "trend": trend_for_period(rows, target_year, target_month),
        "month_sin": math.sin(2 * math.pi * target_month / 12),
        "month_cos": math.cos(2 * math.pi * target_month / 12),
    }




def main():
    dataset_path = Path(sys.argv[1]) if len(sys.argv) > 1 else default_dataset_path()
    model_path = Path(sys.argv[2]) if len(sys.argv) > 2 else default_model_path()
    metrics_path = Path(sys.argv[3]) if len(sys.argv) > 3 else default_metrics_path()
    train_script_path = default_path("train_model.py")


    rows = read_rows(dataset_path)
    if len(rows) < 3:
        raise ValueError("At least 3 processed rows are required to predict the next month.")


    if not os.path.exists(model_path):
        model_path.parent.mkdir(parents=True, exist_ok=True)
        metrics_path.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run(
            [sys.executable, train_script_path, dataset_path, model_path, metrics_path],
            check=True,
            capture_output=True,
            text=True,
        )


    try:
        with open(model_path, "rb") as model_file:
            model = pickle.load(model_file)
    except Exception:
        model_path.parent.mkdir(parents=True, exist_ok=True)
        metrics_path.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run(
            [sys.executable, train_script_path, dataset_path, model_path, metrics_path],
            check=True,
            capture_output=True,
            text=True,
        )
        with open(model_path, "rb") as model_file:
            model = pickle.load(model_file)


    # Only actual dataset rows determine the horizon and lag values.
    last_row = rows[-1]
    next_year, next_month = next_period(int(last_row["year"]), int(last_row["month"]))
    forecast_row = build_forecast_input(rows, next_year, next_month)
    prediction = round(float(predict_row(model, forecast_row)), 2)
    previous = round(float(forecast_row["lag_1"]), 2)
    change_percent = round(((prediction - previous) / previous) * 100, 2) if previous else 0
    metrics = {}


    if os.path.exists(metrics_path):
        with open(metrics_path, "r", encoding="utf-8") as metrics_file:
            metrics = json.load(metrics_file)


    print(json.dumps({
        "year": next_year,
        "month": next_month,
        "predicted_consumption_kwh": prediction,
        "previous_consumption_kwh": previous,
        "change_percent": change_percent,
        "mae": metrics.get("mae"),
        "rmse": metrics.get("rmse"),
        "r2_score": metrics.get("r2_score"),
        "model_type": metrics.get("model_type", model.get("model_type", "Linear Regression")),
    }))


if __name__ == "__main__":
    main()
