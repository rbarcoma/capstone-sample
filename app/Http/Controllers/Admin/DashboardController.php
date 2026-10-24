<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Support\AnalyticsSeries;
use App\Support\ForecastPeriod;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $latestProcessed = ProcessedRecord::query()->orderByDesc('year')->orderByDesc('month')->first();
        $latestForecast = ForecastPeriod::current()->latest('predicted_at')->first();
        $latestDss = ForecastPeriod::currentDss()
            ->latest('updated_at')
            ->first();
        $trend = ProcessedRecord::query()
            ->select('year', 'month', 'consumption_kwh', 'peak_demand_kw', 'solar_irradiance')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->take(24)
            ->get()
            ->sortBy([
                ['year', 'asc'],
                ['month', 'asc'],
            ])
            ->values();

        $rounded = fn ($value) => ($value = AnalyticsSeries::number($value)) === null ? null : round($value, 2);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'latest_average_consumption' => $rounded($latestProcessed?->consumption_kwh),
                'latest_predicted_consumption' => $rounded($latestForecast?->predicted_consumption_kwh),
                'latest_period' => $latestProcessed
                    ? $latestProcessed->year.'-'.str_pad((string) $latestProcessed->month, 2, '0', STR_PAD_LEFT)
                    : 'No data',
                'forecast_period' => $latestForecast
                    ? $latestForecast->year.'-'.str_pad((string) $latestForecast->month, 2, '0', STR_PAD_LEFT)
                    : 'No forecast',
                'forecast_issuance' => $latestForecast?->issuance_label,
                'forecast_change_percent' => $rounded($latestForecast?->change_percent),
                'previous_consumption' => $rounded($latestForecast?->previous_consumption_kwh),
                'peak_demand_kw' => $rounded($latestProcessed?->peak_demand_kw),
                'solar_irradiance' => $rounded($latestProcessed?->solar_irradiance),
                'demand_status' => $latestDss?->demand_status ?? 'No DSS result',
                'readiness_level' => $latestDss?->readiness_level ?? 'No assessment',
                'dss_evidence' => $latestDss?->only('basis'),
                'datasets' => Dataset::count(),
                'processed_records' => ProcessedRecord::count(),
                'forecasts' => ForecastPeriod::throughNext()->count(),
                'reports' => Report::count(),
            ],
            'recentForecasts' => ForecastPeriod::throughNext()->latest('predicted_at')->take(5)->get(),
            'recentReports' => Report::latest()->take(5)->get(),
            'recentDatasets' => Dataset::latest()->take(5)->get(),
            'trend' => $trend,
            'yearly' => ProcessedRecord::query()
                ->select('year', DB::raw('AVG(consumption_kwh) as consumption_kwh'))
                ->groupBy('year')
                ->orderBy('year')
                ->get(),
        ]);
    }
}
