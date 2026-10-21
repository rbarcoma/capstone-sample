<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ForecastPeriod;
use App\Support\MlWorkflow;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class ForecastingController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Forecasting', [
            'latestForecast' => ForecastPeriod::current()->with('user:id,name')->latest('predicted_at')->first(),
            'forecastHistory' => ForecastPeriod::throughNext()->with('user:id,name')->latest('predicted_at')->take(20)->get(),
        ]);
    }

    public function train()
    {
        try {
            $result = app(MlWorkflow::class)->trainIfDatasetChanged(auth()->id(), true);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Model trained successfully. ' . ($result['output'] ?? ''));
    }

    public function predict(Request $request)
    {
        try {
            app(MlWorkflow::class)->trainIfDatasetChanged($request->user()?->id);
            app(MlWorkflow::class)->predictAndGenerate($request->user()?->id, 'manual');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Next-month forecast, DSS recommendations, and report saved.');
    }
}
