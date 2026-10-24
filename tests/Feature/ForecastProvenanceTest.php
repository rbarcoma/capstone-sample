<?php

use App\Models\DssResult;
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

function forecastProvenanceFixture(bool $recorded): ForecastResult
{
    ProcessedRecord::create([
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 1000,
        'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
        'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => 1000, 'lag_2' => 1000, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ]);
    $facts = $recorded ? [
        'artifact_id' => str_repeat('a', 64), 'dataset_hash' => str_repeat('b', 64),
        'training_cutoff' => '2026-08', 'evaluation_protocol' => 'synthetic_protocol',
    ] : [];

    return ForecastResult::create([
        'year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
        'predicted_consumption_kwh' => 1100, 'model_type' => 'Original saved model label',
        'mae' => null, 'rmse' => 0, 'r2_score' => -2,
    ] + $facts)->refresh();
}

test('both roles expose only saved provenance on forecast dashboard Analytics and report responses without writes', function (bool $recorded) {
    $forecast = forecastProvenanceFixture($recorded);
    $expected = $forecast->artifact_provenance;
    expect($expected['status'])->toBe($recorded ? 'recorded' : 'unavailable');
    expect($expected['artifact_id'])->toBe($recorded ? str_repeat('a', 64) : null);
    $stale = ForecastResult::create(['year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 999]);
    $dss = DssResult::create(['forecast_result_id' => $stale->id,
        'demand_status' => 'Original', 'readiness_level' => 'Original',
        'recommendations' => ['Original'], 'priority_actions' => ['Original']]);
    $report = Report::create(['title' => 'Legacy report', 'type' => 'synthetic',
        'summary' => ['latest_forecast' => ['year' => 2026, 'month' => 9], 'original_conclusion' => 'Original']]);
    // A newer/different pointer cannot supply missing facts or overwrite saved ones.
    Storage::put('ml/model_state.json', json_encode(['format_version' => 2,
        'artifact_id' => str_repeat('f', 64), 'dataset_hash' => str_repeat('e', 64),
        'training_cutoff' => '2099-12', 'evaluation_protocol' => 'unrelated']));
    $snapshot = fn () => hash('sha256', json_encode([
        $forecast->fresh()->getRawOriginal(), $stale->fresh()->getRawOriginal(),
        $dss->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal(),
        ProcessedRecord::get()->toArray(), Storage::get('ml/model_state.json'),
    ]));
    $before = $snapshot();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role === 'admin' ? 'admin.forecasting' : 'user.forecast-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latestForecast.artifact_provenance', $expected)
                ->where('forecastHistory.0.artifact_provenance', $expected));
        $this->get(route($role.'.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where(($role === 'admin' ? 'recentForecasts' : 'forecastHistory').'.0.artifact_provenance', $expected));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actualPredictedTrend.1.provenance.issued_forecast.status', $expected['status'])
            ->where('actualPredictedTrend.1.provenance.issued_forecast.artifact_id', $expected['artifact_id'])
            ->where('actualPredictedTrend.1.provenance.issued_forecast.dataset_hash', $expected['dataset_hash'])
            ->where('actualPredictedTrend.1.provenance.issued_forecast.training_cutoff', $expected['training_cutoff'])
            ->where('actualPredictedTrend.1.provenance.issued_forecast.evaluation_protocol', $expected['evaluation_protocol']));
    }
    $this->get(route('user.reports'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reports.0.summary.original_conclusion', 'Original'));
    expect($snapshot())->toBe($before);
    Process::assertNothingRan();
})->with([true, false]);

test('new report snapshots retain recorded issuance facts while legacy report contents remain unchanged', function () {
    $forecast = forecastProvenanceFixture(true);
    $legacy = Report::create(['title' => 'Original', 'type' => 'synthetic',
        'summary' => ['latest_forecast' => ['model_type' => 'Original saved label'], 'original' => true]])->refresh();
    $before = $legacy->getRawOriginal();
    $report = ReportGenerator::create();
    expect($report->summary['latest_forecast']['artifact_provenance'])->toBe($forecast->artifact_provenance);
    $snapshot = $report->refresh()->getRawOriginal();
    $forecast->update(['artifact_id' => str_repeat('f', 64), 'dataset_hash' => str_repeat('e', 64)]);
    expect($report->fresh()->getRawOriginal())->toBe($snapshot);
    expect($legacy->fresh()->getRawOriginal())->toBe($before);
    Process::assertNothingRan();
});

test('partial saved provenance is disclosed as incomplete and is never completed from current state', function () {
    Storage::put('ml/model_state.json', json_encode(['artifact_id' => str_repeat('f', 64)]));
    $forecast = new ForecastResult(['artifact_id' => str_repeat('a', 64)]);
    expect($forecast->artifact_provenance)->toBe([
        'status' => 'incomplete', 'artifact_id' => str_repeat('a', 64),
        'dataset_hash' => null, 'training_cutoff' => null, 'evaluation_protocol' => null,
    ]);
    expect((new ForecastResult)->artifact_provenance['status'])->toBe('unavailable');
    Process::assertNothingRan();
});

test('limited forecast projections disclose unavailable provenance without inventing legacy identity', function () {
    $forecast = forecastProvenanceFixture(true);
    $projected = ForecastResult::query()->select(['id', 'year', 'month'])->findOrFail($forecast->id);
    expect($forecast->artifact_provenance['status'])->toBe('recorded');
    expect($projected->artifact_provenance)->toBe([
        'status' => 'unavailable', 'artifact_id' => null, 'dataset_hash' => null,
        'training_cutoff' => null, 'evaluation_protocol' => null,
    ]);
    expect($forecast->fresh()->artifact_provenance)->toBe($forecast->artifact_provenance);
    Process::assertNothingRan();
});
