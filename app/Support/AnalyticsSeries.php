<?php

namespace App\Support;

use App\Models\ProcessedRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class AnalyticsSeries
{
    public static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    /** Read fitted values only through the existing validated, locked accessor. */
    public static function historicalFits(): Collection
    {
        return app(MlWorkflow::class)->withValidatedArtifact(function (array $artifact) {
            $result = Process::path(base_path('Python'))->run([
                config('services.ml.python'), 'predict_history.py',
                Storage::path($artifact['dataset_path']), Storage::path($artifact['model_path']),
            ]);
            $rows = $result->failed() ? null : json_decode(trim($result->output()), true);
            if (! is_array($rows)) {
                return collect();
            }

            return collect($rows)->filter(fn ($row) => is_array($row) && is_string($row['period'] ?? null))
                ->map(fn ($row) => [
                    'period' => $row['period'],
                    'fitted' => self::number($row['fitted'] ?? null),
                    'provenance' => [
                        'kind' => 'in_sample_fit', 'source' => 'published_model',
                        'artifact_id' => $artifact['artifact_id'],
                        'dataset_hash' => $artifact['dataset_hash'],
                        'training_cutoff' => $artifact['training_cutoff'],
                    ],
                ])->keyBy('period');
        }) ?? collect();
    }

    public static function rows(Collection $actuals, Collection $forecasts, Collection $fits): Collection
    {
        return $actuals->keys()->merge($forecasts->keys())->unique()->sort()->values()
            ->map(function ($period) use ($actuals, $forecasts, $fits) {
                $actual = $actuals->get($period);
                $forecast = $forecasts->get($period);
                $fit = $actual ? $fits->get($period) : null;

                return [
                    'period' => $period, 'label' => $period,
                    'year' => (int) substr($period, 0, 4), 'month' => (int) substr($period, 5, 2),
                    'actual' => self::number($actual?->consumption_kwh),
                    'fitted' => self::number($fit['fitted'] ?? null),
                    'issued_forecast' => self::number($forecast?->predicted_consumption_kwh),
                    'issuance_label' => $forecast?->issuance_label,
                    'provenance' => [
                        'actual' => $actual ? ['kind' => 'actual', 'source' => 'processed_dataset', 'record_id' => $actual->id] : null,
                        'fitted' => $fit['provenance'] ?? null,
                        // Only facts stored at issuance, never today's pointer.
                        'issued_forecast' => $forecast ? [
                            'kind' => 'issued_forecast', 'source' => 'saved_forecast_record',
                            'record_id' => $forecast->id, 'model_type' => $forecast->model_type,
                            'predicted_at' => $forecast->predicted_at?->toISOString(),
                        ] + $forecast->artifact_provenance : null,
                    ],
                ];
            });
    }

    /** These summaries intentionally cover all available actuals, not chart filters. */
    public static function summary(): array
    {
        $highest = ProcessedRecord::orderByDesc('consumption_kwh')->first();
        $rounded = fn ($value) => ($value = self::number($value)) === null ? null : round($value, 2);

        return [
            'average_consumption' => $rounded(ProcessedRecord::avg('consumption_kwh')),
            'highest_consumption' => $rounded($highest?->consumption_kwh),
            'highest_consumption_year' => $highest?->year,
            'highest_consumption_month' => $highest?->month,
            'average_peak_demand' => $rounded(ProcessedRecord::avg('peak_demand_kw')),
            'average_solar_irradiance' => $rounded(ProcessedRecord::avg('solar_irradiance')),
        ];
    }
}
