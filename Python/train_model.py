import json
import math
import pickle
import sys
from pathlib import Path


from monthly_features import FEATURE_VERSION, FEATURES, read_rows, supervised_rows

CROSS_VALIDATION_FOLDS = 5




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




def log(message):
    print(message, flush=True)




def split_train_test(rows):
    test_count = max(1, math.ceil(len(rows) * 0.25))
    if len(rows) - test_count < 3:
        test_count = max(1, len(rows) - 3)


    return rows[:-test_count], rows[-test_count:]




def standardize(train_rows):
    means = {}
    stds = {}


    for feature in FEATURES:
        values = [row[feature] for row in train_rows]
        mean = sum(values) / len(values)
        variance = sum((value - mean) ** 2 for value in values) / len(values)
        std = math.sqrt(variance) or 1.0
        means[feature] = mean
        stds[feature] = std


    return means, stds



def matrix_for(rows, means, stds):
    matrix = []
    for row in rows:
        matrix.append([1.0] + [(row[feature] - means[feature]) / stds[feature] for feature in FEATURES])


    return matrix




def solve_linear_system(matrix, vector):
    size = len(vector)
    augmented = [matrix[row][:] + [vector[row]] for row in range(size)]


    for column in range(size):
        pivot = max(range(column, size), key=lambda row: abs(augmented[row][column]))
        augmented[column], augmented[pivot] = augmented[pivot], augmented[column]


        pivot_value = augmented[column][column]
        if abs(pivot_value) < 1e-12:
            augmented[column][column] = 1e-12
            pivot_value = augmented[column][column]


        for item in range(column, size + 1):
            augmented[column][item] /= pivot_value


        for row in range(size):
            if row == column:
                continue
            factor = augmented[row][column]
            for item in range(column, size + 1):
                augmented[row][item] -= factor * augmented[column][item]


    return [augmented[row][size] for row in range(size)]




def train_model(train_rows):
    means, stds = standardize(train_rows)
    x = matrix_for(train_rows, means, stds)
    y = [row["consumption_kwh"] for row in train_rows]
    columns = len(x[0])
    ridge = 0.1


    xtx = [[0.0 for _ in range(columns)] for _ in range(columns)]
    xty = [0.0 for _ in range(columns)]


    for row_values, target in zip(x, y):
        for left in range(columns):
            xty[left] += row_values[left] * target
            for right in range(columns):
                xtx[left][right] += row_values[left] * row_values[right]


    for index in range(1, columns):
        xtx[index][index] += ridge


    coefficients = solve_linear_system(xtx, xty)


    return {
        "feature_version": FEATURE_VERSION,
        "model_type": "Ridge (penalty 0.1)",
        "features": FEATURES,
        "means": means,
        "stds": stds,
        "coefficients": coefficients,
    }




def predict_row(model, row):
    values = [1.0]
    for feature in model["features"]:
        values.append((row[feature] - model["means"][feature]) / model["stds"][feature])


    return sum(coefficient * value for coefficient, value in zip(model["coefficients"], values))




def regression_metrics(actual, predicted):
    """Score exactly the supplied observations, retaining negative/undefined R²."""
    if len(actual) != len(predicted):
        raise ValueError("Actual and prediction counts must match.")
    if not all(math.isfinite(value) for value in (*actual, *predicted)):
        raise ValueError("Evaluation values must be finite.")
    count = len(actual)
    if not count:
        return {"count": 0, "mae": None, "rmse": None, "r2_score": None}
    errors = [target - estimate for target, estimate in zip(actual, predicted)]
    squared_error = math.fsum(error ** 2 for error in errors)
    mean = math.fsum(actual) / count
    variance = math.fsum((target - mean) ** 2 for target in actual)
    r2 = 1 - squared_error / variance if count >= 2 and variance > 0 else None
    return {
        "count": count,
        "mae": round(math.fsum(abs(error) for error in errors) / count, 4),
        "rmse": round(math.sqrt(squared_error / count), 4),
        "r2_score": round(r2, 4) if r2 is not None else None,
    }



def evaluate(model, rows):
    metrics = regression_metrics([row["consumption_kwh"] for row in rows],
                                 [predict_row(model, row) for row in rows])
    return {key: metrics[key] for key in ("mae", "rmse", "r2_score")} | {"model_type": model["model_type"]}


def average_metric(fold_metrics, key):
    values = [metrics[key] for metrics in fold_metrics if metrics[key] is not None]
    return round(sum(values) / len(values), 4) if values else None


def metric_definitions():
    return json.loads((PROJECT_ROOT / "resources" / "forecast-metrics.json").read_text(encoding="utf-8"))


def metric_label(value):
    return "Unavailable" if value is None else str(value)


def cross_validate(rows, fold_count=CROSS_VALIDATION_FOLDS):
    minimum_training_rows = max(3, len(rows) // (fold_count + 1))
    remaining_rows = len(rows) - minimum_training_rows
    fold_count = min(fold_count, remaining_rows)
    validation_size = max(1, remaining_rows // fold_count)
    fold_metrics = []

    for index in range(fold_count):
        train_end = minimum_training_rows + (index * validation_size)
        validation_end = len(rows) if index == fold_count - 1 else train_end + validation_size
        training_rows = rows[:train_end]
        validation_rows = rows[train_end:validation_end]

        if not validation_rows:
            continue

        model = train_model(training_rows)
        metrics = evaluate(model, validation_rows)
        fold_metrics.append({
            "fold": index + 1,
            "training_rows": len(training_rows),
            "validation_rows": len(validation_rows),
            "mae": metrics["mae"],
            "rmse": metrics["rmse"],
            "r2_score": metrics["r2_score"],
        })

    return {
        "folds": len(fold_metrics),
        "label": "Cross-validation fold averages (not pooled)",
        "aggregation": "unweighted_mean_of_defined_fold_scores",
        "defined_folds": {key: sum(fold[key] is not None for fold in fold_metrics)
                          for key in ("mae", "rmse", "r2_score")},
        "average_mae": average_metric(fold_metrics, "mae"),
        "average_rmse": average_metric(fold_metrics, "rmse"),
        "average_r2_score": average_metric(fold_metrics, "r2_score"),
        "fold_metrics": fold_metrics,
    }




def period_label(row):
    return f"{int(row['year'])}-{int(row['month']):02d}"




def main():
    dataset_path = Path(sys.argv[1]) if len(sys.argv) > 1 else default_dataset_path()
    model_path = Path(sys.argv[2]) if len(sys.argv) > 2 else default_model_path()
    metrics_path = Path(sys.argv[3]) if len(sys.argv) > 3 else default_metrics_path()


    log("Training machine learning model...")
    log(f"Dataset: {dataset_path}")
    log(f"Model output: {model_path}")
    log(f"Metrics output: {metrics_path}")
    log("")


    actuals = read_rows(dataset_path)
    rows = supervised_rows(actuals)
    if len(rows) < 6:
        raise ValueError("At least 6 eligible monthly rows with both preceding calendar actuals are required to train the model.")


    log(f"Loaded {len(rows)} rows from {period_label(rows[0])} to {period_label(rows[-1])}.")
    log(f"Using {len(FEATURES)} features: {', '.join(FEATURES)}")


    train_rows, test_rows = split_train_test(rows)
    log(f"Model validation uses {len(train_rows)} training rows and {len(test_rows)} holdout test rows.")
    log("")


    log(f"Running {CROSS_VALIDATION_FOLDS}-fold rolling cross-validation on the training rows...")
    cross_validation = cross_validate(train_rows)
    for fold in cross_validation["fold_metrics"]:
        log(
            f"Fold {fold['fold']}: "
            f"{fold['training_rows']} training rows, "
            f"{fold['validation_rows']} validation rows, "
            f"MAE={metric_label(fold['mae'])}, RMSE={metric_label(fold['rmse'])}, R2={metric_label(fold['r2_score'])}"
        )
    log(
        "Cross-validation fold averages (not pooled): "
        f"MAE={metric_label(cross_validation['average_mae'])}, "
        f"RMSE={metric_label(cross_validation['average_rmse'])}, "
        f"R2={metric_label(cross_validation['average_r2_score'])} "
        f"({cross_validation['defined_folds']['r2_score']}/{cross_validation['folds']} defined R2 folds)"
    )
    log("")


    validation_model = train_model(train_rows)
    metrics = evaluate(validation_model, test_rows)
    log("Holdout test evaluation:")
    log(f"MAE: {metric_label(metrics['mae'])}")
    log(f"RMSE: {metric_label(metrics['rmse'])}")
    log(f"R2 score: {metric_label(metrics['r2_score'])}")
    log("")


    metrics["cross_validation"] = cross_validation
    metrics["metric_definitions"] = metric_definitions()
    metrics["metric_scope"] = "chronological_holdout"
    metrics["training_rows"] = len(train_rows)
    metrics["testing_rows"] = len(test_rows)
    metrics["total_rows"] = len(rows)
    metrics["actual_rows"] = len(actuals)
    metrics["excluded_missing_lags"] = len(actuals) - len(rows)
    metrics["feature_version"] = FEATURE_VERSION
    metrics["evaluation_periods"] = [period_label(row) for row in test_rows]
    metrics["training_strategy"] = "rolling_cross_validation_then_final_full_dataset_training"


    model = train_model(rows)
    log(f"Final production model trained on all {len(rows)} rows.")


    model_path.parent.mkdir(parents=True, exist_ok=True)
    metrics_path.parent.mkdir(parents=True, exist_ok=True)

    with open(model_path, "wb") as model_file:
        pickle.dump(model, model_file)


    with open(metrics_path, "w", encoding="utf-8") as metrics_file:
        json.dump(metrics, metrics_file)


    log(f"Saved model to {model_path}")
    log(f"Saved metrics to {metrics_path}")
    log("")
    log("Training complete.")
    log("Final metrics JSON:")
    print(json.dumps(metrics))




if __name__ == "__main__":
    main()
