<?php

namespace App\Support;

use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\Dataset;
use App\Models\ProcessedRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DatasetIntegrity
{
    public const DIRECTORY = 'datasets/validated';

    public static function managed(): bool
    {
        return Dataset::where('path', 'like', self::DIRECTORY.'/%')->exists();
    }

    public static function actualRows(): array
    {
        $climate = ClimateRecord::get()->keyBy(fn ($row) => $row->year.'-'.$row->month);

        return ConsumptionRecord::orderBy('year')->orderBy('month')->get()->map(function ($row) use ($climate) {
            $context = $climate->get($row->year.'-'.$row->month);

            return ['year' => (int) $row->year, 'month' => (int) $row->month,
                'consumption_kwh' => $row->consumption_kwh === null ? null : (float) $row->consumption_kwh,
                'peak_demand_kw' => $row->peak_demand_kw === null ? null : (float) $row->peak_demand_kw,
                'temperature' => $context?->temperature === null ? null : (float) $context->temperature,
                'humidity' => $context?->humidity === null ? null : (float) $context->humidity,
                'rainfall' => $context?->rainfall === null ? null : (float) $context->rainfall,
                'solar_irradiance' => $context?->solar_irradiance === null ? null : (float) $context->solar_irradiance];
        })->all();
    }

    public static function monthlyHash(): string
    {
        return hash('sha256', json_encode(self::actualRows(), JSON_THROW_ON_ERROR));
    }

    /** No export, training, repair, or inference of legacy provenance. */
    public static function currentHash(): ?string
    {
        $consumption = ConsumptionRecord::get();
        $ids = $consumption->pluck('dataset_id')->unique()->values();
        if ($ids->count() !== 1 || ! $ids[0]) {
            return null;
        }
        $dataset = Dataset::find($ids[0]);
        if (! $dataset || ! self::sourceMatches($dataset)) {
            return null;
        }
        $processed = ProcessedRecord::orderBy('year')->orderBy('month')->get();
        $actuals = self::actualRows();
        if ($processed->count() !== count($actuals) || $processed->isEmpty()) {
            return null;
        }
        foreach ($processed as $index => $record) {
            foreach ($actuals[$index] as $field => $value) {
                if ($value === null || ! is_finite((float) $value) || (float) $record->{$field} !== (float) $value) {
                    return null;
                }
            }
        }

        return hash('sha256', ProcessedDataset::contents($processed));
    }

    public static function sourceMatches(Dataset $dataset): bool
    {
        if ($dataset->status !== 'valid' || ! str_starts_with($dataset->path, self::DIRECTORY.'/')
            || ! Storage::exists($dataset->path) || ! Storage::exists($dataset->path.'.json')) {
            return false;
        }
        $metadata = json_decode(Storage::get($dataset->path.'.json'), true);
        $consumption = ConsumptionRecord::get();
        $climate = ClimateRecord::get();
        try {
            return ($metadata['format_version'] ?? null) === 1
                && ($metadata['dataset_id'] ?? null) === $dataset->id
                && ($metadata['source_sha256'] ?? null) === hash_file('sha256', Storage::path($dataset->path))
                && ($metadata['monthly_sha256'] ?? null) === self::monthlyHash()
                && $consumption->isNotEmpty() && $climate->count() === $consumption->count()
                && $consumption->every(fn ($row) => (int) $row->dataset_id === $dataset->id)
                && $climate->every(fn ($row) => (int) $row->dataset_id === $dataset->id);
        } catch (\JsonException) {
            return false;
        }
    }

    public static function forecasts(Builder $query): Builder
    {
        if (! self::managed()) {
            return $query; // Legacy experiments are not assigned invented linkage.
        }
        $hash = self::currentHash();

        return $hash !== null && Schema::hasColumn('forecast_results', 'dataset_hash')
            ? $query->where('dataset_hash', $hash)
            : $query->whereRaw('1 = 0');
    }
}
