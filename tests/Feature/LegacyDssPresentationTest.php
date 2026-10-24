<?php

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\DssEvidence;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

function legacyPresentationReport(array $extra = []): Report
{
    return Report::create([
        'title' => 'Synthetic historical snapshot', 'type' => 'analytics_forecast_dss',
        'summary' => array_replace_recursive([
            'report_basis' => 'Original saved report basis',
            'latest_forecast' => ['year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
                'predicted_consumption_kwh' => 1234, 'model_type' => 'Original saved algorithm'],
            'latest_dss' => ['demand_status' => 'High Demand', 'readiness_level' => 'High Readiness',
                'recommendations' => ['Original feasibility claim'], 'priority_actions' => ['Original investment action']],
            'forecast_accuracy' => ['r2_score' => -2, 'interpretation' => 'Original saved suitability conclusion'],
            'dss_reasoning' => ['demand_reason' => 'Original demand reason', 'readiness_reason' => 'Original readiness reason'],
        ], $extra),
    ])->refresh();
}

test('legacy report rendering separates historical conclusions and explanatory timing metric context without changing its snapshot', function () {
    $report = legacyPresentationReport();
    Storage::put('ml/model_state.json', '{"unrelated_current_state":true}');
    $original = $report->getRawOriginal();
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('Historical / unqualified evidence.', 'not a current assessment or validated evidence',
        'Current explanatory metric context (presentation only, not part of the saved assessment):',
        'Recorded timing context (presentation only, derived from this snapshot):',
        'Target: 2026-09', 'Produced after target month; not issued in advance.', '1,234.00 kWh', '-2.0000');
    foreach (['Original saved report basis', 'Original saved algorithm', 'High Demand', 'High Readiness',
        'Original feasibility claim', 'Original investment action', 'Original saved suitability conclusion',
        'Original demand reason', 'Original readiness reason'] as $text) {
        expect($html)->toContain($text);
    }
    expect($html)->not->toContain('Not assessed');
    $this->travel(10)->years();
    expect(view('reports.energy', ['report' => $report->fresh()])->render())->toBe($html);
    expect($report->fresh()->getRawOriginal())->toBe($original);
    expect(Storage::get('ml/model_state.json'))->toBe('{"unrelated_current_state":true}');
    expect(Report::count())->toBe(1);
    Process::assertNothingRan();
});

test('missing or unrecognized report rule versions remain visibly unqualified instead of inheriting current evidence', function ($version) {
    $report = legacyPresentationReport(['dss_reasoning' => ['rule_version' => $version]]);
    $original = $report->getRawOriginal();
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('Historical / unqualified evidence.', 'High Readiness', 'Original feasibility claim');
    expect($html)->not->toContain('Not assessed');
    expect($report->fresh()->getRawOriginal())->toBe($original);
    Process::assertNothingRan();
})->with([null, '', 'legacy', 'unknown_future_version']);

test('legacy report saved metric definitions are identified as saved context and never replaced by current definitions', function () {
    $report = legacyPresentationReport(['forecast_accuracy' => ['metric_definitions' => [
        'mae' => 'Original MAE definition', 'r2_score' => 'Original R2 definition',
    ]]]);
    $original = $report->getRawOriginal();
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('Saved metric context (historical, not revalidated):', 'Original MAE definition', 'Original R2 definition');
    expect($html)->not->toContain('Current explanatory metric context');
    expect($report->fresh()->getRawOriginal())->toBe($original);
    Process::assertNothingRan();
});

test('versioned 6a report presentation keeps existing status advice and missing versus zero formatting without legacy qualifications', function () {
    $report = legacyPresentationReport([
        'latest_forecast' => ['predicted_consumption_kwh' => 0, 'previous_consumption_kwh' => null, 'change_percent' => null],
        'latest_dss' => ['demand_status' => 'Not assessed', 'readiness_level' => 'Not assessed',
            'recommendations' => ['Insufficient verified basis'], 'priority_actions' => ['Verify source completeness']],
        'dss_reasoning' => ['rule_version' => DssEvidence::RULE_VERSION],
    ]);
    $original = $report->getRawOriginal();
    $html = view('reports.energy', ['report' => $report])->render();
    expect($html)->toContain('Not assessed', 'Insufficient verified basis', 'Verify source completeness',
        '0.00 kWh', '<td>Unavailable</td>', 'Target: 2026-09');
    expect($html)->not->toContain('Historical / unqualified evidence.', 'Current explanatory metric context',
        'Saved metric context', 'Recorded timing context (presentation only');
    expect($report->fresh()->getRawOriginal())->toBe($original);
    Process::assertNothingRan();
});

test('both roles receive original legacy DSS values and basis without read time record or artifact mutation', function () {
    ProcessedRecord::create(['year' => 2026, 'month' => 8, 'consumption_kwh' => 1000, 'temperature' => 25,
        'humidity' => 70, 'rainfall' => 10, 'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => null, 'lag_2' => null, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1]);
    $forecast = ForecastResult::create(['year' => 2026, 'month' => 9, 'predicted_consumption_kwh' => 1234])->refresh();
    $dss = DssResult::create(['forecast_result_id' => $forecast->id, 'demand_status' => 'High Demand',
        'readiness_level' => 'High Readiness', 'basis' => null, 'recommendations' => ['Original feasibility claim'],
        'priority_actions' => ['Original investment action']])->refresh();
    $report = legacyPresentationReport();
    Storage::put('ml/retained.pkl', 'Original synthetic bytes');
    $before = [$forecast->getRawOriginal(), $dss->getRawOriginal(), $report->getRawOriginal()];
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role === 'admin' ? 'admin.decision-support' : 'user.decision-support-results'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latestDss.id', $dss->id)->where('latestDss.demand_status', 'High Demand')
                ->where('latestDss.readiness_level', 'High Readiness')->where('latestDss.basis', null)
                ->where('latestDss.recommendations.0', 'Original feasibility claim')
                ->where('latestDss.priority_actions.0', 'Original investment action')
                ->where('history.0.id', $dss->id));
    }
    expect([$forecast->fresh()->getRawOriginal(), $dss->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal()])->toBe($before);
    expect(Storage::get('ml/retained.pkl'))->toBe('Original synthetic bytes');
    Process::assertNothingRan();
});

test('both Dashboard roles expose saved DSS basis for presentation without qualifying missing or versioned assessments as legacy', function ($basis) {
    $forecast = ForecastResult::create(['year' => 2026, 'month' => 9, 'predicted_consumption_kwh' => 1234])->refresh();
    ProcessedRecord::create(['year' => 2026, 'month' => 8, 'consumption_kwh' => 1000, 'temperature' => 25,
        'humidity' => 70, 'rainfall' => 10, 'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => null, 'lag_2' => null, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1]);
    $dss = $basis === 'absent' ? null : DssResult::create(['forecast_result_id' => $forecast->id,
        'demand_status' => $basis === null ? 'High Demand' : 'Not assessed',
        'readiness_level' => $basis === null ? 'High Readiness' : 'Not assessed',
        'basis' => $basis, 'recommendations' => ['Original advice'], 'priority_actions' => ['Original action']])->refresh();
    $before = [$forecast->getRawOriginal(), $dss?->getRawOriginal()];
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.demand_status', $dss?->demand_status ?? 'No DSS result')
            ->where('stats.readiness_level', $dss?->readiness_level ?? 'No assessment')
            ->where('stats.dss_evidence', $dss ? ['basis' => $basis] : null));
    }
    expect([$forecast->fresh()->getRawOriginal(), $dss?->fresh()->getRawOriginal()])->toBe($before);
    expect(Storage::allFiles())->toBe([]);
    Process::assertNothingRan();
})->with([
    'legacy' => [null],
    'versioned' => [['rule_version' => DssEvidence::RULE_VERSION]],
    'absent' => ['absent'],
]);
