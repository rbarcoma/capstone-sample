<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DssResult;
use App\Support\AuditLogger;
use App\Support\EnergyDss;
use App\Support\ForecastPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DecisionSupportController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/DecisionSupport', [
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

    public function generate(Request $request)
    {
        $forecast = ForecastPeriod::current()->latest('predicted_at')->first();
        if (! $forecast) {
            return back()->with('error', 'Run a forecast before generating DSS recommendations.');
        }

        $dss = EnergyDss::generateForForecast($forecast, $request->user()?->id, now());

        AuditLogger::log(
            'Decision Support',
            'Generate DSS Result',
            'Generated DSS result: ' . $dss->demand_status . ' and ' . $dss->readiness_level . '.'
        );

        return back()->with('success', 'Decision support recommendations generated.');
    }
}
