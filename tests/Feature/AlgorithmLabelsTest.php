<?php

use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\ReportGenerator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

test('new report copy names Ridge while stored forecast metadata and old reports remain unchanged', function (string $savedLabel) {
    // Synthetic fixture only; no ML workflow or beneficiary record is involved.
    ProcessedRecord::create([
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 1000,
        'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
        'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => 1000, 'lag_2' => 1000, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ]);
    $forecast = ForecastResult::create([
        'year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
        'predicted_consumption_kwh' => 1100, 'model_type' => $savedLabel,
        'mae' => 10, 'rmse' => 20, 'r2_score' => -2,
    ])->refresh();
    $oldReport = Report::create([
        'title' => 'Synthetic saved report', 'type' => 'analytics_forecast_dss',
        'summary' => ['prediction_explanation' => ['model' => 'Linear Regression', 'description' => 'Original saved explanation']],
    ])->refresh();
    $before = [$forecast->getRawOriginal(), $oldReport->getRawOriginal()];
    $newReport = ReportGenerator::create();
    expect($newReport->summary['prediction_explanation']['description'])->toStartWith('The system uses Ridge (penalty 0.1).');
    expect($newReport->summary['prediction_explanation']['model'])->toBe($savedLabel);
    expect($newReport->summary['latest_forecast']['model_type'])->toBe($savedLabel);
    expect($newReport->summary['forecast_accuracy']['r2_score'])->toBe(-2);
    expect(view('reports.energy', ['report' => $newReport])->render())->toContain('The system uses Ridge (penalty 0.1).');
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role === 'admin' ? 'admin.forecasting' : 'user.forecast-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('latestForecast.model_type', $savedLabel));
    }
    expect([$forecast->fresh()->getRawOriginal(), $oldReport->fresh()->getRawOriginal()])->toBe($before);
    expect(Storage::allFiles())->toBe([]);
    Process::assertNothingRan();
})->with(['Ridge (penalty 0.1)', 'Linear Regression']);

test('new report algorithm fallback uses Ridge when no forecast exists', function () {
    $report = ReportGenerator::create(); // In-memory SQLite fixture, no workflow.
    expect($report->summary['prediction_explanation']['model'])->toBe('Ridge (penalty 0.1)');
    expect(ForecastResult::count())->toBe(0);
    Process::assertNothingRan();
});
