<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProcessedRecord;
use App\Support\ForecastPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $query = ProcessedRecord::query();

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        if ($request->filled('month')) {
            $query->where('month', $request->integer('month'));
        }

        $records = $query->orderBy('year')->orderBy('month')->get();
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
        $historicalPredictions = $this->historicalPredictions()->only($actualRecords->keys()->all());
        $comparisonPeriods = $actualRecords
            ->keys()
            ->merge($forecastRecords->keys())
            ->merge($historicalPredictions->keys())
            ->unique()
            ->sort()
            ->values();
        $all = ProcessedRecord::query();

        $highestConsumptionRecord = ProcessedRecord::query()
            ->orderByDesc('consumption_kwh')
            ->first();

        return Inertia::render('Admin/Analytics', [
            'filters' => $request->only('year', 'month'),
            'records' => $records,
            'monthlyTrend' => $records->map(fn ($record) => [
                'label' => $record->year.'-'.str_pad((string) $record->month, 2, '0', STR_PAD_LEFT),
                'value' => (float) $record->consumption_kwh,
            ])->values(),
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
            'peakDemand' => ProcessedRecord::query()
                ->select('year', 'month', 'peak_demand_kw as value')
                ->orderByDesc('peak_demand_kw')
                ->take(12)
                ->get(),
            'summary' => [
                'average_consumption' => round((float) $all->avg('consumption_kwh'), 2),
                'highest_consumption' => round((float) ($highestConsumptionRecord?->consumption_kwh ?? 0), 2),
                'highest_consumption_year' => $highestConsumptionRecord?->year,
                'highest_consumption_month' => $highestConsumptionRecord?->month,
                'average_peak_demand' => round((float) ProcessedRecord::avg('peak_demand_kw'), 2),
                'average_solar_irradiance' => round((float) ProcessedRecord::avg('solar_irradiance'), 2),
            ],
        ]);
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
