<?php

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\ForecastIssuance;
use App\Support\ForecastPeriod;
use App\Support\ReportGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

test('issuance compares the recorded timestamp with the target month independently of the clock', function (int $year, int $month, string $produced, string $timing) {
    $snapshot = ['year' => $year, 'month' => $month, 'predicted_at' => $produced];
    $labels = [];
    foreach (['2020-01-01', '2026-10-22', '2030-12-31'] as $today) {
        $this->travelTo(Carbon::parse($today));
        $labels[] = ForecastIssuance::label($snapshot);
    }
    expect(array_unique($labels))->toHaveCount(1);
    expect($labels[0])->toContain(sprintf('Target: %04d-%02d.', $year, $month))
        ->toContain('Produced: ')->toContain($timing);
})->with([
    'before September' => [2026, 9, '2026-08-31T23:59:59Z', 'Produced before target month.'],
    'at September start' => [2026, 9, '2026-09-01T00:00:00Z', 'Produced during target month; not issued before the month.'],
    'at September end' => [2026, 9, '2026-09-30T23:59:59Z', 'Produced during target month; not issued before the month.'],
    'after September' => [2026, 9, '2026-10-01T00:00:00Z', 'Produced after target month; not issued in advance.'],
    'December to January' => [2027, 1, '2026-12-31 23:59:59', 'Produced before target month.'],
    'January produced in February' => [2027, 1, '2027-02-01T00:00:00Z', 'Produced after target month; not issued in advance.'],
    'offset normalized to configured timezone' => [2026, 10, '2026-10-01T07:59:59+08:00', 'Produced before target month.'],
]);

test('missing or invalid issuance never becomes the current date or a verified prospective claim', function ($timestamp) {
    $label = ForecastIssuance::label([
        'year' => 2026, 'month' => 9, 'predicted_at' => $timestamp,
        'updated_at' => '2026-10-21T05:55:25Z',
    ]);
    expect($label)->toBe('Target: 2026-09. Produced: unavailable. Record updated: 2026-10-21 05:55:25 UTC. Advance issuance unverified.');
})->with([null, '', 'now', 'tomorrow', '2026-10', '2026-02-30 10:00:00', '2026-10-21 25:00:00']);

test('unknown target and timestamps remain unavailable', function () {
    expect(ForecastIssuance::label([]))->toBe('Target: unavailable. Produced: unavailable. Advance issuance unverified.');
    expect(ForecastIssuance::label(['year' => 2026, 'month' => 13, 'predicted_at' => '2026-10-21T05:55:25Z']))
        ->toBe('Target: unavailable. Produced: 2026-10-21 05:55:25 UTC. Advance issuance unverified.');
});

function issuanceFixture(): array
{
    // Synthetic software fixture only; no beneficiary data or workflow invocation.
    ProcessedRecord::create([
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 1000,
        'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
        'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => 1000, 'lag_2' => 1000, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ]);
    $forecast = ForecastResult::create([
        'year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
        'predicted_consumption_kwh' => 1100, 'model_type' => 'Linear Regression',
        'mae' => 10, 'rmse' => 20, 'r2_score' => -2,
    ]);
    $dss = DssResult::create([
        'forecast_result_id' => $forecast->id, 'demand_status' => 'Moderate Demand',
        'readiness_level' => 'Moderate Readiness', 'recommendations' => ['Synthetic recommendation'],
        'priority_actions' => ['Synthetic action'], 'basis' => ['predicted_consumption_kwh' => 1100],
    ]);
    $dss->forceFill(['updated_at' => '2027-05-01 01:00:00'])->saveQuietly();

    return [$forecast->refresh(), $dss->refresh()];
}

test('both roles receive the same recorded issuance across dashboards forecasts Analytics and DSS without writes', function () {
    $this->travelTo(Carbon::parse('2030-01-01'));
    [$forecast, $dss] = issuanceFixture();
    $label = 'Target: 2026-09. Produced: 2026-10-21 05:55:25 UTC. Produced after target month; not issued in advance.';
    $users = collect(['admin', 'user'])->mapWithKeys(fn ($role) => [$role => User::factory()->create(['role' => $role])]);
    $before = [$forecast->getRawOriginal(), $dss->getRawOriginal(), ProcessedRecord::first()->getRawOriginal()];
    foreach ($users as $role => $user) {
        $this->actingAs($user);
        $this->get(route($role.'.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.forecast_period', '2026-09')->where('stats.forecast_issuance', $label)
            ->where(($role === 'admin' ? 'recentForecasts' : 'forecastHistory').'.0.issuance_label', $label));
        $this->get(route($role === 'admin' ? 'admin.forecasting' : 'user.forecast-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('latestForecast.issuance_label', $label)
                ->where('forecastHistory.0.issuance_label', $label));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actualPredictedTrend.1.period', '2026-09')->where('actualPredictedTrend.1.issuance_label', $label)
            ->where('actualPredictedTrend.0.issuance_label', null));
        $this->get(route($role === 'admin' ? 'admin.decision-support' : 'user.decision-support-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('latestDss.forecast_result.issuance_label', $label)
                ->where('history.0.forecast_result.issuance_label', $label));
    }
    expect([$forecast->fresh()->getRawOriginal(), $dss->fresh()->getRawOriginal(), ProcessedRecord::first()->getRawOriginal()])->toBe($before);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-09');
    expect(Report::count())->toBe(0);
    expect(Storage::allFiles())->toBe([]);
    Process::assertNothingRan();
});

test('reports render issuance from their saved snapshot and retain original records and conclusions', function () {
    [$forecast, $dss] = issuanceFixture();
    // Legacy report snapshot has no derived issuance_label property.
    $snapshot = ['latest_forecast' => $forecast->getAttributes(), 'latest_dss' => $dss->toArray(),
        'prediction_explanation' => ['description' => 'Original saved explanation'],
        'dss_reasoning' => ['demand_reason' => 'Original saved reasoning']];
    $report = Report::create(['title' => 'Synthetic dated report', 'type' => 'analytics_forecast_dss', 'summary' => $snapshot]);
    $before = $report->refresh()->getRawOriginal();
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain($forecast->issuance_label)->toContain('Assessment generated: 2027-05-01')
        ->toContain('Original saved explanation')->toContain('Original saved reasoning');
    // Later issuance, DSS regeneration and the clock cannot rewrite an old snapshot.
    $forecast->forceFill(['predicted_at' => '2028-01-01 00:00:00'])->saveQuietly();
    $dss->forceFill(['updated_at' => '2028-01-01 00:00:00'])->saveQuietly();
    $this->travelTo(Carbon::parse('2030-01-01'));
    expect(view('reports.energy', ['report' => $report->fresh()])->render())->toBe($html);
    expect($report->fresh()->getRawOriginal())->toBe($before);
    Process::assertNothingRan();
});

test('new reports capture issuance separately from report and DSS generation times', function () {
    [$forecast] = issuanceFixture();
    $before = $forecast->getRawOriginal();
    $report = ReportGenerator::create(); // Isolated synthetic report only.
    expect($report->summary['latest_forecast']['issuance_label'])->toBe($forecast->issuance_label);
    expect(view('reports.energy', ['report' => $report])->render())->toContain($forecast->issuance_label);
    expect($forecast->fresh()->getRawOriginal())->toBe($before);
    Process::assertNothingRan();
});
