import json
import os
import pickle
import subprocess
import sys
from pathlib import Path

from monthly_features import FEATURE_VERSION, read_rows, build_forecast_input




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




def predict_row(model, row):
    values = [1.0]
    for feature in model["features"]:
        values.append((row[feature] - model["means"][feature]) / model["stds"][feature])


    return sum(coefficient * value for coefficient, value in zip(model["coefficients"], values))




def next_period(year, month):
    if month == 12:
        return year + 1, 1


    return year, month + 1




def main():
    dataset_path = Path(sys.argv[1]) if len(sys.argv) > 1 else default_dataset_path()
    model_path = Path(sys.argv[2]) if len(sys.argv) > 2 else default_model_path()
    metrics_path = Path(sys.argv[3]) if len(sys.argv) > 3 else default_metrics_path()
    train_script_path = default_path("train_model.py")


    rows = read_rows(dataset_path)
    if len(rows) < 3:
        raise ValueError("At least 3 processed rows are required to predict the next month.")


    last_row = rows[-1]
    next_year, next_month = next_period(int(last_row["year"]), int(last_row["month"]))
    forecast_row = build_forecast_input(rows, next_year, next_month)

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
        if model.get("feature_version") != FEATURE_VERSION:
            raise ValueError("Model uses an outdated feature definition.")
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
        "metric_definitions": metrics.get("metric_definitions"),
        "metric_scope": metrics.get("metric_scope"),
        "model_type": metrics.get("model_type", model.get("model_type", "Ridge (penalty 0.1)")),
    }))


if __name__ == "__main__":
    main()
