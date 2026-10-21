<?php

namespace App\Support;

use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\Dataset;
use App\Models\ProcessedRecord;
use App\Models\Report;

class ReportGenerator
{
    public static function create(?int $userId = null, string $source = 'manual'): Report
    {
        $forecast = ForecastPeriod::current()->latest('predicted_at')->first();
        $dss = ForecastPeriod::currentDss()->latest('updated_at')->first();
        $dataset = Dataset::with('user:id,name')->latest()->first();
        $latestProcessed = ProcessedRecord::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $processedCount = ProcessedRecord::count();
        $monthlyRecordCount = ConsumptionRecord::count();
        $latestPeriod = $latestProcessed
            ? $latestProcessed->year . '-' . str_pad((string) $latestProcessed->month, 2, '0', STR_PAD_LEFT)
            : null;
        $forecastPeriod = $forecast
            ? $forecast->year . '-' . str_pad((string) $forecast->month, 2, '0', STR_PAD_LEFT)
            : null;
        $averageConsumption = round((float) ProcessedRecord::avg('consumption_kwh'), 2);
        $r2Score = (float) ($forecast?->r2_score ?? 0);
        $demandRatio = $averageConsumption > 0 && $forecast
            ? round(((float) $forecast->predicted_consumption_kwh / $averageConsumption) * 100, 2)
            : null;

        return Report::create([
            'user_id' => $userId,
            'title' => 'Renewable Energy Transition Report - '.now()->format('Y-m-d H:i'),
            'type' => 'analytics_forecast_dss',
            'summary' => [
                'source' => $source,
                'dataset_processing' => [
                    'uploaded_dataset' => $dataset?->original_name,
                    'uploaded_by' => $dataset?->user?->name,
                    'uploaded_at' => $dataset?->created_at?->toDateTimeString(),
                    'raw_daily_records' => (int) ($dataset?->record_count ?? 0),
                    'monthly_records_generated' => $monthlyRecordCount,
                    'climate_records_matched' => ClimateRecord::query()
                        ->whereExists(function ($query) {
                            $query->selectRaw('1')
                                ->from('consumption_records')
                                ->whereColumn('consumption_records.year', 'climate_records.year')
                                ->whereColumn('consumption_records.month', 'climate_records.month');
                        })
                        ->count(),
                    'processed_records' => $processedCount,
                    'latest_processed_period' => $latestPeriod,
                    'preprocessing_status' => $processedCount > 0 ? 'Completed' : 'Not yet processed',
                ],
                'analytics' => [
                    'record_count' => $processedCount,
                    'latest_period' => $latestPeriod,
                    'latest_consumption_kwh' => round((float) ($latestProcessed?->consumption_kwh ?? 0), 2),
                    'average_consumption_kwh' => $averageConsumption,
                    'highest_consumption_kwh' => round((float) ProcessedRecord::max('consumption_kwh'), 2),
                    'average_peak_demand_kw' => round((float) ProcessedRecord::avg('peak_demand_kw'), 2),
                    'average_solar_irradiance' => round((float) ProcessedRecord::avg('solar_irradiance'), 2),
                ],
                'latest_forecast' => $forecast,
                'latest_dss' => $dss,
                'forecast_accuracy' => [
                    'mae' => round((float) ($forecast?->mae ?? 0), 4),
                    'rmse' => round((float) ($forecast?->rmse ?? 0), 4),
                    'r2_score' => round($r2Score, 4),
                    'interpretation' => $r2Score >= 0.80
                        ? 'The model explains a strong portion of the historical consumption pattern and is suitable for decision-support estimates.'
                        : ($r2Score >= 0.50
                            ? 'The model explains a moderate portion of the pattern. Results should be reviewed with operational context.'
                            : 'The model has limited explanatory strength. More data or improved features may be needed.'),
                ],
                'prediction_explanation' => [
                    'model' => $forecast?->model_type ?? 'Linear Regression',
                    'forecast_period' => $forecastPeriod,
                    'description' => 'The system uses Linear Regression. Daily uploaded records are aggregated into monthly records, preprocessing creates lag, trend, and seasonal features, and the trained coefficients are applied to the next-month feature values. The sum of the intercept and weighted feature values becomes the predicted monthly consumption.',
                    'features' => [
                        'year',
                        'month',
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
                    ],
                ],
                'dss_reasoning' => [
                    'demand_reason' => $demandRatio
                        ? 'The predicted consumption is ' . $demandRatio . '% of the historical average consumption, which supports the ' . ($dss?->demand_status ?? 'No demand status') . ' classification.'
                        : 'Demand reasoning is unavailable because forecast or average consumption data is incomplete.',
                    'readiness_reason' => 'Readiness is based on solar irradiance, peak demand, and predicted consumption. The current DSS result classified readiness as ' . ($dss?->readiness_level ?? 'No readiness assessment') . '.',
                ],
                'statuses' => [
                    'data_status' => $processedCount > 0 ? 'Preprocessed' : 'Pending',
                    'forecast_status' => $forecast ? 'Completed' : 'Pending',
                    'dss_status' => $dss ? 'Generated' : 'Pending',
                    'model_used' => $forecast?->model_type ?? 'No model',
                ],
                'report_basis' => 'This report is based on uploaded electricity and climate records, monthly aggregated preprocessing results, machine learning forecasting output, and decision support classification.',
            ],
        ]);
    }
}
