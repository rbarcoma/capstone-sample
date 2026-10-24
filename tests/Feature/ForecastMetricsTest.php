<?php

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\EnergyDss;
use App\Support\ForecastMetrics;
use App\Support\ForecastPeriod;
use App\Support\ReportGenerator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

test('unavailable or invalid metric values never format as zero', function ($value) {
    expect(ForecastMetrics::rounded($value))->toBeNull();
    expect(ForecastMetrics::display($value))->toBe('Unavailable');
})->with([null, '', 'bad', '1e999', true, [[]]]);

function metricForecastFixture(mixed $score): ForecastResult
{
    // Synthetic fixture in SQLite :memory: only.
    ProcessedRecord::create([
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 1000,
        'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
        'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => 1000, 'lag_2' => 1000, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ]);

    return ForecastResult::create([
        'year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
        'predicted_consumption_kwh' => 1100, 'previous_consumption_kwh' => 1000,
        'change_percent' => 10, 'model_type' => 'Ridge (penalty 0.1)',
        'mae' => $score === null ? null : 0, 'rmse' => $score === null ? null : 0,
        'r2_score' => $score,
    ])->refresh();
}

test('null zero and negative metrics remain distinct in APIs new snapshots and report rendering', function ($score, string $display) {
    $forecast = metricForecastFixture($score);
    $before = $forecast->getRawOriginal();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role === 'admin' ? 'admin.forecasting' : 'user.forecast-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latestForecast.r2_score', $score)
                ->where('latestForecast.mae', $score === null ? null : 0)
                ->where('latestForecast.rmse', $score === null ? null : 0)
                ->where('latestForecast.metric_definitions', ForecastMetrics::definitions()));
    }
    $report = ReportGenerator::create();
    expect($report->summary['forecast_accuracy']['r2_score'])->toBe($score);
    expect($report->summary['forecast_accuracy']['mae'])->toBe($score === null ? null : 0);
    expect($report->summary['forecast_accuracy']['metric_definitions'])->toBe(ForecastMetrics::definitions());
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('<td>'.$display.'</td>', '<strong>'.$display.'</strong>');
    expect($html)->toContain(e(ForecastMetrics::definitions()['r2_score']), e(ForecastMetrics::definitions()['fold_average']));
    if ($score === null) {
        expect($report->summary['forecast_accuracy']['interpretation'])->toContain('unavailable');
        expect($report->summary['forecast_accuracy']['rmse'])->toBeNull();
    }
    expect($forecast->fresh()->getRawOriginal())->toBe($before);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-09');
    expect(Storage::allFiles())->toBe([]);
    Process::assertNothingRan();
})->with([[null, 'Unavailable'], [0, '0.0000'], [-2, '-2.0000']]);

test('missing forecasts and legacy snapshot fields render unavailable without rewriting saved conclusions', function (array $metrics) {
    $report = Report::create([
        'title' => 'Synthetic legacy report', 'type' => 'analytics_forecast_dss',
        'summary' => ['latest_forecast' => $metrics, 'forecast_accuracy' => $metrics,
            'prediction_explanation' => ['description' => 'Original saved explanation'],
            'forecast_accuracy_note' => 'Original saved conclusion'],
    ])->refresh();
    $before = hash('sha256', json_encode($report->getRawOriginal()));
    $html = view('reports.energy', ['report' => $report])->render();
    expect(substr_count($html, '<td>Unavailable</td>'))->toBe(3);
    expect(substr_count($html, '<strong>Unavailable</strong>'))->toBe(3);
    expect($html)->toContain('Original saved explanation');
    expect(hash('sha256', json_encode($report->fresh()->getRawOriginal())))->toBe($before);
    $newReport = ReportGenerator::create();
    foreach (['mae', 'rmse', 'r2_score'] as $key) {
        expect($newReport->summary['forecast_accuracy'][$key])->toBeNull();
    }
    Process::assertNothingRan();
})->with([[[]], [['mae' => null, 'rmse' => null, 'r2_score' => null]]]);

test('explicit null in a snapshot is not replaced by a contradictory numeric fallback', function () {
    $report = new Report(['title' => 'Synthetic snapshot', 'summary' => [
        'latest_forecast' => ['mae' => null, 'rmse' => null, 'r2_score' => null],
        'forecast_accuracy' => ['mae' => 12, 'rmse' => 34, 'r2_score' => -5],
    ]]);
    $report->created_at = now();
    $html = view('reports.energy', ['report' => $report])->render();
    expect(substr_count($html, '<td>Unavailable</td>'))->toBe(3);
    expect($html)->toContain('<strong>12.0000</strong>', '<strong>-5.0000</strong>');
});

test('metric definitions are read only and metric availability does not change DSS rules or saved stale records', function () {
    $forecast = metricForecastFixture(null);
    $latest = ProcessedRecord::first();
    $stale = ForecastResult::create(['year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 999]);
    $dss = DssResult::create(['forecast_result_id' => $stale->id, 'demand_status' => 'Original',
        'readiness_level' => 'Original', 'recommendations' => ['Original'], 'priority_actions' => ['Original']]);
    $report = Report::create(['title' => 'Original', 'type' => 'synthetic', 'summary' => ['original' => true]]);
    $snapshot = fn () => hash('sha256', json_encode([$stale->fresh()->getRawOriginal(), $dss->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal()]));
    $before = $snapshot();
    $demand = EnergyDss::classifyDemand(1100, 1000);
    $readiness = EnergyDss::readiness(5, 20, 1100);
    expect([$demand, $readiness])->toBe(['Moderate Demand', 'High Readiness']);
    $recommendations = EnergyDss::recommendations($forecast, $latest, $demand, $readiness);
    foreach ([null, 0, -2] as $score) {
        $forecast->r2_score = $score; // In-memory change only.
        expect(EnergyDss::recommendations($forecast, $latest, $demand, $readiness))->toBe($recommendations);
    }
    $definitions = $forecast->metric_definitions;
    $definitions['r2_score'] = 'Synthetic attempted override';
    expect($forecast->metric_definitions)->toBe(ForecastMetrics::definitions());
    expect($forecast->getFillable())->not->toContain('metric_definitions');
    expect($snapshot())->toBe($before);
    expect(Storage::allFiles())->toBe([]);
    Process::assertNothingRan();
});
