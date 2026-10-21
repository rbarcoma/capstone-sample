<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\DssResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Support\ForecastPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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
            'latestDss' => ForecastPeriod::currentDss()->with(['user:id,name', 'forecastResult:id,year,month'])
                ->latest('updated_at')
                ->first(),
            'history' => DssResult::with(['user:id,name', 'forecastResult:id,year,month'])
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

        return [
            'stats' => [
                'latest_average_consumption' => round((float) ($latestProcessed?->consumption_kwh ?? 0), 2),
                'latest_predicted_consumption' => round((float) ($latestForecast?->predicted_consumption_kwh ?? 0), 2),
                'latest_period' => $latestProcessed
                    ? $latestProcessed->year . '-' . str_pad((string) $latestProcessed->month, 2, '0', STR_PAD_LEFT)
                    : 'No data',
                'forecast_period' => $latestForecast
                    ? $latestForecast->year . '-' . str_pad((string) $latestForecast->month, 2, '0', STR_PAD_LEFT)
                    : 'No forecast',
                'forecast_change_percent' => round((float) ($latestForecast?->change_percent ?? 0), 2),
                'previous_consumption' => round((float) ($latestForecast?->previous_consumption_kwh ?? 0), 2),
                'peak_demand_kw' => round((float) ($latestProcessed?->peak_demand_kw ?? 0), 2),
                'solar_irradiance' => round((float) ($latestProcessed?->solar_irradiance ?? 0), 2),
                'demand_status' => $latestDss?->demand_status ?? 'No DSS result',
                'readiness_level' => $latestDss?->readiness_level ?? 'No assessment',
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
            ->keyBy(fn ($record) => $record->year . '-' . str_pad((string) $record->month, 2, '0', STR_PAD_LEFT));
        $forecastRecords = ForecastPeriod::throughNext()
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($forecast) => $forecast->year . '-' . str_pad((string) $forecast->month, 2, '0', STR_PAD_LEFT));
        $historicalPredictions = $this->historicalPredictions()->only($actualRecords->keys()->all());
        $comparisonPeriods = $actualRecords
            ->keys()
            ->merge($forecastRecords->keys())
            ->merge($historicalPredictions->keys())
            ->unique()
            ->sort()
            ->values();
        $highestConsumptionRecord = ProcessedRecord::query()
            ->orderByDesc('consumption_kwh')
            ->first();

        return [
            'monthlyTrend' => ProcessedRecord::query()
                ->select('year', 'month', 'consumption_kwh as value')
                ->orderBy('year')
                ->orderBy('month')
                ->get(),
            'actualPredictedTrend' => $comparisonPeriods->map(function ($period) use ($actualRecords, $forecastRecords, $historicalPredictions) {
                $actual = $actualRecords->get($period);
                $forecast = $forecastRecords->get($period);
                $historicalPrediction = $historicalPredictions->get($period);
                $actualValue = $actual ? (float) $actual->consumption_kwh : null;
                $predictedValue = $forecast
                    ? (float) $forecast->predicted_consumption_kwh
                    : ($historicalPrediction ? (float) $historicalPrediction['predicted'] : null);
                $accuracy = null;

                if ($actualValue !== null && $actualValue > 0 && $predictedValue !== null) {
                    $accuracy = max(0, 100 - (abs($actualValue - $predictedValue) / $actualValue * 100));
                }

                return [
                    'period' => $period,
                    'label' => $period,
                    'year' => (int) substr($period, 0, 4),
                    'month' => (int) substr($period, 5, 2),
                    'actual' => $actualValue,
                    'predicted' => $predictedValue,
                    'accuracy' => $accuracy !== null ? round($accuracy, 2) : null,
                ];
            })->values(),
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
            'summary' => [
                'average_consumption' => round((float) ProcessedRecord::avg('consumption_kwh'), 2),
                'highest_consumption' => round((float) ($highestConsumptionRecord?->consumption_kwh ?? 0), 2),
                'highest_consumption_year' => $highestConsumptionRecord?->year,
                'highest_consumption_month' => $highestConsumptionRecord?->month,
                'average_peak_demand' => round((float) ProcessedRecord::avg('peak_demand_kw'), 2),
                'average_solar_irradiance' => round((float) ProcessedRecord::avg('solar_irradiance'), 2),
            ],
        ];
    }

    private function historicalPredictions()
    {
        if (! Storage::exists('ml/processed_dataset.csv') || ! Storage::exists('ml/model_state.json')) {
            return collect();
        }

        $state = json_decode(Storage::get('ml/model_state.json'), true) ?: [];
        $modelPath = $state['model_path'] ?? null;

        if (! $modelPath || ! Storage::exists($modelPath)) {
            return collect();
        }

        $python = config('services.ml.python');

        $result = Process::path(base_path('Python'))->run([
            $python,
            'predict_history.py',
            Storage::path('ml/processed_dataset.csv'),
            Storage::path($modelPath),
        ]);

        if ($result->failed()) {
            return collect();
        }

        $predictions = json_decode(trim($result->output()), true);

        if (! is_array($predictions)) {
            return collect();
        }

        return collect($predictions)->keyBy('period');
    }
}
