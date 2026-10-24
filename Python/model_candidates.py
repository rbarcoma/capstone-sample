"""Offline reduced linear candidates. Imported by evaluation, never production.

OLS uses unpenalized least squares. Ridge uses penalty 0.1 on standardized
feature coefficients only. Both obey n > parameter_count before fitting.
Householder QR rejects rank-deficient OLS designs instead of adding a penalty.
"""
import hashlib
import json
import math

FEATURE_SET = "reduced_consumption_calendar_v1"
FEATURES = ("lag_1", "lag_2", "month_sin", "month_cos", "trend")
PARAMETER_COUNT = len(FEATURES) + 1
RANK_TOLERANCE = 1e-10
OLS = "reduced_consumption_calendar_ols"
RIDGE = "reduced_consumption_calendar_ridge_0_1"
CANDIDATES = {
    OLS: {"model_type": "Linear Regression", "penalty": 0.0},
    RIDGE: {"model_type": "Ridge (penalty 0.1)", "penalty": 0.1},
}


class CandidateUnavailable(ValueError):
    def __init__(self, reason, detail):
        super().__init__(detail)
        self.reason = reason


def configuration():
    feature_definition = {
        "id": FEATURE_SET,
        "features": list(FEATURES),
        "parameter_count": PARAMETER_COUNT,
        "identifiability": "training_rows > parameter_count",
        "standardization": "training_only_population_std_constant_scale_1",
    }
    return {
        "feature_set": feature_definition,
        "feature_set_signature": hashlib.sha256(json.dumps(feature_definition, sort_keys=True).encode()).hexdigest(),
        "solver": "householder_qr",
        "rank_tolerance": RANK_TOLERANCE,
        "rank_policy": "skip_rank_deficient_ols; ridge_augments_nonintercept_columns",
        "candidates": {name: info.copy() for name, info in CANDIDATES.items()},
    }


def _least_squares(matrix, target):
    """Full-column-rank least squares via QR, without normal-equation inversion."""
    a = [row[:] for row in matrix]
    b = target[:]
    row_count, column_count = len(a), len(a[0])
    scale = max(1.0, max(math.hypot(*(row[column] for row in a)) for column in range(column_count)))
    threshold = RANK_TOLERANCE * scale
    for column in range(column_count):
        vector = [a[row][column] for row in range(column, row_count)]
        norm = math.hypot(*vector)
        if norm <= threshold:
            raise CandidateUnavailable("rank_deficient_design", "Design is not full column rank at the predeclared QR tolerance.")
        diagonal = -math.copysign(norm, vector[0])
        vector[0] -= diagonal
        vector_norm = math.hypot(*vector)
        vector = [value / vector_norm for value in vector]
        for right in range(column, column_count):
            projection = 2 * math.fsum(value * a[column + offset][right] for offset, value in enumerate(vector))
            for offset, value in enumerate(vector):
                a[column + offset][right] -= projection * value
        projection = 2 * math.fsum(value * b[column + offset] for offset, value in enumerate(vector))
        for offset, value in enumerate(vector):
            b[column + offset] -= projection * value
        a[column][column] = diagonal
        for row in range(column + 1, row_count):
            a[row][column] = 0.0
    coefficients = [0.0] * column_count
    for row in range(column_count - 1, -1, -1):
        coefficients[row] = (b[row] - math.fsum(a[row][column] * coefficients[column]
                                              for column in range(row + 1, column_count))) / a[row][row]
    return coefficients


def fit_candidate(rows, candidate):
    return _fit(rows, candidate, CANDIDATES[candidate])


def fit_ridge(rows, penalty):
    """Offline tuned Ridge; fixed candidates and their configuration stay intact."""
    if not math.isfinite(penalty) or penalty <= 0:
        raise ValueError("Ridge penalty must be finite and positive.")
    return _fit(rows, "reduced_consumption_calendar_ridge_tuned",
                {"model_type": "Ridge (training-only tuned penalty)", "penalty": penalty})


def _fit(rows, candidate, specification):
    if len(rows) <= PARAMETER_COUNT:
        raise CandidateUnavailable(
            "insufficient_residual_degrees_of_freedom",
            f"Training rows ({len(rows)}) must exceed parameter count ({PARAMETER_COUNT}, including intercept).",
        )
    if not all(math.isfinite(float(row[field])) for row in rows for field in (*FEATURES, "consumption_kwh")):
        raise ValueError("Candidate training values must be finite.")
    means = {feature: math.fsum(float(row[feature]) for row in rows) / len(rows) for feature in FEATURES}
    stds = {feature: math.sqrt(math.fsum((float(row[feature]) - means[feature]) ** 2 for row in rows) / len(rows)) or 1.0
            for feature in FEATURES}
    matrix = [[1.0, *[(float(row[feature]) - means[feature]) / stds[feature] for feature in FEATURES]] for row in rows]
    target = [float(row["consumption_kwh"]) for row in rows]
    penalty = specification["penalty"]
    if penalty:
        # An unpenalized intercept; augmented rows implement lambda * ||beta||².
        for feature_index in range(1, PARAMETER_COUNT):
            row = [0.0] * PARAMETER_COUNT
            row[feature_index] = math.sqrt(penalty)
            matrix.append(row)
            target.append(0.0)
    coefficients = _least_squares(matrix, target)
    if not all(math.isfinite(value) for value in coefficients):
        raise ValueError("Candidate coefficients must be finite.")
    return {
        "candidate_id": candidate,
        "model_type": specification["model_type"],
        "penalty": penalty,
        "feature_set": FEATURE_SET,
        "features": list(FEATURES),
        "parameter_count": PARAMETER_COUNT,
        "training_rows": len(rows),
        "means": means,
        "stds": stds,
        "coefficients": coefficients,
    }


def predict_row(model, row):
    values = [1.0, *[(float(row[feature]) - model["means"][feature]) / model["stds"][feature] for feature in model["features"]]]
    if not all(math.isfinite(value) for value in values):
        raise ValueError("Candidate prediction inputs must be finite.")
    return math.fsum(coefficient * value for coefficient, value in zip(model["coefficients"], values))
