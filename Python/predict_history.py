import json
import pickle
import sys
from pathlib import Path

from monthly_features import FEATURE_VERSION, read_rows, supervised_rows


SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent
STORAGE_ML_DIR = PROJECT_ROOT / "storage" / "app" / "private" / "ml"


def default_dataset_path():
    return STORAGE_ML_DIR / "processed_dataset.csv"


def default_model_path():
    return STORAGE_ML_DIR / "qc_energy_model.pkl"


def predict_row(model, row):
    values = [1.0]
    for feature in model["features"]:
        values.append((row[feature] - model["means"][feature]) / model["stds"][feature])

    return sum(coefficient * value for coefficient, value in zip(model["coefficients"], values))


def main():
    dataset_path = Path(sys.argv[1]) if len(sys.argv) > 1 else default_dataset_path()
    model_path = Path(sys.argv[2]) if len(sys.argv) > 2 else default_model_path()

    rows = read_rows(dataset_path)

    with open(model_path, "rb") as model_file:
        model = pickle.load(model_file)

    if model.get("feature_version") != FEATURE_VERSION:
        raise ValueError("Model uses an outdated feature definition; retrain before predicting history.")

    predictions = []
    for row in supervised_rows(rows):
        predicted = round(float(predict_row(model, row)), 2)

        year = int(row["year"])
        month = int(row["month"])
        predictions.append({
            "period": f"{year}-{month:02d}",
            "year": year,
            "month": month,
            "fitted": predicted,
            "kind": "in_sample_fit",
        })

    print(json.dumps(predictions))


if __name__ == "__main__":
    main()
