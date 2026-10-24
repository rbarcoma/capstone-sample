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
            ? $latestProcessed->year.'-'.str_pad((string) $latestProcessed->month, 2, '0', STR_PAD_LEFT)
            : null;
        $forecastPeriod = $forecast
            ? $forecast->year.'-'.str_pad((string) $forecast->month, 2, '0', STR_PAD_LEFT)
            : null;
        $rounded = fn ($value) => ($value = AnalyticsSeries::number($value)) === null ? null : round($value, 2);
        $averageConsumption = $rounded(ProcessedRecord::avg('consumption_kwh'));
        $basis = DssEvidence::inspect($forecast, $latestProcessed, $dss);
        [$recommendations, $actions] = DssEvidence::guidance();
        // Qualify this new snapshot; never update the saved DSS or old reports.
        $dssSnapshot = $dss ? array_replace($dss->toArray(), [
            'demand_status' => DssEvidence::NOT_ASSESSED, 'readiness_level' => DssEvidence::NOT_ASSESSED,
            'recommendations' => $recommendations, 'priority_actions' => $actions,
            'basis' => $basis + ['source' => 'report_time_qualification', 'stored_assessment_id' => $dss->id],
        ]) : null;
        $r2Score = ForecastMetrics::rounded($forecast?->r2_score);

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
                    'latest_consumption_kwh' => $rounded($latestProcessed?->consumption_kwh),
                    'average_consumption_kwh' => $averageConsumption,
                    'highest_consumption_kwh' => $rounded(ProcessedRecord::max('consumption_kwh')),
                    'average_peak_demand_kw' => $rounded(ProcessedRecord::avg('peak_demand_kw')),
                    'average_solar_irradiance' => $rounded(ProcessedRecord::avg('solar_irradiance')),
                ],
                'latest_forecast' => $forecast,
                'latest_dss' => $dssSnapshot,
                'forecast_accuracy' => [
                    'mae' => ForecastMetrics::rounded($forecast?->mae),
                    'rmse' => ForecastMetrics::rounded($forecast?->rmse),
                    'r2_score' => $r2Score,
                    'metric_definitions' => ForecastMetrics::definitions(),
                    'interpretation' => DssEvidence::interpretation($basis),
                ],
                'prediction_explanation' => [
                    'model' => $forecast?->model_type ?? 'Ridge (penalty 0.1)',
                    'forecast_period' => $forecastPeriod,
                    'description' => 'The system uses Ridge (penalty 0.1). Daily uploaded records are aggregated into monthly records, preprocessing creates lag, trend, and seasonal features, and the trained coefficients are applied to the next-month feature values. The sum of the intercept and weighted feature values becomes the predicted monthly consumption.',
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
                    'rule_version' => DssEvidence::RULE_VERSION,
                    'basis' => $basis,
                    'demand_reason' => DssEvidence::demandReason($basis),
                    'readiness_reason' => DssEvidence::readinessReason($basis),
                ],
                'statuses' => [
                    'data_status' => $processedCount > 0 ? 'Preprocessed' : 'Pending',
                    'forecast_status' => $forecast ? 'Completed' : 'Pending',
                    'dss_status' => $dss ? 'Generated' : 'Pending',
                    'model_used' => $forecast?->model_type ?? 'No model',
                ],
                'report_basis' => 'This report records available dataset, forecast and saved-assessment evidence at generation time. Demand and readiness are not assessed because the verified unit/threshold and feasibility basis is insufficient; saved assessments are qualified in this new snapshot only.',
            ],
        ]);
    }
}
