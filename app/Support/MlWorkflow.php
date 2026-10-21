<?php

namespace App\Support;

use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MlWorkflow
{
    private const DATASET_PATH = 'ml/processed_dataset.csv';
    private const STATE_PATH = 'ml/model_state.json';

    public function trainIfDatasetChanged(?int $userId = null, bool $force = false): array
    {
        $this->exportProcessedDataset();

        $hash = hash_file('sha256', Storage::path(self::DATASET_PATH));
        $state = $this->modelState();
        $modelPath = $this->modelPathForHash($hash);
        $metricsPath = $this->metricsPathForHash($hash);
        $modelExists = Storage::exists($modelPath);
        $changed = ($state['dataset_hash'] ?? null) !== $hash;

        if (! $force && ! $changed && $modelExists) {
            return [
                'trained' => false,
                'dataset_hash' => $hash,
                'reason' => 'No new or updated processed dataset detected.',
            ];
        }

        $result = $this->runPython('train_model.py', $modelPath, $metricsPath);

        if ($result->failed()) {
            throw new RuntimeException($result->errorOutput() ?: $result->output());
        }

        Storage::put(self::STATE_PATH, json_encode([
            'dataset_hash' => $hash,
            'model_path' => $modelPath,
            'metrics_path' => $metricsPath,
            'trained_at' => now()->toDateTimeString(),
            'trained_by' => $userId,
        ]));

        AuditLogger::log(
            'ML Forecasting',
            $force ? 'Train Model' : 'Auto Train Model',
            'Trained the machine learning model using the latest processed dataset.'
        );

        return [
            'trained' => true,
            'dataset_hash' => $hash,
            'output' => $result->output(),
        ];
    }

    public function predictAndGenerate(?int $userId = null, string $source = 'manual', bool $generateReport = true): array
    {
        $period = ForecastPeriod::next();
        if (! $period) {
            throw new RuntimeException('Complete monthly consumption data is required for forecasting.');
        }

        $this->ensureModelAvailable($userId);

        $state = $this->modelState();
        $modelPath = $state['model_path'] ?? null;
        $metricsPath = $state['metrics_path'] ?? null;

        if (! $modelPath || ! Storage::exists($modelPath)) {
            $training = $this->trainIfDatasetChanged($userId, true);
            $state = $this->modelState();
            $modelPath = $state['model_path'] ?? $this->modelPathForHash($training['dataset_hash']);
            $metricsPath = $state['metrics_path'] ?? $this->metricsPathForHash($training['dataset_hash']);
        }

        $result = $this->runPython('predict_next_month.py', $modelPath, $metricsPath);

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
            'model_type' => $data['model_type'] ?? 'Linear Regression',
        ]);

        $dss = EnergyDss::generateForForecast($forecast, $userId, $generatedAt);
        $report = $generateReport ? ReportGenerator::create($userId, $source) : null;

        AuditLogger::log(
            'Forecasting',
            $source === 'manual' ? 'Generate Prediction' : 'Auto Generate Prediction',
            'Generated next-month prediction for ' .
                $forecast->year . '-' .
                str_pad((string) $forecast->month, 2, '0', STR_PAD_LEFT) .
                ': ' . $forecast->predicted_consumption_kwh . ' kWh.'
        );

        AuditLogger::log(
            'Decision Support',
            $source === 'manual' ? 'Auto Generate DSS Result' : 'System Generate DSS Result',
            'Generated DSS result: ' . $dss->demand_status . ' and ' . $dss->readiness_level . '.'
        );

        if ($report instanceof Report) {
            AuditLogger::log(
                'Reports',
                $source === 'manual' ? 'Generate Report' : 'Auto Generate Report',
                'Generated report: ' . $report->title . '.'
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

    private function ensureModelAvailable(?int $userId = null): void
    {
        $this->exportProcessedDataset();

        $state = $this->modelState();
        $modelPath = $state['model_path'] ?? null;

        if (! $modelPath || ! Storage::exists($modelPath)) {
            $this->trainIfDatasetChanged($userId, true);
        }
    }

    private function exportProcessedDataset(): void
    {
        $records = ProcessedRecord::query()
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        if ($records->count() < 6) {
            throw new RuntimeException('At least 6 processed records are required for forecasting.');
        }

        Storage::makeDirectory('ml');

        $headers = [
            'year',
            'month',
            'consumption_kwh',
            'temperature',
            'humidity',
            'rainfall',
            'solar_irradiance',
            'peak_demand_kw',
            'lag_1',
            'lag_2',
            'trend',
            'month_sin',
            'month_cos',
        ];

        $lines = [implode(',', $headers)];

        foreach ($records as $record) {
            $lines[] = implode(',', array_map(
                fn ($field) => $record->{$field},
                $headers
            ));
        }

        Storage::put(self::DATASET_PATH, implode(PHP_EOL, $lines));
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

        return json_decode(Storage::get(self::STATE_PATH), true) ?: [];
    }

    private function modelPathForHash(string $hash): string
    {
        return 'ml/models/qc_energy_model_' . substr($hash, 0, 16) . '.pkl';
    }

    private function metricsPathForHash(string $hash): string
    {
        return 'ml/models/metrics_' . substr($hash, 0, 16) . '.json';
    }
}
