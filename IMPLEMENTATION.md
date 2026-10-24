# IMPLEMENTATION.md — Implementation History

> **Last updated:** 2026-10-22.
> **Companion documents:** `AGENTS.md` (guidelines) and `CURRENT.md` (live status).

---

## 2026-10-21 — Dataset-based monthly forecasting

**Problem identified.** The previous workflow derived the forecast horizon from the system clock / recursive prediction rather than from the latest validated actual month, so the target period could drift ahead of the available data.

**Root cause (confirmed).** There was no shared notion of the target period between ingestion, training, prediction, and the read-side queries; the horizon was derived independently in each module.

**Solution implemented.** Introduced `app/Support/ForecastPeriod.php` as the single source of the next actual-based period; rewrote `Python/predict_next_month.py` so the horizon and lag values come from actual dataset rows only; added the workflow period guard that rejects an out-of-horizon prediction; aligned all module queries to `ForecastPeriod`; added upload completeness gating so incomplete daily months are excluded; added regression coverage for horizon, rollover, repeated runs, and incomplete input.

**Files created or modified (commit `22070d8`, "Update capstone DSS with dataset-based forecasting"):**
- `Dockerfile.ml`
- `Python/predict_next_month.py`
- `Python/tests/test_predict_next_month.py`
- `app/Http/Controllers/Admin/AnalyticsController.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Admin/DataManagementController.php`
- `app/Http/Controllers/Admin/DecisionSupportController.php`
- `app/Http/Controllers/Admin/ForecastingController.php`
- `app/Http/Controllers/Admin/ReportController.php`
- `app/Http/Controllers/ForecastController.php`
- `app/Http/Controllers/User/ViewerController.php`
- `app/Support/ForecastPeriod.php`
- `app/Support/MlWorkflow.php`
- `app/Support/ReportGenerator.php`
- `compose.yaml`
- `phpunit.xml`
- `requirements-ml.txt`
- `tests/Feature/ForecastPeriodTest.php`

**ML algorithms or forecasting logic affected.** Next-month horizon derivation (`next_period`), lag sourcing from the latest/second-latest actual rows, elapsed-month trend computation (`trend_for_period`), the `ForecastPeriod` query alignment, and the upload → preprocess → train → predict → DSS → report chain. The model algorithm itself was unchanged.

**Tests executed and their actual results (re-run 2026-10-21; these are the results produced on that date, not necessarily the results at commit time):**

Command:
```
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s Python/tests -v
```
Output (tail):
```
test_december_rolls_over_to_january (test_predict_next_month.NextMonthPredictionTest) ... ok
test_incomplete_or_invalid_october_cannot_advance_period (test_predict_next_month.NextMonthPredictionTest) ... ok
test_latest_period_is_chronological_not_last_csv_row (test_predict_next_month.NextMonthPredictionTest) ... ok
test_missing_historical_months_do_not_change_the_horizon (test_predict_next_month.NextMonthPredictionTest) ... ok
test_october_actual_allows_november (test_predict_next_month.NextMonthPredictionTest) ... ok
test_september_forecasts_only_october_and_repeated_runs_do_not_advance (test_predict_next_month.NextMonthPredictionTest) ... ok
test_zero_consumption_is_a_valid_actual (test_predict_next_month.NextMonthPredictionTest) ... ok

----------------------------------------------------------------------
Ran 7 tests in 0.007s

OK
```

Command:
```
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Output:
```
{"tool":"pest","result":"passed","tests":17,"passed":17,"assertions":152}
```

Command:
```
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact
```
Output:
```
{"tool":"pest","result":"passed","tests":62,"passed":57,"assertions":311,"skipped":5}
```
(The 5 skips are all `tests/Feature/Auth/PasswordResetTest.php`, via `skipUnlessFortifyHas(Features::resetPasswords())`; `Features::resetPasswords()` is not enabled in `config/fortify.php:147-149`. Expected, not failures.)

**State of this entry:** *Implemented* (commit `22070d8` exists in git history) and *Tested/Verified* (the three commands above passed on 2026-10-21). Horizon behaviour is *Verified*; upload-completeness gating is *Verified* via `ForecastPeriodTest.php:72-125`.

**Remaining limitations / follow-up tasks.** This commit did **not** address the forecasting defects listed in `AGENTS.md` §3.2: the negative R² (-7.7672), self-target lags, calendar-gap handling, train/serve leakage, the mislabeled regularized fit, misleading CV summaries, Analytics mixing of fitted values with saved forecasts, limited provenance, single-series storage mixing, DSS kW/kWh mismatch, or the misleading UI labels. Those remain open and are tracked in `CURRENT.md` §3-4.

*Note: this entry is recorded retrospectively on 2026-10-21 from git commit `22070d8`; the test output shown is what was re-run on 2026-10-21.*

---

## 2026-10-21 — Documentation baseline: AGENTS.md, CURRENT.md, IMPLEMENTATION.md

**Problem identified.** The project had no project-wide guidelines document, no live status file, and no implementation history; the assessment findings had no durable home and the two tracking files were empty.

**Root cause (confirmed).** The documentation set was never established.

**Solution implemented.** Restructured `AGENTS.md` in place into Project Guidelines (scope, development restrictions, existing ML forecasting problems with verified findings, technical improvement plan, testing requirements, documentation-maintenance workflow, working instructions, implementation map). Created `CURRENT.md` with project status, verified working features, root causes, pending tasks, in-progress work, completed tasks, next steps, and blockers. Created `IMPLEMENTATION.md` with this and the preceding entry.

**Files created or modified:**
- `AGENTS.md` (modified)
- `CURRENT.md` (created)
- `IMPLEMENTATION.md` (created)

**ML algorithms or forecasting logic affected.** None. Documentation only.

**Tests executed and their actual results.** No new tests were written or run for this task. The read-only verification runs performed to gather evidence for the documents are recorded above (Python `Ran 7 tests in 0.007s — OK`; focused Laravel `tests:17, passed:17, assertions:152`; full Laravel `tests:62, passed:57, assertions:311, skipped:5`). No application code was changed, so no new test execution is required or claimed.

**State of this entry:** *Implemented* (the three files now exist with the agreed content). *Tested/Verified* applies only to the underlying evidence runs recorded in the preceding entry and in `AGENTS.md` §5 — not to this documentation task itself.

**Remaining limitations / follow-up tasks.** The application defects in `AGENTS.md` §3.2 are documented but **not fixed**; no implementation is authorized by this task. The next authorized implementation request should define the approved scope.

---

## 2026-10-21 — Documentation consistency pass: designated next task and ML dependency status

**Problem identified.** `CURRENT.md` did not name the next implementation task, so the required work existed only implicitly in the `AGENTS.md` §4 Priority 2 plan. `CURRENT.md` §8 also presented the missing local Python ML packages and the absent host path `/opt/ml-venv/bin/python` as project `Blocked` items, which conflated a host-environment limitation with a project defect. Finally, `CURRENT.md` §1 labelled the recorded hash `9bd1e072…` as a "model-state hash" although it is the **dataset** hash.

**Root cause (confirmed).** The documentation baseline recorded the host interpreter's package state without separating it from the Docker ML image state, left the next task implicit, and mislabelled the dataset hash carried in `model_state.json`.

**Solution implemented.** Designated the next implementation task (self-target lag leakage and calendar-aware feature engineering) in `AGENTS.md` §1 and `CURRENT.md` §1, §4, §5, and §7, with the item list and the pending/started status stated explicitly. Reclassified the Python dependency items in `CURRENT.md` §8 as host-environment limitations under a new §8.2, kept the genuine data and sample-size items as `Blocked` in §8.1, and recorded a fresh verification of the Docker ML environment. Corrected the dataset-hash label and recorded the dataset sha256. No application code, test, UI, or schema was modified.

**Files created or modified:**
- `AGENTS.md` (modified)
- `CURRENT.md` (modified)
- `IMPLEMENTATION.md` (modified)

**ML algorithms or forecasting logic affected.** None. Documentation only; the active training, prediction, evaluation, DSS, and horizon logic is untouched.

**Tests executed and their actual results.** No test was added or changed. Read-only verification re-runs performed on 2026-10-21 (no application code was modified, so the recorded results are expected to be unchanged):

Command:
```
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s Python/tests -v
```
Output (tail):
```
Ran 7 tests in 0.010s

OK
```

Command:
```
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Output:
```
{"tool":"pest","result":"passed","tests":17,"passed":17,"assertions":152,"duration_ms":1010}
```

Command:
```
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact
```
Output:
```
{"tool":"pest","result":"passed","tests":62,"passed":57,"assertions":311,"duration_ms":3001,"skipped":5}
```

Docker ML environment re-verification on 2026-10-21 (Docker 29.7.2), against the image built from this project's `Dockerfile.ml`:
```
docker run --rm --entrypoint sh capstone-sample/ml-app:latest -c "/opt/ml-venv/bin/python -V && /opt/ml-venv/bin/python -c 'import numpy, sklearn, xgboost, pandas'"
→ Python 3.12.3
→ numpy 2.5.4 / sklearn 1.9.1 / xgboost 3.4.1 / pandas 3.0.6
```
```
docker exec capstone-sample-laravel.test-1 /opt/ml-venv/bin/python -c "import numpy,sklearn,xgboost;print('ml deps OK in running container')"
→ ml deps OK in running container
```
The running container mounts this project at `/var/www/html`. The host interpreter (Python 3.9.6) has no `numpy`, `sklearn`, or `xgboost`, and `/opt/ml-venv/bin/python` does not exist on the host.

**State of this entry:** *Implemented* (the three documents are updated and mutually consistent). *Tested/Verified* applies to the read-only re-runs and the Docker environment check quoted above; the documentation edits themselves require no test execution. The designated next implementation task is **pending, not started** — nothing in it is implemented, tested, or verified.

**Remaining limitations / follow-up tasks.** The defects listed in `AGENTS.md` §3.2 remain unfixed; no application change is authorized by this entry. The Docker ML availability is a point-in-time verification: re-verify it, and rebuild the image if `requirements-ml.txt` changes, before treating it as available. The beneficiary dataset and the 17-observation sample size remain genuine blockers for Priority 1 and Priority 3 conclusions.

---

## 2026-10-21 — Shared calendar features and removal of self-target lag leakage

**Problem identified.** The designated Priority 2 task required removing target-filled initial lags, using preceding calendar months rather than row offsets, sharing the feature definition across active paths, and proving that later actuals cannot affect earlier features/predictions.

**Root cause (confirmed).** PHP preprocessing and three Python readers maintained separate lag/trend implementations. Missing history was replaced with the row's own consumption, gaps changed lag meaning, CSV feature columns were trusted even when leaked, and serving estimated context while historical evaluation used target-month actual context. Stored lag columns could not represent missing history. Dataset-only cache keys could reuse models trained with the old feature definition.

**Solution implemented.** Added the stdlib-only `Python/monthly_features.py` as the canonical feature builder. PHP preprocessing sends actual rows through this builder using the configured Python process; actual consumption/climate values remain unchanged. Lag 1/2 use the exact preceding calendar periods, unavailable lags remain null, and trend counts elapsed months from the first actual including warm-up months. The migration makes only `processed_records.lag_1` and `lag_2` nullable. All actual rows remain in storage and period selection; missing-history rows are excluded only from supervised fitting. Six eligible rows are required to train, so a contiguous dataset needs at least eight actual months.

Training, holdout/CV inputs, historical-model inputs, and future inputs now share strictly past-only context estimates (same-calendar-month mean from earlier actuals, otherwise the last 12 earlier actual rows). Imported derived columns are always recomputed. A forecast missing either required preceding actual fails for the same target month without filling or advancing it. PHP preprocessing validates Python output before a transactional replacement using `DELETE`, preserving prior records on process failure and avoiding MySQL `TRUNCATE` implicit commits. Workflow model-state checks and artifact filenames include the shared-feature source signature; Python artifacts carry `calendar-past-only-v1`. Old feature-version models cannot silently receive the new input semantics.

**Files created or modified:**
- `Python/monthly_features.py` (created)
- `Python/train_model.py`
- `Python/predict_next_month.py`
- `Python/predict_history.py`
- `Python/tests/test_monthly_features.py` (created)
- `Python/tests/test_predict_next_month.py`
- `app/Support/EnergyPreprocessor.php`
- `app/Support/MlWorkflow.php`
- `database/migrations/2026_10_21_000001_make_processed_lags_nullable.php` (created)
- `tests/Feature/MonthlyFeaturesTest.php` (created)
- `tests/Feature/ForecastPeriodTest.php`
- `AGENTS.md` (shared-feature requirement, completed-task pointer, Docker test guidance; assessment retained as historical baseline)
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** Feature construction, warm-up/gap eligibility, historical/future context inputs, trend consistency, and compatibility checks changed. The custom fixed-penalty linear algorithm, its 12 predictors, its mislabeled model name, holdout percentage, CV summary formulas, DSS rules, and all UI/UX remain unchanged. The dataset-based next-month increment and shared period guard are preserved. The synthetic end-to-end fixture now supplies eight actual months rather than six because leaked warm-up rows are no longer trainable. A sparse-history predictor test now expects an explicit missing-lag error for the unchanged target.

**Tests executed and their actual results.** All following verification ran in the existing Docker container `capstone-sample-laravel.test-1` (`capstone-sample/ml-app`, project mounted at `/var/www/html`, Python 3.12.3). SQLite used `:memory:` and the MySQL run explicitly used the dedicated `testing` database. Outputs below are the actual relevant tails with terminal color escapes omitted.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Output:
```text
Ran 18 tests in 0.044s

OK
```
The 11 new tests cover missing initial lags, zero actuals, calendar gaps/rollover, unsorted/duplicate periods, reconstruction of leaked export features in every reader, historical/future feature identity, exclusion of target-month actual context, later-actual perturbations with unchanged earlier scaling/model predictions, same-period insufficiency, and insufficient eligible training history. The seven existing period tests remain present.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Output:
```text
Tests:    23 passed (187 assertions)
Duration: 4.28s
```

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=mysql DB_DATABASE=testing DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php
```
Output:
```text
Tests:    6 passed (35 assertions)
Duration: 3.40s
```
This run exercised the actual MySQL nullable-column migration, PHP→configured Python preprocessing, zero/null persistence, calendar-gap handling, preservation on process failure, changed-feature artifact invalidation, and real training/prediction/DSS/report integration. No production database migration was run.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Output:
```text
Tests:    5 skipped, 63 passed (346 assertions)
Duration: 5.00s
```
The five skips remain the expected Fortify password-reset tests, not passing tests.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint --test app/Support/EnergyPreprocessor.php app/Support/MlWorkflow.php database/migrations/2026_10_21_000001_make_processed_lags_nullable.php tests/Feature/MonthlyFeaturesTest.php tests/Feature/ForecastPeriodTest.php
```
Final output:
```text
PASS   ........................................................... 5 files
```
The initial Pint check reported two files with style issues; applying Pint to those changed files fixed them and the final check above passed. `git diff --check` also passed.

### Actual diagnostic measurements (not a claim of improved accuracy)

Before editing, the original baseline was reproduced inside Docker in memory: 17 actual rows, train/test 12/5, evaluation months 2026-04 through 2026-08, MAE 59,365.9026, RMSE 70,684.6888, R² -7.7672, next-period estimate 2026-09 = 203,347.49 kWh.

After the change, the following command measured corrected features against the same unchanged saved actual export. It fitted only in memory; no saved models, state, reports, forecasts, or export were replaced.

```sh
docker exec -i -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python - <<'PY'
import sys,hashlib,json
from pathlib import Path
sys.path.insert(0,'Python')
import train_model as t
import predict_next_month as p
from monthly_features import supervised_rows,FEATURE_VERSION
path=Path('storage/app/private/ml/processed_dataset.csv')
raw=t.read_rows(path);rows=supervised_rows(raw);train,test=t.split_train_test(rows)
y,m=p.next_period(int(raw[-1]['year']),int(raw[-1]['month']))
original_train=[r for r in rows if (int(r['year']),int(r['month'])) < (2026,4)]
original_test=[r for r in rows if (int(r['year']),int(r['month'])) >= (2026,4)]
print(json.dumps({'python':sys.version.split()[0],'dataset_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'feature_version':FEATURE_VERSION,'actual_rows':len(raw),'eligible_rows':len(rows),'excluded_missing_lags':len(raw)-len(rows),'default_split':{'train_rows':len(train),'test_rows':len(test),'test_periods':[t.period_label(r) for r in test],'metrics':t.evaluate(t.train_model(train),test)},'original_holdout_periods':{'train_rows':len(original_train),'test_rows':len(original_test),'test_periods':[t.period_label(r) for r in original_test],'metrics':t.evaluate(t.train_model(original_train),original_test)},'forecast_period':f'{y}-{m:02d}','forecast_kwh':round(p.predict_row(t.train_model(rows),p.build_forecast_input(raw,y,m)),2)},indent=2))
PY
```
Output:
```json
{
  "python": "3.12.3",
  "dataset_sha256": "9bd1e07208b721d7593456644c75a4b26085a9fe75df6933bacc8b01617f18d8",
  "feature_version": "calendar-past-only-v1",
  "actual_rows": 17,
  "eligible_rows": 15,
  "excluded_missing_lags": 2,
  "default_split": {
    "train_rows": 11,
    "test_rows": 4,
    "test_periods": ["2026-05", "2026-06", "2026-07", "2026-08"],
    "metrics": {
      "mae": 145518.0747,
      "rmse": 152088.6912,
      "r2_score": -94.426,
      "model_type": "Linear Regression"
    }
  },
  "original_holdout_periods": {
    "train_rows": 10,
    "test_rows": 5,
    "test_periods": ["2026-04", "2026-05", "2026-06", "2026-07", "2026-08"],
    "metrics": {
      "mae": 109629.9427,
      "rmse": 133508.656,
      "r2_score": -30.2772,
      "model_type": "Linear Regression"
    }
  },
  "forecast_period": "2026-09",
  "forecast_kwh": 177137.16
}
```
The default eligible-row split changes the evaluation window and must not be compared directly to the old five-month result. The original-month diagnostic still performs worse. This task corrects feature validity; it does not establish acceptable accuracy. More honest inputs and fewer eligible training rows expose the unchanged algorithm's limitations. September actual accuracy remains unknown.

**State of this entry:** *Implemented* (code and migration added), *Tested* (Docker commands executed), *Verified* (results inspected and passed). Production migration/reprocessing/retraining is not performed or claimed.

**Remaining limitations or follow-up tasks.** Apply the nullable-lag migration before preprocessing on deployment. Old persisted features/models/forecast records remain historical until the normal workflow is rerun; old-version historical prediction now refuses to display refits until retraining. Restoring non-null lag columns while null records exist requires an explicit data-handling decision; do not fabricate lags to force rollback. Twelve features on this short sample, poor accuracy, the mislabeled penalty, undefined/pooled metric handling, full walk-forward model comparison, remaining missing-context semantics, Analytics origin/accuracy problems, broader provenance, standalone legacy algorithm scripts, and DSS dimensional/reliability problems remain open. No unrelated feature, building-specific platform change, or UI redesign was implemented.

---

## 2026-10-22 — Status review and designation of the Priority 3 evaluation harness (documentation only)

**Problem identified.** The live status document was no longer accurate about the saved state of the system. `CURRENT.md` §1 still named export sha256 `9bd1e072…` and artifact `qc_energy_model_9bd1e07208b721d7.pkl` as the active dataset and model, and still stated that "production database, uploaded source, saved export, saved models, and forecast/report records were not regenerated". Application runs on 2026-10-21 had in fact migrated the local development MySQL database, rebuilt the processed records with nullable lags, regenerated the export, retrained the model, and written a forecast and DSS record. A saved forecast row for `2026-11` — outside the current dataset horizon of `2026-09` — also remains in that database carrying pre-change metrics. In addition, no next implementation scope was designated, so the remaining work existed only as the Priority list in `AGENTS.md` §4.

**Root cause (confirmed).** The documentation recorded the state as it stood when the Priority 2 task finished, and nothing re-derived it afterwards: the active artifact identity lives in `storage/app/private/ml/model_state.json` and in the files it names, so any statement about it goes stale as soon as the workflow retrains. Separately, the write path of `app/Support/MlWorkflow.php` only guards the period of a forecast it is creating; nothing rejects or removes an out-of-horizon row that was persisted earlier, and read-side filtering in `app/Support/ForecastPeriod.php:36-47` merely hides it. A status document cannot be kept correct by hand; it has to be re-derived from the state file and the database.

**Solution implemented.** Re-derived the active dataset hash, artifact, feature signature, metrics, database migration state, and saved forecast rows from the running system; corrected the stale statements in `CURRENT.md` §1 and §8; recorded two new confirmed open issues in `CURRENT.md` §3.2 (artifact identity is discoverable only from the state file; a stale out-of-horizon forecast row with pre-change metrics persists, and the active forecast's stored MAE/RMSE/R² describe a 25% holdout rather than out-of-sample performance); and designated the next highest-priority actionable scope — Priority 3, a reproducible out-of-sample evaluation harness and baseline comparison — in `CURRENT.md` §4, `CURRENT.md` §5/§7, and `AGENTS.md` §1, with an itemized scope, out-of-scope list, and constraints. No application code, UI, schema, dataset, saved artifact, or database record was changed.

**Files created or modified:**
- `AGENTS.md` (modified — §1 header date and designated implementation task)
- `CURRENT.md` (modified — §1 status and saved-state facts, §3.2 open issues, §4 Priority 3 designation, §5, §6, §7, §8)
- `IMPLEMENTATION.md` (modified — this entry)

**ML algorithms or forecasting logic affected.** None. This entry is documentation only. The active training, feature-building, prediction, evaluation, DSS, and horizon logic is untouched, as is the still-open defect list in `AGENTS.md` §3.

**Tests executed and their actual results.** No application code was modified, so the following are read-only re-verification runs whose results are expected to be unchanged; they are recorded here because the status document cites them. All ran in `capstone-sample-laravel.test-1` (`capstone-sample/ml-app`, project mounted at `/var/www/html`, Python 3.12.3 at `/opt/ml-venv/bin/python`) on 2026-10-22. Terminal color escapes are omitted.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Output (tail):
```text
----------------------------------------------------------------------
Ran 18 tests in 0.098s

OK
```

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Output:
```text
Tests:    23 passed (187 assertions)
Duration: 10.06s
```

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Output:
```text
Tests:    5 skipped, 63 passed (346 assertions)
Duration: 10.09s
```
The five skips remain the expected Fortify password-reset tests in `tests/Feature/Auth/PasswordResetTest.php` (`Features::resetPasswords()` is not enabled in `config/fortify.php:147-149`); they are skips, not passes.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=mysql DB_DATABASE=testing DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php
```
Output:
```text
Tests:    6 passed (35 assertions)
Duration: 3.48s
```

Read-only state inspection used to correct the status document (no writes; the application database was queried read-only and the verification runs did not alter the saved export, model state, or model artifacts):

```sh
shasum -a 256 storage/app/private/ml/processed_dataset.csv
→ f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af  storage/app/private/ml/processed_dataset.csv

shasum -a 256 Python/monthly_features.py
→ f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16  Python/monthly_features.py
```
`storage/app/private/ml/model_state.json`:
```json
{"dataset_hash":"f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af","feature_signature":"f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16","model_path":"ml/models/qc_energy_model_4e5c21436dfa4bc1.pkl","metrics_path":"ml/models/metrics_4e5c21436dfa4bc1.json","trained_at":"2026-10-21 15:49:20","trained_by":1}
```
The feature signature in the state file equals the sha256 of the current `Python/monthly_features.py`, so the active model was trained with the current feature definition.

`storage/app/private/ml/models/metrics_4e5c21436dfa4bc1.json` (active metrics; cross-validation summary removed here for length):
```json
{
  "mae": 145518.0747,
  "rmse": 152088.6912,
  "r2_score": -94.426,
  "model_type": "Linear Regression",
  "training_rows": 11,
  "testing_rows": 4,
  "total_rows": 15,
  "actual_rows": 17,
  "excluded_missing_lags": 2,
  "feature_version": "calendar-past-only-v1",
  "evaluation_periods": ["2026-05", "2026-06", "2026-07", "2026-08"],
  "training_strategy": "rolling_cross_validation_then_final_full_dataset_training"
}
```
Its cross-validation summary is 5 folds with average MAE `34873.559`, average RMSE `38488.1974`, average R² `-4.5703`; fold (training rows, validation rows, R²) is `(3,1,0)`, `(4,1,0)`, `(5,1,0)`, `(6,1,0)`, `(7,4,-22.8517)`. Four folds hold a single observation, so their R² is the zero-variance placeholder — the same defect recorded in `AGENTS.md` §3.2 item 7.

Application-database state, read-only:
```text
processed  null_lag1  null_lag2  avg_kwh
17         1          2          218067.06

id  year  month  predicted_consumption_kwh  mae         rmse         r2_score  model_type        predicted_at
1   2026  11     202870.53                 59365.9026  70684.6888  -7.7672    Linear Regression  2026-10-21 05:51:16
2   2026  9      177137.16                 145518.0747 152088.6912  -94.4260   Linear Regression  2026-10-21 15:49:24

migration                                      batch
2026_10_21_000001_make_processed_lags_nullable  2
```

**State of this entry:** *Implemented* (the three documents now describe the verified current state and the designated next scope). *Tested/Verified* applies to the re-verification runs and read-only state inspection quoted above; the documentation edits themselves require no test execution. The designated Priority 3 task is **pending, not started** — nothing in it is implemented, tested, or verified.

**Remaining limitations or follow-up tasks.** The defects listed in `AGENTS.md` §3.2 remain unfixed; no application change is authorized by this entry. The saved forecast for `2026-09` still carries the default 25% holdout metrics, and the stale `2026-11` row still exists in the local development database; both are recorded as open issues rather than silently corrected, because deleting a beneficiary-visible record or rewriting a forecast value is a data-handling decision that needs explicit authorization. The local development database has been migrated, reprocessed, and retrained, but no other environment has; the nullable-lag migration must be applied before preprocessing anywhere else. The beneficiary dataset and the 17-observation sample size remain genuine blockers for Priority 1 and for firm model-selection conclusions.

---

## 2026-10-22 — Priority 3, increment 1: walk-forward evaluation and naive baselines

**Problem identified.** The existing production diagnostics use a fixed chronological holdout and unequal CV summaries, with no reproducible refitted one-step comparison to deployable naive baselines. Prior diagnostic R² values used different evaluation windows. The user authorized incremental Priority 3 implementation, starting with walk-forward evaluation and naive baselines.

**Root cause (confirmed).** There was no shared evaluation harness recording per-origin cutoffs, baseline availability, common comparison periods, pooled errors, and reproducibility provenance. The existing training path and Analytics refits answer different questions and do not provide this comparison.

**Solution implemented.** Added `Python/evaluation.py`, a standalone stdlib CLI which reads the chosen CSV and prints JSON to stdout. At each available target month it truncates actuals before the target, rebuilds eligible training features using `monthly_features.py`, refits the unchanged fixed-penalty model on that history, constructs the one-month-ahead input, predicts, and only then reveals the target actual. No full-data fit is reused as a historical out-of-sample prediction. The default requires six eligible training rows; insufficient history and missing calendar lags are reported explicitly.

Baselines are last available observation, mean of all earlier actuals (including available warm-up observations), preceding 3- and 6-calendar-month means, and prior-year same-month actual. Recent means require complete calendar windows; seasonal-naive is unavailable without the actual prior-year month. Every metric in a comparison uses exactly the same target periods. The nonseasonal comparison includes the incumbent and four baselines; a separate seasonal-common comparison includes all six methods only where all are available. Excluded periods and unavailable baselines are disclosed rather than silently substituted.

MAE/RMSE are computed once from pooled errors. R² uses pooled actual variance, retains negative values, and is null for fewer than two observations or constant actuals. Empty comparisons have null metrics, not artificial zeros. Nonfinite predictions abort evaluation. The JSON records configuration, runtime, dataset/code hashes, feature identifier/version, rejected-input count, per-origin training periods/cutoff, exact feature inputs, predictions, actuals, and errors. Configurations are fixed in advance; this increment performs no tuning or model selection.

**Files created or modified:**
- `Python/evaluation.py` (created)
- `Python/tests/test_evaluation.py` (created)
- `AGENTS.md` (task-status pointer and evaluation test-coverage status)
- `CURRENT.md` (verified increment, measured evidence, remaining scope)
- `IMPLEMENTATION.md` (this entry)

Previously uncommitted Priority 2 files were preserved. No active trainer, predictor, Laravel controller/workflow, database schema, UI/UX, or saved artifact was changed by this increment.

**ML algorithms or forecasting logic affected.** New offline evaluation protocol only. The incumbent is explicitly identified as `current_fixed_penalty_0_1` with the existing 12 features and train-only standardization. The application still uses its saved holdout/CV metrics and existing forecast workflow; it has not adopted a baseline or new metric protocol. Dataset-based horizon selection, upload completeness checks, missing-history insufficiency, and the shared period guard are unchanged.

**Tests executed and their actual results.** Tests ran inside `capstone-sample-laravel.test-1`, project `/var/www/html`. Only synthetic fixtures were used in software tests; the application database and saved models were not test targets.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 35 tests in 0.455s

OK
```
The 17 new `test_evaluation.py` tests cover independent pooled error calculations, negative/undefined R², nonfinite data, exact past-only baseline windows, seasonal availability, chronological refits at every origin, matching shared inputs, identical comparison periods, target/future perturbations, explicit gap exclusions, short/constant series, no outcome-driven tuning, deterministic order, complete JSON provenance, invalid configuration, rejected-input disclosure, and absence of artifact writes. The existing 18 feature/horizon tests also passed.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Actual output tail (terminal colors omitted):
```text
Tests:    5 skipped, 63 passed (346 assertions)
Duration: 4.88s
```
The five skips remain the expected Fortify password-reset feature skips. This full run includes the existing horizon, null-lag, real PHP→Python, cache, DSS/report, and admin/viewer regressions. No new Laravel artifact-publication test is claimed: production integration remains pending. MySQL was not rerun because this increment makes no PHP or schema changes. `git diff --check` passed.

### Measured local-data diagnostic (not production publication)

Actual command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python Python/evaluation.py storage/app/private/ml/processed_dataset.csv
```
The command completed successfully and returned a JSON report. The following values are extracted directly from that output; full per-origin rows are reproducible by the command above.

Provenance:
```text
evaluation_version: walk-forward-baselines-v1
dataset_sha256: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
feature_version: calendar-past-only-v1
feature_signature: f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16
evaluation_signature: b3aa92c00f2cdff9f6eb0402b55f24e9255ff5d1e5c425cea6749d0d4c50dd08
training_signature: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
configuration_sha256: 3e8c0c85baf3e3a4538433678f2e6832ad2ede062dd0d77871e6f1ee48fc63aa
runtime: CPython 3.12.3
actual_rows: 17
eligible_rows: 15
rejected_input_rows: 0
available_actual_cutoff: 2026-08
min_eligible_training_rows: 6
feature_set: current_12_features
penalty: 0.1
```

Nine origins were evaluated. Eight earlier targets were explicitly skipped for insufficient eligible training rows. The first target `2025-12` used cutoff `2025-11` and 8 actual / 6 eligible training rows; the final target `2026-08` used cutoff `2026-07` and 16 actual / 14 eligible training rows. Both features and transforms were rebuilt from the truncated history at each origin.

Nonseasonal common comparison, `2025-12` through `2026-08`, **9 identical targets for every method**:

| Method | MAE kWh | RMSE kWh | Pooled R² |
| --- | --- | --- | --- |
| Current fixed penalty 0.1 | 74110.4097 | 95909.0322 | -14.2725 |
| Last observation | 26586.6667 | 31500.1362 | -0.6475 |
| History mean | 27901.8392 | 37303.7390 | -1.3105 |
| Recent 3-month mean | 29197.0370 | 32830.8917 | -0.7896 |
| Recent 6-month mean | 25621.4815 | 32653.3047 | -0.7703 |

Seasonal common comparison, **5 identical targets** `2026-04` through `2026-08`. The report explicitly excludes `2025-12`, `2026-01`, `2026-02`, and `2026-03` from this comparison because prior-year same-month actuals do not exist.

| Method | MAE kWh | RMSE kWh | Pooled R² |
| --- | --- | --- | --- |
| Current fixed penalty 0.1 | 107434.5265 | 124318.2179 | -26.1193 |
| Last observation | 22540.0000 | 28662.4144 | -0.4416 |
| History mean | 25950.0480 | 36593.7436 | -1.3498 |
| Recent 3-month mean | 32480.0000 | 33641.9299 | -0.9860 |
| Recent 6-month mean | 24688.6667 | 30951.5002 | -0.6810 |
| Seasonal naive | 42420.0000 | 46656.9823 | -2.8198 |

The baselines outperform the incumbent in this diagnostic. Last-observation has the lowest measured RMSE in both comparisons; the 6-month mean has lower MAE in the nine-origin comparison. This is not sufficient evidence of a robust production winner, and it must not be conflated with the saved -94.4260 holdout score or the earlier -30.2772 fixed-window diagnostic. No positive accuracy claim or automatic model selection is made.

Preservation check after evaluation and tests:
```text
Verified: saved export, model state, model artifact, and metrics JSON hashes are unchanged.
```
The checked sha256 values were:
```text
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json: 0aaf29a2074b2d6052a95f5cc05b8f20a5a2f024b3caf812776ac8601ae7a11e
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json: b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
```

**State of this entry:** first increment *Implemented*, *Tested*, and *Verified*. The rest of the designated Priority 3 scope is pending, not completed.

**Remaining limitations or follow-up tasks.** Add ordinary Linear Regression, training-only tuned Ridge, and reduced-feature comparisons; establish a defensible selection/untouched-holdout policy; integrate selected-model and evaluation provenance into production artifacts; add Laravel tests for configuration invalidation, publication failure safety, and matching model/metrics. The standalone null-R²/pooled-metric semantics are not yet applied to the existing application metrics. Billing publication timing is unverified; evaluation assumes preceding actuals are available at each origin. Seasonal scores have shorter coverage and must not be compared to another comparison's different periods. With only 17 actuals and 9 evaluated origins, uncertainty remains substantial. Analytics/DSS/UI semantics, beneficiary data verification, and other unrelated tasks were not implemented.

---

## 2026-10-22 — Second review: increment-1 re-verification, status correction, and Priority 3.2 designation

**Problem identified.** The documentation record for the first Priority 3 increment mixed verification dates, quoted only three of six seasonal metrics in `CURRENT.md`, and asserted that the saved state file was unchanged. A later ordinary application run had already rewritten `storage/app/private/ml/model_state.json`, so the recorded preservation hash no longer matched the file. In addition, the designated task block still described the whole Priority 3 scope and named no small next increment.

**Root cause (confirmed).** Status statements and preservation hashes are point-in-time claims about mutable application state. The workflow rewrites `model_state.json` (`trained_at`) and re-issues the dataset-next-month forecast on ordinary runs, so a hash recorded during one run is superseded by the next. The documentation did not distinguish when each verification result was produced, and the seasonal table was transcribed partially.

**Solution implemented.** Documentation-only correction and designation. `CURRENT.md` now dates each verification result, records the byte-identical deterministic re-train and re-issued forecast, replaces the superseded state-file preservation claim, carries the complete six-method seasonal table and a nine-row per-origin error table, adds the measured incumbent-vs-baseline deficit as a confirmed open issue, and designates **Priority 3.2 — model-variant comparison inside the existing walk-forward harness** with scope, identifiability rule, constraints, out-of-scope work, and acceptance. `AGENTS.md` updates the designated task, adds the measured model-deficit problem, and extends the model-evaluation test requirement. No source, artifact, database, or UI change.

**Files created or modified:**
- `CURRENT.md` (dates, active-artifact timestamp, preservation correction, complete seasonal table, per-origin table, §3.2 item 2/11 + new item 13, Priority 3 checklist, Priority 3.2 designation, §5–§8)
- `AGENTS.md` (§1 designated task; §3.2 new item 16; §5 model-evaluation row)
- `IMPLEMENTATION.md` (this entry)

**ML algorithms or forecasting logic affected.** None. This review changed documentation only. The active fixed-penalty 12-feature trainer, the standalone evaluator, the saved artifact, the saved forecast, and the DSS/Analytics/UI paths are unchanged.

**Tests executed and their actual results.** The second review is documentation-only, so no PHP or schema change was made and the Laravel suites were **not** re-run for it. The independent re-verification used only read-only commands inside `capstone-sample-laravel.test-1` (project `/var/www/html`).

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 35 tests in 0.493s

OK
```

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python Python/evaluation.py storage/app/private/ml/processed_dataset.csv
```
The command completed and returned the same report values recorded in the increment-1 entry: dataset sha256 `f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af`, 17 actual / 15 eligible rows, nine origins `2025-12` → `2026-08`, eight skipped for insufficient eligible training rows, incumbent pooled MAE `74,110.4097` / RMSE `95,909.0322` / R² `-14.2725`, best-baseline MAE `25,621.4815`, and the seasonal-common comparison unchanged. `Python/evaluation.py` sha256 `b3aa92c00f2cdff9f6eb0402b55f24e9255ff5d1e5c425cea6749d0d4c50dd08` and `Python/train_model.py` sha256 `10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae` still match the signatures recorded in the increment-1 entry.

Hash and database inspection (read-only) showed:
```text
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af  (unchanged)
model_state.json:      87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e  (recorded 0aaf29a2… was superseded)
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  (unchanged)
metrics_4e5c21436dfa4bc1.json:        b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  (unchanged)

forecast_results:
id  year month predicted  mae        r2       predicted_at
1   2026 11    202870.53  59365.9026 -7.7672  2026-10-21 05:51:16   (stale, out of horizon)
2   2026 9     177137.16  145518.0747 -94.4260 2026-10-21 17:08:37   (re-issued, identical values)
```
`model_state.json` mtime is `2026-10-22 01:08:35` local, and its only content change is `trained_at` → `2026-10-21 17:08:35` UTC.

`git diff --check` passed for the tracked working tree. `git status --short` shows all three documentation files as new untracked files (`??` — none of the three has ever been committed), alongside the previously uncommitted Priority 2 code changes and the untracked Priority 3 increment files. A direct trailing-whitespace scan of the three documents found none. The pre-existing untracked `Python/__pycache__/*.pyc` files (dated 2026-10-19 and 2026-10-21, not produced by this review) were left untouched.

**Remaining limitations or follow-up tasks.** Priority 3.2 is **designated, not started** — nothing in it is implemented, tested, or verified. No model was selected, no artifact or metric was published, and the incumbent remains the deployed model. The state file remains mutable by ordinary application runs, so its identity must be re-derived at the time of any claim. The Priority 3 increment-1 files and the Priority 2 feature-builder changes remain uncommitted; committing or discarding them is a separate explicit decision. The `2026-11` stale forecast row and the `2026-09` row's holdout-vs-walk-forward metric mismatch remain open issues, not silently corrected. Model-selection conclusions remain limited by 17 actual observations and 9 evaluated origins, and beneficiary provenance and billing release times remain unverified.

---

## 2026-10-22 — Priority 3.2: offline reduced OLS and fixed-penalty Ridge comparison

**Problem identified.** The verified walk-forward benchmark showed the current 12-feature fixed-penalty model losing to naive baselines. The designated next increment required comparing two honestly labeled reduced candidates while retaining the original benchmark and leaving production behavior untouched.

**Root cause (confirmed).** The evaluator had only the incumbent fitter. It lacked reduced candidates, candidate-specific identifiability/rank handling, coverage reports, and feature-set provenance. The first benchmark origin has six eligible training rows, exactly the six parameters of the proposed five-feature model plus intercept; fitting there would violate the predeclared n > parameter-count rule. This does not establish the sole cause of the production model's poor accuracy.

**Solution implemented.** Added stdlib-only `Python/model_candidates.py`, imported by `evaluation.py` and its tests, not by production training/prediction. It defines:

- `reduced_consumption_calendar_ols`: unpenalized ordinary least squares, labeled **Linear Regression**.
- `reduced_consumption_calendar_ridge_0_1`: fixed penalty **0.1**, labeled **Ridge (penalty 0.1)**.

Both use exactly `lag_1`, `lag_2`, `month_sin`, `month_cos`, and `trend`, plus an intercept. Means and population scales come only from the origin's training rows; constant features use scale 1. A Householder QR least-squares solver is used. Ridge augments the design with square-root-penalty rows for feature coefficients only; the intercept is unpenalized. OLS applies no hidden penalty or patched pivot. Both candidates reject n ≤ 6 before solving. Rank-deficient OLS is explicitly unavailable at the predeclared relative tolerance `1e-10`; structural skips are recorded independently of observed errors.

The evaluator reuses `walk_forward`'s fitter/predictor injection points, with a candidate identifier and explicit candidate-unavailability handling. The original nine-origin nonseasonal and five-origin seasonal comparisons are retained unchanged. Added `reduced_common` and `reduced_seasonal_common` comparisons, where every included method uses the same periods. Per-origin candidate inputs, predictions, errors, eligibility/skip details, and candidate coverage are reported. Configuration/provenance includes feature-set identity/signature, candidate source signature, labels, penalties, parameter count, solver, and rank rule. No tuning, selection, or publication occurs.

**Files created or modified:**
- `Python/model_candidates.py` (created)
- `Python/evaluation.py`
- `Python/tests/test_evaluation.py`
- `AGENTS.md` (live implementation-status pointer only)
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** Offline evaluation candidates and comparison reporting only. `Python/train_model.py`, `Python/monthly_features.py`, `Python/predict_next_month.py`, the Laravel workflow, saved model/metrics/state/export, forecast records, DSS, Analytics, and UI/UX were not changed by this task. Existing uncommitted work was preserved. The original nine-origin benchmark is still available, avoiding an apparent score improvement caused merely by dropping the first origin.

**Tests executed and their actual results.** All tests ran inside Docker (`capstone-sample-laravel.test-1`, project `/var/www/html`). Test data is synthetic; Laravel used an isolated in-memory database.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 45 tests in 0.429s

OK
```
All existing 35 tests passed along with 10 new tests verifying independent 50-digit Decimal normal-equation reference fits against the production QR candidate solver for OLS/Ridge; strict n > parameter-count gating before solving; rank-deficiency disclosure without a hidden penalty; an unpenalized intercept; finite input checks; origin-only training and scaling; environmental perturbation invariance of reduced predictions with incumbent sensitivity; matching comparison periods and preserved original benchmarks; future-value invariance; constant-series/rank/null-R² behavior; deterministic CLI output; candidate provenance; and no artifact writes. The independent reference implementation uses stdlib Decimal and does not reuse the candidate solver or fitted scaler values.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Actual output tail (terminal colors omitted):
```text
Tests:    23 passed (187 assertions)
Duration: 4.95s
```
These regressions exercise the dataset-based horizon, repeated/manual/scheduled prediction behavior, calendar features, real PHP→Python path, DSS/report linkage, and admin/viewer consistency. Full Laravel and MySQL suites were not repeated for this Python-evaluator-only increment. `git diff --check` passed.

### Actual local-data comparison

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python Python/evaluation.py storage/app/private/ml/processed_dataset.csv
```
The command succeeded and emitted the following measured values in JSON (tables below transcribe those values).

```text
evaluation_version: walk-forward-model-variants-v2
runtime: CPython 3.12.3
dataset_sha256: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
actual_rows: 17
eligible_rows: 15
rejected_input_rows: 0
actual_cutoff: 2026-08
feature_version: calendar-past-only-v1
feature_signature: f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16
training_signature: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
evaluation_signature: 6b56e0cca2aee191d882b5693fab96923bcaf3aceb077957808bd782a8fdaca8
candidate_signature: 07691656fb56d25b0fd0f83aaf63d8986d4ad1ce4d201753c12dec237f9213a0
candidate_feature_set: reduced_consumption_calendar_v1
candidate_feature_set_signature: cc97caa9a19a91ddf3237eeff1da72bd0678f82799d80b83c6024e8c3da29a33
configuration_sha256: bf83a2bca1451e690a88968c901413a69f772b73376c224237b32fcec89fb28b
```

**Coverage:** the incumbent/baseline benchmark still evaluates nine targets, `2025-12` through `2026-08`, with exactly the earlier metrics (incumbent RMSE 95,909.0322 kWh; last-observation RMSE 31,500.1362 kWh). Both reduced candidates skip `2025-12` with `insufficient_residual_degrees_of_freedom`, training rows **6**, parameter count **6**, cutoff `2025-11`. They evaluate the remaining eight targets `2026-01` through `2026-08`, with 7–14 eligible past training rows. No rank deficiency occurred for the measured local candidate fits. The eight pre-benchmark warm-up targets remain explicitly skipped for insufficient eligible history.

**Reduced common comparison — all seven methods evaluated on the same eight targets:**

| Method | MAE kWh | RMSE kWh | Pooled R² |
| --- | --- | --- | --- |
| Incumbent fixed penalty 0.1, 12 features | 79527.4634 | 101143.3662 | -17.4001 |
| Reduced Linear Regression (OLS) | 45217.8188 | 54108.6742 | -4.2660 |
| Reduced Ridge (penalty 0.1) | 41324.7575 | 47472.7306 | -3.0535 |
| Last observation | 27197.5000 | 32518.1434 | -0.9019 |
| History mean | 30317.6941 | 39450.2691 | -1.7993 |
| Recent 3-month mean | 31884.1667 | 34715.8412 | -1.1677 |
| Recent 6-month mean | 27774.1667 | 34506.4935 | -1.1416 |

**Reduced seasonal-common comparison — all eight methods evaluated on the same five targets `2026-04` through `2026-08`:**

| Method | MAE kWh | RMSE kWh | Pooled R² |
| --- | --- | --- | --- |
| Incumbent fixed penalty 0.1, 12 features | 107434.5265 | 124318.2179 | -26.1193 |
| Reduced Linear Regression (OLS) | 48731.8741 | 58015.7104 | -4.9061 |
| Reduced Ridge (penalty 0.1) | 42258.4945 | 50603.9948 | -3.4934 |
| Last observation | 22540.0000 | 28662.4144 | -0.4416 |
| History mean | 25950.0480 | 36593.7436 | -1.3498 |
| Recent 3-month mean | 32480.0000 | 33641.9299 | -0.9860 |
| Recent 6-month mean | 24688.6667 | 30951.5002 | -0.6810 |
| Seasonal naive | 42420.0000 | 46656.9823 | -2.8198 |

**Comparison conclusion:** both reduced candidates improve on the incumbent on matching periods, but last-observation and other naive baselines still outperform them. No model is selected, promoted, or published. Negative R² values remain visible. Comparing an eight-origin score directly with the retained nine-origin benchmark would be misleading; each conclusion above uses only its own common subset. With 17 actual observations and only eight reduced-model targets, including one residual degree of freedom at the earliest fit, robust reliability is not established.

**Preservation evidence:** SHA256 snapshots before and after matched for the saved export, `model_state.json`, active model and metrics, trainer, shared feature builder, predictor, and Laravel workflow. In particular:
```text
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json: 87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json: b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
Python/train_model.py: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
```

**State of this entry:** Priority 3.2 is *Implemented*, *Tested*, and *Verified*. No production behavior or saved artifact was changed.

**Remaining limitations or follow-up tasks.** Priority 3.3 training-only penalty tuning and an untouched-holdout selection policy remain pending, as does Priority 3.4 artifact integration/publication and Laravel provenance/invalidation/failure-safety coverage. Existing production holdout/CV metrics, model labeling, Analytics/DSS/report semantics, and the stale out-of-horizon database row remain unchanged. OLS may have additional rank-based skips on other data; these remain explicit, and Ridge still obeys the same n > 6 rule. The original beneficiary provenance and actual data-release timing remain unverified. No further model/feature choice should be justified solely by these eight targets.

---

## 2026-10-22 — Status review and designation of Priority 3.3 (documentation only)

**Problem identified.** After Priority 3.2 was recorded as complete, the live status document still needed (a) independent confirmation that the Priority 3.2 claims matched the working tree, (b) a verification record dated to this review, and (c) an explicit, bounded designation of the next increment rather than a general "Priority 3.3 remains next" note.

**Root cause (confirmed).** Status documents describe point-in-time state. Without re-deriving the claims from the current tree, a later reader cannot distinguish an independently reproduced result from a transcribed one, and "next steps" written as themes are not actionable increments.

**Solution implemented.** Documentation-only review and designation. Re-ran the Python suite and the evaluation command against the unchanged export; confirmed every Priority 3.2 comparison table, the candidate/preservation signatures, and that `Python/model_candidates.py` is not imported by any production path. Updated `CURRENT.md` (phase and verification record, completed-task line, §5–§8, and the new Priority 3.3 designation with a predeclared untouched-subset selection policy), `AGENTS.md` (§1 implementation status, §3.2 item 16, and the §5 model-evaluation test requirement), and appended this entry. No source, artifact, database, or UI change.

**Files created or modified:**
- `CURRENT.md` (verification record, completed-task line, Priority 3.3 designation, §5–§8)
- `AGENTS.md` (§1 implementation status; §3.2 item 16; §5 model-evaluation row)
- `IMPLEMENTATION.md` (this entry)

**ML algorithms or forecasting logic affected.** None. The review changed documentation only. The active fixed-penalty 12-feature trainer, the offline evaluator and candidates, the saved artifact/forecast, and the DSS/Analytics/UI paths are unchanged.

**Tests executed and their actual results.** Documentation-only review; no PHP or schema change, so the Laravel suites were **not** re-run. Commands ran inside `capstone-sample-laravel.test-1` (project `/var/www/html`).

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 45 tests in 0.678s

OK
```

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python Python/evaluation.py storage/app/private/ml/processed_dataset.csv
```
The command completed and reproduced all four recorded comparisons exactly:
```text
nonseasonal_common        n=9  incumbent MAE 74110.4097 RMSE 95909.0322 R2 -14.2725
                               last obs   MAE 26586.6667 RMSE 31500.1362 R2 -0.6475
seasonal_common           n=5  incumbent MAE 107434.5265 RMSE 124318.2179 R2 -26.1193
                               last obs  MAE 22540.0000 RMSE 28662.4144 R2 -0.4416
reduced_common            n=8  incumbent MAE 79527.4634 RMSE 101143.3662 R2 -17.4001
                               OLS        MAE 45217.8188 RMSE 54108.6742 R2 -4.2660
                               Ridge 0.1  MAE 41324.7575 RMSE 47472.7306 R2 -3.0535
                               last obs   MAE 27197.5000 RMSE 32518.1434 R2 -0.9019
reduced_seasonal_common   n=5  OLS        MAE 48731.8741 RMSE 58015.7104 R2 -4.9061
                               Ridge 0.1  MAE 42258.4945 RMSE 50603.9948 R2 -3.4934
                               last obs   MAE 22540.0000 RMSE 28662.4144 R2 -0.4416
```
`reduced_common` excludes exactly `2025-12` (six eligible training rows versus six parameters); `reduced_seasonal_common` excludes `2025-12`, `2026-01`, `2026-02`, and `2026-03`. Evaluation version `walk-forward-model-variants-v2`; dataset sha256 `f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af`; 17 actual / 15 eligible rows.

Signature and preservation checks:
```text
Python/model_candidates.py   07691656fb56d25b0fd0f83aaf63d8986d4ad1ce4d201753c12dec237f9213a0
Python/evaluation.py         6b56e0cca2aee191d882b5693fab96923bcaf3aceb077957808bd782a8fdaca8
Python/train_model.py        10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
Python/monthly_features.py   f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16
processed_dataset.csv        f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json             87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl  fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json         b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
```
All match the values recorded for Priority 3.2. A repository search confirmed `model_candidates` is imported only by the evaluator and its tests, not by `app/` or the production Python paths.

**Remaining limitations or follow-up tasks.** Priority 3.3 is **designated, not started** — nothing in it is implemented, tested, or verified. No model was selected, tuned, promoted, or published. The existing production holdout/CV metrics, the "Linear Regression" label, Analytics/DSS/report semantics, and the stale out-of-horizon forecast row remain unchanged. Model-selection conclusions remain limited by 17 actual observations, at most eight comparable reduced-model origins, and one residual degree of freedom at the earliest fitted origin. Beneficiary provenance and billing release times remain unverified. The Priority 2 and Priority 3 files remain uncommitted; committing or discarding them is a separate explicit decision.

---

## 2026-10-22 — Priority 3.3: training-only Ridge tuning and predeclared selection decision

**Problem identified.** Priority 3.2 compared reduced OLS and fixed-0.1 Ridge but had no training-only penalty search or explicit development/reserved decision rule. Seventeen actuals cannot supply inner validation at the earliest reduced-model origin.

**Root cause (confirmed).** January 2026 has seven eligible past supervised rows; an inner fit must have more than six. January is therefore the first possible inner validation target, leaving no inner target before January's outer prediction. A tuned January prediction would require an unsupported default penalty or relaxing the existing guard. Neither was introduced. This structural limitation and the measured model errors do not establish the sole cause of production inaccuracy.

**Solution implemented.** Added offline stdlib-only `Python/ridge_tuning.py`. The grid and decision policy were written into CURRENT.md and source before running the new local-data scores: penalties **0.01, 0.1, 1, 10**, expanding one-month inner folds, training-only feature scaling, n > 6, at least one valid inner fold, unrounded pooled MAE objective, exact ties to the smaller penalty. All grid members use identical valid inner periods; a candidate-unavailability failure discards the whole fold with a reason. No inner evidence means an explicit skip, never a default penalty. Reused the reduced QR fitter/unpenalized intercept through `fit_ridge`; original OLS/fixed-Ridge definitions, fit results, and comparison objects remain unchanged.

At each development target only prior actuals enter penalty selection. Both July and August selections are frozen to actuals strictly before July; July's revealed actual can enter August's coefficient refit and lag inputs but not its inner search. Inner cutoffs, training periods/counts, predictions, errors, objective scores and selected penalties are emitted. Development/reserved metrics are separate under `selection_evaluation`; no combined tuning-plus-reserved ranking was added. Source/configuration/policy signatures identify the new evaluation version.

The policy requires all six January–June and both July–August common targets; selects the best of four nonseasonal naive baselines using unrounded development MAE then identifier; orders challengers using that same development criterion and freezes the first before reserved scoring; requires strictly lower MAE and RMSE in both subsets, at least four development absolute-error wins and no worse error on either reserved target. A reserved failure cannot trigger a switch to another challenger. Otherwise the explicit conclusion is **retain the baseline / seek more genuine history**. Production changes are never authorized by this evaluator. July–August had already been inspected in earlier comparisons, so the report explicitly declines to call this a newly blinded/prospective holdout.

**Files created or modified:**
- `Python/ridge_tuning.py` (created)
- `Python/model_candidates.py` (shared offline fit entry point; original candidate specifications retained)
- `Python/evaluation.py` (tuned diagnostics, separate subsets, policy decision/provenance)
- `Python/tests/test_ridge_tuning.py` (created; 12 synthetic regressions)
- `AGENTS.md` (status pointers/testing coverage only)
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** Offline in-memory Ridge comparison and selection diagnostics only. No production training, preprocessing, model publication, database/schema, forecasting horizon, saved metrics, Analytics, DSS, reports, or UI/UX change. Unrelated uncommitted work was preserved.

**Tests executed and their actual results.** The existing app Docker container was stopped; it was started for these checks. MySQL was not started or queried. Tests used synthetic fixtures and isolated in-memory SQLite, not beneficiary database records.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 57 tests in 0.804s

OK
```
All previous 45 tests passed plus 12 new regressions: independent 50-digit Decimal reference fits for every grid penalty/inner score; exact chronological training prefixes and scaling inputs; target/future perturbation invariance; frozen reserved tuning with allowed later refits; deterministic grid/ties; absent evidence without fallback; rank/identifiability skips and positive finite penalties; unchanged original comparison tables/predictions; identical periods and independently recomputed subset metrics; missing/constant series/null R²; deterministic CLI, signatures, and no artifact writes; positive and negative policy cases including incomplete coverage, ties, RMSE, raw precision, error wins, reserved reversal and no post-reserved challenger switch.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Actual output tail (terminal colors omitted):
```text
Tests:    23 passed (187 assertions)
Duration: 2.12s
```
Full Laravel/MySQL suites were not repeated for this offline Python-only change; previous results remain historical. `git diff --check` passed.

Evaluation CLI (executed as a subprocess inside the read-only Docker verification harness, which captured JSON stdout and additionally compared saved-file hashes and the original comparison objects):
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python Python/evaluation.py storage/app/private/ml/processed_dataset.csv
```
Measured provenance:
```text
evaluation_version: walk-forward-training-only-tuning-v3
runtime: CPython 3.12.3
dataset_sha256: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
actual_rows: 17
eligible_rows: 15
rejected_input_rows: 0
actual_periods: 2025-04 through 2026-08
evaluation_signature: 086811d1e33c0b114170e4ab0aa6202971a97116b3faa2088a69381090adae9a
candidate_signature: dd89e84a7ef410ca6ced7e8fa94652f9f5a65c3042f3d5ef38da716e2bfec08e
tuning_signature: 67a153aac84efa339b647424bdcca67cdc15655738a996d7f08a07fb24c47bf6
candidate_feature_set_signature: cc97caa9a19a91ddf3237eeff1da72bd0678f82799d80b83c6024e8c3da29a33
configuration_sha256: ed3fecf209b387bdb328bc2b827b68996676a4dcf6661483c32281744077accc
policy_signature: 53ef3a820d8ee22e51cc3e0004ec829b200db49524af6752faf600ddc1bc2704
```

Development was predeclared **January–June**. Tuned January has `insufficient_inner_history` (no eligible inner fold); common development coverage is **February–June, five targets**, explicitly excluding January. Reserved coverage is **July–August, two targets**. All eight methods below use the same periods within each subset, and the missing sixth development target fails the policy rather than changing its dates or threshold.

| Method | Development MAE | Development RMSE | Development R² | Reserved MAE | Reserved RMSE | Reserved R² |
| --- | --- | --- | --- | --- | --- | --- |
| current_fixed_penalty_0_1 | 87348.1006 | 116224.6475 | -16.6550 | 71515.0409 | 74540.2490 | -17.8909 |
| reduced_consumption_calendar_ols | 52958.3953 | 62950.9560 | -4.1794 | 35859.5646 | 38543.7986 | -4.0510 |
| reduced_consumption_calendar_ridge_0_1 | 41950.4529 | 50274.6848 | -2.3035 | 33434.6972 | 35200.2110 | -3.2127 |
| reduced_consumption_calendar_ridge_tuned | 51600.5238 | 55297.9001 | -2.9966 | 18555.6409 | 24737.5254 | -1.0806 |
| last_observation | 29796.0000 | 35942.3349 | -0.6884 | 20300.0000 | 24659.4809 | -1.0675 |
| history_mean | 31939.4995 | 43572.1514 | -1.4814 | 23610.9167 | 29060.1554 | -1.8712 |
| recent_mean_3 | 33094.6667 | 36791.4341 | -0.7692 | 26600.0000 | 27825.4280 | -1.6324 |
| recent_mean_6 | 30928.6667 | 39730.4696 | -1.0631 | 18200.0000 | 18203.1755 | -0.1266 |

All consumption errors/metrics are in kWh; R² is dimensionless. Per-origin tuned values (error = actual minus prediction):

| Target | Chosen penalty | Tuned prediction | Tuned error | Inner origins |
| --- | --- | --- | --- | --- |
| 2026-01 | unavailable | unavailable | unavailable | 0 |
| 2026-02 | 0.01 | 158840.9547 | 63699.0453 | 1 |
| 2026-03 | 10 | 234273.2371 | -67673.2371 | 2 |
| 2026-04 | 10 | 197827.6605 | -40327.6605 | 3 |
| 2026-05 | 0.1 | 140637.3192 | 68662.6808 | 4 |
| 2026-06 | 10 | 202860.0048 | 17639.9952 | 5 |
| 2026-07 | 10 | 212003.8625 | 2196.1375 | 6 |
| 2026-08 | 10 | 214815.1444 | -34915.1444 | 6 |

The reserved targets both select penalty 10 using the same six inner targets January–June, with actual cutoff June. February starts with one inner target. Per-origin errors, inputs and full inner evidence for every candidate remain in the emitted JSON.

**Confirmed decision:** best naive on development = `last_observation`; first development challenger = fixed-0.1 reduced Ridge. All five checks are false: full coverage, development MAE/RMSE superiority, four development error wins, reserved MAE/RMSE superiority, no reserved reversal. `condition_met: false`, `production_change_authorized: false`, conclusion **retain the baseline / seek more genuine history**. Tuned Ridge also loses badly on development; its lower reserved MAE than last-observation cannot override that evidence, and its reserved RMSE is slightly higher. No algorithm is promoted, and the shipped incumbent remains unchanged.

**Preservation evidence:** before/after comparisons produced:
```text
hashesMatch: true
comparisonsMatch: true
```
All files under `storage/app/private/ml` (including historical artifacts), plus the production trainer/shared feature builder/two prediction scripts/Laravel workflow, have identical SHA256 values. Key hashes:
```text
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json: 87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json: b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
Python/train_model.py: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
```
All four original nine/five/eight/five-origin comparison objects match the pre-edit snapshot exactly, not merely rounded headline scores.

**State of this entry:** Priority 3.3 is **Implemented**, **Tested**, **Verified**; the selection condition is **not met**. No production/artifact integration was performed.

**Remaining limitations or follow-up tasks.** Only 17 actuals, five tuned development targets, two retrospectively reserved targets, and one inner target for the earliest tuned prediction provide weak selection evidence. January cannot be evaluated honestly under this policy; do not weaken it or widen the grid to chase scores. Obtain more genuine, uninspected observations and verify beneficiary provenance/billing release timing. Priority 3.4 requires a separately scoped decision about the retained-baseline conclusion and artifact integration/invalidation/failure safety; no selected challenger exists for automatic publication. Production holdout/CV formulas, mislabeled algorithm, Analytics fitted-series semantics, missing metrics, DSS/report rules and the stale out-of-horizon forecast row remain unchanged. No new source-data reliability or prospective accuracy claim is made.

---

## 2026-10-22 — Independent re-verification of Priority 3.3 and designation of Priority 3.4a (documentation only)

**Problem identified.** The Priority 3.3 entry recorded that the tuning and the selection policy were implemented, tested, and verified, and that the policy failed. A status document written by the same task that produced the numbers is not independent evidence: nothing in the project distinguished a reproduced result from a transcribed one for this increment, and three specific claims had never been checked by a second method. (a) The tuned Ridge and its penalty search were validated only against the project's own `Decimal` reference helper, so an error shared by the implementation and its reference could pass unnoticed. (b) The artifact-identity and failure-safety gaps that block Priority 3.4 were described in `AGENTS.md` §3.2 item 9 and §5 without file/line evidence, so the next task could not be scoped from verified facts. (c) The recommendation "seek more genuine history" was not quantified, so the data request to the beneficiary remained open-ended and un-actionable.

**Root cause (confirmed).** The project had no second-implementation cross-check for the offline evaluator, and the artifact-workflow defects had never been read directly from `app/Support/MlWorkflow.php`. The January exclusion was known to be a structural consequence of the `n > 6` guard, but the amount of additional history required to remove it had not been computed.

**Solution implemented.** Documentation-only independent re-verification. Re-ran the Python suite, the evaluation CLI, and the focused Laravel suite against the unchanged export; re-derived the entire tuned-Ridge selection loop and every tuned prediction with an independent NumPy least-squares implementation inside the Docker ML image, bypassing the project's Householder QR solver; probed for leakage by perturbing a target/future actual; confirmed the saved artifact is byte-reproducible from the current export; read the development database read-only to confirm the documented forecast rows; and measured — with clearly synthetic rows and no predictive claim — how much earlier the series must start for the predeclared `complete_coverage` condition to become structurally satisfiable. `CURRENT.md` gained a re-verification record, a "findings not previously recorded" subsection, a new open issue for artifact identity and failure safety, a designation of **Priority 3.4a** (artifact identity, atomic publication, failure safety, model/metrics linkage) with acceptance criteria, a rewritten next-steps section, and a quantified data request. `AGENTS.md` received the status pointer, the roadmap pointer, and three corrected citations. No source, artifact, database, forecast, or UI change.

**Files created or modified:**
- `CURRENT.md` (§1 re-verification record and new findings; §3.2 open issues; §4 Priority 3.4a designation; §5–§8)
- `AGENTS.md` (§1 implementation status; §3.2 items 8, 13, 15 citation corrections; §4 Priority 3 pointer; §5 model-artifact row)
- `IMPLEMENTATION.md` (this entry)

**ML algorithms or forecasting logic affected.** None. The review changed documentation only. `Python/train_model.py`, `Python/monthly_features.py`, `Python/predict_next_month.py`, `Python/predict_history.py`, `app/Support/MlWorkflow.php`, `app/Support/EnergyPreprocessor.php`, the offline `Python/evaluation.py` / `Python/model_candidates.py` / `Python/ridge_tuning.py`, the saved artifacts, the forecast rows, and the UI/UX are all unchanged. No model was selected, tuned, promoted, or published.

**Tests executed and their actual results.** All commands ran inside `capstone-sample-laravel.test-1` (project `/var/www/html`). The application database was queried **read-only** with `SELECT` only and was not modified.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
----------------------------------------------------------------------
Ran 57 tests in 10.054s

OK
```

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MonthlyFeaturesTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php
```
Actual output tail (terminal colors omitted):
```text
  Tests:    23 passed (187 assertions)
  Duration: 2.27s
```
The full Laravel suite and the MySQL suite were **not** re-run for this documentation-only review; their results remain the historical ones recorded in `CURRENT.md` §1. The Laravel tests use `Storage::fake('local')` and an in-memory SQLite database, so no real artifact, forecast, or beneficiary row is touched.

Command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python Python/evaluation.py storage/app/private/ml/processed_dataset.csv
```
Exit code `0`; 104272 bytes of JSON on stdout. Provenance and all four comparison objects reproduced the recorded values exactly:
```text
evaluation_version: walk-forward-training-only-tuning-v3
dataset_sha256: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
input_rows: 17   rejected_input_rows: 0
runtime: CPython 3.12.3   available_actual_cutoff: 2026-08
evaluation_signature: 086811d1e33c0b114170e4ab0aa6202971a97116b3faa2088a69381090adae9a
candidate_signature: dd89e84a7ef410ca6ced7e8fa94652f9f5a65c3042f3d5ef38da716e2bfec08e
tuning_signature: 67a153aac84efa339b647424bdcca67cdc15655738a996d7f08a07fb24c47bf6
candidate_feature_set_signature: cc97caa9a19a91ddf3237eeff1da72bd0678f82799d80b83c6024e8c3da29a33
configuration_sha256: ed3fecf209b387bdb328bc2b827b68996676a4dcf6661483c32281744077accc
policy_signature: 53ef3a820d8ee22e51cc3e0004ec829b200db49524af6752faf600ddc1bc2704
```
```text
nonseasonal_common        n=9  incumbent MAE 74110.4097 RMSE 95909.0322 R2 -14.2725
                               last obs   MAE 26586.6667 RMSE 31500.1362 R2 -0.6475
seasonal_common           n=5  incumbent MAE 107434.5265 RMSE 124318.2179 R2 -26.1193
                               last obs   MAE 22540.0000 RMSE 28662.4144 R2 -0.4416
reduced_common            n=8  incumbent MAE 79527.4634 RMSE 101143.3662 R2 -17.4001
                               OLS        MAE 45217.8188 RMSE 54108.6742 R2 -4.2660
                               Ridge 0.1  MAE 41324.7575 RMSE 47472.7306 R2 -3.0535
                               last obs   MAE 27197.5000 RMSE 32518.1434 R2 -0.9019
reduced_seasonal_common   n=5  OLS        MAE 48731.8741 RMSE 58015.7104 R2 -4.9061
                               Ridge 0.1  MAE 42258.4945 RMSE 50603.9948 R2 -3.4934
                               last obs   MAE 22540.0000 RMSE 28662.4144 R2 -0.4416
```
Development subset = `2026-02`…`2026-06` (five targets; `2026-01` excluded as `insufficient_inner_history`), reserved subset = `2026-07`…`2026-08`. All eight methods reproduced the recorded development and reserved MAE/RMSE/R², and the decision object reproduced exactly:
```text
best_naive_on_development: last_observation
challenger_frozen_before_reserved: reduced_consumption_calendar_ridge_0_1
conditions: complete_coverage=false, development_mae_and_rmse=false,
            development_absolute_error_wins_at_least_four=false,
            reserved_mae_and_rmse=false, no_reserved_absolute_error_reversal=false
condition_met: false
conclusion: retain the baseline / seek more genuine history
production_change_authorized: false
```
Chosen penalties `2026-02`→`2026-08`: `0.01, 10, 10, 0.1, 10, 10, 10`, matching the recorded values.

Independent cross-check (NumPy 2.5.4 `lstsq`, a different solver and a separately written tuning loop; script piped to the container's interpreter over stdin, writing nothing):
```text
numpy version: 2.5.4
2026-02: numpy penalty=0.01 recorded=0.01 MATCH=True
   numpy prediction=158840.954729 recorded=158840.954729 absdiff=1.455e-10
2026-03: numpy penalty=10.0 recorded=10.0 MATCH=True
   numpy prediction=234273.237130 recorded=234273.237130 absdiff=1.746e-10
2026-04: numpy penalty=10.0 recorded=10.0 MATCH=True
   numpy prediction=197827.660527 recorded=197827.660527 absdiff=5.821e-11
2026-05: numpy penalty=0.1 recorded=0.1 MATCH=True
   numpy prediction=140637.319223 recorded=140637.319223 absdiff=1.164e-10
2026-06: numpy penalty=10.0 recorded=10.0 MATCH=True
   numpy prediction=202860.004848 recorded=202860.004848 absdiff=8.731e-11
penalties matched 5/5 ; predictions matched 5/5
```
Reserved frozen search and refit, independently recomputed:
```text
frozen reserved tuning (cutoff 2026-06): selected=10.0 scores={0.01: 48342.0446, 0.1: 43954.7776, 1.0: 37658.3013, 10.0: 33637.5375}
2026-07: refit on 13 eligible rows (includes revealed earlier actuals); numpy pred=212003.862461 recorded=212003.862461 match=True
2026-08: refit on 14 eligible rows (includes revealed earlier actuals); numpy pred=214815.144353 recorded=214815.144353 match=True
```
Leakage probe (perturbing the `2026-08` actual by ×100 and recomputing the `2026-06` tuned prediction):
```text
perturbed penalty=10.0 prediction=202860.004848 (baseline 2026-06 penalty=10 pred=202860.004848) match=True
tuning input cutoff for reserved = 2026-06 (must be 2026-06, i.e. no July actual)
penalty from actuals<2026-07 = 10.0  == penalty from actuals<2026-08 frozen path = 10.0 -> True
```
Structural sensitivity probe (**synthetic** rows with arbitrary values; structural only, no predictive claim). The question was how much earlier the series must start for the `2026-01` origin to gain an inner fold:
```text
prepended=0 months (series starts 2025-04): january tuned status=insufficient_inner_history selected=None inner_folds=[] (n=0)
prepended=1 months (series starts 2025-03): january tuned status=evaluated selected=10.0 inner_folds=['2025-12'] (n=1)
   -> outer fit rows=8 (>6 required: True); January becomes evaluable
prepended=2 months (series starts 2025-02): january tuned status=evaluated selected=10.0 inner_folds=['2025-11', '2025-12'] (n=2)
   -> outer fit rows=9 (>6 required: True); January becomes evaluable
prepended=3 months (series starts 2025-01): january tuned status=evaluated selected=10.0 inner_folds=['2025-10', '2025-11', '2025-12'] (n=3)
   -> outer fit rows=10 (>6 required: True); January becomes evaluable
```

Artifact reproducibility check (output written to the container's own `/tmp`, never to the mounted project, and removed afterwards):
```sh
docker exec capstone-sample-laravel.test-1 sh -lc 'cd /var/www/html && /opt/ml-venv/bin/python Python/train_model.py storage/app/private/ml/processed_dataset.csv /tmp/repro_model.pkl /tmp/repro_metrics.json'
```
Actual output:
```text
exit=0
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  /tmp/repro_model.pkl
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  /tmp/repro_metrics.json
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  storage/app/private/ml/models/qc_energy_model_4e5c21436dfa4bc1.pkl
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  storage/app/private/ml/models/metrics_4e5c21436dfa4bc1.json
```
The saved artifact and metrics are byte-reproducible from the current export.

Preservation check — SHA256 of the saved export, state file, every model and metrics artifact, and the production trainers/feature builder/predictors/workflow, taken before and after all verification commands:
```text
processed_dataset.csv  f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json       87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl  fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json         b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
Python/train_model.py  10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
Python/monthly_features.py  f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16
Python/predict_next_month.py  6f2445bf25b9dcf14017b786b3f2b17c3eab588f8acef540845908b119eeaf59
Python/predict_history.py     3a68e9a489e29ebb2fe271ef7d7397d7c202c0504934152e2721459e67fb5053
app/Support/MlWorkflow.php    4644dd53fe0092f8551d3f72cd9fbced81ecc22ba5cd7e8c34d9eda8fe0384c6
app/Support/EnergyPreprocessor.php  f8f34bfe22b4051a07b83b1b878bbffe3ae7e2818fb6e17acedd079785d8fd5a
```
All values are identical to the pre-verification snapshot. A repository search confirmed `ridge_tuning`, `model_candidates`, and `evaluation` are imported only by the evaluator and its tests — never by `app/`, `resources/`, `config/`, `routes/`, or the production Python entry points.

Read-only database confirmation (`SELECT` only):
```text
id  year  month  predicted_consumption_kwh  mae        rmse        r2_score   model_type           predicted_at
2   2026  9      177137.16                 145518.0747 152088.6912 -94.4260   Linear Regression   2026-10-21 17:08:37
1   2026  11     202870.53                 59365.9026  70684.6888  -7.7672    Linear Regression   2026-10-21 05:51:16
```
`storage/app/private/ml/model_state.json` also reproduced the documented contents: `dataset_hash` `f0819b2d…`, `feature_signature` `f4cbf5e6…`, `model_path` `ml/models/qc_energy_model_4e5c21436dfa4bc1.pkl`, `metrics_path` `ml/models/metrics_4e5c21436dfa4bc1.json`, `trained_at` `2026-10-21 17:08:35`, `trained_by` `1`.

**State of this entry:** the Priority 3.3 implementation is **Verified** by independent means: the selection-policy result is reproduced exactly, the tuned Ridge and its penalty search are confirmed by a second, differently implemented solver, the leakage boundary is confirmed by direct perturbation, and production preservation is confirmed by hash. The policy still **fails**; nothing was published.

**Remaining limitations or follow-up tasks.** The verification does not strengthen the statistical evidence, which remains 17 actual observations, five tuned development targets, two retrospectively inspected reserved targets, and a single inner fold for the earliest tuned prediction. Three new items are now recorded rather than resolved. First, the predeclared `complete_coverage` condition is **structurally unsatisfiable** on this series: `2026-01` can never obtain an inner fold under the `n > 6` guard, so the condition would fail even for a perfect challenger. The probe shows one additional contiguous month before `2025-04` would give `2026-01` one inner fold and make the condition satisfiable; this quantifies the data request but proves nothing about predictive performance. Second, "retain the baseline" is used for two different artifacts — in the policy the baseline is the best *naive* predictor (`last_observation`), while production retains the 12-feature incumbent that is ≈2.9× worse on the identical protocol — so the deployment gap is an open decision, not a settled outcome. Third, the reserved subset is weakly informative rather than merely "not newly blinded": the tuned candidate's pooled reserved MAE (`18,555.6409`) beats the frozen baseline's (`20,300.0000`), but that gain comes from a single target — per-target absolute error, the tuned candidate beats last-observation only at `2026-07` (`2,196.1375` vs `6,300.0000`) and is worse at `2026-08` (`34,915.1444` vs `34,300.0000`) — while its pooled reserved RMSE is worse (`24,737.5254` vs `24,659.4809`) and `recent_mean_6` beats it on both reserved metrics (`18,200.0000` MAE / `18,203.1755` RMSE). `AGENTS.md` also carried three stale citations, now corrected: the "Accuracy" element in both Analytics pages is a table column with a `%` value and no explanatory text (there is no tooltip), and the clipped percentage-error complement in `Python/predict_history.py` is at lines 47–49, with the non-existent default model path at lines 17–18. **Priority 3.4a is designated, not started.** The Priority 2 and Priority 3 files remain uncommitted; committing or discarding them is a separate, explicit decision.

---

## 2026-10-22 — Priority 3.4a: artifact identity, atomic publication, failure safety and linkage

**Problem identified.** The workflow reused artifacts after trainer changes, wrote training output over referenced files, committed state non-atomically, and did not verify that prediction used a matching model/metrics pair. State lacked trainer/configuration identity, cutoff and evaluation-protocol provenance.

**Root cause (confirmed).** Artifact identity previously included only export and feature hashes. The trainer received final paths; reuse checked model existence rather than pair integrity. The predictor also has a legacy automatic retraining fallback that can write to its input artifact paths. Passing a published model directly into it would leave a second overwrite route even after fixing explicit training.

**Solution implemented.** Kept every Python source file and ML choice unchanged. `MlWorkflow` now:

- Serializes its export/train/predict operations with a cooperative local file lock.
- Computes format-version-2 identity from the dataset SHA256, shared feature source signature, trainer/configuration signature, latest exported actual period (`training_cutoff`), and the unchanged protocol identifier `chronological_holdout_25pct_rolling_cv_v1`. The trainer/configuration signature hashes the complete trainer source (including its hardcoded features, penalty and holdout defaults), configured Python interpreter path and protocol. A trainer/input signature change during training aborts publication.
- Runs the existing trainer with unique staging output paths. Both nonempty files and metrics JSON are validated; MAE/RMSE/R² must be numeric and finite or null. This validates storage integrity without changing metric semantics, clipping R² or adopting offline evaluation metrics.
- Moves the complete staged pair together to immutable `ml/models/<artifact_id>_<uuid>/model.pkl` and `metrics.json`. Writes a complete temporary state file on the same filesystem, then atomically renames it over `ml/model_state.json` only after validating the referenced generation and checksums. The active state remains the old complete set until this commit. Old generations are never overwritten or deleted. Ordinary failures clean up only the attempted staging/generation/temp-state files; failed initial publication leaves no active state.
- Records `format_version`, `artifact_id`, `dataset_hash`, `feature_signature`, `trainer_signature`, `training_cutoff`, `evaluation_protocol`, both paths and SHA256 checksums, `trained_at`, and `trained_by` in state. The cutoff is the latest actual period available in that exported training snapshot; the trainer still applies its existing eligibility and split rules.
- Requires state identity plus model/metrics existence, valid metrics, matching checksums, shared generation paths, expected filenames, and the identity-bound generation prefix for reuse. Missing, corrupt, mismatched and legacy incomplete pairs deterministically retrain. Failed repair never invokes prediction or writes a forecast.
- Passes validated temporary copies to prediction and verifies they remain unchanged afterwards. Python's existing auto-training fallback therefore cannot overwrite published files; changed copies cause a clear Train Model error. Valid prediction values and metrics retain their existing meaning.

**Files created or modified:**
- `app/Support/MlWorkflow.php`
- `tests/Feature/MlArtifactsTest.php` (created; 27 parameterized cases)
- `tests/Feature/ForecastPeriodTest.php` (updated the existing period-rejection test's stub to write valid staged artifacts before returning its deliberately invalid target period)
- `AGENTS.md` (version-2 filesystem requirements, completed-status pointers and Model artifacts coverage)
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** Artifact lifecycle and integrity only. The incumbent 12-feature fixed-penalty model, training/holdout/CV algorithms, offline tuning and model selection, shared features, prediction formula, period guards, UI/UX, Analytics/DSS/report semantics, schema and real saved artifacts were not changed. No D1 decision or Priority 3.4b implementation occurred. Existing unrelated local changes were preserved.

**Tests executed and their actual results.** All PHP verification ran in Docker with SQLite `:memory:` and fake local storage. New cases use a stubbed Python runtime and isolated copies of trainer/feature source when testing invalidation; they never edit the project's Python files. Existing real-Python integration tests run with synthetic records and fake storage. No development MySQL query/migration or beneficiary database mutation was performed.

Focused artifact command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php
```
Actual final output tail (terminal colors omitted):
```text
Tests:    27 passed (286 assertions)
Duration: 1.22s
```

Full Laravel command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Actual final output tail:
```text
Tests:    5 skipped, 90 passed (632 assertions)
Duration: 3.09s
```
The five skips are the existing disabled-Fortify password-reset cases, not passes. This includes all previous Laravel tests, new artifact regressions and the existing real PHP→Python horizon/module integration checks. An initial focused run reported `1 failed, 46 passed (447 assertions)` because the test's state-write failure mock created a filesystem manager without its application configuration. The simulation was corrected to intercept the initialized fake disk adapter. Final runs above passed; no application workaround was added for this test-harness error. During final review a correct-hash cross-generation pair regression was added, bringing the final focused count to 27.

Python command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 57 tests in 1.062s

OK
```

Formatting command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint app/Support/MlWorkflow.php tests/Feature/MlArtifactsTest.php tests/Feature/ForecastPeriodTest.php
```
Actual output tail:
```text
PASS   ........................................................... 3 files
```
`git diff --check` also passed. No MySQL persistence suite was repeated: there is no schema/query change, and the designated acceptance uses isolated SQLite.

**Six completion criteria — verified evidence:**

| Criterion | Named regression / verification |
| --- | --- |
| 1 — identity changes on trainer/configuration changes | `trainer hyperparameter feature dataset and interpreter changes invalidate artifact identity` (five cases) edits only synthetic source/config/data, requires a new identity/path and checks old artifact bytes survive. |
| 2 — failed partial training preserves usable artifacts/state | `partial failed training preserves the active pair and state and prediction can still use them`; invalid success outputs, move/write/commit failures, mid-training source changes and failed first publication are also covered. |
| 3 — atomic consistent pair; missing/mismatch handling | `publication exposes the old complete pair until one atomic state pointer replacement`; `missing or mismatched model metrics and state are retrained before prediction`; `individually valid model and metrics from different generations cannot be mixed in state`; failed repair and predictor fallback overwrites are rejected. |
| 4 — required state provenance | `artifact state records identity cutoff protocol pair hashes and attribution` checks every required field, stable reuse and byte-identical state on a cache hit. |
| 5 — Laravel regressions and coverage status | 27 focused cases passed; AGENTS.md Model artifacts row is now Covered. |
| 6 — unchanged behavior outside lifecycle | Full Laravel/Python suites pass; no UI/schema/Python change; saved-file hashes and read-only benchmark/saved-estimate evidence below remain unchanged. |

**Read-only preservation / behavior evidence.** Before/after SHA256 snapshots matched for all eight files under `storage/app/private/ml` and seven active/offline Python source files (15 files total). This includes every historical model/metrics file as well as the active export/state/pair. Key values:
```text
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json: 87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json: b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
Python/train_model.py: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
savedAndPythonHashesUnchanged: true
filesCompared: 15
```
The unchanged export has 17 actual observations (`2025-04`–`2026-08`), 15 eligible rows and zero rejected inputs. A Docker read-only call to `evaluation.evaluate_dataset()` reproduced the recorded nine/five/eight/five-origin benchmark tables with unchanged source/configuration signatures and the same failed offline selection decision. Incumbent nine-origin `2025-12`–`2026-08` MAE/RMSE/R² remain `74110.4097 / 95909.0322 / -14.2725`; last-observation on those identical dates remains `26586.6667 / 31500.1362 / -0.6475`. This diagnostic does not decide D1 or publish anything.

A separate Docker in-memory check loaded the existing state/model/metrics, read the same export, and called the unchanged next-period, shared-input and prediction functions without executing training or writing files. Actual output:
```json
{"target_period": "2026-09", "prediction_kwh": 177137.16, "mae": 145518.0747, "rmse": 152088.6912, "r2_score": -94.426}
```
Those metrics remain the saved incumbent's four-target `2026-05`–`2026-08` fixed holdout, not the offline walk-forward metrics and not observed September accuracy. The first invocation of this one-off verification command failed only while formatting float-valued CSV year/month fields as integers; rerunning with the predictor's existing integer conversion produced the output above. No application code or artifact was changed to address it.

**State of this entry:** Priority 3.4a is **Implemented**, **Tested**, **Verified**; all six completion criteria passed. No real saved model/metrics/state publication was performed during implementation or verification.

**Remaining limitations or follow-up tasks.** D1 is still an open team decision and 3.4b remains blocked; production metric formulas, labels, Analytics fitted-series semantics, DSS/report rules and the stale out-of-horizon row are unchanged. The real legacy state was deliberately preserved; its next authorized workflow run will rebuild the unchanged incumbent into version 2 rather than silently trust missing provenance. Identity includes the interpreter configuration path, not an independently measured runtime/package-version fingerprint. Atomic visibility relies on the existing local filesystem, same-filesystem rename and cooperative workflow file locking; arbitrary external file writers are outside that lock. Abrupt process termination can leave unreferenced temporary/generation files, which cannot become the active pair before state commit; garbage collection and forced power-loss durability testing are outside this increment. No claim of stronger predictive accuracy is made.

## 2026-10-22 — Independent re-verification of Priority 3.4a and designation of Priority 3.4c (documentation only)

**Problem identified.** The Priority 3.4a completion recorded above asserted six satisfied criteria and three passing suites. That record had not been independently re-derived from the working tree, and the review scope named gaps the 3.4a implementation had explicitly excluded: the artifact read path used by Analytics, the identity-defining export write, artifact-validity coupling to a display label, audit-log ordering, the protocol label, interpreter identity, and generation garbage collection. Two of those gaps mean the 3.4a guarantee "the prediction path never serves a model whose metrics do not match it" holds for the forecast path but not for the second, historical-prediction read path.

**Root cause (confirmed for the two Priority 3.4c findings).** Priority 3.4a redesigned the *training/publication* path and the *forecast* path. `app/Http/Controllers/Admin/AnalyticsController.php:125` and `app/Http/Controllers/User/ViewerController.php:190` still read `model_path` straight out of `ml/model_state.json` and check only `Storage::exists($modelPath)`; they never call the new pair validation, so a tampered, mismatched, or cross-generation model is served to the Analytics chart. Separately, `MlWorkflow::exportProcessedDataset()` (`app/Support/MlWorkflow.php:238`) writes the identity-defining `ml/processed_dataset.csv` with a plain `Storage::put` on every workflow call, including cache hits, while every artifact write downstream of it became atomic — so a crash mid-write can leave the live export truncated while the state still references the previous `dataset_hash`. Root cause is **not confirmed** for the roadmap observation in item 19 of `CURRENT.md` §3.2 (that the 3.4b label/metric work does not in fact depend on the D1 artifact choice); that is a scoping judgement, not a measured defect.

**Solution implemented.** Documentation only. The review re-ran the three suites, re-derived the v2 identity independently, closed four test seams the 3.4a suite leaves open, recorded ten residual findings, designated Priority 3.4c, and split the open team decisions into separately actionable items. **No application code, test, saved artifact, database record, or UI value was changed.**

**Files created or modified:**
- `IMPLEMENTATION.md` (this entry)
- `CURRENT.md` (§1.3 verification record, §3.2 items 16–19, §4 Priority 3.4c designation and 3.4b re-scope note, §6 completed task, §7 roadmap, §8 blockers)
- `AGENTS.md` (§1 status, §3.2 item 9 post-3.4a line references, §5 "Model artifacts" row and verification command, §8 implementation map)

**ML algorithms or forecasting logic affected.** None. The incumbent 12-feature fixed-penalty model, its features, penalty, holdout/CV rules, the shared feature builder, the prediction formula, the dataset-based horizon and its guards, the UI/UX, DSS/report semantics, and the schema are unchanged. No model was selected, published, or promoted; no D1a, D1b, D2, D3, or D5 decision was made.

**Tests executed and their actual results.** All three commands were re-run in Docker on 2026-10-22 for this review. The PHP runs used SQLite `:memory:` and fake local storage; the Python run used the image interpreter. No development MySQL query or mutation was performed.

Focused artifact suite:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php
```
Actual output tail (terminal colors omitted):
```text
Tests:    27 passed (286 assertions)
Duration: 1.64s
```

Full Laravel suite:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Actual output tail:
```text
Tests:    5 skipped, 90 passed (632 assertions)
Duration: 4.16s
```
The five skips are the existing disabled-Fortify password-reset cases, not passes.

Python suite:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
Actual output tail:
```text
----------------------------------------------------------------------
Ran 57 tests in 0.971s

OK
```

**Independent checks beyond the recorded evidence.** The 3.4a suite stubs the Python runtime, so four seams it cannot reach were closed by direct inspection and read-only execution:

1. *The real trainer's metrics satisfy the new validator.* `assertArtifactFiles()` (`app/Support/MlWorkflow.php:335`) requires a non-empty `model_type` string and numeric/finite-or-null MAE, RMSE and R². The **saved, real** `storage/app/private/ml/models/metrics_4e5c21436dfa4bc1.json` satisfies all of these (`model_type` "Linear Regression", `mae` `145518.0747`, `rmse` `152088.6912`, `r2_score` `-94.426`). A real training run will therefore not be rejected by the new validation. The metric *values* remain the incumbent's four-target holdout and are unchanged.
2. *The new "no leftover files" assertions are meaningful.* In the installed Laravel/Flysystem version, `Storage::allFiles()` is recursive (`FilesystemAdapter::allFiles()` → `files($directory, true)` → `listDirectoryRecursively()` with `SKIP_DOTS`). The staging-cleanup and orphan-generation assertions in `tests/Feature/MlArtifactsTest.php` therefore inspect real nested contents rather than an empty top level.
3. *The nested generation path is still consumed correctly by the read paths.* The new state keeps the `model_path` key and points it at `ml/models/<artifact_id>_<uuid>/model.pkl`; both `AnalyticsController` and `ViewerController` read that key, so the v2 layout does not break them. It does, however, bypass the new validation — see finding 1 below.
4. *The v2 identity was re-derived independently.* Recomputing the documented formula against the real export (dataset `f0819b2d…`, feature signature `f4cbf5e6…`, trainer source `10d51ee0…`, cutoff `2026-08`, protocol `chronological_holdout_25pct_rolling_cv_v1`, interpreter path `/opt/ml-venv/bin/python`) yields `trainer_signature` `ff08de1783c0a3fc95eb433c92cdaf5b328be63836cb251b7c1d43e0b770b4d2` and `artifact_id` `721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d`. The next authorized real run should publish `ml/models/721b01ad…_<uuid>/` and re-issue `2026-09` at the identical `177,137.16` kWh, because the trainer is byte-reproducible. This is a falsifiable expectation, not a published result.

`Python/predict_next_month.py` was also inspected to confirm the premise of the staging-copy design: it has **two** auto-training fallback routes (`:85-89` when the model file is absent, and `:102-110` on an unpickle failure or a `feature_version` mismatch), and both write to the paths passed in on the command line. `MlWorkflow` therefore passes validated temporary copies and re-verifies their checksums after the run; that is what protects published files from a legacy overwrite route.

**Preservation evidence.** SHA256 of all eight real files under `storage/app/private/ml` and all seven active/offline Python source files (15 files) was measured before and after the review and is identical:
```text
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json: 87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json: b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
Python/train_model.py: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
Python/monthly_features.py: f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16
Python/predict_next_month.py: 6f2445bf25b9dcf14017b786b3f2b17c3eab588f8acef540845908b119eeaf59
Python/predict_history.py: 3a68e9a489e29ebb2fe271ef7d7397d7c202c0504934152e2721459e67fb5053
Python/evaluation.py: 086811d1e33c0b114170e4ab0aa6202971a97116b3faa2088a69381090adae9a
Python/model_candidates.py: dd89e84a7ef410ca6ced7e8fa94652f9f5a65c3042f3d5ef38da716e2bfec08e
Python/ridge_tuning.py: 67a153aac84efa339b647424bdcca67cdc15655738a996d7f08a07fb24c47bf6
```
`git status` shows no new project files. The real state file is still the **legacy** shape (no `format_version`, `artifact_id`, or checksums) and is correctly treated as invalid, so the next authorized workflow run will rebuild the unchanged incumbent into version 2 rather than trust missing provenance. No file was written to `storage/app/private/ml` during this review.

> **Superseded on 2026-10-22 — see “Independent re-verification of Priority 3.4c (R1/R2)” below.** The statement above was true when this snapshot was taken and is preserved as a dated observation. It was falsified later the same day: a user-initiated application run at `2026-10-22 03:12:07` UTC migrated the real state to version 2 exactly as predicted here, published `ml/models/721b01ad…_<uuid>/`, and re-issued the `2026-09` forecast at the identical value. The legacy flat files were retained as predicted. The expectation recorded in `CURRENT.md` §1.3 was therefore confirmed, not falsified.

**Residual findings recorded (none are new regressions; all are disclosed limitations or scope gaps).**

| # | Finding | Severity |
| --- | --- | --- |
| 1 | The Analytics/Viewer historical-prediction path bypasses the new artifact validation: both controllers check only `Storage::exists($model_path)`. `Python/predict_history.py` has no training fallback, so the worst case is an empty prediction series rather than corruption. | Medium — designated as Priority 3.4c |
| 2 | The identity-defining export write is still non-atomic and rewrites the live export on every workflow call, including cache hits, while everything downstream is atomic. | Medium — designated as Priority 3.4c |
| 3 | The audit log entry for training is written **before** the atomic state commit, so a failed commit can leave the log claiming a training that was never published. | Low |
| 4 | `evaluation_protocol` is a hand-maintained PHP constant. The trainer emits no protocol identifier into the metrics JSON, so a future change to the trainer's split logic changes `trainer_signature` (forcing a retrain) but leaves the recorded protocol label stale. | Low |
| 5 | Artifact identity binds the interpreter *path*, not an independently measured runtime/package-version fingerprint. A rebuilt image with a different Python or pickle protocol can change the trainer's output bytes without changing the identity. | Low — already disclosed in the 3.4a entry |
| 6 | Unreferenced artifact generations are never garbage-collected; each forced retrain leaves a permanent `ml/models/<artifact_id>_<uuid>/` directory. | Low–Medium — natural follow-on to 3.4c |
| 7 | `assertArtifactFiles()` makes artifact validity depend on a display label: `model_type` must be a non-empty string, so renaming or removing that field would fail publication with a misleading "model metrics are invalid" message. | Low |
| 8 | A staging-copy tamper surfaces as "does not match its published state", which describes the published state although the mismatch is in a temporary copy. | Low — cosmetic |
| 9 | Documentation accuracy: the 3.4a entry above lists `tests/Feature/ForecastPeriodTest.php` as modified only for the period-rejection stub, and does not record that a **second** test's fixture was widened from 6 to 8 monthly records — required because the Priority 2 trainer needs six *eligible* rows, each with two preceding calendar actuals, not merely six rows. | Low — corrected in `AGENTS.md` and `CURRENT.md` by this entry |
| 10 | The roadmap understated the Priority 5b change surface: the "Linear Regression" label is hard-coded in `Python/train_model.py:157` as well as in the `app/Support/MlWorkflow.php:153` fallback, so the label correction also requires a trainer edit, which changes `trainer_signature` and forces a retrain. | Low — recorded as decision D5 |

**Designation — Priority 3.4c: artifact validation parity and atomic export.** This is the next smallest actionable task. It needs no beneficiary data, no model selection, no UI/UX change, no schema change, and no change to the dataset-based horizon safeguard, and it is fully testable in Docker with SQLite. In scope: (1) expose a validated-artifact accessor on `MlWorkflow` and route `AnalyticsController.php:125` and `ViewerController.php:190` through it, so an unverified, tampered, or cross-generation pair yields an empty series instead of an unchecked model; (2) write `ml/processed_dataset.csv` to a temporary file on the same filesystem and rename it atomically, so the identity-defining export cannot be left truncated while the state still references the previous `dataset_hash`. Out of scope: any model, algorithm, or feature change; UI/UX; schema; DSS and report semantics; the stale out-of-horizon `2026-11` forecast row; the algorithm-label correction (Priority 5b); the authorized workflow run (decision D2); and generation garbage collection (finding 6). Acceptance: a tampered model, a tampered metrics file, a cross-generation path, and a truncated export are each refused by a named regression; Analytics degrades to an empty series rather than serving an unverified model; the export write is atomic; and the real artifact hashes remain unchanged.

**Remaining limitations or follow-up tasks.** The deployed-artifact choice (D1a), the algorithm-label correction (D1b/D5), the authorized workflow run that migrates the legacy state to version 2 (D2), the stale out-of-horizon `2026-11` row (D3), and generation garbage collection (finding 6) all remain open and are recorded with technical recommendations in `CURRENT.md` §7 and §8. D1a remains the decision that blocks Priority 3.4b's protocol-dependent part; the recommendation in `CURRENT.md` §7 is to split that work so the protocol-independent metric-definition and label-correction increments can proceed without prejudging D1a. No claim of stronger predictive accuracy is made, and no deployment outcome is presumed by this entry.


---

## 2026-10-22 — Priority 3.4c R1/R2: validated historical reads and atomic dataset export

**Problem identified.** Analytics and Viewer bypassed the shared artifact checks by reading `model_path` and testing only existence. Separately, every workflow invocation rewrote the identity-defining live CSV directly, allowing an interrupted write to leave a truncated export beside previously valid state/model/metrics.

**Root cause (confirmed).** Priority 3.4a protected explicit training/publication and forecast consumption, but the controllers retained their independent historical-read code and `exportProcessedDataset()` retained a direct `Storage::put` to the live path. The new hash/generation checks were not applied to that second read path, and atomic publication began only after the export had already been rewritten.

**Solution implemented.** R1 exposes `MlWorkflow::withValidatedArtifact(callable $reader)`. Missing exports and missing/malformed/legacy state return null without training, export regeneration or state migration. For v2 state the accessor holds the existing cooperative workflow lock, compares current export/source/configuration/cutoff identity through the same identity matcher used by training, and reuses the existing model/metrics schema, generation/path and checksum validation. It invokes the consumer only for a valid pair, then revalidates before returning the result. Both Analytics controllers now call this accessor and map null to their existing empty historical collection, retaining the existing Python-error/JSON handling and all chart/forecast merge logic. The callback keeps validation and historical Python consumption inside one lock so another cooperative workflow cannot replace the CSV between them. An observed external mutation causes the result to be discarded.

R2 writes the unchanged serialized CSV payload to `ml/.processed_dataset_<uuid>.csv` on the same filesystem, verifies its complete SHA256, then atomically renames it over `ml/processed_dataset.csv`. The temporary file is removed in `finally`. Partial/failed writes, a short write falsely reporting success and a failed rename cannot replace the previous live export; training is not invoked after an export failure. Cache hits use the same atomic export path. Existing generation/state publication regressions now observe three moves (export, generation, state), and their failure injection explicitly targets the original generation/state boundaries rather than accidentally failing at the new export step.

**Files created or modified:**
- `app/Support/MlWorkflow.php`
- `app/Http/Controllers/Admin/AnalyticsController.php`
- `app/Http/Controllers/User/ViewerController.php`
- `tests/Feature/MlArtifactsTest.php`
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** Artifact read validation and export publication only. No Python, model selection, features, penalty, training/holdout/CV algorithm, metric definition, serializer content, period guard, UI design, schema, DSS/report rule or algorithm label change. No D1a, D1b, D2, D3, D5 or Priority 3.4b implementation/decision. No real workflow run, state upgrade, forecast re-issue or record deletion was performed. Unrelated local work was preserved.

> **Superseded on 2026-10-22 — see “Independent re-verification of Priority 3.4c (R1/R2)” below.** The sentence “No real workflow run, state upgrade, forecast re-issue or record deletion was performed” describes this entry's own implementation work and was true when written. It is no longer true of the environment: a user-initiated application run at `2026-10-22 03:12:07`–`03:12:17` UTC performed a training run, migrated the real state to version 2, re-issued the `2026-09` forecast at an identical value, refreshed the DSS record in place, and created a new report. No D1a/D1b/D2/D3/D5 decision was taken; D2 remains formally open.

**Tests executed and their actual results.** Docker PHP tests used SQLite `:memory:` and fake local storage. New cases use synthetic processed records, stubbed Python and isolated trainer/feature source copies. HTTP checks exercise both Admin Analytics and User Analytics; no real artifact or beneficiary record is mutated. Existing full-suite real PHP→Python integrations continue to use synthetic records/fake storage.

Focused command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php
```
Actual output tail (terminal colors omitted):
```text
Tests:    47 passed (1179 assertions)
Duration: 2.87s
```
All 27 previous cases still pass. Twenty new cases cover the four named refusals below; eight absent/malformed/legacy/source/configuration cases; valid unchanged values on both HTTP pages; two external-mutation/discard cases; atomic export on a model cache hit; three write/rename failures; and failed first export. Invalid historical reads never invoke the Python consumer or training, create no forecast, preserve processed records, and leave the complete fake ML file snapshot byte-identical. Valid reads retain the same prediction/accuracy response values and also leave files/records unchanged.

Full Laravel command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Actual output tail:
```text
Tests:    5 skipped, 110 passed (1525 assertions)
Duration: 3.75s
```
The five skips remain the disabled-Fortify password-reset cases, not passes. Existing horizon, repeated/scheduled workflow, actual-to-DSS/report linkage and real Python feature integration regressions pass. No MySQL-specific persistence test was repeated: there is no schema change and the designated acceptance uses isolated SQLite. No development MySQL query or mutation was performed.

Python command:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
Actual output tail:
```text
Ran 57 tests in 0.678s

OK
```

Formatting command (only the three selected files):
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint app/Support/MlWorkflow.php app/Http/Controllers/Admin/AnalyticsController.php tests/Feature/MlArtifactsTest.php
```
Actual output tail:
```text
PASS   ........................................................... 3 files
```
Viewer syntax check (surrounding pre-existing formatting was preserved):
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 php -l app/Http/Controllers/User/ViewerController.php
```
Actual output:
```text
No syntax errors detected in app/Http/Controllers/User/ViewerController.php
```
`git diff --check` passed; whitespace was also checked explicitly for all task files, including untracked documentation/tests.

**Named acceptance evidence:**

| Acceptance condition | Passing regression/evidence |
| --- | --- |
| Tampered model refused | `historical reads refuse a tampered model without training or mutating artifacts` |
| Tampered metrics refused | `historical reads refuse tampered metrics without serving an unchecked model` |
| Cross-generation pair refused, including correct per-file hashes | `historical reads refuse cross generation paths even when individual hashes are correct` |
| Truncated live export refused | `historical reads refuse a truncated export rather than using the previously trained model` |
| Empty-series degradation on both pages | Each refusal case calls both HTTP routes and asserts actual rows remain while historical predicted values are null; the Python consumer never runs. Valid v2 state is separately tested on both pages with identical response values. |
| Atomic export and failure safety | `the complete export is staged and atomically replaced even on a model cache hit` inspects complete temporary bytes and intact live bytes before rename; three partial-write/rename failures and a failed first export leave no partial live file or attempted temporary file. |
| Existing valid artifacts preserved | Before/after hashes below match; no real workflow, migration or database-record operation was invoked. |

**Current post-3.4c source map.** `app/Support/MlWorkflow.php` is 458 lines: validated public accessor `:27`, read validator `:46`, shared identity matcher `:64`, workflow lock `:262`, export method `:281`, temporary CSV `:320`, atomic export rename `:326`, Python invocation `:336`, feature signature `:363`, artifact identity `:368`, artifact-file validation `:388`, pair validation `:408` / `:425`, and prediction copies `:436`. The unchanged label fallback is now `:196`. Controller historical accessors call shared validation at `AnalyticsController.php:121` and `ViewerController.php:186`. Older references in dated history/review entries describe the earlier tree.

**Preservation evidence.** SHA256 snapshots before and after matched for every real ML file (eight files) and all seven active/offline Python source files — 15 files total, with no new file under `storage/app/private/ml`:
```text
hashesUnchanged: true
filesCompared: 15
processed_dataset.csv: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
model_state.json: 87dc6c41b05dc694b7322553e03e9ddb602904ee6a965e2ab91d533bdfda2f0e
qc_energy_model_4e5c21436dfa4bc1.pkl: fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948
metrics_4e5c21436dfa4bc1.json: b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908
Python/train_model.py: 10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae
Python/monthly_features.py: f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16
Python/predict_next_month.py: 6f2445bf25b9dcf14017b786b3f2b17c3eab588f8acef540845908b119eeaf59
Python/predict_history.py: 3a68e9a489e29ebb2fe271ef7d7397d7c202c0504934152e2721459e67fb5053
Python/evaluation.py: 086811d1e33c0b114170e4ab0aa6202971a97116b3faa2088a69381090adae9a
Python/model_candidates.py: dd89e84a7ef410ca6ced7e8fa94652f9f5a65c3042f3d5ef38da716e2bfec08e
Python/ridge_tuning.py: 67a153aac84efa339b647424bdcca67cdc15655738a996d7f08a07fb24c47bf6
```
The production model, metrics, forecasts and source history were not regenerated to obtain this evidence; no new predictive-performance measurement is claimed.

**State of this entry:** Priority 3.4c R1 and R2 are **Implemented**, **Tested**, **Verified**, with all documented acceptance criteria satisfied.

**Remaining limitations or follow-up tasks.** The real state remains legacy and intentionally unchanged; historical fitted predictions are now withheld until valid v2 state exists, with actuals and saved forecasts retaining their existing read paths. D2 remains a separate authorization, not an automatic side effect of visiting Analytics. D1a/D1b/D3/D5 and Priority 3.4b are untouched. Generation garbage collection, audit-before-commit ordering, protocol-label/runtime-version provenance, display-label validation coupling and the other recorded residual findings are outside R1/R2 and remain open. Atomic visibility relies on local same-filesystem rename and cooperative locking, not a forced-power-loss durability guarantee. External writers do not honor that lock; revalidation discards an observed mutation but is not a security boundary against an adversary controlling files. No claim of improved forecasting accuracy is made.

> **Superseded on 2026-10-22 — see “Independent re-verification of Priority 3.4c (R1/R2)” below.** The opening sentences of this paragraph (“The real state remains legacy and intentionally unchanged; historical fitted predictions are now withheld until valid v2 state exists”) were true when written and are preserved as a dated observation. They are no longer true of the environment: the real state is now version 2 and historical fitted predictions are served again, because a user-initiated application run at `2026-10-22 03:12:07` UTC performed the migration and re-issue. That run was a manual application session (audit trail rows 41–46), not an automatic side effect of visiting Analytics and not a recorded D2 authorization; D2 remains formally open.

---

## 2026-10-22 — Independent re-verification of Priority 3.4c (R1/R2) and correction of the recorded artifact state

**Problem identified.** Priority 3.4c had been implemented and self-reported as Docker-verified, but it had not been independently verified, and its recorded preservation claims had been falsified by the environment after they were written. The 3.4c entry asserts that "the real state remains legacy and intentionally unchanged", that "no real workflow run, state upgrade, forecast re-issue or record deletion was performed", and that no file was written under `storage/app/private/ml`. The 3.4a re-verification entry likewise recorded the real state as legacy. Both statements describe a moment that no longer existed by the time of this review.

**Root cause (confirmed for the stale claims).** The three dated entries were written at 11:08:45 local time on 2026-10-22. A manual application session then executed at 03:11:40–03:12:17 UTC the same day, i.e. after those entries were written. The audit trail identifies it as an interactive user session — `audit_trails` rows 41–46, `user_id` 1 ("Renante Barcoma"), IP `192.168.65.1`, every row `status = success`:

| Audit row | Created at (UTC) | Module / action |
| --- | --- | --- |
| 41 | 2026-10-22 03:11:40 | Preprocessing — "Run Preprocessing", 17 processed records generated with ML features |
| 42 | 2026-10-22 03:12:07 | ML Forecasting — "Train Model", trained on the latest processed dataset |
| 43 | 2026-10-22 03:12:09 | Forecasting — "Generate Prediction for 2026-09: 177137.16 kWh" |
| 44 | 2026-10-22 03:12:09 | Decision Support — "Auto Generate DSS Result", Low Demand and Moderate Readiness |
| 45 | 2026-10-22 03:12:09 | Reports — "Generate Report: Renewable Energy Transition Report - 2026-10-22 03:12" |
| 46 | 2026-10-22 03:12:17 | Decision Support — "Generate DSS Result", Low Demand and Moderate Readiness |

The session was therefore a deliberate interactive application workflow, not an automatic side effect of visiting an Analytics page and not an artifact of the code review. It was **not** a recorded team decision, so D2 remains formally open. The root cause of the stale claims is a documentation-ordering defect — a point-in-time snapshot was published as a continuing statement of fact about a mutable environment — and this entry corrects that pattern without rewriting the original dated conclusions.

**Solution implemented.** Verification and documentation only. No application code, Python source, test, migration, database record, model artifact, UI or configuration file was created or modified. The review (a) re-ran all three verification suites against the current tree, (b) inspected the real ML tree, the development database and the audit trail read-only, (c) exercised the validated historical accessor against the real storage and database without any test double, (d) proved two latent defects in the 3.4c read path, and (e) recorded the new artifact state and the newly opened findings. The recorded state of the three dated entries is corrected by superseding notes that point to this entry rather than by deleting or rewriting their original conclusions.

**Files created or modified:**
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. The subject of the verification is the artifact lifecycle of the existing 12-feature penalized linear fit, which was neither retrained nor modified by this review. No feature, penalty, training/holdout/CV algorithm, metric definition, serializer content, period guard, DSS/report rule, schema or algorithm label was changed. No D1a, D1b, D2, D3 or D5 decision was taken, and no model-selection or deployment outcome is asserted.

**Tests executed and their actual results.**

Focused artifact suite:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php
```
Actual output tail:
```text
  Tests:    47 passed (1179 assertions)
  Duration: 4.03s
```
This reproduces the recorded 47 cases / 1179 assertions exactly.

Full Laravel suite:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
Actual output tail:
```text
  Tests:    5 skipped, 110 passed (1525 assertions)
  Duration: 13.11s
```
The five skips remain the disabled-Fortify password-reset cases, not passes.

Python suite:
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
Actual output tail:
```text
----------------------------------------------------------------------
Ran 57 tests in 1.108s

OK
```

Read-only inspection of the real ML tree after the suites (SHA256):
```text
ee807ff74bef90ab99ca27fea4e70669cf719233544c182872f70e4ab9761524  storage/app/private/ml/model_state.json
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  storage/app/private/ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_e9e4b6b8-4904-4f4a-888e-4d5c303564b0/metrics.json
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  storage/app/private/ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_e9e4b6b8-4904-4f4a-888e-4d5c303564b0/model.pkl
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  storage/app/private/ml/models/metrics_4e5c21436dfa4bc1.json
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  storage/app/private/ml/models/metrics_66eeed996498898f.json
01b09802a341ed3d7e4d58fec415cebd7d30d3895f2c9851ea1ea79c12d3dcac  storage/app/private/ml/models/metrics_9bd1e07208b721d7.json
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  storage/app/private/ml/models/qc_energy_model_4e5c21436dfa4bc1.pkl
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  storage/app/private/ml/models/qc_energy_model_66eeed996498898f.pkl
c24988fee0bcd5bc721821805215e4ff994e8c5af59e192e4b75e14ccae3d0a4  storage/app/private/ml/models/qc_energy_model_9bd1e07208b721d7.pkl
f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af  storage/app/private/ml/processed_dataset.csv
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855  storage/app/private/ml/workflow.lock
```
`e3b0c44…` is the SHA256 of the empty string: `ml/workflow.lock` is a zero-byte lock file. The Python sources are byte-identical to the pre-review baseline:
```text
10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae  Python/train_model.py
f4cbf5e67330404f90a2b1f5b531c72aed2d0f25f6f6a2f34c6d9b18c40f5a16  Python/monthly_features.py
6f2445bf25b9dcf14017b786b3f2b17c3eab588f8acef540845908b119eeaf59  Python/predict_next_month.py
3a68e9a489e29ebb2fe271ef7d7397d7c202c0504934152e2721459e67fb5053  Python/predict_history.py
086811d1e33c0b114170e4ab0aa6202971a97116b3faa2088a69381090adae9a  Python/evaluation.py
dd89e84a7ef410ca6ced7e8fa94652f9f5a65c3042f3d5ef38da716e2bfec08e  Python/model_candidates.py
67a153aac84efa339b647424bdcca67cdc15655738a996d7f08a07fb24c47bf6  Python/ridge_tuning.py
```

Read-only accessor probe against the real storage and the real development database (no test doubles, no writes):
```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 php artisan tinker --execute="app(App\Support\MlWorkflow::class)->withValidatedArtifact(fn (array $a) => [...])"
```
Actual output:
```text
accessor result: {"dataset_path":"ml/processed_dataset.csv","model_path":"ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_e9e4b6b8-4904-4f4a-888e-4d5c303564b0/model.pkl","artifact_id":"721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d"}
```
The accessor now **succeeds** against the real environment, so the historical fitted series is served again on both Analytics pages. The 3.4c entry's prediction that it would be withheld until a version-2 state existed was correct as written and is now overtaken by the environment.

**Independent confirmation of the recorded falsifiable expectation.** `CURRENT.md` §1.3 recorded the v2 identity as `trainer_signature ff08de17…` / `artifact_id 721b01ad…` and stated, as an expectation and not a published result, that the next authorized run "should publish `ml/models/721b01ad…_<uuid>/` and re-issue `2026-09` at the identical `177,137.16` kWh, because the trainer is byte-reproducible". The `2026-10-22 03:12:07` run **confirmed every clause**:

| Predicted | Observed | Match |
| --- | --- | --- |
| Publish `ml/models/721b01ad…_<uuid>/` | `ml/models/721b01ad…_e9e4b6b8-4904-4f4a-888e-4d5c303564b0/` with `model.pkl` + `metrics.json` | Yes |
| `ml/model_state.json` rewritten into the version-2 shape | `format_version` 2, `artifact_id 721b01ad…`, `trainer_signature ff08de17…`, `training_cutoff 2026-08`, checksums present | Yes |
| Every legacy flat file retained | All six legacy `qc_energy_model_*` / `metrics_*` files still present | Yes |
| `2026-09` re-issued at the identical `177,137.16` kWh | `forecast_results` `2026-09` = `177137.16`, `change_percent` `-1.54`, `predicted_at 2026-10-22 03:12:09` | Yes |
| Model/metrics bytes unchanged (byte-reproducible trainer) | `model_sha256 fd929ddc…` and `metrics_sha256 b7d4b763…` equal the legacy files' hashes | Yes |
| Live export unchanged | `processed_dataset.csv f0819b2d…` unchanged; `.staging/` empty; no orphaned `ml/.processed_dataset_*.csv` | Yes |

**Live database state after the run** (read-only `SELECT`):

| Record | Value |
| --- | --- |
| `forecast_results` `2026-09` | `177137.16` kWh, MAE `145518.0747`, RMSE `152088.6912`, R² `-94.4260`, `model_type` "Linear Regression", `predicted_at 2026-10-22 03:12:09` |
| `forecast_results` `2026-11` (stale, out of horizon) | `202870.53` kWh, R² `-7.7672`, `predicted_at 2026-10-21 05:51:16` — unchanged by the run; D3 still open |
| `dss_results` id 2 | "Low Demand / Moderate Readiness", `updated_at 2026-10-22 03:12:17` (mutated in place), basis `predicted_consumption_kwh 177137.16`, `average_consumption_kwh 218067.058824` |
| `dss_results` id 1 | "Moderate Demand / Moderate Readiness", attached to the stale `2026-11` forecast, unchanged |
| `reports` id 9 | Created `2026-10-22 03:12:09`; summary still asserts `"model_used": "Linear Regression"` and stores the holdout metrics under the key `forecast_accuracy` |

**Named acceptance evidence for this review:**

| Review condition | Passing evidence |
| --- | --- |
| 3.4c's own regression coverage is real and green | 47 focused cases / 1179 assertions reproduced; the three new atomic-export cases assert staged complete bytes before rename, that a partial write, a short write falsely reporting success and a failed rename all leave the previous export pair and state byte-identical, and that a failed first export publishes neither a truncated dataset nor artifact state |
| The new cases are non-vacuous | The write-failure cases inject at the `ml/.processed_dataset_` prefix and at `File::move`, and assert `artifactFiles()` is unchanged, so a passing assertion cannot be produced by a code path that never writes |
| Historical reads refuse unverified artifacts | Named cases cover a tampered model, tampered metrics, a cross-generation path with individually correct hashes, a truncated export, and eight absent/malformed/legacy/source/configuration variants; each asserts the reader callback is never invoked and both Analytics routes still return 200 with no historical predictions |
| Valid reads do not train or mutate | `valid artifacts preserve historical values for both Analytics pages without any workflow writes` asserts one training run, zero prediction runs, two historical runs, an unchanged file snapshot, unchanged processed records and zero forecast rows |
| External mutation during a read is discarded | `historical results are discarded if validated inputs change during the read` for both `model_path` and `dataset_path` |
| Production behavior | The real accessor probe above succeeds; the 3.4c prediction that it would be withheld while the state is legacy was confirmed by code reading and is now overtaken by the environment |
| Preservation of unrelated work | `git status` shows the same 9 modified and 14 untracked paths as before the review; `storage/app/private/**` is covered by `storage/app/private/.gitignore` (`*`), so the new generation, the migrated state and the lock file do not appear in the repository status |

**Newly recorded residual findings.**

| # | Finding | Severity |
| --- | --- | --- |
| 1 | **Documentation ordering.** Point-in-time environment snapshots were published as continuing statements of fact, and three dated entries came to describe a state the environment had already left. This entry supersedes them without rewriting their conclusions. | Medium — process defect, corrected here |
| 2 | **State-supplied dataset path.** `MlWorkflow.php:39` passes the reader `$state + ['dataset_path' => self::DATASET_PATH]`. The union operator keeps the **left** operand's key, and `matchesIdentity()` (`:64-73`) compares only the identity keys, so it tolerates extra state keys. A `dataset_path` key written into `ml/model_state.json` therefore overrides the canonical export path, while the export-integrity check at `:57` continues to hash `self::DATASET_PATH` — the check and the consumed file can diverge. Proven: `$state + [canonical]` yields the state's value, and the extra key survives identity matching. | Low severity (requires write access to the state file, the same trust level as the model file), but it contradicts the "never serve an unchecked input" guarantee — designated for Priority 3.4d |
| 3 | **Non-re-entrant workflow lock.** `withArtifactLock()` (`:262-279`) is not re-entrant. Proven in the container: a second `flock(LOCK_EX)` on the same file from a second descriptor in the same process is **blocked**. No current consumer re-enters `MlWorkflow`, but the accessor holds the lock across the whole reader callback, so a future callback that calls back into the workflow would hang the request. | Low — latent, designated for Priority 3.4d |
| 4 | **Silent degradation when the database is ahead of the artifact.** `validatedArtifact()` derives the cutoff from the latest `ProcessedRecord` (`:52`). If preprocessing has succeeded but training has not yet run, or failed, the recomputed cutoff no longer matches the state's `training_cutoff`, the accessor returns null, and **both Analytics pages silently drop the entire predicted series** with no error and no log entry. | Medium — designated for Priority 3.4d |
| 5 | **Read paths take an exclusive lock and create a file.** `withArtifactLock()` takes `LOCK_EX` and `fopen(..., 'c')`, so every Analytics/Viewer page view serializes against other page views and against a concurrent training run, and the first valid read creates `ml/workflow.lock` in the production ML tree (it now exists, zero bytes). | Low — operational, to be documented in Priority 3.4d |
| 6 | **Orphaned export temporaries on abrupt termination.** `exportProcessedDataset()` (`:320-331`) removes the temporary in `finally`, but a hard kill between the temporary write and the rename leaves `ml/.processed_dataset_<uuid>.csv` behind. No garbage collection exists. Currently zero orphans. | Low — same class as the already-recorded generation-GC finding |
| 7 | **A side-effect chain wider than the forecast row.** The run also mutated `dss_results` id 2 in place (`updated_at` moved, `created_at` unchanged) and created `reports` id 9. The stale out-of-horizon `2026-11` forecast retains its own DSS row id 1, so deleting the stale forecast under D3 must also consider the DSS and report linkage rather than the forecast row alone. | Low — extends the recorded D3 recommendation |
| 8 | **The shipped report text still asserts the mislabeled algorithm.** `reports` id 9 records `"model_used": "Linear Regression"` and a `prediction_explanation` that describes the penalized fit as plain linear regression, and stores holdout metrics under the key `forecast_accuracy`. This is the same defect class as the Priority 5a/5b findings, now observable in a persisted artifact. | Low — belongs to D1b/D5 and Priority 5a |

Findings 3–8 of the earlier 3.4a residual list remain open and unchanged: audit-log-before-commit ordering (`AuditLogger::log` at `:133` precedes the state commit at `:138`), `evaluation_protocol` maintained only as a PHP constant, interpreter identity bound to a path rather than a runtime fingerprint, `model_type` as both display label and validated field (`:397`), the staging-copy error wording, and the absence of generation garbage collection.

**State of this entry:** The 3.4c implementation is **Verified** by independent re-execution (47 focused cases / 1179 assertions, 110 passed / 5 skipped / 1525 assertions, 57 Python tests), its documented acceptance criteria are satisfied, its recorded environment snapshot is superseded by this entry, and **Priority 3.4d** is designated as the next smallest actionable task.

**Remaining limitations or follow-up tasks.** This review is verification only: no defect was fixed, and the eight findings above plus the earlier residual list are recorded, not resolved. The D2 decision remains open; the `2026-10-22 03:12:07` run was a user-initiated application session and is recorded as an environmental fact, not as a team authorization, so the recommendation to defer a formal D2 authorization until the label correction is in place is unchanged. D1a, D1b, D3 and D5 are untouched, and Priority 3.4b remains blocked on D1a. No claim of improved forecasting accuracy is made, and no deployment outcome is presumed.


---

## 2026-10-22 — Priority 3.4d: historical read-path hardening

**Problem identified.** The 3.4c review designated three remaining read-path defects: a state-supplied dataset path could override the checked canonical export; a callback re-entering the workflow could block indefinitely; and a database cutoff ahead of the publication silently removed historical predictions. Exclusive read locking and lock-file creation also needed an explicit operational contract.

**Root cause (confirmed).** The state-left PHP array union retained an extra `dataset_path` key although identity validation checked the canonical export. The lock opened another descriptor and attempted blocking `LOCK_EX` without detecting same-process ownership. Identity mismatch returned null without distinguishing a newer processed-data cutoff from empty data.

**Solution implemented.** The consumer now receives an explicit canonical `dataset_path` override. A static guard keyed by normalized lock location rejects nested workflow calls across both the same and separately resolved instances before a second acquisition; ownership is released in `finally`, including after callback exceptions. Historical refusals emit Laravel application warnings with structured reason codes. `database_ahead_of_artifact` includes only published/current calendar cutoffs, and remains distinct from `empty_dataset`, missing inputs, invalid/legacy state, identity mismatch, invalid pairs and mutation during consumption. Null/empty-series responses and both controllers' existing HTTP 200 behavior remain unchanged; no UI field or database-backed audit write was introduced.

The code contract and AGENTS.md document that a v2 read uses an exclusive lock for the entire callback, serializes readers and writers, and creates `ml/workflow.lock` when absent. Reads rejected by early export/state gates do not acquire the lock. No training, migration or forecast fallback was added.

Twelve new regression cases build synthetic version-2 publications directly in fake storage without invoking a training or forecasting workflow. They cover canonical arguments in the direct reader and both Analytics routes; same-instance and cross-instance re-entry rejection with outer-lock ownership retained; cleanup after exceptions; distinct database-ahead/empty-data reasons with both HTTP pages; six missing/invalid/empty reason cases; and lock creation, exclusivity and release. Together with the existing refusal coverage, all five designated criteria are verified:

| Criterion | Verified evidence |
| --- | --- |
| Canonical checked input | `historical readers consume the canonical export even when state supplies a dataset path` checks direct consumption and both controllers' Python arguments. |
| No nested-call deadlock | `reentrant readers fail fast and leave the outer workflow lock held` covers two instances; exception recovery permits the next read. |
| Graceful, diagnosable refusal | Both Analytics pages return HTTP 200 with null historical predictions for a database-ahead publication and for an empty dataset, with distinct log reasons; existing absent/invalid/stale HTTP regressions remain green. |
| Required verification | All three suites passed with the actual results below; the exclusive-lock operational contract is tested and documented. |
| Preservation | Only the support class, its existing regression file and three documentation files changed for this task; all 18 saved-ML/Python hashes match the pre-change snapshot. |

**Files modified (no new task files).**
- `app/Support/MlWorkflow.php` — canonical consumer input, same-process re-entry guard, refusal diagnostics and locking contract.
- `tests/Feature/MlArtifactsTest.php` — direct synthetic publication helper, log spy and twelve focused cases.
- `AGENTS.md` — current verification counts, implementation map and read-lock operational contract.
- `CURRENT.md` — all five criteria marked verified, evidence, current source map and unchanged decision boundaries.
- `IMPLEMENTATION.md` — this implementation and verification record.

**ML algorithms or forecasting logic affected.** None. Production model selection, fitting, features, metrics, evaluation protocol, saved model/metrics bytes, dataset-based next-month horizon, UI/UX, schema, labels and DSS/report rules are unchanged. No live training, forecasting, accessor probe or production-database operation was executed for this task. Existing required suites use their isolated synthetic/fake-storage workflows, including pre-existing real-Python integration tests; the new read-path cases never train or forecast.

**Tests executed and actual results (Docker, 2026-10-22).** Output tails are reproduced below.

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint app/Support/MlWorkflow.php tests/Feature/MlArtifactsTest.php
```
```text
PASS ... 2 files
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php
```
```text
Tests:    59 passed (1366 assertions)
Duration: 3.96s
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```
```text
Tests:    5 skipped, 122 passed (1712 assertions)
Duration: 6.35s
```
The five skips remain the expected disabled-Fortify password-reset cases; they are not passes.

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 57 tests in 0.648s

OK
```

Final `git diff --no-index --check /dev/null <path>` checks passed for each of the five task files, including untracked documentation and the existing untracked test file. The final diff against pre-task source copies contains only the read-path changes and regressions described above.

Read-only before/after SHA256 snapshots matched for all **11 real ML files** (export, state, lock, active generation pair and six legacy artifacts) and **seven Python sources**. In particular, the active version-2 state remains `ee807ff74bef90ab99ca27fea4e70669cf719233544c182872f70e4ab9761524`, model `fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948`, metrics `b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908` and export `f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af`. No saved ML artifact or original dataset was overwritten. The prior user-initiated application run recorded above remains an environmental fact, not a D2 authorization.

**Remaining limitations or follow-up tasks.** Priority 3.4d is **Implemented, Tested, Verified**; all five documented criteria passed. Read locking remains exclusive and serializing by design; nested workflow use is rejected, not supported. Diagnostic warnings use application logs, with no new UI control or database record. Previously recorded model/metric/label, audit ordering, runtime fingerprinting and garbage-collection limitations remain outside this increment. D1a, D1b, D2, D3 and D5 remain open; Priority 3.4b was not implemented. No improvement in forecasting accuracy is claimed.

## 2026-10-22 — Designation of Priority 3.4e and scheduling of Priority 4b (documentation only)

**Problem identified.** The selected roadmap decision was Priority 3.4e followed by Priority 4b, but Priority 3.4e existed in no documentation file: a content search for `3.4e` across `AGENTS.md`, `CURRENT.md` and `IMPLEMENTATION.md`, run before this change, returned **no match**, and no section stated its scope, its acceptance criteria or its position in the sequence. Priority 4b existed only as a one-line roadmap row in `CURRENT.md` §7, with no scope, no acceptance criteria and no defined position after the newly designated task. The same inspection found the artifact-state facts recorded in `CURRENT.md` §1 to be stale: a further user-initiated application run at `2026-10-22 05:54:52–05:55:25` UTC had published a **second** generation for the same `artifact_id` and re-issued the `2026-09` forecast, so the recorded state hash, generation path, forecast timestamps and report inventory no longer described the live tree.

**Root cause.** *Documentation defect:* the designation was never written to disk, and the 4b roadmap row was never expanded. *Environment-oversight defect:* the one already recorded as `CURRENT.md` §3.2 item 11 and §3.2a item 20 — a mutable environment was described in the present tense and then cited as a continuing fact. *The defect that Priority 3.4e must address is confirmed by source reading:* `app/Support/MlWorkflow.php:459` validates the published generation with `str_starts_with($directory, 'ml/models/'.($state['artifact_id'] ?? '').'_')`, which (a) collapses to the wildcard `ml/models/_` when `artifact_id` is absent, (b) accepts any sibling directory that merely shares the prefix, and (c) is not backed by an identity re-derivation on the forecast path, where `predictAndGenerate()` (`:201`) asserts the pair straight from the raw state file.

**Solution implemented.** Documentation only. Priority 3.4e is now designated as the immediate next task, with a complete scope, an explicit out-of-scope list, seven in-scope items and seven checkable acceptance criteria, and with the requirement that it change no production behavior, trigger no retrain and require no state-format change. Priority 4b is scheduled immediately after it, with its own scope, acceptance criteria and out-of-scope list. The stale artifact facts were superseded with re-derived values and the earlier dated claim was preserved as an explicitly superseded statement. The confirmed defect was recorded as `CURRENT.md` §3.2a item 28 with its evidence.

**Files created or modified.**
- `AGENTS.md` — implementation-status paragraph, Priority 3 "Current state", new §5 testing row "Published-generation binding" (**Pending**), new §5 environment note on the canonical generation layout, and the §8 implementation-map entry for the active workflow.
- `CURRENT.md` — §1 phase and corrected artifact/forecast facts, new §1.5 (designation evidence and the second publication), §3.2 item 18 (two generation directories), new §3.2a item 28 (the confirmed defect), new §4 "Priority 3.4e — designated, pending", new §4 "Priority 4b — scheduled after Priority 3.4e", §4 checklist entries, §5 tasks in progress, §6 completed task, §7 roadmap row and reordered steps, §8 environment note.
- `IMPLEMENTATION.md` — this entry.

**ML algorithms or forecasting logic affected.** None. The incumbent 12-feature fixed-penalty model, its features, penalty, holdout/CV rules, metric formulas, evaluation protocol, shared feature builder, prediction scripts, dataset-based next-month horizon, UI/UX, schema, DSS and report semantics, and every saved artifact byte are unchanged. No model was selected, promoted or published. No live training, forecasting, accessor probe or production-database mutation was performed; the database was read with `SELECT` only. Decisions D1a, D1b, D2, D3 and D5 remain open and unimplemented; Priority 3.4b remains blocked on D1a.

**Tests executed and their actual results.** **No test suite was run for this task**, because no application code, test file, database record or ML artifact was changed; the Docker suites were not invoked and no suite result is claimed. The following read-only checks were executed instead, and their real output is reproduced below.

```sh
# A. Content search for the missing designation, before this change
grep -n "3.4e" AGENTS.md CURRENT.md IMPLEMENTATION.md
```
```text
(no output — the designation existed in none of the three files)
```

```sh
# B. Probe of the exact generation predicate from app/Support/MlWorkflow.php:459 (host PHP 8.5.0)
php -r '$id = "721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d";
$state = ["artifact_id" => null];
echo "empty-artifact_id prefix collapses to wildcard: ";
var_export(str_starts_with("ml/models/_staging", "ml/models/".($state["artifact_id"] ?? "")."_")); echo "\n";
echo "sibling generation with the same artifact_id satisfies the prefix: ";
var_export(str_starts_with("ml/models/".$id."_e9e4b6b8-4904-4f4a-888e-4d5c303564b0", "ml/models/".$id."_")); echo "\n";
echo "published generation matches the canonical form: ";
var_export((bool) preg_match("#^ml/models/[0-9a-f]{64}_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#", "ml/models/".$id."_5a4e647d-16ee-4589-a398-a491f99cb1ef")); echo "\n";'
```
```text
empty-artifact_id prefix collapses to wildcard: true
sibling generation with the same artifact_id satisfies the prefix: true
published generation matches the canonical form: true
```

```sh
# C. Saved ML tree hashes (read-only, unchanged by this documentation task)
shasum -a 256 storage/app/private/ml/model_state.json storage/app/private/ml/processed_dataset.csv storage/app/private/ml/models/*/model.pkl storage/app/private/ml/models/*/metrics.json
```
```text
240562340a27df45f861030e776e020aa3d105e14561268123db5d14fc18494b  storage/app/private/ml/model_state.json
f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af  storage/app/private/ml/processed_dataset.csv
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  storage/app/private/ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_5a4e647d-16ee-4589-a398-a491f99cb1ef/model.pkl
fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948  storage/app/private/ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_e9e4b6b8-4904-4f4a-888e-4d5c303564b0/model.pkl
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  storage/app/private/ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_5a4e647d-16ee-4589-a398-a491f99cb1ef/metrics.json
b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908  storage/app/private/ml/models/721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_e9e4b6b8-4904-4f4a-888e-4d5c303564b0/metrics.json
```

```sh
# D. Generation directories present in the live tree
ls -1 storage/app/private/ml/models/ | grep -v '\.json$\|\.pkl$'
```
```text
721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_5a4e647d-16ee-4589-a398-a491f99cb1ef
721b01ada7dd050b2e62e8c38607af5bccc5103da1ca8ba1b61035acef08b26d_e9e4b6b8-4904-4f4a-888e-4d5c303564b0
```

```sh
# E. Development database, read-only SELECT
docker exec capstone-sample-mysql-1 sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" capstone_db_sample -e "SELECT id,year,month,predicted_consumption_kwh,r2_score,model_type,predicted_at FROM forecast_results ORDER BY id; SELECT id,action,created_at,user_id FROM audit_trails ORDER BY id DESC LIMIT 6; SELECT id,title,created_at FROM reports ORDER BY id DESC LIMIT 2;" 2>/dev/null'
```
```text
id	year	month	predicted_consumption_kwh	r2_score	model_type	predicted_at
1	2026	11	202870.53	-7.7672	Linear Regression	2026-10-21 05:51:16
2	2026	9	177137.16	-94.4260	Linear Regression	2026-10-22 05:55:25
id	action	created_at	user_id
52	Generate Report	2026-10-22 05:55:25	1
51	Auto Generate DSS Result	2026-10-22 05:55:25	1
50	Generate Prediction	2026-10-22 05:55:25	1
49	Train Model	2026-10-22 05:55:23	1
48	Run Preprocessing	2026-10-22 05:55:09	1
47	Run Preprocessing	2026-10-22 05:54:52	1
id	title	created_at
10	Renewable Energy Transition Report - 2026-10-22 05:55	2026-10-22 05:55:25
9	Renewable Energy Transition Report - 2026-10-22 03:12	2026-10-22 03:12:09
```

The `2026-10-22 05:55` run (audit rows 47–52) is a user-initiated interactive session, recorded as an environmental fact and **not** as a D2 authorization.

**Remaining limitations or follow-up tasks.** Priority 3.4e is **designated only — no implementation exists yet**, and its acceptance criteria are unverified until it is implemented and its three suites are run. Priority 4b is **scheduled and pending**; it changes factual label text and therefore needs its own implementation scope and a preserved UI design. The generation-garbage-collection limitation (§3.2 item 18) and the orphaned-export-temporary limitation (§3.2a item 25) are unchanged and remain out of scope for 3.4e, so the live tree will keep accumulating generations. The mislabeled algorithm string persists in the trainer (`:157`), the workflow fallback (`:234`), the UI and the persisted reports id 9 and id 10; it remains bound to decisions D1b/D5 and Priority 5b. D1a, D1b, D2, D3 and D5 remain open, Priority 3.4b remains blocked on D1a, and no claim of improved forecasting accuracy is made. This documentation task did not commit anything: `AGENTS.md`, `CURRENT.md` and `IMPLEMENTATION.md` remain untracked in the working tree, and committing them is a separate, explicit decision.


---

## 2026-10-22 — Priority 3.4e: canonical generation and current-pointer binding

**Problem identified.** Published-pair validation accepted a directory prefix rather than an exact canonical generation; absent IDs collapsed it to a wildcard. Forecast consumption asserted the raw pair without independently re-deriving artifact identity. The designation also required absolute sibling rejection despite the existing v2 state being the only publication pointer.

**Root cause (confirmed).** `str_starts_with()` did not validate the ID, UUID or complete path. Checksums establish byte linkage but cannot distinguish byte-identical siblings. The forecast consumer depended on the preceding cache check for identity. There is no independent state field or trusted record identifying an earlier generation after the entire pointer is rewritten.

**Solution implemented.** The user clarified the intended boundary explicitly: **“Bind to current v2 pointer; document the limitation.”** `canonicalGeneration()` now requires a lowercase 64-hex ID, lowercase UUID, exact model/metrics filenames in the same canonical directory and unredirected physical paths. Consumers compare the candidate ID and both paths to the current v2 pointer, rejecting stale/sibling candidates even when their bytes match. Historical refusal logs `reason=non_canonical_generation`; forecast refusal throws a clear error containing that same reason before staging or forecast persistence. Forecast consumption also independently re-derives the full current identity using the live export, feature/trainer/configuration signature, processed-data cutoff and protocol, rejecting `identity_mismatch`.

Pre-publication checks validate canonical shape without requiring the not-yet-committed pair to match the live pointer. Existing cache repair is unchanged. Internal prediction copies remain exempt from published-directory rules and retain their existing file/metrics/checksum checks. No state field, format bump, new anchor, model choice or retraining was introduced. A complete rewrite of the sole pointer to another canonical, identity/checksum-valid sibling cannot be detected with the existing information; that boundary is documented and regression-tested, not presented as tamper resistance.

Twenty-four new isolated regression cases cover missing/empty/noncanonical IDs, prefix-only and invalid UUID names, traversal, mixed and retained sibling candidates, directory/file symlink redirects, independent validation of all seven identity fields, both Analytics routes' HTTP 200/null-prediction degradation, and valid historical/forecast consumption without retraining. Forecast fault injection occurs after the normal cache check, so automatic repair cannot mask the consumer validation. Symlink redirects are injected at that same seam for the forecast tests. Fixture snapshots inspect real fake-storage bytes rather than injected read responses. The valid-publication case checks both HTTP pages, copied Python arguments and bytes, unchanged v2 state/export/model/metrics, cleaned staging and the same dataset-next-month period.

**Files modified.**

- `app/Support/MlWorkflow.php` — canonical/current-pointer validation and independent forecast-time identity validation.
- `tests/Feature/MlArtifactsTest.php` — 24 focused cases and isolated candidate-read/symlink fixtures.
- `AGENTS.md` — updated testing requirement, artifact-consumer contract and line references.
- `CURRENT.md` — seven verified criteria, user clarification, current status, source map and unchanged boundaries.
- `IMPLEMENTATION.md` — this evidence record.

**ML algorithms or forecasting logic affected.** None. The production model, ML selection, features, metric/protocol/label semantics, dataset-next-month horizon, UI/UX, schema and DSS/report behavior are unchanged. Production database records and valid saved artifacts were preserved. No live training, forecasting or accessor workflow and no production-database operation was executed. New cases use directly constructed synthetic publications, fake storage, SQLite `:memory:` and stubbed Python. Existing required suites continue to exercise their isolated synthetic workflows.

**Tests executed and actual results (Docker, 2026-10-22).** Final output tails:

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint app/Support/MlWorkflow.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 2 files
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php --colors=never
```
```text
Tests:    83 passed (1776 assertions)
Duration: 9.75s
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 146 passed (2122 assertions)
Duration: 10.25s
```
The five skips remain the expected disabled-Fortify password-reset tests and are not passes.

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 57 tests in 1.495s

OK
```

Earlier runs exposed test-fixture issues, corrected before the final results: repeatedly mocking an already mocked disk terminated the runner without diagnostics; the helper now always wraps the original fake disk. Flysystem rejects symlink enumeration, so symlink snapshots read the explicitly arranged fixture files and link target. Repeated injected state responses initially polluted snapshot comparisons; those assertions now restore the original disk and inspect actual bytes. These were test-double failures, not passing verification. The final focused and full suites above passed after the fixes and final pointer-ID comparison.

Read-only before/after SHA256 snapshots match for **20 files**: all 13 saved ML files (export, state, lock, two retained generation pairs and six flat artifacts) and seven Python sources. A separate read-only filesystem/JSON check returned `true` for canonical ID, canonical model path, exact metrics path, unredirected physical paths, matching checksums and unchanged format version 2. It invoked no application code. The active state remains `240562340a27df45f861030e776e020aa3d105e14561268123db5d14fc18494b`; active model `fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948`, metrics `b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908`, export `f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af`. No saved bytes were rewritten.

Final whitespace checks (`git diff --no-index --check /dev/null <path>`) passed for all five scoped files, including the untracked documentation/test file; the final diff against pre-task source copies contains only this increment.

Post-change source references (`MlWorkflow.php`, 550 lines): historical accessor `:35`, validation `:65`, refusal logger `:98`, identity matcher `:105`, forecast consumer `:194` with pair check `:204` and identity re-derivation `:205–211`, lock `:311`, export `:337`, Python invocation `:392`, state reader `:408`, identity builder `:424`, file validation `:444`, canonical binding `:468`, pair assertion `:498`, pair predicate `:517`, staging copies `:528`. The unchanged label fallback is `:245`.

**Remaining limitations or follow-up tasks.** Priority 3.4e is **Implemented, Tested, Verified** under the explicit user clarification; all seven acceptance criteria are recorded against that boundary in CURRENT.md §4. Complete canonical-pointer replacement and arbitrary non-cooperative concurrent filesystem mutation are outside the guarantee. Cooperative exclusive locking, local storage and same-filesystem publication remain requirements. No independent anchor, generation cleanup or state-format change was added. Priority 4b remains pending and was not implemented. D1a, D1b, D2, D3 and D5 remain open; Priority 3.4b remains blocked. No forecasting-accuracy improvement is claimed.

## 2026-10-22 — Independent re-verification of Priority 3.4e (documentation only)

**Problem identified.** Priority 3.4e (canonical published-generation directory binding and current-pointer binding, plus independent forecast-time identity validation) was recorded as **Implemented, Tested, Verified** with seven acceptance criteria, 24 new focused cases and four recorded command results, but no independent review had re-checked those criteria against the implementation, the tests and the live environment. The task was to verify the increment without changing any code, test, database record or saved artifact, and to confirm the next task on the roadmap.

**Root cause (confirmed: documentation-verification gap, not a defect).** No code defect was found. The review did confirm the increment's structure by source reading: `canonicalGeneration()` (`app/Support/MlWorkflow.php:468`) requires a lowercase 64-hex `artifact_id`, a lowercase UUID generation, exact `model.pkl`/`metrics.json` names inside the same canonical directory, physical paths that resolve to their expected locations (`:479-486`), and — when `$published` is true — identity and both paths equal to the current v2 pointer (`:487-493`). It is called with `published: true` from the historical path (`validatedArtifact()` `:71`) and from the forecast path (`predictAndGenerate()` `:204`), which additionally re-derives the full identity from the live export, current cutoff and configuration (`:205-211`). Staging copies remain exempt (`assertArtifactPair($copies, temporary: true)` at `:540`/`:543`). All sixteen post-3.4e line references recorded in `CURRENT.md` §4 resolve exactly to these methods in the 550-line file; one AGENTS.md environment-note reference (`:204–211`) was corrected to `:205–211` with the published-pair check noted at `:204`.

**Solution implemented.** Documentation only, recording the verification outcome: all seven acceptance criteria confirmed; the four recorded commands re-run with identical counts; preservation and production-safety checks repeated before and after the runs; and one new residual finding recorded (epoch-based modification times place the implementation at `2026-10-22 08:24:38 UTC` and `08:25:56 UTC`, after the live publication at `05:55:23 UTC`, so the new binding has not yet been exercised by a live application run). **Priority 4b is confirmed as the next task**; no open decision was resolved.

**Files created or modified.**
- `AGENTS.md` — Priority 3 status paragraph, implementation-status paragraph, §5 testing row (re-verification note), §5 environment note (live-exercise limitation and the corrected line reference), §8 implementation-map row.
- `CURRENT.md` — header, §1 verification bullets, new §1.6, §3.2a heading and new item 29, §4 re-verification paragraph, §5/§6 status, §7 roadmap row and steps, §8 residual note.
- `IMPLEMENTATION.md` — this entry.

**ML algorithms or forecasting logic affected.** None. No application code, test, migration, UI, DSS/report rule, dataset, feature, metric, protocol, label or saved artifact was created or modified; `git status` is unchanged by the task. The verification runs used SQLite `:memory:`, fake local storage and stubbed Python, so no live training, forecasting or accessor workflow touched the live ML tree, and the development MySQL database was queried with `SELECT` only.

**Tests executed and their actual results (Docker, 2026-10-22).** All four commands were re-run; the counts reproduce the recorded 3.4e results exactly (wall-clock durations differ). Final output tails:

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint app/Support/MlWorkflow.php tests/Feature/MlArtifactsTest.php
```
```text
    PASS   ........................................................... 2 files
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php --colors=never
```
```text
Tests:    83 passed (1776 assertions)
Duration: 9.50s
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 146 passed (2122 assertions)
Duration: 16.12s
```
The five skips remain the expected disabled-Fortify password-reset tests and are not passes.

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 57 tests in 3.787s

OK
```

Read-only preservation snapshot taken **after** the runs; all 20 files match their pre-review values (state `240562340a27df45f861030e776e020aa3d105e14561268123db5d14fc18494b`, export `f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af`, active model `fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948`, active metrics `b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908`, lock `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`, the sibling generation pair, the six legacy flat artifacts, and the seven Python sources). `ml/.staging/` contains 0 entries. A read-only `SELECT` before and after the runs returned `audit_trails` 52 rows, max id 52, last `2026-10-22 05:55:25`; `forecast_results` rows 1 (`2026-11`, `202870.53`) and 2 (`2026-09`, `177137.16`, `predicted_at 2026-10-22 05:55:25`); `dss_results` 2 rows; `reports` 10 rows; `processed_records` max period `2026-08`. `git status --short` was identical before and after the runs.

**Remaining limitations or follow-up tasks.** The increment is **independently re-verified**, not live-exercised: the running application has never applied the new binding to the live tree, so the first live exercise will be the run authorized under decision **D2**, which should be checked against the same seven criteria (§3.2a item 29). The retained sibling generation `ml/models/721b01ad…_e9e4b6b8-…/` and the legacy flat artifacts are refused by the new rule but are not garbage-collected (§3.2 item 18). A complete canonical-pointer rewrite remains outside the user-clarified guarantee. Priority 4b is confirmed as the next task and was not implemented here. D1a, D1b, D2, D3 and D5 remain open and unresolved; Priority 3.4b remains blocked on D1a. No forecasting-accuracy improvement is claimed, and nothing was committed.

---

## 2026-10-22 — Priority 4b: recorded issuance versus dataset-next-month validity

**Problem identified.** Forecast targets already followed the latest actual dataset month, but “upcoming month” and “Next-Month Forecast” wording could present an estimate produced after that month as prospective. Cards/dashboard summaries did not show production time alongside the target. DSS showed assessment-generation time without the linked estimate's production time, and report creation time could be mistaken for estimate issuance.

**Root cause (confirmed).** Target validity and issuance time were not represented together in presentation data. The stored `predicted_at` was available on forecast rows, but DSS eager loading selected only forecast ID/year/month; report bodies lacked a recorded-issuance explanation. No forecast-period defect or modeling defect was identified or changed in this increment.

**Solution implemented.** Added `ForecastIssuance::label()` and a read-only `ForecastResult.issuance_label` accessor. The label explicitly states `Target: …` and `Produced: …`, then identifies production before/during/after the target month. Late estimates state “not issued in advance”; in-month estimates state “not issued before the month”. Only complete recorded timestamps are accepted; partial/relative/malformed dates cannot cause Carbon to fill fields from today's date. Timestamps and month comparisons use the existing configured application timezone (UTC), explicitly printed. Missing issuance remains unavailable; a recorded `updated_at` is identified only as a record update with “Advance issuance unverified”, rather than being asserted to be forecast issuance.

Both roles receive the same label in existing forecast responses, dashboard stats, stored-forecast Analytics rows and DSS forecast relations. Necessary factual wording replaces “upcoming month” with the month after the latest complete actual dataset month; the affected “Next-Month Forecast”/“Prediction Next Month” card titles become “Dataset-next-month Estimate”. Forecast history's timestamp header becomes “Produced”, and DSS's timestamp header becomes “Assessment Generated”. Existing card helpers, history cells, dashboard summary/recent rows, chart tooltips and DSS dialog/period text display the timing context. Layouts, CSS, navigation, cards, tables, charts and controls are preserved; no screen or control was added. Generic algorithm names and explanatory model formulas remain unchanged.

Report-body annotations derive exclusively from the saved `summary.latest_forecast` and saved DSS timestamps. Original saved explanations, recommendations and conclusions are retained verbatim. Existing report/forecast/DSS records are never rewritten. New reports capture the computed label through existing model serialization; `ReportGenerator` did not need modification. Report lists show report metadata rather than an estimate, and illustrative landing data is outside this issued-estimate scope.

**Files created or modified.**

- `app/Support/ForecastIssuance.php` — new recorded-time label helper, strict timestamp validation and clock-independent month comparison.
- `app/Models/ForecastResult.php` — appended read-only issuance label, with no database column.
- `app/Http/Controllers/Admin/DashboardController.php` — shared issuance label in dashboard stats.
- `app/Http/Controllers/Admin/AnalyticsController.php` — issuance label for saved-forecast comparison rows only.
- `app/Http/Controllers/Admin/DecisionSupportController.php` — include linked forecast timestamps in existing eager loads.
- `app/Http/Controllers/User/ViewerController.php` — matching dashboard, Analytics and DSS issuance data.
- `resources/js/types/dss.ts` — optional issuance metadata in existing forecast/DSS types.
- `resources/js/pages/Admin/Forecasting.tsx` — necessary dataset-relative wording and issuance text in existing card/history fields.
- `resources/js/pages/User/ForecastResults.tsx` — matching issuance text and factual explanation.
- `resources/js/pages/Admin/Dashboard.tsx` — issuance text in existing estimate, snapshot and recent-forecast sections.
- `resources/js/pages/User/Dashboard.tsx` — matching fields and dataset-relative wording.
- `resources/js/pages/Admin/Analytics.tsx` — issuance text in the existing saved-forecast tooltip.
- `resources/js/pages/User/Analytics.tsx` — matching tooltip text.
- `resources/js/pages/Admin/DecisionSupport.tsx` — linked-estimate issuance and separate assessment-generation labels.
- `resources/js/pages/User/DecisionSupportResults.tsx` — matching history/dialog context.
- `resources/views/reports/energy.blade.php` — snapshot-derived timing annotations in forecast and DSS sections.
- `tests/Feature/ForecastIssuanceTest.php` — 18 synthetic regression cases.
- `AGENTS.md` — issuance testing requirement and read-only presentation/snapshot contract.
- `CURRENT.md` — all five verified criteria, evidence, source map, status and remaining dependencies.
- `IMPLEMENTATION.md` — this record.

**ML algorithms or forecasting logic affected.** None. No training, inference, selection, feature, metric, protocol, algorithm-label, dataset, schema or DSS-rule change. `MlWorkflow.php`, `ForecastPeriod.php`, `EnergyDss.php` and all seven Python sources are byte-identical. No live retraining, forecasting, accessor workflow or production-database query/mutation was performed. Required Laravel suites use SQLite `:memory:` and fake storage, with pre-existing synthetic integration workflows; the new tests prevent stray processes and never invoke an ML workflow. Their report-generation calls create only isolated synthetic test records. D1a, D1b, D2, D3 and D5 remain open; no unrelated work or Priority 3.4b was implemented.

**Tests executed and their actual results (2026-10-22).**

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint --test app/Support/ForecastIssuance.php app/Models/ForecastResult.php tests/Feature/ForecastIssuanceTest.php
```
```text
PASS   ........................................................... 3 files
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php --colors=never
```
```text
Tests:    35 passed (301 assertions)
Duration: 5.68s
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 164 passed (2271 assertions)
Duration: 30.86s
```
The five skips remain the expected disabled-Fortify password-reset cases and are not passes.

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 57 tests in 2.388s

OK
```

```sh
npm run types:check
```
```text
> types:check
> tsc --noEmit
```
Exit code 0, both before and after the TypeScript changes. No frontend bundle/public assets were overwritten for verification.

The 18 new cases cover seven date/boundary/offset scenarios under three different clocks; seven missing/invalid/relative timestamps; unknown target/time; eight admin/viewer GETs with identical timing context and no forecast/DSS/actual-row writes; legacy report rendering unchanged after later live-fixture forecast/DSS updates and a changed clock; and new report capture of the recorded estimate separately from report/DSS generation. All new cases prevent stray processes. The initial focused run had three fixture-comparison failures because freshly created Eloquent instances lacked database-default columns/order until reloaded. Snapshot setup now refreshes those synthetic records; no application defect was hidden or bypassed, and the final focused/full results above passed after that correction.

Preservation checks found **22 of 23 original protected files unchanged**: every original model/metrics/export/lock file, seven Python sources and `MlWorkflow.php`/`ForecastPeriod.php`/`EnergyDss.php`. The sole changed original path was `ml/model_state.json`; two new generation files also appeared during concurrent external application activity. The new model and metrics are byte-identical to the old pair. The initial snapshot was taken at `2026-10-22 09:33:51` UTC. The current state records `trained_at 2026-10-22 09:39:28` UTC, the same artifact identity/cutoff and generation `…_f5d37e23-cbc2-47ca-bb15-c80c4a53a80f/`, with state hash `5af7ac116e20c8faac3356d7d202f2f452305759061b69f4cec18a09a822f887`. The original `…_5a4e647d-…/` publication and every earlier artifact remain present and unchanged.

A read-only diagnostic confirmed this was separate HTTP activity, not the in-process isolated tests:

```sh
docker logs --since 2026-10-22T09:39:00Z --until 2026-10-22T09:40:00Z capstone-sample-laravel.test-1 2>&1 | rg 'admin/(forecasting|preprocessing|reports|decision-support)'
```
Relevant actual lines:
```text
2026-10-22 09:39:27 /admin/forecasting/train ......................... ~ 1s
2026-10-22 09:39:30 /admin/forecasting/predict ....................... ~ 1s
2026-10-22 09:39:34 /admin/decision-support/generate ........... ~ 501.48ms
```
These are 17:39 Manila-time requests to the running application. No such endpoint was invoked by the agent. All workflow tests use fake local storage, and the new tests prevent processes and exercise only GETs or isolated report rendering/generation. The agent preserved the observed publication rather than overwriting it. No production-database query was made to infer current forecast/DSS/report rows, and no live end-to-end outcome is claimed. This environmental fact does not resolve D2 or any other decision; dated prior hashes are superseded only for the state pointer. The saved export/model/metrics hashes remain `f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af`, `fd929ddce1600a84cfd96078d0e5ee6173fb71d64d81e4914ce07039e8a30948`, and `b7d4b76317d17e0081e32ae5f0f5e0dc79810fc2a49efe4efce69ea0e6f6b908`.

Final source review and `git diff --no-index --check /dev/null <path>` checks passed for all 20 scoped source/test/documentation files, including untracked files. A before/after comparison confirms that every existing literal CSS class in the eight changed React pages remains present; only necessary text/metadata uses existing structures.

**Remaining limitations or follow-up tasks.** Priority 4b is **Implemented, Tested, Verified** against all five criteria in CURRENT.md §4. A missing `predicted_at` cannot establish advance issuance from `updated_at`, report creation or DSS regeneration; the UI explicitly leaves that unverified. Existing mutable forecast rows are still not an immutable issuance ledger, and provenance/metric/algorithm-label/DSS reliability issues retain their previously recorded scope and dependencies. No open decision was resolved or subsequent task implemented. Presentation verification consists of backend/HTML regressions, TypeScript checking and source inspection; no live application workflow or browser acceptance run was performed. Existing layouts/styles remain unchanged, while necessary explanatory text can wrap within existing fields.

## 2026-10-22 — Independent re-verification of Priority 4b and read-only investigation of the concurrent 09:39 UTC workflow run (documentation only)

**Problem identified.** Priority 4b had been implemented and Docker-verified by its originating task, but a second, independent check of its five acceptance criteria had not been recorded; at the same time a concurrent external application run at `09:39` UTC had produced a new artifact generation, forecast/DSS/report writes and audit rows that the 4b task explicitly did not query, leaving the post-run database state and the live behaviour of the 3.4e binding undocumented.

**Root cause (confirmed).** Not a defect: the 4b task's snapshot deliberately stopped short of production-database queries after observing the external activity, so CURRENT.md still carried pre-run database facts and an unverified live-exercise limitation (§3.2a item 29). Separately, an external actor deleted the gitignored `public/build` Vite manifest during this verification window, breaking four Blade-auth tests and causing one live HTTP 500.

**Solution implemented.** Documentation only, plus one environment repair. All five 4b criteria were re-checked against `app/Support/ForecastIssuance.php`, `app/Models/ForecastResult.php`, the four controllers, `resources/views/reports/energy.blade.php` and the 18-case `tests/Feature/ForecastIssuanceTest.php` (7+7+4 datasets across 6 test functions). The five recorded commands were re-run in Docker with their outputs reproduced exactly. The concurrent run was reconstructed read-only from container access logs, `storage/logs/laravel.log`, the live ML tree and `SELECT`-only database queries: preprocessing (`09:39:23`, audit 53) → train (`09:39:27`, audit 54, generation `…f5d37e23…`, state `5af7ac11…`) → predict (`09:39:30`, audits 55/56/57, forecast id 2 re-issued at identical `177,137.16`, `reports` id 11 created) → manual DSS generate (`09:39:34`, audit 58, `dss_results` id 2 refreshed). Report id 11 was confirmed as the first live report storing `latest_forecast.issuance_label` ("Target: 2026-09. Produced: 2026-10-22 09:39:30 UTC. Produced after target month; not issued in advance."); reports 9/10 remain label-free in storage but render correctly because the blade recomputes from the saved snapshot. **Zero live-channel ML-refusal warnings** exist in `laravel.log` (all such entries are `testing.*` from isolated runs), confirming the 09:39 predict exercised the Priority 3.4e canonical binding live; §3.2a item 29 was updated from "exposure" to "resolved as an environmental fact", with D2 still open. The deleted `public/build` manifest was rebuilt with a **container-side** `npm run build` (`10:46` UTC) after the host `npm run build` was found to fail with a rolldown-vite config-bundle `TypeError`; the suite was then verified not to delete the manifest, and the full suite reproduced the recorded result. A pre/post snapshot of 25 hashes (23 protected files + the new generation pair) and of `git status` was byte-identical, and the development database was unchanged (audit max id 58) across all verification runs. Three benign `local.ERROR mkdir(): File exists` entries at `09:35:02` were traced to console `artisan wayfinder:generate` (recurring pattern, gitignored outputs, no live-state effect).

**Files created or modified.**

- `CURRENT.md` — header; §1 saved-records/verification bullets; new §1.7 (criteria table, verified external-run timeline, report-snapshot evidence, 3.4e live-exercise confirmation, environment events, preservation); §3.2a item 29 resolution; §4 4b re-verification paragraph; §5/§6/§7 status and designated next task; §8 updated snapshots and two new environment bullets.
- `IMPLEMENTATION.md` — this entry.
- `AGENTS.md` — status-paragraph clause and environment note for the host `npm run build` limitation.
- `public/build/**` — regenerated gitignored Vite build output (container-side `npm run build`); no tracked file changed.

**ML algorithms or forecasting logic affected.** None. No application source, test, migration, Python file, saved ML artifact, forecast, DSS row or report was modified. All database access was `SELECT`-only; the verification suites used SQLite `:memory:` and fake storage. The regenerated `public/build` is a gitignored frontend bundle. D1a, D1b, D2, D3 and D5 remain open; the designated next task is decision capture (CURRENT.md §7).

**Tests executed and their actual results (2026-10-22).**

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 vendor/bin/pint --test app/Support/ForecastIssuance.php app/Models/ForecastResult.php tests/Feature/ForecastIssuanceTest.php
```
```text
PASS   ........................................................... 3 files
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/DashboardTest.php --colors=never
```
```text
Tests:    35 passed (301 assertions)
Duration: 7.61s
```

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 164 passed (2271 assertions)
Duration: 21.80s
```
(An earlier attempt during the external `public/build` deletion reported `4 failed, 5 skipped, 160 passed` with `ViteManifestNotFoundException` in the four Blade-auth screen tests; after the container-side rebuild the counts above reproduced exactly. The five skips remain the expected disabled-Fortify password-reset cases.)

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 57 tests in 4.393s

OK
```

```sh
npm run types:check
```
```text
> types:check
> tsc --noEmit
```
Exit code 0.

```sh
docker exec -w /var/www/html capstone-sample-laravel.test-1 npm run build
```
```text
✓ built in 2m 3s
```
(`public/build/manifest.json` created `2026-10-22 10:46` UTC; host `npm run build` fails with `TypeError: The "paths[1]" argument must be of type string` in vite/rolldown's config bundler — host-platform limitation.)

**Preservation.** Before/after snapshot: all 25 hashes identical (export `f0819b2d…`, state `5af7ac11…`, lock `e3b0c442…`, three generation pairs `fd929ddc…`/`b7d4b763…`, six legacy flat artifacts, seven Python sources, `MlWorkflow`/`ForecastPeriod`/`EnergyDss`); `ml/.staging/` empty; `git status` identical; `audit_trails` 58 rows before and after (max id 58, last `2026-10-22 09:39:35`); forecast rows unchanged.

**Remaining limitations or follow-up tasks.** Priority 4b is now **independently re-verified**; all five criteria are confirmed on live data, including the report-snapshot path. The designated next task is **decision capture (D1a, D1b, D5)** — no code task is authorized until those answers are recorded; Priority 5b follows D1b/D5 and Priority 3.4b follows D1a. The externally deleted/rebuilt `public/build` and the host `npm run build` failure are recorded as environment limitations (CURRENT.md §8, AGENTS.md environment notes). The wayfinder `mkdir(): File exists` errors are a recurring benign console pattern. No open decision was resolved; the 09:39 run remains an environmental fact, not a D2 authorization.

---

## 2026-10-23 — Priority 5b: algorithm-label source correction only

**Problem identified.** The existing standardized, fixed-0.1-penalty fit was named “Linear Regression” in trainer output, missing-label fallbacks, static UI/landing copy and future-report explanation. D1b/D5 approve factual label corrections only; D1a defers replacement and D2/D3 remain open.

**Root cause (confirmed).** The trainer adds 0.1 to each non-intercept diagonal term but emits the unqualified ordinary-regression name. Fallbacks and static copy repeat it. No mathematical, forecast-period or model-selection change is needed for accurate naming.

**Solution implemented.** Changed exactly the literal algorithm name to `Ridge (penalty 0.1)` in 14 production/copy files. A source audit against pre-task copies confirms each file equals its original after only that literal substitution. No formula, coefficient computation, scaling, feature, split, penalty, metric, protocol, horizon, validation branch, UI markup/style, navigation or control changed. The offline ordinary-OLS candidate remains correctly named `Linear Regression`. Saved model/metrics metadata, forecast rows and dated report snapshots retain their original labels; metadata read from them takes precedence over new fallbacks.

Added four Python regressions: pre-change synthetic fit predictions/holdout/CV numeric references; temporary trainer-model/metrics/prediction linkage; truthful prediction fallback with unchanged values/period/files; and preservation of legacy model/metrics labels and bytes. Added five Laravel cases: new report copy while preserving both new/legacy stored labels and old reports (two cases); no-forecast report fallback; PHP prediction fallback with identical numeric output and published bytes; and rejection of the prior source identity after a label edit without read-time migration or repair.

**Files created or modified.**

- `Python/train_model.py` — corrected emitted model label only.
- `Python/predict_next_month.py` — corrected missing-label fallback only.
- `app/Support/MlWorkflow.php` — corrected forecast-label fallback only.
- `app/Support/ReportGenerator.php` — corrected label fallback and static future-report explanation only.
- `resources/js/pages/Admin/Forecasting.tsx` — corrected training/algorithm text.
- `resources/js/pages/User/ForecastResults.tsx` — corrected algorithm explanation text.
- `resources/js/pages/User/Dashboard.tsx` — corrected algorithm explanation text.
- `resources/js/pages/welcome.tsx` — corrected landing metadata copy.
- `resources/js/components/landing/about-section.tsx` — corrected algorithm name.
- `resources/js/components/landing/capabilities-section.tsx` — corrected algorithm name.
- `resources/js/components/landing/hero-section.tsx` — corrected algorithm name.
- `resources/js/components/landing/methodology-section.tsx` — corrected algorithm name.
- `resources/js/components/landing/workflow-section.tsx` — corrected algorithm name.
- `resources/js/components/landing/landing-data.ts` — corrected illustrative model-name field; illustrative values unchanged.
- `Python/tests/test_algorithm_labels.py` — new synthetic numerical/linkage regressions.
- `Python/tests/test_predict_next_month.py` — fallback/legacy-preservation regressions.
- `tests/Feature/AlgorithmLabelsTest.php` — new isolated report/legacy-record regressions.
- `tests/Feature/MlArtifactsTest.php` — fallback and label-source identity invalidation regressions.
- `AGENTS.md` — source-only label contract, completed status and testing requirement.
- `CURRENT.md` — five verified criteria, test results, unchanged artifacts and open activation boundary.
- `IMPLEMENTATION.md` — this record.

**ML algorithms or forecasting logic affected.** Label metadata only; no model selection or mathematical change. Before editing, the prior trainer was run in memory on explicitly synthetic rows inside a read-only, network-isolated temporary Docker container to capture numerical references; no beneficiary evaluation or artifact publication occurred. The new regressions reproduce those references, including negative R² and the existing CV semantics. No live training, forecasting, accessor workflow, production-database query/mutation, schema change, artifact migration or prior-record relabeling occurred. D1a still defers model replacement; D2/D3 remain open.

**Tests executed and actual results.** The original application and MySQL containers were stopped. The first attempted `docker exec` reported `container … is not running`; no workflow ran. Verification instead used a dedicated container from the existing ML image, with no network, no web/scheduler services and a read-only mount over the private dataset/artifact tree:

```sh
docker run -d --name capstone-5b-verification --network none --entrypoint /bin/sh --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction,dst=/var/www/html --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction/storage/app/private,dst=/var/www/html/storage/app/private,readonly -w /var/www/html -e PYTHONDONTWRITEBYTECODE=1 capstone-sample/ml-app -c 'exec sleep infinity'
```

```sh
docker exec -w /var/www/html capstone-5b-verification vendor/bin/pint tests/Feature/AlgorithmLabelsTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 2 files
```

```sh
docker exec -w /var/www/html capstone-5b-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/AlgorithmLabelsTest.php tests/Feature/MlArtifactsTest.php tests/Feature/ForecastPeriodTest.php --colors=never
```
```text
Tests:    103 passed (1993 assertions)
Duration: 8.78s
```

```sh
docker exec -w /var/www/html capstone-5b-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 169 passed (2340 assertions)
Duration: 10.45s
```
The five skips remain the expected disabled-Fortify password-reset cases and are not passes. All Laravel tests used SQLite `:memory:`; workflow tests used fake storage/stubbed processes or their existing synthetic Python integrations.

```sh
docker exec -w /var/www/html capstone-5b-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 61 tests in 1.672s

OK
```

```sh
docker exec -w /var/www/html capstone-5b-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No frontend bundle/public assets were rebuilt or overwritten.

The exact before/after artifact set and all SHA256 values match for **25 saved files**: 17 files in private ML storage and eight legacy Python artifact/JSON files. The pre-existing state is `2c09acfc9cb40c572bc351aaab91d78b41fac428ebc10b47e2f40c97d14cea2a`, with generation `…_670431f5-e854-4c62-828b-b454ba1e546a/`, trained `2026-10-22 11:00:33` UTC, cutoff `2026-08` and stored label `Linear Regression`. Four private generations are present. This publication predates the task and supersedes the previous documented state snapshot; it was not created or modified by this implementation. No current database-state claim is made because no production database was queried.

Final `git diff --no-index --check /dev/null <path>` checks passed for all 21 scoped files, including untracked tests/docs. The final production/copy audit confirmed exactly 14 literal name substitutions, and the complete saved-artifact set/hash comparison again passed for all 25 files. The dedicated verification container was removed with `docker rm -f capstone-5b-verification`; the original application and MySQL containers were not started.

**Remaining limitations or follow-up tasks.** Priority 5b is **Implemented, Tested, Verified for label source and isolated tests only**. Trainer SHA256 changed from `10d51ee09fb2aaab48e9dd1d96b5cdf06fb149d9ca2d10b7189a4138a12003ae` to `f0edd6295106cd6baa5451344ed4e47bb6a3ab65d00f21f9d18bdba2ab952597`. With the documented interpreter/protocol configuration, the derived trainer signature becomes `fac469b89025dcda2cb8f6233e768c329390fc28d1158af78e9a44227357901a`, while the unchanged saved state retains `ff08de17…`. Therefore historical validation refuses the stale identity, and an authorized forecasting workflow would request repair through existing logic. No signature pinning, bypass, forced regeneration or silent legacy-label rewrite was added. **D2 must separately authorize live regeneration/publication/activation**; it is still open. D3 remains open, replacement remains deferred under D1a, and metric/protocol/accuracy/DSS work is outside this task. No new task or live workflow is implicitly authorized by this completion.

---

## 2026-10-23 — Independent re-verification of Priority 5b and recording of the live artifact state (documentation only)

**Problem identified.** The Priority 5b entry above was self-verified by its implementer and recorded a saved state (`2c09acfc…` / `721b01ad…` / `ff08de17…` / `…_670431f5…`) that was accurate at task start. Before relying on that record, the label correction, the source/signature consequence, the preserved artifacts and the then-current live state needed independent re-checking.

**Root cause.** None — this was a verification task, not a defect fix. One factual discrepancy was found: between the 5b implementation and this re-verification, a **user-initiated application run at `2026-10-22 18:55 UTC`** retrained under the corrected trainer and published a new generation. The previously documented state was therefore stale.

**Solution implemented.** No application code, test, saved artifact, forecast, DSS row, report or database record was changed. Documentation now records the independently reproduced evidence, the current live artifact state and the environmental publication, and designates **Priority 3.4b-i** as the next dependency-unblocked task with explicit scope and acceptance criteria. The source labels were re-read rather than re-edited.

**Files created or modified.**
- `CURRENT.md` — added §1.8; refreshed the header, active-artifact and saved-forecast facts; updated §3.2 item 18, §3.2a item 27, §4, §5, §6, §7 and §8.
- `IMPLEMENTATION.md` — this entry.
- `AGENTS.md` — one factual status-paragraph update in §1 (no guideline change).

**ML algorithms or forecasting logic affected.** None. The verification confirmed that `Python/train_model.py:134` sets the fixed penalty `0.1`, `:149` adds it only to the non-intercept diagonal, and `:157` emits `Ridge (penalty 0.1)`; the offline OLS candidate remains `Linear Regression` (`Python/model_candidates.py:18`). No fitting, prediction, split, penalty, protocol, horizon, label-source or DSS rule was changed by this verification.

**Tests executed and actual results.** The earlier implementation's five commands were re-run and reproduced:

```text
Python:  Ran 61 tests in 0.890s — OK
Focused: Tests: 103 passed (1993 assertions) — Duration: 10.12s
Full:    Tests: 5 skipped, 169 passed (2340 assertions) — Duration: 24.06s
Pint:    PASS ... 2 files
tsc:     npm run types:check → tsc --noEmit → exit 0
```

The five skips remain the expected disabled-Fortify password-reset cases. The identity-invalidation case temporarily rewrites `Python/train_model.py`; it was restored to SHA256 `f0edd629…` after the run. The live environment was then inspected read-only:

- `model_state.json` sha256 `84639e4d5f31a3a42f1042c3ed0affb0a57d09d19db74ce020b939ab3d780099`, version 2
- `dataset_hash f0819b2d…`, `feature_signature f4cbf5e6…`, `trainer_signature fac469b8…`, cutoff `2026-08`, protocol `chronological_holdout_25pct_rolling_cv_v1`
- `artifact_id ec25ca02c5e008ad8f44afd7715b65ae02c35def83d0ea12c719f88d906c8d1d`
- pointer `ml/models/ec25ca02…_ea5116cc-4764-45f1-ae6d-ee31dbb48c6c/model.pkl` (`fa784e16…`) and `metrics.json` (`97429ca3…`)
- export `f0819b2d…` unchanged; `.staging/` empty; `workflow.lock` zero bytes
- five generations across two `artifact_id`s: four old `721b01ad…` pairs (`fd929ddc…` / `b7d4b763…`) and the current `ec25ca02…` pair
- six legacy flat artifacts unchanged
- `MlWorkflow::withValidatedArtifact()` returns the current generation

The old `…_670431f5…` and new `…_ea5116cc…` models have identical `coefficients`, `means`, `stds` and `features`; the metrics differ only in `model_type` (serialized size +2 bytes). Database re-query (read-only): `2026-09 = 177,137.16` kWh, `Ridge (penalty 0.1)`, `predicted_at 2026-10-22 18:55:32`; `dss_results` id 2 refreshed at `18:55:32`; `reports` 1–13 (13 corrected; 9–12 preserve `Linear Regression`); `audit_trails` 67 rows (63–67 from the environmental run); stale `2026-11 = 202,870.53` kWh and DSS id 1 untouched (D3). No agent database write occurred.

**Remaining limitations or follow-up tasks.** The environmental `18:55 UTC` run is **not** a recorded D2 authorization; D2 and D3 remain open. The re-verification establishes computational/integration correctness and label accuracy, not prospective forecast accuracy. Historical reports 9–12 and the D3 row retain their original labels and must not be rewritten retroactively. **Priority 3.4b-i** is designated but not authorized: it needs explicit scope approval, and any trainer edit it makes will change `trainer_signature`, so live activation/regeneration remains gated by D2.

---

## 2026-10-23 — Priority 3.4b-i: protocol-independent metric semantics

**Problem identified.** The active trainer emitted numeric zero for undefined singleton/constant-target R² and averaged those values into a CV summary without an explicit fold-average label. Report generation and rendering substituted zero for missing evaluation metrics. Existing consumer explanations did not disclose undefined R², negative values or fold-average scope.

**Root cause.** Confirmed: `train_model.evaluate()` used zero when target variance was zero; `average_metric()` averaged every fold's value. `ReportGenerator` and Blade casts/defaults converted missing MAE/RMSE/R² to zero. The offline evaluator already had the required null/negative arithmetic, but production did not share it.

**Solution implemented.** The explicit current user request authorizes this source/test increment and supersedes the earlier scheduling-only designation. The already-tested offline arithmetic is centralized in `train_model.regression_metrics()` and reused by the offline evaluator and active holdout/fold evaluator. Empty metrics are null; R² is null for fewer than two observations or zero variance; legitimate zero and negative scores stay numeric. Existing splits, scoring observations and compatibility keys remain. CV adds its explicit unweighted-defined-fold-average aggregation/label and contributing counts; all-undefined R² stays null. No pooled score is substituted for a fold average or holdout. Logs display “Unavailable”.

The canonical `resources/forecast-metrics.json` definitions describe units, formulas, undefined/negative values, scope, fold averages and legacy limitations. Temporary trainer output includes definitions and holdout scope; prediction JSON passes saved metadata through without upgrading legacy artifacts. PHP exposes definitions read-only through existing forecast serialization and the existing Forecasting response; new report snapshots store them. The existing dialogs, cards, history and report cells distinguish null/missing from legitimate zero. Blade reads only saved report values and retains original explanations/recommendations. Definitions explicitly do not retroactively validate legacy singleton zeros. Missing R² gets an unavailable interpretation; existing numeric interpretation/DSS rules are retained.

The definitions SHA256 joins the existing trainer-signature payload. This is necessary so later definition changes cannot silently reuse an artifact identity. State shape, protocol identifier, canonical-generation binding, checksums, atomic publication and null-tolerant metric validation are unchanged. No signature pin, bypass, repair, activation or migration was introduced.

**Files created or modified.**
- `Python/train_model.py`
- `Python/evaluation.py`
- `Python/predict_next_month.py`
- `Python/tests/test_metric_semantics.py`
- `Python/tests/test_algorithm_labels.py`
- `app/Support/ForecastMetrics.php`
- `app/Support/MlWorkflow.php`
- `app/Support/ReportGenerator.php`
- `app/Models/ForecastResult.php`
- `app/Http/Controllers/Admin/ForecastingController.php`
- `resources/forecast-metrics.json`
- `resources/js/pages/Admin/Forecasting.tsx`
- `resources/js/types/dss.ts`
- `resources/views/reports/energy.blade.php`
- `tests/Feature/ForecastMetricsTest.php`
- `tests/Feature/MlArtifactsTest.php`
- `tests/frontend/forecast-metrics.test.cjs`
- `AGENTS.md` — shared metric/identity contract and test requirements.
- `CURRENT.md` — verified criteria, source-versus-saved identity, roadmap and limits.
- `IMPLEMENTATION.md` — this record.

**ML algorithms or forecasting logic affected.** Evaluation representation/arithmetic is shared; undefined/fold-average R² semantics change deliberately. Forecast fitting/prediction functions, standardization, coefficients, 12 features, fixed penalty 0.1, chronological splits, holdout/CV protocol, full-data refit, model selection, algorithm labels, actual-based monthly horizon and DSS rules are preserved. Shared scoring uses the offline evaluator's existing `math.fsum` implementation; four-decimal synthetic holdout/MAE/RMSE benchmarks and offline comparison/policy results remain verified. No beneficiary evaluation or predictive-accuracy improvement is claimed. The prior label regression now expects null singleton fold R² and the mean of its one defined fold; it still asserts the same numeric holdout, each MAE/RMSE, row counts and predictions.

Six new Python cases cover singleton/constant/empty sets, independently calculated scores, negative/valid-zero R², mixed/all-undefined fold means/counts, frozen synthetic coefficients/features/splits and temporary null-holdout/prediction passthrough. The artifact suite has 100 cases (85 previously, 14 new cases, one additional existing invalidation parameter). Thirteen new Laravel consumer cases cover null/zero/negative APIs/snapshots/rendering, invalid/missing displays, immutable legacy/D3 fixtures, read-only definitions and unchanged DSS output. Five frontend cases render the actual Forecasting and StatCard components with isolated Inertia/UI adapters and inspect the existing R² dialog callback. No new dependency or UI control was added.

**Tests executed and their actual results.** The original application and MySQL containers were already running; they were left untouched. No web route, scheduler, training command or forecasting workflow was invoked against the live environment. Verification used a dedicated container with no network and a read-only mount over private data/artifacts:

```sh
docker run -d --name capstone-34bi-verification --network none --entrypoint /bin/sh --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction,dst=/var/www/html --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction/storage/app/private,dst=/var/www/html/storage/app/private,readonly -w /var/www/html -e PYTHONDONTWRITEBYTECODE=1 capstone-sample/ml-app -c 'exec sleep infinity'
```

Laravel used SQLite `:memory:`, fake storage and synthetic records. Python fits/publication tests used only memory or explicit temporary/fake outputs. The initial focused run found a test-fixture assumption that model identity could be inferred from numeric R² (`synthetic-model-0` expected for null). It reported `1 failed, 106 passed (2058 assertions)`; the fixture now verifies the actual model SHA256 and copied metrics bytes for the null case. No production validation was weakened. Final verification:

```sh
docker exec -w /var/www/html capstone-34bi-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/ForecastMetricsTest.php tests/Feature/MlArtifactsTest.php tests/Feature/AlgorithmLabelsTest.php tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/MonthlyFeaturesTest.php tests/Feature/DashboardTest.php --colors=never
```
```text
Tests:    157 passed (2467 assertions)
Duration: 13.20s
```

```sh
docker exec -w /var/www/html capstone-34bi-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 197 passed (2626 assertions)
Duration: 19.90s
```
The five skips remain the expected disabled-Fortify password-reset tests and are not passes. The full final run includes the final unavailable-log wording.

```sh
docker exec -w /var/www/html capstone-34bi-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 67 tests in 2.733s

OK
```

```sh
docker exec -w /var/www/html capstone-34bi-verification node --test tests/frontend/forecast-metrics.test.cjs
```
```text
✔ Forecasting renders null metrics without zero substitution
✔ Forecasting renders undefined metrics without zero substitution
✔ Forecasting renders 0 metrics without zero substitution
✔ Forecasting renders -2 metrics without zero substitution
✔ Forecasting without any saved forecast renders unavailable evaluation metrics
ℹ tests 5
ℹ suites 0
ℹ pass 5
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 789.690667
```

```sh
docker exec -w /var/www/html capstone-34bi-verification vendor/bin/pint --test app/Support/ForecastMetrics.php app/Support/ReportGenerator.php app/Support/MlWorkflow.php app/Models/ForecastResult.php app/Http/Controllers/Admin/ForecastingController.php tests/Feature/ForecastMetricsTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 7 files
```

```sh
docker exec -w /var/www/html capstone-34bi-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No frontend bundle was built or overwritten.

Post-verification preservation check at `2026-10-22 19:24:12 UTC`, over the complete private tree plus top-level legacy Python `.pkl`/`.json` files:
```text
Protected files: 24 -> 24
Added: []
Removed: []
Changed: []
Saved state SHA256: 84639e4d5f31a3a42f1042c3ed0affb0a57d09d19db74ce020b939ab3d780099
Saved trainer signature: fac469b89025dcda2cb8f6233e768c329390fc28d1158af78e9a44227357901a
```
At that check there were 21 private files and 3 legacy Python artifact/JSON files. The later final comparison detected the concurrent external event below. No development database was queried or written; preservation of live forecast/report/D3 records follows from this verification isolation, not a new database snapshot claim. Synthetic tests separately hash-check unchanged snapshots/artifacts and keep stale-row/DSS/report fixtures intact.

The trainer SHA256 is now `52a8975fc5612c2e40f22a9860c3ee01e64a54ad3819e3a3a73644d8a5b22f4a` (previously `f0edd629…`), and definitions SHA256 is `e3487a52e2ac62b4fd965bd6c8154e826f8330a7e7b2f879f66160a921927587`. For `/opt/ml-venv/bin/python` and unchanged `chronological_holdout_25pct_rolling_cv_v1`, the existing signature algorithm with its added definitions hash derives `cc28057cb90b07bd5eb5e0fe9d80db3178f7767f4e122fa805af735ace9b7be5`; this was independently reproduced using PHP `json_encode`/SHA256 without booting the application. No artifact was regenerated to satisfy that identity.

**Concurrent external publication observed during final documentation.** After the passing `19:24:12 UTC` preservation check, the final assertion correctly detected a changed path set/hash: 24 → 26 files. Only `ml/model_state.json` changed; none was removed; all other 23 task-start files retain their SHA256. A new canonical pair appeared under `ml/models/a1409a54953f6c85264879db8b801e8aa03c7bed066ed0e06e046af27a4b4900_f90ab794-bf3b-4f9e-a340-0efad33e5fd9/`. The pointer records `trained_at 2026-10-22 19:26:09 UTC`, cutoff `2026-08`, trainer signature `cc28057c…`; state SHA256 is `c2efd4ade4ea5d9468f188a9d7b1df1087316aeba8a5405b5485243a109f83cf`. Model SHA256 is exactly the original `fa784e1693928f22322e933dd9bcce14b9862157c75e74ca336278b404c304b8`; metrics SHA256 is `ebc2436a452f1714f5a5ca2649ef040fe763fe9f1aa3d1be70ce2351d3fa0420`. Both pair checksums match state, and the metadata definitions match the canonical JSON. The export is still `f0819b2d…`; metrics still describe 17 actual / 15 eligible / 11 train / 4 test rows with holdout periods `2026-05` → `2026-08`. Holdout MAE `145518.0747`, RMSE `152088.6912`, R² `-94.426` are unchanged; CV R² is explicitly the sole defined fold's `-22.8517` with count 1/5, while its four singleton fold scores are null. This is observed saved metadata, not a new agent evaluation or accuracy claim.

No agent command invoked that publication: the dedicated test container had no network and private storage was read-only; original application/MySQL services were not operated. The external initiator was not investigated, and no development database was queried to infer new forecast/report records. The new pointer/pair were preserved, not reverted. This environmental fact does not resolve D2/D3 or authorize any further live workflow.

Final checks after recording the external event:
```text
Whitespace checks: PASS for 20 scoped files
Preservation: 23 original files unchanged, none removed; external pointer replacement and two added files documented
No further artifact changes since the recorded external publication (26 files)
Trainer SHA256: 52a8975fc5612c2e40f22a9860c3ee01e64a54ad3819e3a3a73644d8a5b22f4a
```
The dedicated verification container was removed with `docker rm -f capstone-34bi-verification` (output: `capstone-34bi-verification`). The original services were left untouched.

**Remaining limitations or follow-up tasks.** Priority 3.4b-i is **Implemented, Tested, Verified for source and isolated tests**. The task-start generation is retained with its original legacy metrics; it became stale against the new identity and was not repaired by the agent. The later external publication now points to a matching `cc28057c…` generation. Its appearance is recorded, not an agent live acceptance run; further live activation/regeneration still requires separate **D2** authorization through the existing workflow. No repair or bypass is authorized here. D2/D3 remain open, D1a still defers replacement, and 3.4b-ii protocol choice remains deferred. Priority 5a (fitted-versus-issued separation, clipped “Accuracy” semantics and missing consumption/context summaries), Priority 6 numeric reliability/unit rules, beneficiary provenance and additional genuine history remain outside this increment. Existing report numeric suitability wording and saved conclusions are intentionally retained for that separate scope. A next task needs its own scoped request; this completion authorizes no live workflow.

---

## 2026-10-23 — Priority 3.4b-i acceptance recheck after repeated implementation request

**Problem identified.** The repeated request named the same nine acceptance criteria as the completed Priority 3.4b-i implementation. The current working tree needed checking before making further changes, to avoid duplicating completed work or disturbing existing artifacts.

**Root cause.** No new defect confirmed. The implementation and focused regression tests were already present; the previously recorded external publication remains unchanged.

**Solution implemented.** Rechecked all nine criteria against the shared Python scoring, nullable holdout/fold output, explicit fold-average labels/counts, canonical definitions, prediction passthrough, read-only forecast definitions, report/API/React rendering, identity hashing and artifact validation. Existing tests already cover the requested cases, so no application or test code was changed or duplicated. Re-ran the relevant checks in isolation and recorded the actual results and preservation evidence. This is repeat verification, not a second implementation or an independent-review claim.

**Files created or modified.**
- `CURRENT.md` — latest verification, acceptance recheck and completed-task record.
- `IMPLEMENTATION.md` — this entry.

`AGENTS.md` was followed and left unchanged because no project-wide technical requirement changed.

**ML algorithms or forecasting logic affected.** None in this task. All application, Python, frontend and test source bytes remain unchanged. No fitting, prediction, feature, penalty, split, protocol, label, actual-based month selection, UI design or DSS behavior changed. D2/D3 remain open; 3.4b-ii remains deferred.

**Tests executed and their actual results.** Created only a dedicated verification container from the existing image. It had no network, no web/scheduler service, and a read-only private-data/artifact mount. Original services were not started, stopped or used for live workflows:

```sh
docker run -d --name capstone-34bi-recheck --network none --entrypoint /bin/sh --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction,dst=/var/www/html --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction/storage/app/private,dst=/var/www/html/storage/app/private,readonly -w /var/www/html -e PYTHONDONTWRITEBYTECODE=1 capstone-sample/ml-app -c 'exec sleep infinity'
```

Laravel tests used SQLite `:memory:` and synthetic records/fake storage; Python fits and artifact writes were confined to synthetic fixtures and temporary directories. No development database was queried or written.

```sh
docker exec -w /var/www/html capstone-34bi-recheck env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/ForecastMetricsTest.php tests/Feature/MlArtifactsTest.php tests/Feature/AlgorithmLabelsTest.php tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/MonthlyFeaturesTest.php tests/Feature/DashboardTest.php --colors=never
```
```text
Tests:    157 passed (2467 assertions)
Duration: 17.96s
```

```sh
docker exec -w /var/www/html capstone-34bi-recheck env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 197 passed (2626 assertions)
Duration: 11.31s
```
The five skips are the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-34bi-recheck env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 67 tests in 4.082s

OK
```

```sh
docker exec -w /var/www/html capstone-34bi-recheck node --test tests/frontend/forecast-metrics.test.cjs
```
```text
✔ Forecasting renders null metrics without zero substitution (157.313709ms)
✔ Forecasting renders undefined metrics without zero substitution (144.643417ms)
✔ Forecasting renders 0 metrics without zero substitution (85.679291ms)
✔ Forecasting renders -2 metrics without zero substitution (62.6085ms)
✔ Forecasting without any saved forecast renders unavailable evaluation metrics (77.173875ms)
ℹ tests 5
ℹ suites 0
ℹ pass 5
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 2824.081418
```

```sh
docker exec -w /var/www/html capstone-34bi-recheck vendor/bin/pint --test app/Support/ForecastMetrics.php app/Support/ReportGenerator.php app/Support/MlWorkflow.php app/Models/ForecastResult.php app/Http/Controllers/Admin/ForecastingController.php tests/Feature/ForecastMetricsTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 7 files
```

```sh
docker exec -w /var/www/html capstone-34bi-recheck npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No frontend bundle or public assets were rebuilt.

The before/after snapshot included application/Python/frontend/test source, the entire private-data tree and recursive legacy Python `.pkl`/`.json` files. A read-only SHA256/JSON check re-derived the existing trainer signature and verified both pair checksums without loading a live workflow or unpickling a production model:

```text
Changed or removed snapshotted files: []
Derived trainer signature: cc28057cb90b07bd5eb5e0fe9d80db3178f7767f4e122fa805af735ace9b7be5
Matches saved signature: True
Saved pair checksums valid: True
Before/after path sets equal: True
All hashes equal: True
Protected artifact/data files: 31
Total source/artifact hashes: 312
```

The saved state SHA256 remains `c2efd4ade4ea5d9468f188a9d7b1df1087316aeba8a5405b5485243a109f83cf`. The wider 31-file protected set includes recursive legacy Python artifacts; the preceding implementation's 26-file comparison included only top-level legacy Python files. This is a verification-scope difference, not a new publication. No artifact path or byte changed during this repeat task.

Final checks after updating documentation:
```text
Final preservation: PASS, all 312 source/artifact hashes and paths unchanged
Documentation whitespace checks: PASS, 2 files
```
Removed the dedicated container with `docker rm -f capstone-34bi-recheck` (output: `capstone-34bi-recheck`); existing application/database services were left untouched.

**Remaining limitations or follow-up tasks.** Priority 3.4b-i remains **Implemented, Tested, Verified**; no additional implementation gap was found. Software checks do not establish prospective forecast accuracy. This repeat request does not authorize live training/forecasting/publication, D2/D3 resolutions, protocol selection, Priority 5a or unrelated work. Existing dated reports, forecasts and D3 records were not queried or modified. Future source/definition edits must continue to invalidate identity; no pinning or read-time repair is permitted.

## 2026-10-23 — Documentation-only 3.4b-i confirmation, consumer-surface assessment, and Priority 5a designation

**Problem identified.** The project documents had drift after the sequential 3.4b-i and 5b increments: `AGENTS.md` still described the active algorithm as labeled `Linear Regression`, cited 61 Python tests after the suite had grown to 67, and pointed at stale line numbers; `CURRENT.md` still mixed resolved and remaining Analytics/Forecasting/DSS issues without a consolidated evidence record. No task had recorded the next unblocked scope after 3.4b-i, and the existing Priority 5a row was too narrow to cover fitted/issued separation, the clipped “Accuracy” value, and non-metric missing values together.

**Root cause.** Documentation drift across several incremental changes, plus an incompletely scoped Priority 5a designation. Not a code defect.

**Solution implemented.** A documentation-only review re-read `AGENTS.md`, `CURRENT.md`, the 3.4b-i implementation entry, the named regressions, and the current Analytics/Forecasting/DSS call sites. It:
- confirmed Priority 3.4b-i completion against the source and the recorded 2026-10-23 repeat verification;
- recorded the remaining consumer-surface issues with precise call sites in `CURRENT.md` §1.9 (fitted/issued mixing, clipped “Accuracy”, non-metric `0` values, filtered-table/unfiltered-summary ambiguity; Forecasting missing-value coercion; DSS unit/reliability/threshold/provenance/report issues);
- corrected stale label, test-count and line-reference statements in `AGENTS.md` and `CURRENT.md`;
- designated Priority 5a with scope, out-of-scope boundaries and acceptance criteria in `CURRENT.md` §4;
- updated the roadmap, completed-task list, current-status and blocker sections, preserving D1a/D2/D3 and deferring 3.4b-ii.

**Files created or modified.**
- `CURRENT.md`
- `AGENTS.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. No model, feature, penalty, split, evaluation protocol, label, horizon, DSS rule, schema, artifact, forecast or report was changed. The active saved identity was not re-derived or modified by this review.

**Tests executed and their actual results.** **None.** No test suite, Docker container, Python entry point, live training/forecasting workflow, artifact publication or database operation was run for this documentation-only task. The confirmation relies on direct source inspection and the previously recorded 3.4b-i repeat verification in the preceding entry: focused **157 passed / 2467 assertions**, full Laravel **197 passed / 5 expected skips / 2626 assertions**, Python **67 passed**, frontend **5 passed**, Pint **7 files**, TypeScript **exit 0**; all **312** snapshotted source/artifact hashes unchanged, including **31** protected files. This entry does not claim a new execution or an independent re-run.

**Remaining limitations or follow-up tasks.** Priority 5a is **designated but not authorized or started**; its implementation requires a separate scoped request. Priority 6 (DSS) follows 5a and also needs verified demand/peak semantics or a recorded beneficiary threshold basis. D2 still gates every live training/forecasting/publication; D3 still gates removal of the stale `2026-11` forecast and its DSS/report linkage. D1a still defers model replacement, and 3.4b-ii remains deferred. No UI redesign, saved-report rewrite, schema change or beneficiary-data modification is authorized by this review.

---

## 2026-10-23 — Priority 5a: Analytics origins and nullable quantity presentation

**Problem identified.** Both Analytics controllers combined historical production-model fits and saved issued estimates into a single `predicted` field, preferring a forecast over a fit when periods overlapped. The chart called the combined values predictions and displayed a clipped percentage-error complement as “Accuracy”. Empty consumption/context summaries, absent forecasts and missing changes displayed zero. Aggregate queries remained all-time while record/chart filters had different scopes without an explicit explanation.

**Root cause.** Confirmed in the duplicated controller mapping, `Python/predict_history.py` and the existing React views. The historical script also emitted a redundant clipped `accuracy`. Casts and `?? 0` defaults erased missing-versus-zero distinctions; no provenance identified the origin of each chart value.

**Solution implemented.** The current explicit implementation request supersedes the earlier designation-only status. `AnalyticsSeries` shares the validated historical reader, independent row fields/provenance and nullable summaries between Admin and Viewer. Historical reads still use `MlWorkflow::withValidatedArtifact()` and its existing canonical pointer, identity/checksum, lock and refusal safeguards. No validator, repair or training path changed.

The existing response container `actualPredictedTrend` now returns separate `actual`, `fitted` and `issued_forecast` values, including simultaneous fits and issued estimates for one month. Provenance identifies actual record IDs; fitted artifact ID, dataset hash, cutoff and in-sample origin; and saved forecast record ID, saved model label and recorded production timestamp. A saved issued estimate does not invent linkage to the current artifact. Future periods without actuals have null actual/fitted fields. Neither controller emits the removed combined `predicted` or clipped `accuracy` fields.

`predict_history.py` emits `fitted` and `kind: in_sample_fit`, removing the duplicate error complement. Its prediction function, feature use, eligibility and two-decimal fitted numbers are unchanged. Both existing comparison charts keep their containers and styles, with separate named actual/fitted/issued series; the existing second checkbox controls the two blue model-output series together. Existing selectors, cards, tables, navigation and layout remain. Tooltip rows now identify actual, historical fit and issued estimate, with recorded issuance text; necessary copy explicitly disclaims out-of-sample evaluation. No new chart, screen, control, panel or styling was introduced. The extra model-output data series is necessary to avoid conflating two different origins within the existing chart.

All-time aggregate queries are intentionally retained. API metadata `aggregate_scope: all_available_actuals` and both Analytics headers explain that summary/yearly/seasonal/peak figures use all available actuals, while month/year selections filter the comparison chart. Existing admin record/monthlyTrend response filters remain unchanged. Missing summary/context values stay null; existing Analytics/Forecasting/ForecastResults cards, tooltips and history cells show “Unavailable”, preserving genuine zero. A shared `displayValue()` prevents missing quantities and percentages from becoming zero or malformed units. Forecast types describe nullability; the two Dashboard formatter changes are parameter type annotations only, with no runtime/UI change. Stored zero measurements are not retroactively reclassified as missing.

**Files created or modified.**
- `app/Support/AnalyticsSeries.php`
- `app/Http/Controllers/Admin/AnalyticsController.php`
- `app/Http/Controllers/User/ViewerController.php`
- `Python/predict_history.py`
- `resources/js/lib/display-value.ts`
- `resources/js/types/dss.ts`
- `resources/js/pages/Admin/Analytics.tsx`
- `resources/js/pages/User/Analytics.tsx`
- `resources/js/pages/Admin/Forecasting.tsx`
- `resources/js/pages/User/ForecastResults.tsx`
- `resources/js/pages/Admin/Dashboard.tsx` — nullable formatter parameter annotation only.
- `resources/js/pages/User/Dashboard.tsx` — nullable formatter parameter annotation only.
- `tests/Feature/AnalyticsOriginsTest.php`
- `tests/Feature/MlArtifactsTest.php`
- `Python/tests/test_predict_history.py`
- `tests/frontend/analytics-origins.test.cjs`
- `tests/frontend/forecast-metrics.test.cjs` — resolve the shared display helper in the existing isolated adapter.
- `AGENTS.md` — Analytics origin/aggregate/presentation contract and regression requirements.
- `CURRENT.md` — verified completion, results and remaining boundaries.
- `IMPLEMENTATION.md` — this entry.

**ML algorithms or forecasting logic affected.** None. Training, next-month prediction, shared features, fixed penalty, splits, evaluation protocol, algorithm labels, artifact identity/validation/publication, actual-based horizon, DSS and report-generation sources are unchanged. The historical `predict_row()` function is byte-identical; only output metadata/field naming and the removed error complement changed. This increment does not change `trainer_signature` and requires no artifact regeneration. No forecast, report, DSS or actual record was rewritten; no live workflow was invoked. No new beneficiary evaluation or accuracy improvement is claimed.

**Tests executed and their actual results.** A dedicated container used the existing ML image with no network, no web/scheduler service, and private data/artifacts mounted read-only. Original application/database services were left untouched:

```sh
docker run -d --name capstone-5a-verification --network none --entrypoint /bin/sh --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction,dst=/var/www/html --mount type=bind,src=/Users/renantebarcoma/Documents/clone-capstone-system/capstone-dss-prediction/storage/app/private,dst=/var/www/html/storage/app/private,readonly -w /var/www/html -e PYTHONDONTWRITEBYTECODE=1 capstone-sample/ml-app -c 'exec sleep infinity'
```

Laravel used SQLite `:memory:`, synthetic records, fake storage and stubbed processes. Python fixtures used explicit temporary CSV/model paths. No development database was queried or written. Three new Laravel cases cover both-role empty/zero/provenance responses and all-time scope with empty record filters; one new artifact integration case covers overlapping origins, future zero estimates without actuals, saved label/timestamp provenance, D3 exclusion and immutable dataset/forecast/DSS/report/artifact snapshots. Existing artifact regressions now assert `fitted` and absence of `accuracy`. Three Python cases cover unchanged fitted numbers, zero/negative fits, calendar gaps and read-only temporary files. Eight new frontend cases cover both Analytics/forecast views, origin series/tooltip labels, absence of Accuracy, null/zero, no saved forecast, filtering and unchanged card/chart/control counts; five existing metric cases still pass.

The first TypeScript run found two `TS2345` errors where the newly nullable forecast type reached Dashboard formatter parameters. Their parameter annotations now accept null; bodies/UI behavior are unchanged. The initial frontend adapter rendered SVG definitions outside an SVG namespace and emitted React warnings despite passing; the test-only chart adapter now supplies the SVG container. No production workaround was added. Final results:

```sh
docker exec -w /var/www/html capstone-5a-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/AnalyticsOriginsTest.php tests/Feature/MlArtifactsTest.php tests/Feature/ForecastMetricsTest.php tests/Feature/AlgorithmLabelsTest.php tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/MonthlyFeaturesTest.php tests/Feature/DashboardTest.php --colors=never
```
```text
Tests:    161 passed (2706 assertions)
Duration: 8.06s
```

```sh
docker exec -w /var/www/html capstone-5a-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 201 passed (2865 assertions)
Duration: 11.30s
```
The five skips remain the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-5a-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 70 tests in 1.051s

OK
```

```sh
docker exec -w /var/www/html capstone-5a-verification node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs
```
```text
ℹ tests 13
ℹ suites 0
ℹ pass 13
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 974.556
```

```sh
docker exec -w /var/www/html capstone-5a-verification vendor/bin/pint --test app/Support/AnalyticsSeries.php app/Http/Controllers/Admin/AnalyticsController.php app/Http/Controllers/User/ViewerController.php tests/Feature/AnalyticsOriginsTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 5 files
```

```sh
docker exec -w /var/www/html capstone-5a-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No frontend bundle/public assets were built or overwritten. SQLite verification does not establish new MySQL-specific behavior; the aggregate SQL itself remains the existing portable query pattern.

Before/after SHA256 checks and focused source/attribute comparisons:
```text
Protected forecasting/DSS/report/identity source hashes unchanged: PASS (8 files)
Historical fitted prediction function byte-identical: PASS
Saved dataset/artifact path set and SHA256 unchanged: PASS (31 files)
Existing className attributes preserved: PASS (6 views)
```

The protected source set is `Python/train_model.py`, `Python/predict_next_month.py`, `Python/monthly_features.py`, `app/Support/MlWorkflow.php`, `app/Support/ForecastPeriod.php`, `app/Support/EnergyDss.php`, `app/Support/ReportGenerator.php` and `resources/forecast-metrics.json`. Preserved artifacts/data include the complete private tree and recursive legacy Python `.pkl`/`.json` files. The current saved state remains the previously observed publication; no state pointer or artifact pair was repaired or published by this increment. Synthetic read tests separately verify immutable record snapshots, including stale D3/DSS/report fixtures; no production database snapshot claim is made.

Final scoped review and preservation check:
```text
Scoped changes: PASS, 17 planned source/test files; no unrelated source changed or removed
Protected dataset/artifact path set and SHA256: PASS, 31 files unchanged
Whitespace checks: PASS, all 20 scoped source/test/documentation files
```
Removed the dedicated container with `docker rm -f capstone-5a-verification` (output: `capstone-5a-verification`). Existing application/database services were not operated.

**Remaining limitations or follow-up tasks.** Priority 5a is **Implemented, Tested, Verified** within its eight criteria. These checks establish software behavior, not prospective predictive accuracy. Historical issued rows still lack a stored artifact/dataset-generation link; their provenance exposes only recorded facts and is not retroactively assigned to today's artifact. Broader cross-experiment provenance remains pending. All-time summaries intentionally do not follow comparison selections, and stored zeros cannot be distinguished from previously imputed zeros without separate source/missingness work. Dashboard runtime missing-value policy, saved report conclusions and DSS rules remain unchanged. Priority 6 requires verified demand/threshold basis and its own scoped request. D1a still defers replacement, D2/D3 stay open, and 3.4b-ii remains deferred. No live training/forecasting/publication, stale-row deletion, report rewrite, schema change or unrelated task was performed.

## 2026-10-23 — Independent Priority 5a verification and Priority 5c designation

**Problem identified.** Priority 5a had been implementation-verified, but its acceptance evidence had not been independently reproduced, and `AGENTS.md`/`CURRENT.md` still carried stale 5a wording and pre-change call sites. The next unblocked increment after 5a was not designated.

**Root cause.** Documentation drift and an incomplete next-task designation. No 5a acceptance failure was found; this was not a code defect.

**Solution implemented.** An independent review re-read `app/Support/AnalyticsSeries.php`, both Analytics controllers, `Python/predict_history.py`, the nullable display helper, the Admin/User Analytics and Forecasting/ForecastResults views, and the named regressions. It then re-ran the recorded verification commands in a dedicated network-isolated container with private storage mounted read-only. Laravel used SQLite `:memory:`, synthetic records, fake storage and stubbed processes. The review updated the project documents to record the independent result, corrected stale 5a status/line references and the Python test count, and designated **Priority 5c — issued-forecast dataset/artifact provenance** as the next unblocked increment. No application, test, artifact, forecast, report, DSS or database change was made.

**Files created or modified.**
- `CURRENT.md`
- `AGENTS.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. Training, next-month prediction, shared features, penalty, splitting, evaluation protocol, labels, artifact identity/publication, horizon, DSS and report generation were unchanged.

**Tests executed and their actual results.** A dedicated container named `capstone-5a-reverify` used `capstone-sample/ml-app:latest` with no network and a read-only private-data mount. Original application/database services were not started or used.

```sh
docker exec -w /var/www/html capstone-5a-reverify vendor/bin/pint --test app/Support/AnalyticsSeries.php app/Http/Controllers/Admin/AnalyticsController.php app/Http/Controllers/User/ViewerController.php tests/Feature/AnalyticsOriginsTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 5 files
```

```sh
docker exec -w /var/www/html capstone-5a-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/AnalyticsOriginsTest.php tests/Feature/MlArtifactsTest.php tests/Feature/ForecastMetricsTest.php tests/Feature/AlgorithmLabelsTest.php tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/MonthlyFeaturesTest.php tests/Feature/DashboardTest.php --colors=never
```
```text
Tests:    161 passed (2706 assertions)
Duration: 12.18s
```

```sh
docker exec -w /var/www/html capstone-5a-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 201 passed (2865 assertions)
Duration: 14.69s
```
The five skips remain the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-5a-reverify env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 70 tests in 0.966s

OK
```

```sh
docker exec -w /var/www/html capstone-5a-reverify node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs
```
```text
tests 13
pass 13
fail 0
```

```sh
docker exec -w /var/www/html capstone-5a-reverify npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0.

Preservation check: all 23 files under `storage/app/private`, 5 legacy Python `.pkl` artifacts and the 8 protected production sources (`Python/train_model.py`, `Python/predict_next_month.py`, `Python/monthly_features.py`, `app/Support/MlWorkflow.php`, `app/Support/ForecastPeriod.php`, `app/Support/EnergyDss.php`, `app/Support/ReportGenerator.php`, `resources/forecast-metrics.json`) were byte-identical before/after. `model_state.json` remains `c2efd4ad…` and `processed_dataset.csv` remains `f0819b2d…`; no private path was added or removed. The container was removed with `docker rm -f capstone-5a-reverify` (output: `capstone-5a-reverify`).

**Findings.** All eight 5a acceptance criteria are confirmed. The active Analytics path has independent `actual`/`fitted`/`issued_forecast` fields and no clipped Accuracy; fitted values are served only through the validated accessor; the stale D3 forecast is excluded by `ForecastPeriod::throughNext()`; null values render as unavailable while zero survives; all-time aggregate scope is labelled; and the existing chart/control structure is preserved. No application defect required correction.

**Remaining limitations or follow-up tasks.** Dashboard formatters still coerce missing forecast statistics to zero; that surface is outside 5a and remains open. Saved issued forecasts still lack a stored artifact/dataset-generation link, which is why Analytics deliberately exposes only their recorded facts. **Priority 5c** is designated to persist and expose that provenance without backfilling legacy rows. Priority 6 remains blocked on recorded demand semantics/threshold basis. D1a still defers replacement, D2/D3 remain open, and 3.4b-ii remains deferred. These checks establish software behavior, not prospective forecast accuracy.

## 2026-10-23 — Priority 5c: saved issued-forecast dataset/artifact provenance

**Problem identified.** Issued forecast rows did not retain the artifact ID, dataset hash, training cutoff or evaluation protocol used at issuance. After data replacement or a newer publication, consumers could not establish that linkage from the saved row; consulting the current pointer would invent provenance for older results.

**Root cause.** The workflow consumed a validated publication but persisted only the forecast values, metrics, label and issuance time. `forecast_results` had no columns for these four origin facts. Priority 5a deliberately exposed only facts already recorded rather than guessing linkage.

**Solution implemented.** Added an additive nullable-column migration with no backfill/data update. The existing prediction workflow captures the four fields from the validated publication under its existing lock, alongside the consumed prediction result, then persists that captured snapshot through the unchanged per-period upsert. It never accepts provenance from Python JSON/HTTP fields or rereads a later pointer. Added read-only `artifact_provenance` serialization and Analytics issued-origin metadata. Existing forecast/dashboard responses and new report snapshots inherit the saved facts through model serialization; report rules and saved report JSON are unchanged. `recorded` means all four facts are present; `incomplete` means partial saved facts; `unavailable` covers legacy rows and limited query projections. These statuses describe available metadata, not artifact revalidation or forecast accuracy. No source changes to controllers, UI components, Python, period selection, DSS or report generation were needed.

Nine synthetic regression cases cover migration upgrade/rollback preservation and null legacy fields (1), both-role response reads and recorded/unavailable origins (2), immutable report snapshots (1), partial provenance (1), limited query projections (1), consumed-publication binding despite forged Python metadata/pointer change (1), replacement/new-publication preservation (1), and ignored forged HTTP provenance (1). The complete artifact suite now has 104 cases. Updated the three project documents with verified results and the separate deployment prerequisite.

**Files created or modified.**

- `database/migrations/2026_10_23_000001_add_forecast_artifact_provenance.php`
- `app/Models/ForecastResult.php`
- `app/Support/MlWorkflow.php`
- `app/Support/AnalyticsSeries.php`
- `resources/js/types/dss.ts`
- `tests/Feature/ForecastProvenanceTest.php`
- `tests/Feature/MlArtifactsTest.php`
- `tests/Unit/ForecastProvenanceMigrationTest.php`
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. Training/prediction mathematics, fixed penalty, features, evaluation protocol, metric arithmetic, model selection, labels, dataset-based horizon, canonical generation validation and identity derivation are unchanged. The workflow change only captures and persists issuance metadata. Existing same-period reissuance behavior remains unchanged. All Python, UI component, period-selection, DSS and report-rule sources are byte-identical to task start; unrelated uncommitted work was preserved.

**Tests executed and their actual results.** Tests ran in dedicated `capstone-5c-verification` using `capstone-sample/ml-app`, with private storage mounted read-only. SQLite used `:memory:` and fake/temporary ML storage. MySQL used `mysql:8.4` in `capstone-5c-mysql` on the internal-only `capstone-5c-test` network, no published ports and `/var/lib/mysql` in tmpfs, database `testing` and synthetic credentials. Development application/database services were not operated or queried; no migration was applied there. Workflow processes in the new cases were stubbed, never live training/forecasting.

```sh
docker exec -w /var/www/html capstone-5c-verification vendor/bin/pint --test app/Models/ForecastResult.php app/Support/MlWorkflow.php app/Support/AnalyticsSeries.php database/migrations/2026_10_23_000001_add_forecast_artifact_provenance.php tests/Feature/ForecastProvenanceTest.php tests/Feature/MlArtifactsTest.php tests/Unit/ForecastProvenanceMigrationTest.php
```
```text
PASS   ........................................................... 7 files
```
Pint was also run in formatting mode on these seven files; the final `--test` above passed.

```sh
docker exec -w /var/www/html capstone-5c-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Unit/ForecastProvenanceMigrationTest.php tests/Feature/ForecastProvenanceTest.php tests/Feature/MlArtifactsTest.php --colors=never
```
```text
Tests:    110 passed (2232 assertions)
Duration: 12.42s
```

```sh
docker exec -w /var/www/html capstone-5c-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 210 passed (3094 assertions)
Duration: 13.32s
```
The five skips are the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-5c-verification env DB_CONNECTION=mysql DB_HOST=capstone-5c-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan test --compact tests/Feature/ForecastProvenanceTest.php --colors=never
```
```text
Tests:    5 passed (185 assertions)
Duration: 0.89s
```

```sh
docker exec -w /var/www/html capstone-5c-verification env DB_CONNECTION=mysql DB_HOST=capstone-5c-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php '--filter=issued forecast provenance|replacement dataset and new artifact|forecast endpoint ignores' --colors=never
```
```text
Tests:    3 passed (34 assertions)
Duration: 0.86s
```
MySQL verification covers fresh migration/persistence and the eight new feature cases, not the complete suite. The populated legacy upgrade/rollback case deliberately uses a separate SQLite in-memory connection.

```sh
docker exec -w /var/www/html capstone-5c-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests -v
```
```text
Ran 70 tests in 0.609s

OK
```

```sh
docker exec -w /var/www/html capstone-5c-verification node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs
```
```text
ℹ tests 13
ℹ suites 0
ℹ pass 13
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 717.384959
```

```sh
docker exec -w /var/www/html capstone-5c-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No frontend build or published asset was created/overwritten.

A task-start SHA256/path snapshot was compared after verification, including the complete private tree and recursive legacy Python `.json`/`.pkl` files. A source snapshot across `app`, `Python`, `resources`, `tests` and `database` verified the exact eight intended source/test changes and no others:
```text
Protected data/artifact path set and SHA256: PASS 31 files
Scoped source/test changes: PASS 8 files
Python, period selection, DSS, report rules and UI source preservation: PASS
```
The workflow source diff changes only provenance capture/persistence, not validation or numerical behavior. Synthetic read tests separately snapshot the actuals, forecast/stale-D3 fixture, DSS, report and fake state pointer to verify no read-time mutation. No live database snapshot claim is made because the development database was never accessed.

Cleanup commands and actual output:
```sh
docker rm -f capstone-5c-verification capstone-5c-mysql
```
```text
capstone-5c-verification
capstone-5c-mysql
```
```sh
docker network rm capstone-5c-test
```
```text
capstone-5c-test
```

**Final concurrent-file observation.** After the first passing eight-file source snapshot, the final check observed four generated files refreshed at `2026-10-23 05:02:46–47 UTC`: `resources/js/actions/App/Http/Controllers/Admin/AnalyticsController.ts`, `resources/js/actions/App/Http/Controllers/User/ViewerController.ts`, `resources/js/routes/admin/index.ts` and `resources/js/routes/user/index.ts`. Their complete diffs change only `@see` source-reference line numbers; comparison after normalizing those comments proves runtime code byte-identical. No build/generation command was run by this task, the writer was not established, and these concurrent files were preserved. They do not change the tested runtime behavior. The protected private/artifact snapshot still matches all 31 files.
```text
Concurrent generated route changes: PASS (4 files; only @see source-line comments)
Final changed-source review: PASS (8 intended files plus 4 preserved generated comment refreshes)
Whitespace/conflict checks: PASS (11 scoped files)
```

**Remaining limitations or follow-up tasks.** Priority 5c is **Implemented, Tested, Verified** within its designated acceptance scope. The nullable migration is **not applied to development**; apply it before any separately authorized future issuance. Metadata alone needs no retrain, but D2 still prohibits live workflows without separate authorization. Existing legacy forecasts/report snapshots remain without inferred provenance; new issuance records the actual consumed facts, including when the unchanged upsert reissues a period. The minimal fields identify the artifact/data/cutoff/protocol; they do not store a generation UUID/checksum archive, filter older rows by dataset, prove original beneficiary provenance or improve predictive accuracy. Broad experiment isolation remains Priority 1; Dashboard missing-value presentation and Priority 6 rules remain separate. D1a defers replacement, D2/D3 remain open, and 3.4b-ii remains deferred. No unrelated task or new team decision was implemented.

## 2026-10-23 — Priority 5c independent re-verification (isolated, no application/database/artifact changes)

**Problem identified.** The 5c implementation record needed an independent check of provenance capture, null/partial/legacy behavior, replacement safety, migration up/down, and preservation against live state drift after the task.

**Root cause.** Documentation-only verification requirement (no code changes).

**Solution implemented.** Read-only static review of migration/model/workflow/analytics, then executed all recorded tests in a dedicated container with private storage read-only. Ran disposable MySQL (rollback + re-apply) and verified development migration status read-only. Captured pre/post SHA256/path snapshots of private tree and protected sources. Recorded current live-state drift as environmental, not D2 authorization. Designated Priority 5d.

**Files created or modified.**
- `CURRENT.md` (status, §1.11, §4 5c updates, §5, §6, §7 table/steps, §8 qualification)
- `IMPLEMENTATION.md` (this entry)
- `AGENTS.md` (5c independent re-verification status and artifact-case count alignment as needed)

**ML algorithms or forecasting logic affected.** None. No training/prediction/migration to development performed; only observation and isolated tests.

**Tests executed and their actual results.** All in `capstone-5c-reverify` (dedicated network, read-only private mount). SQLite `:memory:`, fake/temporary ML storage. MySQL 8.4 `capstone-5c-reverify-mysql` tmpfs, internal network, no published ports, DB `testing`. Development services untouched.

```sh
docker exec -w /var/www/html capstone-5c-reverify vendor/bin/pint --test app/Models/ForecastResult.php app/Support/MlWorkflow.php app/Support/AnalyticsSeries.php database/migrations/2026_10_23_000001_add_forecast_artifact_provenance.php tests/Feature/ForecastProvenanceTest.php tests/Feature/MlArtifactsTest.php tests/Unit/ForecastProvenanceMigrationTest.php
```
```text
PASS   ........................................................... 7 files
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Unit/ForecastProvenanceMigrationTest.php tests/Feature/ForecastProvenanceTest.php tests/Feature/MlArtifactsTest.php --colors=never
```
```text
Tests:    110 passed (2232 assertions)
Duration: 15.29s
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 210 passed (3094 assertions)
Duration: 27.09s
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=mysql DB_HOST=capstone-5c-reverify-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan migrate:rollback --step=1 --force; echo "---"; env DB_CONNECTION=mysql DB_HOST=capstone-5c-reverify-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan migrate --force; echo "---"; env DB_CONNECTION=mysql DB_HOST=capstone-5c-reverify-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan migrate:status
```
```text
Rolled back: 2026_10_23_000001_add_forecast_artifact_provenance   (18.83ms)
---
Nothing to migrate.
---
Migration name ............................................................................................... Batch / Status
2026_04_28_000001_create_dss_energy_tables ...................................................................... [1] Ran
...
2026_10_23_000001_add_forecast_artifact_provenance .............................................................. [2] Ran
```
(After rollback batch 2 removed; re-migrate applied batch 2 in 56.18ms as reported during run; status shows Ran in batch 2.)

```sh
docker exec capstone-5c-reverify-mysql mysql -uroot -pcapstone_test_only -e "USE testing; SHOW COLUMNS FROM forecast_results LIKE '%artifact%'; SHOW COLUMNS FROM forecast_results LIKE '%dataset_hash%'; SHOW COLUMNS FROM forecast_results LIKE '%training_cutoff%'; SHOW COLUMNS FROM forecast_results LIKE '%evaluation_protocol%';"
```
```text
Field                Type         Null Key Default Extra
artifact_id          varchar(64)  YES        NULL
dataset_hash         varchar(64)  YES        NULL
training_cutoff      varchar(7)   YES        NULL
evaluation_protocol  varchar(255) YES        NULL
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=mysql DB_HOST=capstone-5c-reverify-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan test --compact tests/Feature/ForecastProvenanceTest.php --colors=never
```
```text
Tests:    5 passed (185 assertions)
Duration: 1.74s
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=mysql DB_HOST=capstone-5c-reverify-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php '--filter=issued forecast provenance|replacement dataset and new artifact|forecast endpoint ignores' --colors=never
```
```text
Tests:    3 passed (34 assertions)
Duration: 1.36s
```

```sh
# Development DB status (read-only)
docker exec -w /var/www/html capstone-sample-laravel.test-1 php artisan migrate:status 2>&1 | grep 2026_10_23_000001
```
```text
2026_10_23_000001_add_forecast_artifact_provenance .............................................................. Pending
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 0.838s
OK
```

```sh
docker exec -w /var/www/html capstone-5c-reverify node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs
```
```text
ℹ tests 13
ℹ pass 13
```

```sh
docker exec -w /var/www/html capstone-5c-reverify npm run types:check; echo "---"
```
```text
exit:0
```

Preservation: pre/post SHA256 of all 27 current private files and 8 protected production sources identical; no additions/removals. Live state drift observed (not by agent): `ml/model_state.json` SHA256 `a66c4e0998e10ffd0a778c78da075531aebc024e1981df06f86e06c05ceb90b8`, pointer `a1409a54953f6c85264879db8b801e8aa03c7bed066ed0e06e046af27a4b4900_f992639c-84b3-48ee-bc15-559d96124e7e`, `trained_at 2026-10-23 05:07:32 UTC`, trainer `cc28057c…`, model `fa784e16…`, metrics `ebc2436a…`, cutoff `2026-08`; generation dirs `…_48370081-1fd3-496a-8b35-6c77db41b6f6` (05:04:21 UTC) and `…_f992639c…` (05:07:32 UTC) added after recorded 5c snapshot. No agent database/artifact writes.

Cleanup:
```sh
docker rm -f capstone-5c-reverify capstone-5c-reverify-mysql; docker network rm capstone-5c-reverify-net 2>&1 | tail -1
```
```text
capstone-5c-reverify
capstone-5c-reverify-mysql
capstone-5c-reverify-net
```

**Remaining limitations or follow-up tasks.** Priority 5c independently re-verified; migration remains Pending on development. No legacy backfill/inference. External state drift documented; D2/D3 open. Priority 5d designated (not started). Priority 6 blocked.

## 2026-10-23 — Priority 5d: Dashboard missing-value presentation

**Problem identified.** Admin and User Dashboards displayed missing latest actual/forecast/change/previous/peak/solar quantities as zero. Empty data could therefore look like measured `0 kWh` or a saved `+0%` change, and missing recent-estimate values inherited the same formatter behavior.

**Root cause.** Both Dashboard controller responses used `round((float) ($value ?? 0), 2)`. Both view formatters independently used `Number(value ?? 0)`, with unit and sign text appended without checking availability. Counts legitimately use zero and must retain that behavior.

**Solution implemented.** Both Dashboard controller paths now reuse `AnalyticsSeries::number()` with the existing two-decimal rounding, returning null for absent/non-numeric/non-finite quantities and preserving real zero. Both views declare nullable quantity fields and reuse `displayValue()` before number localization, units and signed text. Cards, snapshot quantities, saved-estimate history and formatter-backed tooltips display `Unavailable` for missing values; numeric strings, genuine zeros, thousands separators and signed percentages retain their existing formatting. Count defaults remain numeric zero. Existing arrow-selection statements are byte-identical, including the pre-existing zero fallback used only for icon choice; missing change text no longer uses that fallback. Chart preparation, labels, helpers, layouts, classes, info controls, models, workflow and DSS/report rules were not changed.

Added seven isolated Laravel cases covering both roles: empty data, actual/forecast zeros and counts, stale linked D3-like fixtures, available actuals without a current forecast, and nullable/zero/positive/negative change with absent previous consumption. Read snapshots verify no changes to synthetic actual/forecast/DSS/report/dataset/audit rows or fake artifact files, and no process runs. Added ten frontend render cases covering null/undefined/zero, non-finite guards, panels/history/tooltips, numeric strings/localization, signed arrows, card/chart counts and info controls. Updated the project documents with actual verified results and preserved decision boundaries.

**Files created or modified.**

- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/User/ViewerController.php` (Dashboard portion only)
- `resources/js/pages/Admin/Dashboard.tsx`
- `resources/js/pages/User/Dashboard.tsx`
- `tests/Feature/DashboardValuesTest.php`
- `tests/frontend/dashboard-values.test.cjs`
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. Training/prediction mathematics, penalty, features, model selection, protocol/metrics, artifact identity/publication, dataset-based horizon, stored forecast values and provenance, DSS rules and saved report snapshots are unchanged. The only production changes are read-only quantity serialization and display. No model, schema or migration file was edited.

**Tests executed and their actual results.** Dedicated container `capstone-5d-verification` used `capstone-sample/ml-app`, `--network none`, the project workspace mount and a separate read-only private-data mount. Laravel used synthetic SQLite `:memory:`, fake/temporary ML storage and the existing isolated test-schema setup. No migration command was run, no development schema was accessed/changed, and no live training/forecasting/publication workflow ran. Existing application/database services were not operated or queried. MySQL was not used for this display-only increment; SQL queries are unchanged.

```sh
docker exec -w /var/www/html capstone-5d-verification vendor/bin/pint --test app/Http/Controllers/Admin/DashboardController.php app/Http/Controllers/User/ViewerController.php tests/Feature/DashboardValuesTest.php
```
The initial check reported:
```text
FAIL   ............................................ 3 files, 1 style issue
⨯ app/Http/Controllers/Admin/DashboardController.php            concat_space
```
Fixed only that formatting through:
```sh
docker exec -w /var/www/html capstone-5d-verification vendor/bin/pint app/Http/Controllers/Admin/DashboardController.php app/Http/Controllers/User/ViewerController.php tests/Feature/DashboardValuesTest.php
```
```text
FIXED   ..................................... 3 files, 1 style issue fixed
✓ app/Http/Controllers/Admin/DashboardController.php            concat_space
```
The final identical `--test` command passed:
```text
PASS   ........................................................... 3 files
```

```sh
docker exec -w /var/www/html capstone-5d-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DashboardValuesTest.php tests/Feature/DashboardTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastProvenanceTest.php --colors=never
```
```text
Tests:    47 passed (848 assertions)
Duration: 9.11s
```

```sh
docker exec -w /var/www/html capstone-5d-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 217 passed (3456 assertions)
Duration: 21.29s
```
The five skips are the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-5d-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 2.259s

OK
```

```sh
docker exec -w /var/www/html capstone-5d-verification node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs
```
```text
ℹ tests 23
ℹ suites 0
ℹ pass 23
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 2477.731293
```
An initial standalone Dashboard render run also passed all 10 cases but emitted SVG casing warnings because the chart adapter rendered children inside a div. The test adapter now retains an SVG parent; the final combined command above passed without those warnings. No production chart or styling change was needed.

```sh
docker exec -w /var/www/html capstone-5d-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No frontend build, Wayfinder generation or published asset write was performed.

Before/after SHA256/path snapshots covered the complete private tree and recursive legacy Python `.json`/`.pkl` files, plus source/test files across `app`, `Python`, `resources`, `tests` and `database`. The scoped diff and source-level style/icon comparison returned:
```text
Protected data/artifact path set and SHA256: PASS (37 files)
Scoped source/test changes: PASS (6 files)
Dashboard className attributes and sign-icon selection: PASS (2 views)
Python, workflow, period, model, schema, DSS and report source preservation: PASS
```
No private path was added/removed. The existing uncommitted work was preserved. Database preservation is established by avoiding development database access and by the synthetic read-snapshot tests; no live database snapshot was taken.

Cleanup:
```sh
docker rm -f capstone-5d-verification
```
```text
capstone-5d-verification
```

**Remaining limitations or follow-up tasks.** Priority 5d is **Implemented, Tested, Verified** within the designated acceptance scope. Previously stored zeros cannot be distinguished from past imputation, and chart preparation/source completeness remain unchanged. These tests establish presentation and integration correctness, not prospective predictive accuracy or renewable feasibility. D1a retains the incumbent provisionally; D2/D3 stay open; 3.4b-ii remains deferred; Priority 6 still needs verified demand/threshold semantics and separate scope. The 5c migration was last recorded Pending on development (§1.11); it was not queried or applied during this task. No migration commands, live workflows, artifact publication, database-record cleanup or unrelated work were performed.

## 2026-10-23 — Independent re-verification of Priority 5d

**Problem identified.** Priority 5d had been Implemented, Tested and Verified during implementation, but its null-versus-zero behavior, UI preservation and artifact safety had not been independently re-checked against the source and isolated test suites.

**Root cause.** Not a defect found. The review was required to confirm that absent Dashboard quantities remain unavailable rather than becoming zero, that genuine zero/count values survive, and that the read-only change did not mutate data, artifacts, schema, forecasts, DSS/report state or the development database.

**Solution implemented.** Read-only source review plus isolated Docker test execution. No application code, test, migration, artifact, forecast, DSS/report record, configuration or documentation behavior was changed by the verification itself. The documentation set was updated to record the independent result and to designate Priority 1 code-side dataset hardening as the next unblocked task.

Reviewed source:

- `app/Http/Controllers/Admin/DashboardController.php:35` — `$rounded` returns `null` when `AnalyticsSeries::number()` is `null`, otherwise rounds; all six quantity fields use it and none substitute `?? 0`. Dataset/record/forecast/report **counts** retain legitimate `?? 0`.
- `app/Http/Controllers/User/ViewerController.php:75` — same null-preserving helper and quantity mapping.
- `resources/js/lib/display-value.ts:2-7` — only `null`, `undefined`, empty string or non-finite values become `Unavailable`; numeric `0` remains valid.
- `resources/js/pages/Admin/Dashboard.tsx:65-67` and `resources/js/pages/User/Dashboard.tsx:61-63` — `formatNumber()` returns `Unavailable` before suffixing, so unavailable quantities cannot acquire units, `+` signs or `NaN`.
- Both Dashboard views — cards, chart containers, controls, info-modal buttons/handlers, signed arrow selection, badges, helpers, labels and className structure are unchanged.
- `tests/Feature/DashboardValuesTest.php` (7 cases) and `tests/frontend/dashboard-values.test.cjs` (10 cases) — absence, genuine zero, counts, stale links, nullable/zero/positive/negative change, signed/formatting and read-only assertions.

**Files created or modified.** Documentation only: `CURRENT.md`, `IMPLEMENTATION.md`, `AGENTS.md`. No source or test file was changed by this verification.

**ML algorithms or forecasting logic affected.** None. This is presentation-only verification of already-saved values; no model, feature, metric, forecast, DSS rule or report logic was changed.

**Tests executed and their actual results.** All Laravel commands used SQLite `:memory:` against the isolated `capstone-5c-reverify` container; no development database was accessed.

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DashboardValuesTest.php tests/Feature/DashboardTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/ForecastIssuanceTest.php tests/Feature/ForecastProvenanceTest.php --colors=never
```
```text
Tests:    47 passed (848 assertions)
Duration: 3.23s
```

```sh
docker exec -w /var/www/html capstone-5c-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 217 passed (3456 assertions)
Duration: 12.73s
```
The five skips are the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-5c-reverify env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 3.811s

OK
```

```sh
docker exec -w /var/www/html capstone-5c-reverify node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs
```
```text
ℹ tests 23
ℹ suites 0
ℹ pass 23
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 3955.698752
```
All 10 Dashboard render cases passed, including null/undefined/zero, tooltip guards, signed change/arrows and saved forecast strings.

```sh
docker exec -w /var/www/html capstone-5c-reverify vendor/bin/pint --test app/Http/Controllers/Admin/DashboardController.php app/Http/Controllers/User/ViewerController.php tests/Feature/DashboardValuesTest.php
```
```text
PASS   ........................................................... 3 files
```

```sh
docker exec -w /var/www/html capstone-5c-reverify npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0; no frontend build or asset publication was performed.

**Concurrency note.** An initial attempt ran two Laravel suites concurrently in the same container. Their shared fake-storage snapshot paths interfered, producing transient false failures (4 focused / 2 full) and one transient missing-staging-pair error in `ForecastPeriodTest`. Rerunning each Laravel suite sequentially after the other finished passed exactly as recorded above. No source change was made to resolve those transient results, and no product defect is inferred from them.

**Preservation evidence.** The isolated container's private tree SHA256 aggregate was `42fadc79dc80a8001cf26183213dd17df653390b3d32facad885ebd22f4005e2` after the runs and matched the host-mounted tree. The legacy flat model/metrics files, `processed_dataset.csv` and `model_state.json` remained, with no new generation or staging files in `storage/app/private/ml`. No migration command, forecast/DSS/report write, artifact publication or development-database access was performed. The disposable verification containers (`capstone-5c-reverify`, `capstone-5c-reverify-mysql`) were removed after the checks. The existing uncommitted source/test work was preserved.

**Remaining limitations or follow-up tasks.** Priority 5d is **Implemented, Tested, Verified and independently re-verified**. Stored zeros still cannot be distinguished from past imputation without separate missingness metadata, and chart aggregation/source completeness are outside 5d. This confirms presentation/integration behavior, not predictive accuracy or renewable feasibility. D1a, D2 and D3 remain unchanged; 3.4b-ii is deferred; Priority 6 remains blocked on verified demand/threshold semantics and a separate scoped request. The 5c provenance migration remains Pending on development and was not applied. **Priority 1 code-side dataset compatibility/integrity hardening is designated next (§7 of `CURRENT.md`); its external-source verification remains blocked.**

## 2026-10-23 — Priority 1 designated code-side dataset compatibility/integrity hardening

**Problem identified.** Upload parsing padded short rows and could crash on long rows or silently accept malformed headers/quoting. Replacement updated catalog paths, deleted original files and used MySQL `TRUNCATE` before all related writes succeeded. Unchecked uploads upserted periods into the active observations, allowing unrelated series to mix. Old processed rows could survive failed/insufficient preprocessing, and period-only forecast selection plus cutoff/export-only historical validation could reuse outputs from a different dataset with the same final month.

**Root cause.** There was no complete validation/staging boundary, activation transaction or source-bound experiment contract. Filenames and year/month overlap were implicitly treated as continuity. No immutable upload provenance connected the active raw/processed records, and historical validation did not compare managed actual inputs to the saved artifact's input hash.

**Solution implemented.** Strict CSV/header/quote/width validation precedes any activation; the existing required numeric/date/duplicate/completeness checks now also reject storage overflow and negative peak values that would otherwise be clipped. BOM/quoted headers and CRLF are supported. Gaps are sorted and recorded without filling; incomplete daily months remain excluded. All eight existing numeric columns remain required; missing values fail rather than becoming zero, while legitimate zeros survive. Source units and monthly completeness remain source assertions requiring external verification.

Changed uploads require the existing replacement checkbox and the full intended series; exact source-byte replay with verified source/actual ownership is a no-op. Imports serialize through a local file lock. Catalog, raw/processed transactional DELETE/INSERT, preprocessing and checksum-verified provenance are committed together; failed validation/SQL/preprocessing/metadata leaves prior rows/catalog paths and archives intact and cleans staged files. Existing same-name catalog ID/timestamp behavior is preserved. Original raw CSV/sidecar files are retained, and deleting the active source catalog entry is rejected through the existing password-error surface. Under three complete months, valid raw data is accepted with zero processed rows and explicit insufficiency, preventing stale derived data from remaining active. Automated workflow failure after commit retains the valid activated data and reports incomplete automation; it does not claim an activation rollback.

New immutable `datasets/validated/<generated CSV>` files carry `<csv-path>.json` sidecars with source and persisted-monthly SHA256, complete/excluded periods, gap intervals, aggregation and missingness/units-contract notes. `DatasetIntegrity` validates ownership/source/metadata/raw/processed consistency. `ProcessedDataset` shares the exact original CSV serialization with the existing exporter, avoiding divergent input hashes. Managed forecast/DSS queries require saved input-hash equality; historical reads refuse `active_dataset_mismatch`, including same-cutoff changed actuals, and training refuses inconsistent managed raw/processed inputs before exporting. Missing provenance yields unavailable output without repair or legacy backfill. No schema or UI component changes were needed.

Added **34 regression cases**: 33 in the new import suite and one valid historical pair accepted before managed-input mutation and refused afterward, without writes. Two existing upload fixtures now explicitly replace complete series; the leap-year fixture supplies three months to retain the original preprocessing minimum. The artifact suite now has 105 cases. Updated the three project documents to distinguish completed code hardening from externally blocked source validation.

**Files created or modified.**

- `app/Http/Controllers/Admin/DataManagementController.php`
- `app/Support/DatasetIntegrity.php`
- `app/Support/ProcessedDataset.php`
- `app/Support/ForecastPeriod.php`
- `app/Support/MlWorkflow.php`
- `tests/Feature/DatasetIntegrityTest.php`
- `tests/Feature/ForecastPeriodTest.php`
- `tests/Feature/MlArtifactsTest.php`
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** No fitting, prediction, feature, penalty, model-selection, metric or evaluation-protocol calculation changed; all Python sources are byte-identical. `ForecastPeriod::next()` is untouched; only managed input provenance constrains saved-output eligibility. CSV identity bytes are preserved by extraction into the shared serializer, verified against the original implementation and existing real-Python fixtures. Trainer signatures/artifact formats are unchanged. DSS rules, report generation/snapshots, models, schema and UI source are unchanged. This tightens activation and read eligibility without changing forecast mathematics or selecting a model.

**Tests executed and their actual results.** SQLite/Python/frontend verification used dedicated `capstone-p1-verification` (`capstone-sample/ml-app`, `--network none`). MySQL used a second runner `capstone-p1-mysql-verification` and `mysql:8.4` database `capstone-p1-mysql` on internal-only network `capstone-p1-test`, no published ports, `/var/lib/mysql` in tmpfs, DB `testing` and synthetic credentials. Both runners mounted private storage read-only. Laravel suites ran sequentially using synthetic data and fake/temporary storage; new importer tests stubbed feature output and ML automation, while existing feature/horizon tests exercised real configured Python on synthetic inputs. Development services/database were not operated or queried. Existing suites initialized their isolated test schemas; no migration command or development schema operation was performed.

Initial verification identified test-fixture issues, not an accepted product failure. The provenance-write injection's facade mock lost its filesystem-manager application state during snapshotting; a proxy around the real fake manager, restored before snapshotting, fixed the test. An old append-based leap fixture was changed to explicit full replacement containing three complete months. Initial commands/results:
```sh
docker exec -w /var/www/html capstone-p1-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DatasetIntegrityTest.php tests/Feature/ForecastPeriodTest.php --colors=never
```
```text
Tests:    2 failed, 42 passed (390 assertions)
Duration: 5.45s
```
The next run isolated the remaining mock-restoration problem:
```text
Tests:    1 failed, 43 passed (393 assertions)
Duration: 4.28s
```
After fixture correction and final source/test additions, all commands below passed. No validation rule or assertion was relaxed to obtain a pass.

```sh
docker exec -w /var/www/html capstone-p1-verification vendor/bin/pint --test app/Http/Controllers/Admin/DataManagementController.php app/Support/DatasetIntegrity.php app/Support/ProcessedDataset.php app/Support/ForecastPeriod.php app/Support/MlWorkflow.php tests/Feature/ForecastPeriodTest.php tests/Feature/DatasetIntegrityTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 8 files
```
Pint formatting mode was also run on the same eight scoped files before final verification.

```sh
docker exec -w /var/www/html capstone-p1-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DatasetIntegrityTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/MlArtifactsTest.php tests/Feature/MonthlyFeaturesTest.php --colors=never
```
```text
Tests:    159 passed (2511 assertions)
Duration: 10.33s
```

```sh
docker exec -w /var/www/html capstone-p1-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 251 passed (3747 assertions)
Duration: 13.76s
```
The five skips are the expected disabled-Fortify password-reset cases, not passes.

```sh
docker exec -w /var/www/html capstone-p1-mysql-verification env DB_CONNECTION=mysql DB_HOST=capstone-p1-mysql DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=capstone_test_only DB_URL= php artisan test --compact tests/Feature/DatasetIntegrityTest.php tests/Feature/MlArtifactsTest.php '--filter=DatasetIntegrityTest|a valid historical pair is refused' --colors=never
```
```text
Tests:    34 passed (291 assertions)
Duration: 4.29s
```
This verifies all new import/rollback/provenance cases on MySQL, including failure after deletes/partial insertion, feature failure, metadata failure, post-commit automation failure, exact replay, zero values and valid-pair same-cutoff refusal. The entire legacy suite was not run on MySQL.

```sh
docker exec -w /var/www/html capstone-p1-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 1.453s

OK
```

```sh
docker exec -w /var/www/html capstone-p1-verification node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs
```
```text
ℹ tests 23
ℹ suites 0
ℹ pass 23
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 2861.620292
```

```sh
docker exec -w /var/www/html capstone-p1-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0. No asset build or route generation was performed.

A before/after path/SHA256 snapshot included every private file and recursive legacy Python `.json`/`.pkl` artifact, plus source/test files under `app`, `Python`, `resources`, `tests` and `database`:
```text
Final protected data/artifact hashes and paths: PASS (37 files)
Final scoped source/test changes: PASS (8 files)
Python, UI, models, schema, DSS and report source preservation: PASS
```
No private path was added/removed and no existing saved model, metrics, export or state was rewritten. Source diffs preserve the original period advance and numerical CSV export. Synthetic read/rollback snapshots preserve forecast, stale-link DSS and report rows and fake artifacts; the development database was never accessed, so no live database snapshot claim is made. Unrelated pre-existing work was preserved.

Cleanup commands and actual output:
```sh
docker rm -f capstone-p1-verification capstone-p1-mysql-verification capstone-p1-mysql
```
```text
capstone-p1-verification
capstone-p1-mysql-verification
capstone-p1-mysql
```
```sh
docker network rm capstone-p1-test
```
```text
capstone-p1-test
```

**Remaining limitations or follow-up tasks.** The designated **code-side increment is Implemented, Tested, Verified**; full Priority 1 beneficiary acceptance remains **Blocked** on provenance, units, billing/calendar completeness, weather/demand semantics and an evaluated optional-field alternative. Monthly rows assert completeness; hashes establish input content, not independent source authenticity or identity of external series with identical observations. Changed series must be supplied as a full replacement using the existing checkbox. Legacy experiments do not acquire inferred provenance or new binding without a separately authorized activation, and old stored zeros are not reclassified. Raw archives/sidecars are retained; archive retention cleanup is outside this increment. Import coordination remains local-filesystem based, consistent with existing deployment. The previously recorded 5c migration prerequisite was not queried/applied here. No live workflow, development database change/migration, model replacement, D2/D3 resolution, stale-row deletion, UI redesign, building-management feature or unrelated task occurred. D1a remains deferred, D2/D3 remain open, 3.4b-ii is deferred and Priority 6 still requires verified semantics/basis and separate scope.

---

## 2026-10-23 — Independent verification of Priority 1 code-side dataset hardening

**Problem identified.** Priority 1's upload/replacement/isolation implementation and its recorded test results had not yet been independently reproduced against the source, the private artifact tree, or the current development environment.

**Root cause.** Not a defect. This was an independent verification task; the implementation itself is recorded in the preceding entry.

**Solution implemented.** Re-read `DataManagementController`, `DatasetIntegrity`, `ProcessedDataset`, `ForecastPeriod`, `MlWorkflow`, `DatasetIntegrityTest` and `MlArtifactsTest`; checked the acceptance behaviours against source and named regressions; re-ran the recorded scoped/full/Python/frontend/Pint/TypeScript commands in isolated containers; repeated the disposable-MySQL run; compared 37 protected data/artifact path/hash entries before and after; and performed one read-only development-database query. No application code, test, migration, artifact, forecast, DSS row or report was changed. The containers and network were removed after verification.

**Files created or modified.**
- `CURRENT.md`
- `IMPLEMENTATION.md`
- `AGENTS.md`

No source, test, migration or artifact file was modified by this verification.

**ML algorithms or forecasting logic affected.** None. No fitting, prediction, feature, penalty, metric, protocol, horizon, artifact-identity or publication logic changed. The verification read the current published state and confirmed it was untouched by the test runs.

**Tests executed and their actual results.**

```sh
docker exec -w /var/www/html capstone-p1-reverify vendor/bin/pint --test app/Http/Controllers/Admin/DataManagementController.php app/Support/DatasetIntegrity.php app/Support/ProcessedDataset.php app/Support/ForecastPeriod.php app/Support/MlWorkflow.php tests/Feature/ForecastPeriodTest.php tests/Feature/DatasetIntegrityTest.php tests/Feature/MlArtifactsTest.php
```
```text
PASS   ........................................................... 8 files
```

```sh
docker exec -w /var/www/html capstone-p1-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DatasetIntegrityTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/MlArtifactsTest.php tests/Feature/MonthlyFeaturesTest.php --colors=never
```
```text
Tests:    159 passed (2511 assertions)
Duration: 21.06s
```

```sh
docker exec -w /var/www/html capstone-p1-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 251 passed (3747 assertions)
Duration: 28.06s
```

```sh
docker exec -w /var/www/html capstone-p1-mysql env DB_CONNECTION=mysql DB_HOST=<internal-mysql> DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=<test-only> DB_URL= php artisan test --compact tests/Feature/DatasetIntegrityTest.php tests/Feature/MlArtifactsTest.php '--filter=DatasetIntegrityTest|a valid historical pair is refused' --colors=never
```
```text
Tests:    34 passed (291 assertions)
Duration: 4.23s
```
The MySQL command used an internal-only disposable hostname and synthetic test credentials; those values are elided from this record rather than documented as reusable credentials.

```sh
docker exec -w /var/www/html capstone-p1-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DatasetIntegrityTest.php --colors=never
```
```text
Tests:    33 passed (283 assertions)
```

```sh
docker exec -w /var/www/html capstone-p1-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/MlArtifactsTest.php --colors=never
```
```text
Tests:    105 passed (2045 assertions)
```

```sh
docker exec -w /var/www/html capstone-p1-reverify env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 1.260s

OK
```

```sh
docker exec -w /var/www/html capstone-p1-reverify node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs
```
```text
tests 23
pass 23
fail 0
duration_ms 2967.746044
```

```sh
docker exec -w /var/www/html capstone-p1-reverify npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0.

Preservation checks:
```text
Private-tree files: 29
Recursive Python artifact files: 8
Protected path/hash comparisons identical: 37/37
Private aggregate SHA256 (before and after): c600bcbb428b6cc8fc730f70edecbcb2ad8e5f04dbf614936f789814b4a7acce
Scoped source/test mtimes unchanged: 8/8
Private paths added or removed: 0
```

Active filesystem state observed during verification:
```text
model_state.json SHA256: c0fdf9cf56ac53e57108491681438a712e37fdbec4cbc3e355338ea6eea79eeb
artifact_id: a1409a54953f6c85264879db8b801e8aa03c7bed066ed0e06e046af27a4b4900
active generation: ..._8018e3ca-1280-47ff-9481-add747306fdf
training_cutoff: 2026-08
trained_at: 2026-10-23 06:27:49
generation directories: 9
staging entries: 0
export SHA256: f0819b2d3a86335b1a030d147ada6724881d1ab0402ca42289ea7174d32ec7af
```

Read-only development-database query:
```text
audit_trails max id: 75
ids 72-75: preprocessing 2026-10-23 05:04:06; train 05:04:21; train 05:07:32; train 06:27:49
reports max id: 14 (2026-10-22 19:26:12)
forecast rows: 2; latest 2026-09 = 177137.16 kWh, Ridge (penalty 0.1), predicted_at 2026-10-22 19:26:11
```
No database row was written. The later environmental runs were training-only; no forecast, DSS or report row was created after the `2026-10-22 19:26` records.

Cleanup:
```sh
docker rm -f capstone-p1-reverify capstone-p1-mysql
```
```text
capstone-p1-reverify
capstone-p1-mysql
```
```sh
docker network rm capstone-p1-test
```
```text
capstone-p1-test
```

**Remaining limitations or follow-up tasks.** Four low-severity residuals are recorded in `CURRENT.md` §1.13/§3.2a item 31: secondary sidecar fields are recorded but not re-derived; environmental values are not sign-constrained; `managed()` is catalog-wide rather than active-only; and import-lock/archive cleanup has no garbage collection. Full beneficiary provenance, units, billing/calendar completeness and weather/demand semantics remain Blocked. The independently verified active state was externally produced; it is not a D2 authorization. D1a defers replacement, D2/D3 remain open, 3.4b-ii is deferred, Priority 6a is designated but not started, and Priority 6b remains blocked on beneficiary evidence.

---

### 2026-10-23 — Priority 6a: DSS evidence qualification and factual report wording

**Problem identified.** New DSS output could present demand/readiness and investment-oriented advice from unsupported demand semantics and kW-versus-monthly-kWh comparisons. Missing supporting measurements became zero. New reports asserted suitability from R² alone and repeated legacy readiness conclusions without an evidence qualification.

**Root cause (confirmed).** `EnergyDss::generateForForecast()` applied the historical classification/recommendation helpers without checking forecast linkage, numeric availability or evaluation limitations. Its basis lacked units, availability and rule version. `ReportGenerator` interpreted R² thresholds as decision suitability; quantity casts hid missing values. Priority 3.4b-i had already fixed nullable evaluation metrics; this increment preserves those definitions and calculations.

**Solution implemented.** Added `DssEvidence` with rule `dss_evidence_qualification_v1`, deterministic reasons, nullable finite inputs, declared/unverified units, nonnegative-validity flags, saved forecast provenance/issuance facts and evaluation status. Missing, invalid, negative and genuine zero inputs remain distinct; no upper plausibility cutoff is invented. Undefined evidence is unavailable, negative R² remains weak, and even positive R² remains unvalidated for prospective use. New demand/readiness statuses are `Not assessed`, with qualified monitoring/source-verification advice and no feasibility/savings conclusion. Unsaved/dirty/deleted forecasts are refused before writing; missing/current/mismatched/ambiguous linkage is inspectable. No current artifact is used to infer old forecast provenance.

Legacy demand/readiness functions and their numeric thresholds are byte-preserved. The original recommendation body is retained privately and inactive; new output does not use its unit-inconsistent or unsupported conclusions. Selecting replacement rules remains 6b. Existing per-forecast upsert and generation timestamps are preserved.

New reports qualify their copy of saved DSS evidence, retain the stored assessment ID, preserve nullable quantities and genuine zeros, and replace R²-only suitability with factual uncertainty. They do not update the source DSS or older reports. The report view uses nullable formatting only for versioned new snapshots; legacy wording and formatting remain intact. Existing Admin help text describes this qualification and dated legacy labels. The shared basis type accepts nested nullable evidence. No layout, CSS class, stylesheet, control, route or screen was changed.

**Files created or modified.**
```text
app/Support/DssEvidence.php (new)
app/Support/EnergyDss.php
app/Support/ReportGenerator.php
resources/js/pages/Admin/DecisionSupport.tsx
resources/js/types/dss.ts
resources/views/reports/energy.blade.php
tests/Feature/DssEvidenceTest.php (new)
tests/frontend/dss-evidence.test.cjs (new)
AGENTS.md
CURRENT.md
IMPLEMENTATION.md
```

**ML algorithms or forecasting logic affected.** None. Training, predictors, features, model selection, artifact validation, metrics mathematics and dataset-based horizon sources are unchanged. Legacy DSS numeric formulas and thresholds remain unchanged but are inactive for new conclusions pending verified 6b evidence. No live training/forecast/DSS/report workflow, development migration, development database query/write or artifact publication was executed. Tests used synthetic SQLite records/fake storage, a network-isolated disposable Docker container, and read-only private storage. The ambiguous-linkage fixture drops the existing unique-period index only inside the disposable test database to simulate corrupt legacy rows; no production constraint/schema is changed.

**Tests executed and actual results.** Added 25 Laravel cases covering null/non-finite/negative/zero inputs, missing/dirty/deleted/mismatched/ambiguous linkage, poor/undefined/zero/high evaluation, deterministic reasons, legacy rule boundaries, stored provenance, saved-row/report preservation and both-role response shapes; two frontend cases render the existing Admin/User tables, filters and assessment dialogs without invoking workflows.

The first attempt in the previous turn was not executed because automatic approval review hit its usage limit. On continuation that restriction cleared. Docker initially was stopped and later became unavailable during concurrent Laravel/TypeScript verification; those interrupted commands ended with exit 255 and are not counted as passing. Docker Desktop was restarted and remaining checks ran sequentially. The cause of Docker stopping was not confirmed. A completed isolated new-suite attempt exposed a fixture error, not an application failure:
```sh
docker exec -w /var/www/html capstone-6a-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DssEvidenceTest.php --colors=never
```
```text
FAILED  Tests\Feature\DssEvidenceTest…  UniqueConstraintViolationException
SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: forecast_results.year, forecast_results.month
Tests:    1 failed, 24 passed (359 assertions)
Duration: 12.74s
```
The ambiguity fixture was corrected as described above; no production unique constraint was removed. Final verification:

```sh
docker exec -w /var/www/html capstone-6a-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DssEvidenceTest.php tests/Feature/ForecastMetricsTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/ForecastProvenanceTest.php tests/Feature/ForecastIssuanceTest.php --colors=never
```
```text
Tests:    76 passed (1012 assertions)
Duration: 4.86s
```

```sh
docker exec -w /var/www/html capstone-6a-verification env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 276 passed (4112 assertions)
Duration: 31.82s
```
The five skips are the existing disabled Fortify password-reset cases; they are not passes.

```sh
docker exec -w /var/www/html capstone-6a-verification env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
......................................................................
----------------------------------------------------------------------
Ran 70 tests in 14.703s

OK
```

```sh
docker exec -w /var/www/html capstone-6a-verification node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs tests/frontend/dss-evidence.test.cjs
```
```text
ℹ tests 25
ℹ suites 0
ℹ pass 25
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 1559.366376
```

```sh
docker exec -w /var/www/html capstone-6a-verification npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0.

```sh
docker exec -w /var/www/html capstone-6a-verification vendor/bin/pint --test app/Support/DssEvidence.php app/Support/EnergyDss.php app/Support/ReportGenerator.php tests/Feature/DssEvidenceTest.php
```
```text
PASS   ........................................................... 4 files
```

Preservation checks used the pre-implementation `/tmp/capstone-6a-before.json` hashes and saved source copies, with the final results:
```text
Protected paths/hashes unchanged: 37/37; additions/removals: 0
Scoped source/test changes: 8 (the six production and two test paths listed above)
Touched view class attributes/styles unchanged: 2/2
Legacy demand/readiness/retained recommendation bodies unchanged: 3/3
Forecasting/Python/model/schema/controller sources unchanged from task start
ML staging entries: 0
State SHA256: c0fdf9cf56ac53e57108491681438a712e37fdbec4cbc3e355338ea6eea79eeb
```
Container configuration was confirmed with `docker inspect`:
```text
network=none private_storage_writable=false
```
No MySQL-specific query/schema change was introduced; this increment did not repeat disposable MySQL persistence verification or access the development database. UI verification uses actual page source rendered with synthetic adapters plus unchanged class/style comparisons; no interactive live workflow was used.

Cleanup:
```sh
docker rm -f capstone-6a-verification
```
```text
capstone-6a-verification
```

**Remaining limitations or follow-up tasks.** Priority 6a is Implemented, Tested, Verified in isolated tests, not a live acceptance run. Existing saved DSS rows and reports retain their dated original labels/claims; new reports explicitly qualify copied evidence without retroactively changing those records. No forecast score or indicative solar observation establishes engineering/investment readiness. Priority 6b remains Blocked on beneficiary demand semantics, verified units/comparison window and a justified threshold basis; no replacement threshold/unit rule was selected. D1a still defers model replacement; D2/D3 remain open; no additional implementation task or live workflow is authorized. The 5c migration remains last-recorded pending on development and was not queried/applied by this task. External dataset provenance, units and genuine history remain unverified.

### 2026-10-23 — Independent re-verification of Priority 6a (documentation and isolated tests)

**Problem identified.** Priority 6a was recorded as Implemented, Tested, Verified, but its completion followed an interrupted session and had not been independently re-run. The documentation also still claimed 37/37 protected paths unchanged, while the live state pointer had subsequently changed. Legacy pre-6a DSS rows and report rendering needed an explicit verification record.

**Root cause.** Not a confirmed 6a code defect. The state-file difference is environmental: `storage/app/private/ml/model_state.json` was externally replaced after the earlier 6a snapshot. The exact trigger of that external publication was not reconstructed by this review.

**Solution implemented.** Re-read `DssEvidence`, `EnergyDss`, `ReportGenerator`, the report view, both DSS pages, the DSS type contract and the 25 Laravel plus two frontend 6a cases. Recreated the isolated Docker pattern, re-ran the recorded suites sequentially, compared the current tree against `/tmp/capstone-6a-before.json`, and updated only the status/verification documentation. No application code, test, migration, saved artifact, DSS row, report, forecast or development database record was changed; no live workflow was invoked.

**Files created or modified.**
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. No model, feature builder, evaluation protocol, horizon, DSS threshold, report conclusion or saved artifact was changed. The review exercised only existing isolated tests and read-only state inspection.

**Tests executed and their actual results.**

Container isolation:
```text
network=none | /var/www/html rw=true | /var/www/html/storage/app/private rw=false
private_writable=no
ml_writable=no
```

Focused Laravel:
```sh
docker exec -w /var/www/html capstone-6a-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/DssEvidenceTest.php tests/Feature/ForecastMetricsTest.php tests/Feature/ForecastPeriodTest.php tests/Feature/ForecastProvenanceTest.php tests/Feature/ForecastIssuanceTest.php --colors=never
```
```text
Tests:    76 passed (1012 assertions)
Duration: 7.18s
```

Full Laravel:
```sh
docker exec -w /var/www/html capstone-6a-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 276 passed (4112 assertions)
Duration: 37.91s
```

Python:
```sh
docker exec -w /var/www/html capstone-6a-reverify env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 1.877s

OK
```

Frontend:
```sh
docker exec -w /var/www/html capstone-6a-reverify node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs tests/frontend/dss-evidence.test.cjs
```
```text
tests 25
pass 25
fail 0
skipped 0
duration_ms 3026.566626
```

TypeScript:
```sh
docker exec -w /var/www/html capstone-6a-reverify sh -lc 'npm run types:check > /tmp/capstone-6a-ts.out 2>&1; code=$?; cat /tmp/capstone-6a-ts.out; printf "exit_code=%s\n" "$code"; exit "$code"'
```
```text
npm notice run types:check
npm notice run tsc --noEmit
exit_code=0
```

Pint:
```sh
docker exec -w /var/www/html capstone-6a-reverify vendor/bin/pint --test app/Support/DssEvidence.php app/Support/EnergyDss.php app/Support/ReportGenerator.php tests/Feature/DssEvidenceTest.php
```
```text
PASS   ........................................................... 4 files
```

Preservation comparison against `/tmp/capstone-6a-before.json`:
```text
Baseline source paths: 316; unchanged: 311
Expected 6a-modified production sources: 5
Expected 6a additions: 3
Protected paths compared: 37; unchanged: 36
Changed protected path: storage/app/private/ml/model_state.json
Before: c0fdf9cf56ac53e57108491681438a712e37fdbec4cbc3e355338ea6eea79eeb
After:  b72b5e179ed6fb9f2a7e684ed91e138aac5072b0814843f886a09acb14feac75
State artifact_id: a1409a54953f6c85264879db8b801e8aa03c7bed066ed0e06e046af27a4b4900
State generation: …_6420ea69-d377-4afc-8f85-d5c810fb4304
Model SHA256: fa784e1693928f22322e933dd9bcce14b9862157c75e74ca336278b404c304b8
Metrics SHA256: ebc2436a452f1714f5a5ca2649ef040fe763fe9f1aa3d1be70ce2351d3fa0420
State trained_at: 2026-10-23 13:30:02
Staging entries: 0
Generation directories: 10
```

The five skips are the existing disabled-Fortify password-reset cases and are not counted as passes. `git diff --check` passed for the tracked working tree; a separate trailing-whitespace scan of the three untracked documentation files returned no matches.

**Remaining limitations or follow-up tasks.** Priority 6a is **Implemented, Tested, Verified and independently re-verified** in isolated tests, not a live acceptance run. The 36/37 protected comparison is not a 6a source regression; only the externally replaced state pointer differs. Two presentation residuals remain: legacy report rendering appends current metric definitions/issuance context, and pre-6a stored DSS rows still show dated demand/readiness labels without an explicit legacy tag. The user-designated next unblocked task is the **Priority 6a legacy DSS/report evidence presentation qualification follow-up**, not started by this review. Existing saved records, artifacts, thresholds and conclusions remain unchanged. Priority 6b remains Blocked; D1a still defers replacement; D2/D3 remain open; 3.4b-ii remains deferred; the 5c migration remains pending on development. No live workflow, migration or development database write was performed.

---

### 2026-10-23 — Priority 6a follow-up: legacy DSS/report evidence presentation

**Problem identified.** Pre-6a saved DSS labels/advice appeared without an explicit legacy qualifier in history rows, dialogs and Dashboard summaries. Legacy report snapshots retained their original conclusions but displayed metric definitions and derived timing context without clearly separating those explanations from the saved assessment.

**Root cause (confirmed).** DSS views displayed the saved status/advice fields directly without checking the recorded evidence-rule version. Dashboard responses supplied status strings without the saved basis needed to distinguish versioned 6a evidence. The report view already distinguished versioned snapshots for quantity formatting, but did not identify legacy conclusions or distinguish fallback/current metric explanations from saved definitions.

**Solution implemented.** Added the shared `legacyDssNotice` presentation helper: a saved DSS object with a missing/unrecognized `basis.rule_version` receives `Historical / unqualified evidence`; the known `dss_evidence_qualification_v1` version and absence of a DSS result receive no notice. Classification uses saved metadata, never today, current metrics or the active model. Original labels/advice remain unchanged.

Existing DSS row/dialog text and Dashboard header/advice text now show the notice when appropriate. The only backend changes are two additive Dashboard response lines projecting the saved basis as nullable `dss_evidence`; neither changes data selection nor writes a record. The TypeScript basis contract now permits its existing null database value.

Legacy reports qualify their original saved conclusions in existing Report Basis/assessment paragraphs. Existing timing text is explicitly identified as presentation context derived from that snapshot. Saved metric definitions are labelled historical/not revalidated; fallback definitions are labelled current explanatory context, not part of the saved assessment. No saved summary, original conclusion, recommendation, metric value or quantity formatting was rewritten. New versioned 6a report rendering remains byte-identical for the before/after synthetic fixture. Existing element structures, class attributes, styles and controls are unchanged across all five touched views; no new component, screen, filter or action was added.

**Files created or modified.**
```text
app/Http/Controllers/Admin/DashboardController.php
app/Http/Controllers/User/ViewerController.php
resources/js/lib/dss-evidence.ts (new)
resources/js/pages/Admin/Dashboard.tsx
resources/js/pages/Admin/DecisionSupport.tsx
resources/js/pages/User/Dashboard.tsx
resources/js/pages/User/DecisionSupportResults.tsx
resources/js/types/dss.ts
resources/views/reports/energy.blade.php
tests/Feature/LegacyDssPresentationTest.php (new)
tests/frontend/dashboard-values.test.cjs
tests/frontend/dss-evidence.test.cjs
AGENTS.md
CURRENT.md
IMPLEMENTATION.md
```

**ML algorithms or forecasting logic affected.** None. All Python/model/schema sources, `EnergyDss`, `DssEvidence`, `ReportGenerator`, `MlWorkflow` and `ForecastPeriod` match the task-start hashes. DSS thresholds, model selection, forecasting mathematics, actual-based period selection and report/DSS generation remain unchanged. No live workflow, migration command, development database query/write or saved-artifact mutation occurred. Laravel tests use isolated SQLite `:memory:` and fake storage; the disposable Docker container used `network=none` with private storage read-only. Existing historical database records were not queried or modified.

**Tests executed and actual results.** Added 11 Laravel cases: preserved legacy report conclusions/snapshots and clock invariance; missing/blank/legacy/unknown versions; saved metric definitions; unmodified versioned status/advice and zero-versus-unavailable formatting; both-role legacy DSS API reads; legacy/versioned/absent Dashboard metadata. Added six frontend cases covering both roles, null/missing/unknown bases, mixed legacy/versioned histories, unchanged input objects and controls, and Dashboard notices/advice. Existing versioned frontend cases also assert absence of a legacy notice.

Final focused Laravel:
```sh
docker exec -w /var/www/html capstone-6a-legacy env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/LegacyDssPresentationTest.php tests/Feature/DssEvidenceTest.php tests/Feature/DashboardValuesTest.php tests/Feature/ForecastMetricsTest.php tests/Feature/ForecastIssuanceTest.php --colors=never
```
```text
Tests:    74 passed (1235 assertions)
Duration: 4.40s
```

Full Laravel:
```sh
docker exec -w /var/www/html capstone-6a-legacy env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 287 passed (4306 assertions)
Duration: 27.85s
```
The five skips are the existing disabled-Fortify password-reset cases, not passes.

Python:
```sh
docker exec -w /var/www/html capstone-6a-legacy env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
----------------------------------------------------------------------
Ran 70 tests in 1.418s

OK
```

Frontend:
```sh
docker exec -w /var/www/html capstone-6a-legacy node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs tests/frontend/dss-evidence.test.cjs
```
```text
ℹ tests 31
ℹ suites 0
ℹ pass 31
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 2180.360793
```

TypeScript:
```sh
docker exec -w /var/www/html capstone-6a-legacy npm run types:check
```
```text
npm notice run types:check
npm notice run tsc --noEmit
```
Exit code 0.

Pint:
```sh
docker exec -w /var/www/html capstone-6a-legacy vendor/bin/pint --test app/Http/Controllers/Admin/DashboardController.php app/Http/Controllers/User/ViewerController.php tests/Feature/LegacyDssPresentationTest.php
```
```text
PASS   ........................................................... 3 files
```

Additional before/after report-render check: copied the task-start template to `/tmp/before-energy.blade.php` inside the disposable container; bootstrapped Laravel with SQLite isolation; rendered that template with `Blade::render()` and the current `reports.energy` view using the same **unsaved synthetic** versioned report (zero prediction, null MAE, zero RMSE, negative R², `Not assessed` labels). No model persistence, database query or workflow invocation was needed. The actual command was:
```sh
docker exec -i -w /var/www/html capstone-6a-legacy env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$report = new App\Models\Report(['title' => 'Synthetic versioned render', 'summary' => [
    'dss_reasoning' => ['rule_version' => App\Support\DssEvidence::RULE_VERSION],
    'latest_forecast' => ['year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25', 'predicted_consumption_kwh' => 0],
    'latest_dss' => ['demand_status' => 'Not assessed', 'readiness_level' => 'Not assessed', 'recommendations' => ['Insufficient verified basis'], 'priority_actions' => ['Verify sources']],
    'forecast_accuracy' => ['mae' => null, 'rmse' => 0, 'r2_score' => -2],
]]);
$report->created_at = '2026-10-21 05:55:25';
$report->setRelation('user', null);
$before = Illuminate\Support\Facades\Blade::render(file_get_contents('/tmp/before-energy.blade.php'), ['report' => $report], true);
$after = view('reports.energy', ['report' => $report])->render();
echo 'Before render SHA256: '.hash('sha256', $before).PHP_EOL;
echo 'After render SHA256: '.hash('sha256', $after).PHP_EOL;
echo 'Versioned report render byte-identical: '.($before === $after ? 'yes' : 'no').PHP_EOL;
exit($before === $after ? 0 : 1);
PHP
```
Exact output:
```text
Before render SHA256: c081880a910631ad46f5edbcaf7219134fdeb669b0240d36056f6d321635df9f
After render SHA256: c081880a910631ad46f5edbcaf7219134fdeb669b0240d36056f6d321635df9f
Versioned report render byte-identical: yes
```
This is a focused synthetic render comparison, not a claim that legacy report HTML remains byte-identical: the authorized legacy notices intentionally add presentation text.

Fresh preservation checks used `/tmp/capstone-6a-legacy-before.json` and task-start source copies:
```text
Protected paths/hashes unchanged: 39/39; additions/removals: 0
Scoped source/test change count: 12
Existing view class attributes/styles/tag structure unchanged: 5/5
DSS rules/generation, report generation, ML workflow, horizon unchanged: 5/5
All Python/schema/model sources unchanged
ML staging entries: 0
State SHA256: b72b5e179ed6fb9f2a7e684ed91e138aac5072b0814843f886a09acb14feac75
```
The fresh snapshot includes the externally published generation already present at this task's start; it does not revise the earlier independent 6a verification's dated 36/37 comparison.

Container isolation:
```sh
docker inspect --format 'network={{.HostConfig.NetworkMode}} private_storage_writable={{range .Mounts}}{{if eq .Destination "/var/www/html/storage/app/private"}}{{.RW}}{{end}}{{end}}' capstone-6a-legacy
```
```text
network=none private_storage_writable=false
```
Cleanup:
```sh
docker rm -f capstone-6a-legacy
```
```text
capstone-6a-legacy
```

**Remaining limitations or follow-up tasks.** This follow-up is Implemented, Tested, Verified in isolated tests. Historical unsupported labels/claims remain verbatim and are now visibly qualified; they were not revalidated or replaced by the new `Not assessed` status. Missing/unrecognized rule metadata is treated as unqualified, not repaired or inferred from dates/artifacts. Displayed fallback definitions remain current explanatory context, not evidence of historical training semantics or prospective performance. No MySQL-specific query/schema behavior changed, so disposable MySQL verification was not repeated. Priority 6b remains Blocked on verified demand meaning, units/window and justified thresholds; D1a still defers replacement, D2/D3 remain open, and the 5c migration remains last-recorded pending on development. No additional implementation or live workflow is authorized.

### 2026-10-23 — Independent re-verification of the Priority 6a legacy-presentation follow-up

**Problem identified.** The follow-up implementation record claimed passing isolated suites, a byte-identical versioned report render, unchanged UI structure and a 39/39 protected-path snapshot. Those claims needed independent reproduction, including the distinction between the follow-up's point-in-time protected set and the later externally advanced ML state.

**Root cause.** Not an implementation defect. The saved `ml/model_state.json` was externally replaced after the follow-up snapshot, so a naive current comparison would misreport the follow-up as a regression.

**Solution implemented.** A fresh independent review re-read the shared helper, both Dashboard controllers, all four touched DSS/Dashboard pages, the report view, the type contract and all 11 Laravel/six frontend cases. The recorded suites were rerun in a new network-isolated container with repository and private storage read-only, writable tmpfs for framework/Pest caches, SQLite `:memory:` and synthetic/fake model storage. A versioned report was rendered against both the task-start and current templates. No application source or test was changed.

**Files created or modified.**
- `AGENTS.md`
- `CURRENT.md`
- `IMPLEMENTATION.md`

**ML algorithms or forecasting logic affected.** None. The verification changed no model, feature, metric, protocol, label, horizon, DSS rule or report calculation.

**Tests executed and their actual results.**

```sh
docker exec -w /var/www/html capstone-6a-followup-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact tests/Feature/LegacyDssPresentationTest.php tests/Feature/DssEvidenceTest.php tests/Feature/DashboardValuesTest.php tests/Feature/ForecastMetricsTest.php tests/Feature/ForecastIssuanceTest.php --colors=never
```
```text
Tests:    74 passed (1235 assertions)
Duration: 16.48s
```

```sh
docker exec -w /var/www/html capstone-6a-followup-reverify env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact --colors=never
```
```text
Tests:    5 skipped, 287 passed (4306 assertions)
Duration: 45.81s
```

```sh
docker exec -w /var/www/html capstone-6a-followup-reverify env PYTHONDONTWRITEBYTECODE=1 /opt/ml-venv/bin/python -m unittest discover -s Python/tests
```
```text
Ran 70 tests in 2.500s
OK
```

```sh
docker exec -w /var/www/html capstone-6a-followup-reverify node --test tests/frontend/forecast-metrics.test.cjs tests/frontend/analytics-origins.test.cjs tests/frontend/dashboard-values.test.cjs tests/frontend/dss-evidence.test.cjs
```
```text
ℹ tests 31
ℹ pass 31
ℹ fail 0
ℹ duration_ms 7277.636962
```

```sh
docker exec -w /var/www/html capstone-6a-followup-reverify sh -lc 'npm run types:check >/tmp/types.out 2>&1; code=$?; cat /tmp/types.out; echo "exit=$code"'
```
```text
npm notice run types:check
npm notice run tsc --noEmit
exit=0
```

```sh
docker exec -w /var/www/html capstone-6a-followup-reverify vendor/bin/pint --test app/Http/Controllers/Admin/DashboardController.php app/Http/Controllers/User/ViewerController.php tests/Feature/LegacyDssPresentationTest.php
```
```text
PASS   ........................................................... 3 files
```

The five Laravel skips are the expected disabled-Fortify password-reset cases. An initial container run failed on a missing tmpfs `storage/framework/views`; creating the framework directories resolved it. Pest then warned that its result cache under the read-only vendor mount was unwritable; a writable tmpfs at `vendor/pestphp/pest/.temp` removed the warning before the recorded runs.

Versioned report render comparison:
```text
Before render SHA256: c081880a910631ad46f5edbcaf7219134fdeb669b0240d36056f6d321635df9f
After render SHA256: c081880a910631ad46f5edbcaf7219134fdeb669b0240d36056f6d321635df9f
Versioned report render byte-identical: yes
```

Static preservation check across the five touched views showed unchanged class/style/control/table counts. Diff review found only imports, nullable `DssResult` basis projections and notice text; the apparent regex tag-count difference came from TypeScript generics such as `Pick<DssResult, 'basis'>`, not JSX.

Fresh pre/post snapshot:
```text
source paths 321 changed 0 added 0 removed 0
protected paths 41 changed 0 added 0 removed 0
state_equal True
generation_directories 11
staging_entries 0
manifest_equal True
captured_at_local 2026-10-23 22:40:53 PST (+0800)
```

Against the follow-up's task-start baseline:
```text
source baseline 319 current 321 unchanged 309 changed 10 added 2 removed 0
protected baseline 39 current 41 unchanged 38 changed 1 added 2 removed 0
changed protected: storage/app/private/ml/model_state.json
added protected: active generation model.pkl and metrics.json
```

The current state is SHA256 `fde504c4263f3e4c50599654946481695d62d1089fa4d98a4a1c109ffd340e04`, `trained_at 2026-10-23 14:10:20 UTC`, generation `…_24efe42a-663c-465f-a2b4-9ec5389cce37`, model `fa784e16…`, metrics `ebc2436a…`, 11 generation directories and empty staging. This external publication is not a D2 authorization.

**Remaining limitations or follow-up tasks.** The focused/versioned checks establish software presentation correctness, not prospective forecast accuracy. No MySQL-specific behavior, live workflow, development database query/write, migration command or staging end-to-end run occurred. The legacy rendered report is intentionally not byte-identical to older output because it adds qualification text; saved snapshots and conclusions are unchanged. The remaining integration path is recorded in `CURRENT.md` §7: close D2/D3 and 3.4b-ii decisions, complete external Priority 1 evidence and added history, resolve or keep 6b qualified, deploy the reviewed migrations, then run one separately authorized staging end-to-end workflow. Priority 6b remains Blocked; D1a still defers replacement; D2/D3 remain open; the 5c migration remains pending on development.

---

### 2026-10-24 — Read-only release-readiness audit

**Problem identified.** Completed software increments and independently passing isolated suites did not establish a reproducible committed release candidate, current frontend/Docker build, confirmed target schema or authorized final integration workflow.

**Root cause (confirmed where noted).** All feature changes after commit `22070d8` remain in the working tree; required sources/tests/migrations/docs are untracked. Git/Docker exclusions are incomplete for environment variants, generated bytecode and private build context. Existing frontend assets predate the latest DSS views. Current CI does not reproduce the configured Python/database/test protocol. Docker availability prevented current build/runtime/schema inspection. The reason the Docker daemon was unavailable was not investigated; no application defect or current CI failure is inferred from an unexecuted check. Provenance/distribution status of the tracked CSV/model files is not confirmed.

**Solution implemented.** Performed Git/ref/config/status checks, read-only remote reference lookup, exclusion and limited secret-signature checks, static Docker/dependency/CI/migration review, existing-build completeness/marker checks, and preservation hashing. Added the release verdict, critical blockers and shortest safe completion path to CURRENT.md §1.16; updated release guidance in AGENTS.md. **Verdict: not ready for release or final integration approval.** No application code, Git configuration, database record, migration, environment file, dependency lock, build output or saved ML artifact was changed. No commit, push, fetch/ref update, service start/stop, build, application test suite or live workflow ran.

**Files created or modified.**
```text
AGENTS.md
CURRENT.md
IMPLEMENTATION.md
```

**ML algorithms or forecasting logic affected.** None. Forecasting mathematics, model selection, DSS thresholds, actual-based horizon, stored labels and report snapshots are unchanged. D1a remains deferred; D2/D3 remain open; Priority 6b remains Blocked and 3.4b-ii remains deferred. This audit does not authorize implementation, deployment, dataset replacement, artifact regeneration or cleanup.

**Checks executed and actual results.** No new application tests were run. The latest independent passing suites remain the dated 2026-10-23 evidence in the preceding entry (74 focused Laravel / 1235 assertions; 287 full Laravel / 5 expected skips / 4306 assertions; 70 Python; 31 frontend; TypeScript exit 0; Pint PASS three files). They were not repeated or promoted to release acceptance on this audit date.

Read-only Git commands (some invoked by small Python wrappers for counts/redaction):
```sh
git status --short --branch
git status --porcelain=v1 --untracked-files=all
git branch -vv
git diff --stat
git diff --cached --name-only
git rev-list --left-right --count HEAD...origin/main
git remote get-url origin
git remote get-url --push origin
git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}'
```
Actual output excerpts and wrapper counts:
```text
## main...origin/main
* main 22070d8 [origin/main] Update capstone DSS with dataset-based forecasting
Status counts: {' M': 34, '??': 41}
Staged diff empty: True
HEAD: 22070d8 2026-10-21 Update capstone DSS with dataset-based forecasting
Local refs ahead/behind: 0	0
Remote origin fetch https://github.com/rbarcoma/capstone-sample.git
Remote origin push https://github.com/rbarcoma/capstone-sample.git
Upstream: origin/main
branch.main.remote: origin
branch.main.merge: refs/heads/main
remote.origin.fetch: +refs/heads/*:refs/remotes/origin/*
remote.pushDefault: (unset)
push.default: (unset)
34 files changed, 1145 insertions(+), 924 deletions(-)
```
There is no staged diff. The 41 untracked files include required helpers, definitions, tests, both new migrations and the three project documents, plus a generated CPython cache; they must be reviewed rather than blindly staged. Tracked-only changes are insufficient to recreate the implementation.

Read-only remote lookup, without fetching/updating refs or enabling credential prompts:
```sh
env GIT_TERMINAL_PROMPT=0 GCM_INTERACTIVE=never git -c credential.interactive=false ls-remote --exit-code origin HEAD refs/heads/main
```
```text
22070d85abde3c36cb747daa6291c44fd820f702	HEAD
22070d85abde3c36cb747daa6291c44fd820f702	refs/heads/main
```
Remote read access and ref identity were verified; push permission, protection settings and hosted CI status were not checked. No remote credential values were printed.

Sensitive-file/path checks used `git check-ignore -v`, `git check-ignore --no-index -q`, `git ls-files`, local reachable history for the conventional environment paths, and a limited high-confidence signature scan of tracked text. Actual findings:
```text
Tracked env files: ['.env.example']
Tracked private storage files: 0
Tracked CSV count: 19
Tracked pickle count: 5
.env.staging ignored=False
.env.local ignored=False
.env.testing ignored=False
.env.example ignored=False
Python/__pycache__/probe.pyc ignored=False
Python/model/probe.pkl ignored=False
release-backup.sql ignored=False
database/backup.sql ignored=False
Local reachable history commits touching .env: 0
Local reachable history commits touching .env.backup: 0
Local reachable history commits touching .env.production: 0
Limited high-confidence tracked-text secret signature hits: []
Not a full history or arbitrary-secret scan
```
Actual `.env`, `.env.backup`, `.env.production`, auth.json, private storage, dependencies, compiled assets and SQLite paths matched existing ignore rules. The signature scan checked private-key headers, AWS access-key signatures and GitHub-token signatures, reported paths/kinds only, and did not print matches/secret values. This does not prove a secret-free history or approve tracked datasets/models for distribution. No ignore rule, tracked file, history or source dataset was changed.

Docker availability:
```sh
docker ps --format '{{.Names}}\t{{.Image}}\t{{.Status}}'
```
```text
Cannot connect to the Docker daemon at unix:///Users/renantebarcoma/.docker/run/docker.sock. Is the docker daemon running?
```
Exit code 1. Docker was not started: a read-only audit must not start application services or workflows as a side effect. No image inspection, fresh Docker/frontend build, runtime probe, Compose up/down or migration-status command was executed. Existing runtime success in older entries remains point-in-time evidence.

Static Docker/build findings from `Dockerfile.ml`, `compose.yaml`, vendored Sail runtime and dependency manifests:
```text
Root .dockerignore present: False
Dockerfile-specific ignore present: False
ML requirements lines: numpy / pandas / scikit-learn / joblib / xgboost (unpinned)
Vite lock requirement: {'node': '^20.19.0 || >=22.12.0'}
```
The Dockerfile requires the separate `sail-8.5/app` base and copies only `requirements-ml.txt`; the vendored base Dockerfile requires explicit `WWWGROUP`. Root Compose uses a source bind mount and persistent development MySQL volume. No context ignore protects environment/private data eligible under `context: .`; this is not a claim that the explicit requirements-only COPY baked secrets into an image. A fresh, reviewed build/runtime identity and an explicit staging/production packaging plan remain required; no Dockerfiles, dependency pins or services were changed.

Existing frontend-build inspection:
```text
Existing Vite manifest entries: 70 missing referenced files: 0
public/build/manifest.json 2026-10-22T18:46:18+08:00
resources/js/lib/dss-evidence.ts 2026-10-23T21:59:23+08:00
resources/js/pages/Admin/DecisionSupport.tsx 2026-10-23T21:59:23+08:00
resources/js/pages/User/Dashboard.tsx 2026-10-23T22:03:38+08:00
Existing Vite manifest mtime predates DSS helper: True
Built JS marker 'Historical / unqualified evidence.' matching files: 0
Built JS marker 'dss_evidence_qualification_v1' matching files: 0
Built JS marker 'dss_evidence' matching files: 0
Built JS files inspected: 69
```
The manifest's existence and referenced-file completeness do not establish current compiled UI. The candidate needs a fresh container-side asset build; the existing `public/build` was not overwritten.

Migration inventory:
```text
Tracked current migrations: 14
Working tree migration files: 16
Untracked migrations:
database/migrations/2026_10_21_000001_make_processed_lags_nullable.php
database/migrations/2026_10_23_000001_add_forecast_artifact_provenance.php
```
The first makes `lag_1`/`lag_2` nullable; the second adds nullable `artifact_id`, `dataset_hash`, `training_cutoff` and `evaluation_protocol` to issued forecasts. Last confirmed development status is nullable lags applied and 5c provenance Pending. **Current/target status was not re-queried:** Docker access is unavailable and no database connection was attempted. No migration was applied/rolled back. Before future issuance, verify both ledger entries and actual column definitions; separately authorize deployment with backup/rollback ownership. A NOT NULL rollback cannot preserve null lags automatically, and dropping provenance columns loses recorded metadata.

CI static review (`.github/workflows/tests.yml`, `lint.yml`, `phpunit.xml`, `.env.example`, `config/database.php`, `config/services.php` and real Python integration tests):
- Existing tests matrix PHP 8.3/8.4/8.5 is compatible with the recorded PHP constraints; Node 22 is declared. Both Composer/npm lockfiles are tracked, but CI uses `npm i` and has no Python lock.
- CI builds assets and runs Pest, but does not provision the configured `/opt/ml-venv/bin/python` or run standalone Python/frontend/TypeScript checks.
- The copied environment example defaults to SQLite, while phpunit sets `DB_DATABASE=testing`; no testing SQLite file, explicit `:memory:` override or MySQL service is configured. Real preprocessing tests use the configured Python path. These are static setup/parity gaps, not a newly executed CI failure.
- Lint workflow runs formatting/lint fixes rather than check-only gates, so its success is not an assertion that the original candidate is formatted/lint-clean. No workflow was modified or triggered by this audit.

Fresh preservation checks against `/tmp/capstone-release-audit-before.json`:
```text
Source/config paths unchanged: 343/343
Protected data/artifact paths unchanged: 41/41
Git HEAD/refs/config unchanged: yes
Environment and Vite manifest unchanged: yes
Staged diff empty: True
Documentation-only audit changes
```
The current filesystem pointer still records `artifact_id a1409a54…`, `trained_at 2026-10-23 14:10:20 UTC`, 11 generation directories and empty staging; this is a read-only filesystem observation, not a database/workflow verification or D2 authorization. `git diff --check` passed.

**Remaining limitations or follow-up tasks.** The shortest safe path is documented in CURRENT.md §1.16: (1) separately authorize focused release hygiene/CI/build preparation and review all intended source/data inclusions before any requested branch/commit; (2) build/test the exact candidate in disposable isolated environments with explicit Python/SQLite/MySQL configuration and recorded runtime/build identity; (3) read target migration/schema status and obtain separate deployment authorization; (4) obtain approved source evidence and expressly authorize a concrete staging acceptance workflow under D2. Keep D3 records intact/excluded/qualified until a separate disposition; deletion is not a prerequisite for isolated build/tests. Keep 6b Blocked with `Not assessed`; do not invent thresholds, promote a model or select a protocol. The audit did not resolve any team decision or authorize any next implementation/live action. Docker/build/schema/CI verification gaps and original-source/accuracy limits remain open. No fresh test count, release approval or secret/data-clearance guarantee is claimed.
