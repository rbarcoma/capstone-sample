<?php

use App\Models\ProcessedRecord;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

test('empty Analytics returns null summaries and no invented values for either role', function () {
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('aggregate_scope', 'all_available_actuals')
            ->where('summary.average_consumption', null)->where('summary.highest_consumption', null)
            ->where('summary.average_peak_demand', null)->where('summary.average_solar_irradiance', null)
            ->has('actualPredictedTrend', 0));
    }
    expect(Storage::allFiles())->toBe([]);
    Process::assertNothingRan();
});

test('zero actual and context values stay numeric while unavailable fits and forecasts stay null', function () {
    $record = ProcessedRecord::create([
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 0,
        'temperature' => 0, 'humidity' => 0, 'rainfall' => 0,
        'solar_irradiance' => 0, 'peak_demand_kw' => 0,
        'lag_1' => null, 'lag_2' => null, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ])->refresh();
    $before = $record->getRawOriginal();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.average_consumption', 0)->where('summary.highest_consumption', 0)
            ->where('summary.average_peak_demand', 0)->where('summary.average_solar_irradiance', 0)
            ->where('actualPredictedTrend.0.actual', 0)
            ->where('actualPredictedTrend.0.fitted', null)->where('actualPredictedTrend.0.issued_forecast', null)
            ->where('actualPredictedTrend.0.provenance.actual.record_id', $record->id)
            ->where('actualPredictedTrend.0.provenance.fitted', null)
            ->missing('actualPredictedTrend.0.predicted')->missing('actualPredictedTrend.0.accuracy'));
    }
    expect($record->fresh()->getRawOriginal())->toBe($before);
    Process::assertNothingRan();
});

test('Analytics aggregates are explicitly all time even when record filters select no rows', function () {
    foreach ([[2025, 12, 100], [2026, 1, 300]] as [$year, $month, $value]) {
        ProcessedRecord::create([
            'year' => $year, 'month' => $month, 'consumption_kwh' => $value,
            'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
            'solar_irradiance' => 5, 'peak_demand_kw' => 20,
            'lag_1' => null, 'lag_2' => null, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
        ]);
    }
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $response = $this->get(route($role.'.analytics', ['year' => 1999, 'month' => 7]))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('aggregate_scope', 'all_available_actuals')
            ->where('summary.average_consumption', 200)->where('summary.highest_consumption', 300)
            ->has('yearlyComparison', 2)->has('seasonalPattern', 2)->has('actualPredictedTrend', 2));
        if ($role === 'admin') {
            $response->assertInertia(fn (Assert $page) => $page->has('records', 0)->has('monthlyTrend', 0)->has('peakDemand', 2));
        }
    }
    Process::assertNothingRan();
});
