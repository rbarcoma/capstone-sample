<?php

namespace App\Support;

use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MlWorkflow
{
    private const DATASET_PATH = 'ml/processed_dataset.csv';

    private const STATE_PATH = 'ml/model_state.json';

    private const EVALUATION_PROTOCOL = 'chronological_holdout_25pct_rolling_cv_v1';

    private static array $activeLocks = [];

    public function trainIfDatasetChanged(?int $userId = null, bool $force = false): array
    {
        return $this->withArtifactLock(fn () => $this->trainLocked($userId, $force));
    }

    /**
     * Read without training, under an exclusive lock for the entire callback.
     * A v2 read creates ml/workflow.lock if absent and serializes with other
     * readers/writers. Nested workflow calls sharing that lock are rejected.
     */
    public function withValidatedArtifact(callable $reader): mixed
    {
        // A read must not train or migrate legacy state, even to obtain a lock.
        if (! Storage::exists(self::DATASET_PATH)) {
            return $this->refuseHistoricalRead('missing_export');
        }
        if (! Storage::exists(self::STATE_PATH)) {
            return $this->refuseHistoricalRead('missing_state');
        }
        if (($this->modelState()['format_version'] ?? null) !== 2) {
            return $this->refuseHistoricalRead('invalid_or_legacy_state');
        }

        return $this->withArtifactLock(function () use ($reader) {
            $state = $this->validatedArtifact();
            if ($state === null) {
                return null;
            }
            $result = $reader(array_merge($state, ['dataset_path' => self::DATASET_PATH]));

            // Discard the result if an external writer changed the validated inputs.
            $after = $this->validatedArtifact();
            if ($after === null) {
                return null;
            }

            return $after === $state ? $result : $this->refuseHistoricalRead('artifact_changed_during_read');
        });
    }

    private function validatedArtifact(): ?array
    {
        if (! Storage::exists(self::DATASET_PATH)) {
            return $this->refuseHistoricalRead('missing_export');
        }
        $state = $this->modelState();
        if (DatasetIntegrity::managed() && DatasetIntegrity::currentHash() !== ($state['dataset_hash'] ?? null)) {
            return $this->refuseHistoricalRead('active_dataset_mismatch');
        }
        if (! $this->canonicalGeneration($state, published: true)) {
            return $this->refuseHistoricalRead('non_canonical_generation');
        }
        $latest = ProcessedRecord::query()->orderByDesc('year')->orderByDesc('month')->first();
        if (! $latest) {
            return $this->refuseHistoricalRead('empty_dataset');
        }
        $cutoff = sprintf('%04d-%02d', $latest->year, $latest->month);
        $publishedCutoff = $state['training_cutoff'] ?? null;
        if (is_string($publishedCutoff) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $publishedCutoff)
            && $cutoff > $publishedCutoff) {
            return $this->refuseHistoricalRead('database_ahead_of_artifact', [
                'published_cutoff' => $publishedCutoff, 'current_cutoff' => $cutoff,
            ]);
        }
        $identity = $this->artifactIdentity(
            hash_file('sha256', Storage::path(self::DATASET_PATH)),
            $cutoff
        );

        if (! $this->matchesIdentity($identity, $state)) {
            return $this->refuseHistoricalRead('identity_mismatch');
        }

        return $this->validArtifactPair($state) ? $state : $this->refuseHistoricalRead('invalid_artifact_pair');
    }

    private function refuseHistoricalRead(string $reason, array $context = []): null
    {
        Log::warning('Historical ML predictions unavailable.', ['reason' => $reason] + $context);

        return null;
    }

    private function matchesIdentity(array $identity, array $state): bool
    {
        foreach ($identity as $key => $value) {
            if (($state[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function trainLocked(?int $userId, bool $force = false): array
    {
        if (DatasetIntegrity::managed() && DatasetIntegrity::currentHash() === null) {
            throw new RuntimeException('The active dataset must be completely preprocessed before training.');
        }
        $cutoff = $this->exportProcessedDataset();
        $hash = hash_file('sha256', Storage::path(self::DATASET_PATH));
        $identity = $this->artifactIdentity($hash, $cutoff);
        $state = $this->modelState();
        if (! $force && $this->matchesIdentity($identity, $state) && $this->validArtifactPair($state)) {
            return [
                'trained' => false,
                'dataset_hash' => $hash,
                'reason' => 'No new or updated processed dataset detected.',
            ];
        }

        $generation = $identity['artifact_id'].'_'.Str::uuid();
        $staging = 'ml/.staging/'.$generation;
        $destination = 'ml/models/'.$generation;
        $temporaryState = 'ml/.model_state_'.Str::uuid().'.json';
        $published = false;
        $moved = false;

        try {
            $result = $this->runPython('train_model.py', $staging.'/model.pkl', $staging.'/metrics.json');

            if ($result->failed()) {
                throw new RuntimeException($result->errorOutput() ?: $result->output());
            }

            $pair = [
                'model_path' => $staging.'/model.pkl',
                'metrics_path' => $staging.'/metrics.json',
            ];
            $this->assertArtifactFiles($pair);
            $pair['model_sha256'] = hash_file('sha256', Storage::path($pair['model_path']));
            $pair['metrics_sha256'] = hash_file('sha256', Storage::path($pair['metrics_path']));

            if ($identity !== $this->artifactIdentity(hash_file('sha256', Storage::path(self::DATASET_PATH)), $cutoff)) {
                throw new RuntimeException('Training inputs or configuration changed during training. Retry training.');
            }

            Storage::makeDirectory('ml/models');
            if (! File::move(Storage::path($staging), Storage::path($destination))) {
                throw new RuntimeException('Unable to publish the trained artifact pair. Retry training.');
            }
            $moved = true;

            $state = array_merge($identity, $pair, [
                'model_path' => $destination.'/model.pkl',
                'metrics_path' => $destination.'/metrics.json',
                'trained_at' => now()->toDateTimeString(),
                'trained_by' => $userId,
            ]);
            if (! Storage::put($temporaryState, json_encode($state, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Unable to write model state. Previous artifacts remain active.');
            }
            $this->assertArtifactPair($state);

            // Do fallible work before committing the only live pointer.
            AuditLogger::log(
                'ML Forecasting',
                $force ? 'Train Model' : 'Auto Train Model',
                'Trained the machine learning model using the latest processed dataset.'
            );
            if (! File::move(Storage::path($temporaryState), Storage::path(self::STATE_PATH))) {
                throw new RuntimeException('Unable to publish model state. Previous artifacts remain active.');
            }
            $published = true;

            return ['trained' => true, 'dataset_hash' => $hash, 'output' => $result->output()];
        } finally {
            Storage::deleteDirectory($staging);
            Storage::delete($temporaryState);
            if ($moved && ! $published) {
                Storage::deleteDirectory($destination);
            }
        }
    }

    public function predictAndGenerate(?int $userId = null, string $source = 'manual', bool $generateReport = true): array
    {
        $period = ForecastPeriod::next();
        if (! $period) {
            throw new RuntimeException('Complete monthly consumption data is required for forecasting.');
        }

        [$result, $provenance] = $this->withArtifactLock(function () use ($userId) {
            $this->trainLocked($userId);
            $state = $this->modelState();
            $this->assertArtifactPair($state, published: true);
            $latest = ProcessedRecord::query()->orderByDesc('year')->orderByDesc('month')->first();
            if (! $latest || ! Storage::exists(self::DATASET_PATH)
                || ! $this->matchesIdentity($this->artifactIdentity(
                    hash_file('sha256', Storage::path(self::DATASET_PATH)),
                    sprintf('%04d-%02d', $latest->year, $latest->month)
                ), $state)) {
                throw new RuntimeException('identity_mismatch: Published artifact does not match current forecasting inputs. Run Train Model again.');
            }

            // Bind to the validated pair consumed by this prediction, even if
            // the publication pointer changes before forecast persistence.
            return [
                $this->predictWithArtifactCopies($state),
                array_intersect_key($state, array_flip(ForecastResult::ARTIFACT_PROVENANCE_FIELDS)),
            ];
        });

        if ($result->failed()) {
            throw new RuntimeException($result->errorOutput() ?: $result->output());
        }

        $data = json_decode(trim($result->output()), true);

        if (! $data) {
            throw new RuntimeException('Invalid JSON output from Python prediction script.');
        }

        if ((int) ($data['year'] ?? 0) !== $period->year || (int) ($data['month'] ?? 0) !== $period->month) {
            throw new RuntimeException('Prediction period must immediately follow the latest complete actual month.');
        }

        $generatedAt = now();

        $forecast = ForecastResult::updateOrCreate([
            'year' => $data['year'],
            'month' => $data['month'],
        ], [
            'user_id' => $userId,
            'predicted_at' => $generatedAt,
            'predicted_consumption_kwh' => $data['predicted_consumption_kwh'],
            'previous_consumption_kwh' => $data['previous_consumption_kwh'] ?? null,
            'change_percent' => $data['change_percent'] ?? null,
            'mae' => $data['mae'] ?? null,
            'rmse' => $data['rmse'] ?? null,
            'r2_score' => $data['r2_score'] ?? null,
            'model_type' => $data['model_type'] ?? 'Ridge (penalty 0.1)',
        ] + $provenance);

        $dss = EnergyDss::generateForForecast($forecast, $userId, $generatedAt);
        $report = $generateReport ? ReportGenerator::create($userId, $source) : null;

        AuditLogger::log(
            'Forecasting',
            $source === 'manual' ? 'Generate Prediction' : 'Auto Generate Prediction',
            'Generated next-month prediction for '.
                $forecast->year.'-'.
                str_pad((string) $forecast->month, 2, '0', STR_PAD_LEFT).
                ': '.$forecast->predicted_consumption_kwh.' kWh.'
        );

        AuditLogger::log(
            'Decision Support',
            $source === 'manual' ? 'Auto Generate DSS Result' : 'System Generate DSS Result',
            'Generated DSS result: '.$dss->demand_status.' and '.$dss->readiness_level.'.'
        );

        if ($report instanceof Report) {
            AuditLogger::log(
                'Reports',
                $source === 'manual' ? 'Generate Report' : 'Auto Generate Report',
                'Generated report: '.$report->title.'.'
            );
        }

        return [
            'forecast' => $forecast,
            'dss' => $dss,
            'report' => $report,
        ];
    }

    public function runAfterDatasetUpload(?int $userId = null): array
    {
        $training = $this->trainIfDatasetChanged($userId);

        if (! $training['trained']) {
            return [
                'trained' => false,
                'predicted' => false,
                'message' => $training['reason'],
            ];
        }

        $result = $this->predictAndGenerate($userId, 'dataset_upload');

        return [
            'trained' => true,
            'predicted' => true,
            'forecast' => $result['forecast'],
            'dss' => $result['dss'],
            'report' => $result['report'],
        ];
    }

    public function runScheduledMonthlyPrediction(): array
    {
        $this->trainIfDatasetChanged(null);

        return $this->predictAndGenerate(null, 'monthly_cron');
    }

    private function withArtifactLock(callable $callback): mixed
    {
        Storage::makeDirectory('ml');
        $path = Storage::path('ml/workflow.lock');
        $key = (realpath(dirname($path)) ?: dirname($path)).DIRECTORY_SEPARATOR.basename($path);
        if (isset(self::$activeLocks[$key])) {
            throw new RuntimeException('Re-entrant ML workflow calls are not allowed while the current operation holds the lock.');
        }
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the ML workflow lock. Retry training.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the ML workflow. Retry training.');
            }
            self::$activeLocks[$key] = true;

            return $callback();
        } finally {
            unset(self::$activeLocks[$key]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function exportProcessedDataset(): string
    {
        $records = ProcessedRecord::query()
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        if ($records->count() < 6) {
            throw new RuntimeException('At least 6 processed records are required for forecasting.');
        }

        Storage::makeDirectory('ml');

        $contents = ProcessedDataset::contents($records);
        $temporary = 'ml/.processed_dataset_'.Str::uuid().'.csv';
        try {
            if (! Storage::put($temporary, $contents)
                || hash_file('sha256', Storage::path($temporary)) !== hash('sha256', $contents)) {
                throw new RuntimeException('Unable to export the complete processed dataset. Retry training.');
            }
            if (! File::move(Storage::path($temporary), Storage::path(self::DATASET_PATH))) {
                throw new RuntimeException('Unable to publish the processed dataset. Retry training.');
            }
        } finally {
            Storage::delete($temporary);
        }

        return sprintf('%04d-%02d', $records->last()->year, $records->last()->month);
    }

    private function runPython(string $script, string $modelPath, string $metricsPath)
    {
        $python = config('services.ml.python');

        Storage::makeDirectory(dirname($modelPath));
        Storage::makeDirectory(dirname($metricsPath));

        return Process::path(base_path('Python'))->run([
            $python,
            $script,
            Storage::path(self::DATASET_PATH),
            Storage::path($modelPath),
            Storage::path($metricsPath),
        ]);
    }

    private function modelState(): array
    {
        if (! Storage::exists(self::STATE_PATH)) {
            return [];
        }

        $state = json_decode(Storage::get(self::STATE_PATH), true);

        return is_array($state) ? $state : [];
    }

    private function featureSignature(): string
    {
        return hash_file('sha256', base_path('Python/monthly_features.py'));
    }

    private function artifactIdentity(string $hash, string $cutoff): array
    {
        // All production hyperparameters/defaults live in this trainer's source.
        $trainer = hash_file('sha256', base_path('Python/train_model.py'));
        $identity = [
            'format_version' => 2,
            'dataset_hash' => $hash,
            'feature_signature' => $this->featureSignature(),
            'trainer_signature' => hash('sha256', json_encode([
                'source_sha256' => $trainer,
                'metric_definitions_sha256' => hash_file('sha256', base_path('resources/forecast-metrics.json')),
                'python' => config('services.ml.python'),
                'evaluation_protocol' => self::EVALUATION_PROTOCOL,
            ], JSON_THROW_ON_ERROR)),
            'training_cutoff' => $cutoff,
            'evaluation_protocol' => self::EVALUATION_PROTOCOL,
        ];

        return $identity + ['artifact_id' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))];
    }

    private function assertArtifactFiles(array $state): void
    {
        foreach (['model_path', 'metrics_path'] as $key) {
            $path = $state[$key] ?? null;
            if (! is_string($path) || $path === '' || ! Storage::exists($path) || Storage::size($path) === 0) {
                throw new RuntimeException('Model/metrics pair is missing or empty. Run Train Model again.');
            }
        }
        $metrics = json_decode(Storage::get($state['metrics_path']), true);
        if (! is_array($metrics) || ! is_string($metrics['model_type'] ?? null) || $metrics['model_type'] === '') {
            throw new RuntimeException('Model metrics are invalid. Run Train Model again.');
        }
        foreach (['mae', 'rmse', 'r2_score'] as $key) {
            if (! array_key_exists($key, $metrics) || ($metrics[$key] !== null
                && (! is_numeric($metrics[$key]) || ! is_finite((float) $metrics[$key])))) {
                throw new RuntimeException('Model metrics are invalid. Run Train Model again.');
            }
        }
    }

    /**
     * Published paths must be exact canonical names, with no symlink redirection.
     * The v2 state is the publication pointer; staging copies are checked separately.
     */
    private function canonicalGeneration(array $state, bool $published = false): bool
    {
        $id = $state['artifact_id'] ?? null;
        $model = $state['model_path'] ?? null;
        $metrics = $state['metrics_path'] ?? null;
        if (! is_string($id) || ! preg_match('/^[0-9a-f]{64}$/D', $id)
            || ! is_string($model) || ! is_string($metrics)
            || ! preg_match('#^ml/models/'.$id.'_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/model\\.pkl$#D', $model)
            || $metrics !== dirname($model).'/metrics.json') {
            return false;
        }
        clearstatcache(true);
        $root = realpath(Storage::path(''));
        foreach ([$model, $metrics] as $path) {
            $resolved = realpath(Storage::path($path));
            if ($resolved !== false && $resolved !== $root.'/'.$path) {
                return false;
            }
        }
        if ($published) {
            $pointer = $this->modelState();
            if ($id !== ($pointer['artifact_id'] ?? null)
                || $model !== ($pointer['model_path'] ?? null) || $metrics !== ($pointer['metrics_path'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function assertArtifactPair(array $state, bool $temporary = false, bool $published = false): void
    {
        if (! $temporary && ! $this->canonicalGeneration($state, $published)) {
            throw new RuntimeException('non_canonical_generation: Model/metrics paths must name one canonical published generation. Run Train Model again.');
        }
        $this->assertArtifactFiles($state);
        $directory = dirname($state['model_path']);
        if ($directory !== dirname($state['metrics_path'])
            || basename($state['model_path']) !== 'model.pkl' || basename($state['metrics_path']) !== 'metrics.json') {
            throw new RuntimeException('Model/metrics pair belongs to a different artifact generation. Run Train Model again.');
        }
        foreach (['model', 'metrics'] as $name) {
            $expected = $state[$name.'_sha256'] ?? '';
            if (! is_string($expected) || ! hash_equals($expected, hash_file('sha256', Storage::path($state[$name.'_path'])))) {
                throw new RuntimeException('Model/metrics pair does not match its published state. Run Train Model again.');
            }
        }
    }

    private function validArtifactPair(array $state): bool
    {
        try {
            $this->assertArtifactPair($state);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function predictWithArtifactCopies(array $state)
    {
        $directory = 'ml/.staging/prediction_'.Str::uuid();
        $copies = $state;
        try {
            Storage::makeDirectory($directory);
            foreach (['model' => 'pkl', 'metrics' => 'json'] as $name => $extension) {
                $copies[$name.'_path'] = $directory.'/'.$name.'.'.$extension;
                if (! Storage::copy($state[$name.'_path'], $copies[$name.'_path'])) {
                    throw new RuntimeException('Unable to stage the prediction artifacts. Retry prediction.');
                }
            }
            $this->assertArtifactPair($copies, temporary: true);
            // Python's legacy auto-training fallback must never overwrite a live generation.
            $result = $this->runPython('predict_next_month.py', $copies['model_path'], $copies['metrics_path']);
            $this->assertArtifactPair($copies, temporary: true);

            return $result;
        } finally {
            Storage::deleteDirectory($directory);
        }
    }
}
