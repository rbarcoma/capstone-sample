<?php

namespace App\Support;

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ForecastPeriod
{
    // Processed records contain actual monthly totals from validated uploads.
    public static function next(): ?Carbon
    {
        $latest = ProcessedRecord::query()
            ->whereBetween('year', [1, 9998])
            ->whereBetween('month', [1, 12])
            ->where('consumption_kwh', '>=', 0)
            ->orderByDesc('year')->orderByDesc('month')->first();

        return $latest
            ? Carbon::createMidnightDate($latest->year, $latest->month, 1)->addMonth()
            : null;
    }

    public static function current(): Builder
    {
        $period = self::next();

        return DatasetIntegrity::forecasts(ForecastResult::query())
            ->where('year', $period?->year ?? 0)
            ->where('month', $period?->month ?? 0);
    }

    public static function throughNext(): Builder
    {
        $period = self::next();

        return DatasetIntegrity::forecasts(ForecastResult::query())->where(function (Builder $query) use ($period) {
            $query->where('year', '<', $period?->year ?? 0)
                ->orWhere(function (Builder $query) use ($period) {
                    $query->where('year', $period?->year ?? 0)
                        ->where('month', '<=', $period?->month ?? 0);
                });
        });
    }

    public static function currentDss(): Builder
    {
        return DssResult::query()->whereIn('forecast_result_id', self::current()->select('id'));
    }
}
