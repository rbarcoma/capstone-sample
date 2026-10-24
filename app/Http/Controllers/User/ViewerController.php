<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\DssResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Support\AnalyticsSeries;
use App\Support\ForecastPeriod;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ViewerController extends Controller
{
    public function dashboard()
    {
        return Inertia::render('User/Dashboard', $this->sharedData());

    }

    public function analytics()
    {
        return Inertia::render('User/Analytics', $this->analyticsData());
    }

    public function forecasts()
    {
        return Inertia::render('User/ForecastResults', [
            'latestForecast' => ForecastPeriod::current()->with('user:id,name')->latest('predicted_at')->first(),
            'forecastHistory' => ForecastPeriod::throughNext()->with('user:id,name')->latest('predicted_at')->take(20)->get(),
        ]);
    }

    public function decisionSupport()
    {
        return Inertia::render('User/DecisionSupportResults', [
            'latestDss' => ForecastPeriod::currentDss()->with(['user:id,name', 'forecastResult:id,year,month,predicted_at,updated_at'])
                ->latest('updated_at')
                ->first(),
            'history' => DssResult::with(['user:id,name', 'forecastResult:id,year,month,predicted_at,updated_at'])
                ->whereIn('forecast_result_id', ForecastPeriod::throughNext()->select('id'))
                ->latest('updated_at')
                ->take(20)
                ->get(),
        ]);
    }

    public function reports()
    {
        return Inertia::render('User/Reports', [
            'reports' => Report::latest()->get(),
        ]);
    }

    private function sharedData(): array
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

        return [
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
            ],
            'recommendations' => $latestDss?->recommendations ?? [],
            'priorityActions' => $latestDss?->priority_actions ?? [],
            'forecastHistory' => ForecastPeriod::throughNext()->latest('predicted_at')->take(10)->get(),
            'trend' => $trend,
        ];
    }

    private function analyticsData(): array
    {
        $actualRecords = ProcessedRecord::query()
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($record) => $record->year.'-'.str_pad((string) $record->month, 2, '0', STR_PAD_LEFT));
        $forecastRecords = ForecastPeriod::throughNext()
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($forecast) => $forecast->year.'-'.str_pad((string) $forecast->month, 2, '0', STR_PAD_LEFT));
        $historicalPredictions = AnalyticsSeries::historicalFits()->only($actualRecords->keys()->all());
        $comparisonPeriods = $actualRecords
            ->keys()
            ->merge($forecastRecords->keys())
            ->merge($historicalPredictions->keys())
            ->unique()
            ->sort()
            ->values();

        return [
            'monthlyTrend' => ProcessedRecord::query()
                ->select('year', 'month', 'consumption_kwh as value')
                ->orderBy('year')
                ->orderBy('month')
                ->get(),
            'actualPredictedTrend' => AnalyticsSeries::rows($actualRecords, $forecastRecords, $historicalPredictions),
            'aggregate_scope' => 'all_available_actuals',
            'filterOptions' => [
                'years' => $comparisonPeriods
                    ->map(fn ($period) => (int) substr($period, 0, 4))
                    ->unique()
                    ->sort()
                    ->values(),
                'months' => range(1, 12),
            ],
            'yearlyComparison' => ProcessedRecord::query()
                ->select('year', DB::raw('AVG(consumption_kwh) as value'))
                ->groupBy('year')
                ->orderBy('year')
                ->get(),
            'seasonalPattern' => ProcessedRecord::query()
                ->select('month', DB::raw('AVG(consumption_kwh) as value'))
                ->groupBy('month')
                ->orderBy('month')
                ->get(),
            'summary' => AnalyticsSeries::summary(),
        ];
    }
}
