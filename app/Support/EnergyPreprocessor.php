<?php

namespace App\Support;

use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\ProcessedRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class EnergyPreprocessor
{
    public static function run(): int
    {
        $consumption = ConsumptionRecord::query()
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $climate = ClimateRecord::query()
            ->get()
            ->keyBy(fn ($record) => "{$record->year}-{$record->month}");

        if ($consumption->count() < 3) {
            throw new RuntimeException('At least 3 monthly consumption records are required.');
        }

        $rows = [];

        foreach ($consumption as $record) {
            $climateRecord = $climate->get("{$record->year}-{$record->month}");

            $rows[] = [
                'year' => $record->year,
                'month' => $record->month,
                'consumption_kwh' => (float) $record->consumption_kwh,
                'temperature' => (float) ($climateRecord?->temperature ?? 0),
                'humidity' => (float) ($climateRecord?->humidity ?? 0),
                'rainfall' => (float) ($climateRecord?->rainfall ?? 0),
                'solar_irradiance' => (float) ($climateRecord?->solar_irradiance ?? 0),
                'peak_demand_kw' => (float) ($record->peak_demand_kw ?? 0),
            ];
        }

        // One canonical builder supplies calendar features to PHP and all ML paths.
        // Missing lags remain null; actual records stay available to Analytics and
        // period selection, while Python excludes them from supervised fitting.
        $result = Process::path(base_path('Python'))
            ->input(json_encode($rows, JSON_THROW_ON_ERROR))
            ->run([config('services.ml.python'), 'monthly_features.py']);

        if ($result->failed()) {
            throw new RuntimeException($result->errorOutput() ?: $result->output());
        }

        $rows = json_decode($result->output(), true);
        if (! is_array($rows) || count($rows) !== $consumption->count()) {
            throw new RuntimeException('Invalid monthly feature output from Python.');
        }

        DB::transaction(function () use ($rows) {
            ProcessedRecord::query()->delete();
            foreach ($rows as $row) {
                ProcessedRecord::create($row);
            }
        });

        return count($rows);
    }
}
