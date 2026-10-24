<?php

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\DssEvidence;
use App\Support\EnergyDss;
use App\Support\ForecastPeriod;
use App\Support\ReportGenerator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

function dssEvidenceFixture(mixed $score = -2, float $value = 1100): ForecastResult
{
    ProcessedRecord::create([
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 1000,
        'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
        'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => null, 'lag_2' => null, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ]);

    return ForecastResult::create([
        'year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
        'predicted_consumption_kwh' => $value, 'mae' => 10, 'rmse' => 20, 'r2_score' => $score,
        'artifact_id' => str_repeat('a', 64), 'dataset_hash' => str_repeat('b', 64),
        'training_cutoff' => '2026-08', 'evaluation_protocol' => 'synthetic_saved_evaluation',
    ])->refresh();
}

function dssEvidenceSavedState(): string
{
    $rows = [ProcessedRecord::get()->toArray(), ForecastResult::get()->toArray(), DssResult::get()->toArray(), Report::get()->toArray()];
    foreach (Storage::allFiles() as $path) {
        $rows['files'][$path] = Storage::get($path);
    }

    return json_encode($rows, JSON_THROW_ON_ERROR);
}

test('missing evidence stays unavailable in DSS basis new report data and existing report cells', function () {
    $before = dssEvidenceSavedState();
    $basis = DssEvidence::inspect(null, null);
    expect($basis['forecast_linkage']['status'])->toBe('missing');
    expect($basis['rule_version'])->toBe(DssEvidence::RULE_VERSION);
    expect($basis['reliability_status'])->toBe('insufficient_verified_basis');
    foreach ($basis['inputs'] as $input) {
        expect($input['value'])->toBeNull();
        expect($input['availability'])->toBe('unavailable');
        expect($input['validity'])->toBe('missing');
    }
    expect(dssEvidenceSavedState())->toBe($before);
    $report = ReportGenerator::create();
    foreach (['average_consumption_kwh', 'latest_consumption_kwh', 'highest_consumption_kwh', 'average_peak_demand_kw', 'average_solar_irradiance'] as $field) {
        expect($report->summary['analytics'][$field])->toBeNull();
    }
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('Demand not assessed', 'Readiness not assessed', '<strong>Unavailable</strong>');
    expect(substr_count($html, '<td>Unavailable</td>'))->toBe(6);
    expect($html)->not->toContain('Unavailable kWh', 'Unavailable kW', 'Unavailable%');
    expect(DssResult::count())->toBe(0);
    Process::assertNothingRan();
});

test('nonfinite missing negative and zero inputs are distinguished without clipping or zero filling', function ($raw, $expected, string $validity) {
    $forecast = dssEvidenceFixture();
    $before = dssEvidenceSavedState();
    $forecast->predicted_consumption_kwh = $raw; // Inspection only, never persist synthetic invalid values.
    $latest = new ProcessedRecord(['year' => 2026, 'month' => 8, 'solar_irradiance' => $raw, 'peak_demand_kw' => $raw]);
    $basis = DssEvidence::inspect($forecast, $latest);
    foreach (['predicted_consumption_kwh', 'solar_irradiance', 'peak_demand_kw'] as $field) {
        expect($basis['inputs'][$field]['value'])->toBe($expected);
        expect($basis['inputs'][$field]['validity'])->toBe($validity);
        expect($basis['inputs'][$field]['availability'])->toBe($expected === null ? 'unavailable' : 'available');
    }
    expect($basis['assessment_status'])->toBe('not_assessed');
    expect($basis['units_verified'])->toBeFalse();
    expect(dssEvidenceSavedState())->toBe($before);
    Process::assertNothingRan();
})->with([
    'missing' => [null, null, 'missing'],
    'not a number' => [NAN, null, 'invalid_numeric'],
    'infinity' => [INF, null, 'invalid_numeric'],
    'overflow' => ['1e999', null, 'invalid_numeric'],
    'text' => ['invalid', null, 'invalid_numeric'],
    'boolean' => [true, null, 'invalid_numeric'],
    'negative' => [-1, -1.0, 'negative'],
    'zero' => [0, 0.0, 'finite_nonnegative'],
]);

test('weak undefined zero and high evaluation scores never authorize demand readiness feasibility or savings', function ($score, string $status) {
    $forecast = dssEvidenceFixture($score);
    Storage::put('ml/retained.pkl', 'Original synthetic model');
    $originalForecast = $forecast->getRawOriginal();
    $dss = EnergyDss::generateForForecast($forecast);
    expect([$dss->demand_status, $dss->readiness_level])->toBe(['Not assessed', 'Not assessed']);
    expect($dss->basis['evaluation']['status'])->toBe($status);
    expect($score === null ? $dss->basis['evaluation']['r2_score'] : (float) $dss->basis['evaluation']['r2_score'])->toBe($score === null ? null : (float) $score);
    expect($dss->basis['forecast_linkage'])->toBe(['status' => 'current', 'forecast_result_id' => $forecast->id]);
    expect($dss->basis['forecast_provenance'])->toBe($forecast->artifact_provenance);
    expect($dss->basis['inputs']['peak_demand_kw']['unit'])->toBe('kW');
    expect($dss->basis['inputs']['solar_irradiance']['unit'])->toBe('unverified source unit');
    expect($dss->basis['reasons'])->toContain('unit_consistent_threshold_basis_unverified', 'prospective_performance_unverified');
    if ($score !== null && $score < 0) {
        expect($dss->basis['reasons'])->toContain('negative_r2');
    }
    expect(implode(' ', $dss->recommendations))->toContain('insufficient verified basis', 'indicative resource information only');
    expect(implode(' ', $dss->priority_actions))->toContain('compare issued estimates with observed outcomes');
    $report = ReportGenerator::create();
    expect($report->summary['latest_dss']['demand_status'])->toBe('Not assessed');
    expect($report->summary['latest_dss']['basis']['source'])->toBe('report_time_qualification');
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('Demand not assessed', 'Readiness not assessed', 'source unit unverified');
    expect($html)->not->toContain('suitable for decision-support estimates', 'High Readiness', 'Moderate Readiness', 'within manageable conditions');
    expect($forecast->fresh()->getRawOriginal())->toBe($originalForecast);
    expect(Storage::get('ml/retained.pkl'))->toBe('Original synthetic model');
    Process::assertNothingRan();
})->with([[null, 'unavailable'], [-2, 'weak'], [0, 'unvalidated'], [0.99, 'unvalidated'], [1, 'unvalidated'], [1.1, 'invalid']]);

test('genuine zero quantities survive in generated basis and report without becoming high readiness', function () {
    $forecast = dssEvidenceFixture(0, 0);
    ProcessedRecord::query()->update(['consumption_kwh' => 0, 'peak_demand_kw' => 0, 'solar_irradiance' => 0]);
    $dss = EnergyDss::generateForForecast($forecast);
    foreach (['predicted_consumption_kwh', 'average_consumption_kwh', 'peak_demand_kw', 'solar_irradiance'] as $field) {
        expect($dss->basis[$field])->toBe(0);
        expect($dss->basis['inputs'][$field]['availability'])->toBe('available');
    }
    $html = view('reports.energy', ['report' => ReportGenerator::create()])->render();
    expect($html)->toContain('0.00 kWh', '0.00 kW', 'Not assessed');
    expect($html)->not->toContain('High Readiness');
    Process::assertNothingRan();
});

test('unsaved dirty or deleted forecast linkage is refused before any DSS persistence', function (string $kind) {
    $forecast = dssEvidenceFixture();
    if ($kind === 'unsaved') {
        $forecast = new ForecastResult(['year' => 2026, 'month' => 9, 'predicted_consumption_kwh' => 1100]);
    } elseif ($kind === 'dirty') {
        $forecast->month = 11;
    } else {
        $forecast->delete();
    }
    $before = dssEvidenceSavedState();
    expect(fn () => EnergyDss::generateForForecast($forecast))->toThrow(RuntimeException::class, 'saved, unchanged forecast');
    expect(dssEvidenceSavedState())->toBe($before);
    Process::assertNothingRan();
})->with(['unsaved', 'dirty', 'deleted']);

test('out of horizon or ambiguous saved forecasts receive explicit linkage qualifications', function (string $kind) {
    $forecast = dssEvidenceFixture();
    if ($kind === 'ambiguous') {
        // Corrupt legacy fixture in the isolated test database only. Valid
        // production rows retain the existing unique period constraint.
        Schema::table('forecast_results', fn ($table) => $table->dropUnique(['year', 'month']));
    }
    $other = ForecastResult::create(['year' => 2026, 'month' => $kind === 'mismatch' ? 11 : 9, 'predicted_consumption_kwh' => 1200]);
    $dss = EnergyDss::generateForForecast($other);
    expect($dss->basis['forecast_linkage']['status'])->toBe($kind);
    expect($dss->basis['reasons'])->toContain('forecast_linkage_'.$kind);
    expect($dss->demand_status)->toBe('Not assessed');
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-09');
    expect($forecast->fresh()->month)->toBe(9);
    Process::assertNothingRan();
})->with(['mismatch', 'ambiguous']);

test('qualification reasons and guidance are deterministic and do not use the current clock or numeric legacy thresholds', function () {
    $forecast = dssEvidenceFixture();
    $latest = ProcessedRecord::first();
    $first = DssEvidence::inspect($forecast, $latest);
    $this->travel(10)->years();
    expect(DssEvidence::inspect($forecast, $latest))->toBe($first);
    expect(EnergyDss::classifyDemand(1149, 1000))->toBe('Moderate Demand');
    expect(EnergyDss::classifyDemand(1150, 1000))->toBe('High Demand');
    expect(EnergyDss::classifyDemand(899, 1000))->toBe('Low Demand');
    expect(EnergyDss::classifyDemand(900, 1000))->toBe('Moderate Demand');
    expect(EnergyDss::readiness(5, 200, 1000))->toBe('High Readiness');
    expect(EnergyDss::readiness(5, 201, 1000))->toBe('Moderate Readiness');
    expect(EnergyDss::recommendations($forecast, $latest, 'High Demand', 'High Readiness'))->toBe(DssEvidence::guidance());
    expect(EnergyDss::recommendations($forecast, null, 'Low Demand', 'Low Readiness'))->toBe(DssEvidence::guidance());
    Process::assertNothingRan();
});

test('new report qualification preserves historical DSS rows reports and legacy rendering', function () {
    $forecast = dssEvidenceFixture(0.99);
    $old = DssResult::create(['forecast_result_id' => $forecast->id, 'demand_status' => 'High Demand', 'readiness_level' => 'High Readiness',
        'recommendations' => ['Original investment claim'], 'priority_actions' => ['Original action'], 'basis' => ['original' => true]])->refresh();
    $legacy = Report::create(['title' => 'Legacy snapshot', 'type' => 'synthetic', 'summary' => [
        'latest_dss' => $old->toArray(), 'forecast_accuracy' => ['interpretation' => 'Original saved suitability conclusion'],
        'dss_reasoning' => ['demand_reason' => 'Original demand reason'],
    ]])->refresh();
    $before = [$old->getRawOriginal(), $legacy->getRawOriginal(), $forecast->getRawOriginal()];
    $new = ReportGenerator::create();
    expect($new->summary['latest_dss']['id'])->toBe($old->id);
    expect($new->summary['latest_dss']['basis']['stored_assessment_id'])->toBe($old->id);
    expect($new->summary['latest_dss']['readiness_level'])->toBe('Not assessed');
    $newHtml = view('reports.energy', ['report' => $new])->render();
    $oldHtml = view('reports.energy', ['report' => $legacy])->render();
    expect($newHtml)->not->toContain('Original investment claim', 'Original saved suitability conclusion', 'High Readiness');
    expect($oldHtml)->toContain('Original investment claim', 'Original saved suitability conclusion', 'High Readiness', 'Original demand reason');
    expect([$old->fresh()->getRawOriginal(), $legacy->fresh()->getRawOriginal(), $forecast->fresh()->getRawOriginal()])->toBe($before);
    Process::assertNothingRan();
});

test('assessment-to-forecast mismatch and absent provenance remain inspectable instead of being inferred from the current artifact', function () {
    $forecast = dssEvidenceFixture();
    $forecast->forceFill(['artifact_id' => null, 'dataset_hash' => null, 'training_cutoff' => null, 'evaluation_protocol' => null])->save();
    $other = ForecastResult::create(['year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 1200]);
    $assessment = DssResult::create(['forecast_result_id' => $other->id, 'demand_status' => 'Original', 'readiness_level' => 'Original',
        'recommendations' => [], 'priority_actions' => []]);
    Storage::put('ml/model_state.json', '{"artifact_id":"unrelated current artifact"}');
    $before = dssEvidenceSavedState();
    $basis = DssEvidence::inspect($forecast, ProcessedRecord::first(), $assessment);
    expect($basis['reasons'])->toContain('assessment_forecast_mismatch', 'forecast_provenance_unavailable_or_incomplete');
    expect($basis['forecast_provenance']['artifact_id'])->toBeNull();
    expect(dssEvidenceSavedState())->toBe($before);
    Process::assertNothingRan();
});

test('both roles receive the existing DSS response shape and saved basis without read-time writes', function () {
    $forecast = dssEvidenceFixture();
    $dss = EnergyDss::generateForForecast($forecast);
    $before = dssEvidenceSavedState();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role === 'admin' ? 'admin.decision-support' : 'user.decision-support-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latestDss.demand_status', 'Not assessed')
                ->where('latestDss.readiness_level', 'Not assessed')
                ->where('latestDss.basis.rule_version', DssEvidence::RULE_VERSION)
                ->where('latestDss.basis.forecast_linkage.forecast_result_id', $forecast->id)
                ->where('history.0.id', $dss->id)->has('latestDss.recommendations', 2)->has('latestDss.priority_actions', 2));
    }
    expect(dssEvidenceSavedState())->toBe($before);
    Process::assertNothingRan();
});
