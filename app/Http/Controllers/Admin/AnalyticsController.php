<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProcessedRecord;
use App\Support\AnalyticsSeries;
use App\Support\ForecastPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $historicalPredictions = AnalyticsSeries::historicalFits()->only($actualRecords->keys()->all());
        $comparisonPeriods = $actualRecords
            ->keys()
            ->merge($forecastRecords->keys())
            ->merge($historicalPredictions->keys())
            ->unique()
            ->sort()
            ->values();

        return Inertia::render('Admin/Analytics', [
            'filters' => $request->only('year', 'month'),
            'records' => $records,
            'monthlyTrend' => $records->map(fn ($record) => [
                'label' => $record->year.'-'.str_pad((string) $record->month, 2, '0', STR_PAD_LEFT),
                'value' => (float) $record->consumption_kwh,
            ])->values(),
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
            'peakDemand' => ProcessedRecord::query()
                ->select('year', 'month', 'peak_demand_kw as value')
                ->orderByDesc('peak_demand_kw')
                ->take(12)
                ->get(),
            'summary' => AnalyticsSeries::summary(),
        ]);
    }
}
