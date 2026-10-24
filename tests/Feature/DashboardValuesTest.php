<?php

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Process::preventStrayProcesses();
    $this->withoutVite();
});

function dashboardValueActual(array $values = []): ProcessedRecord
{
    return ProcessedRecord::create($values + [
        'year' => 2026, 'month' => 8, 'consumption_kwh' => 0,
        'temperature' => 0, 'humidity' => 0, 'rainfall' => 0,
        'solar_irradiance' => 0, 'peak_demand_kw' => 0,
        'lag_1' => null, 'lag_2' => null, 'trend' => 1, 'month_sin' => 0, 'month_cos' => 1,
    ]);
}

function assertDashboardValuesForBothRoles($test, array $expected, array $counts): void
{
    // Snapshot persisted facts, not serialized metadata; reads must not repair them.
    $snapshot = function () {
        $rows = [];
        foreach (['processed_records', 'forecast_results', 'dss_results', 'reports', 'datasets', 'audit_trails'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        $rows['files'] = [];
        foreach (Storage::allFiles() as $path) {
            $rows['files'][$path] = Storage::get($path);
        }

        return json_encode($rows);
    };
    $before = $snapshot();
    foreach (['admin', 'user'] as $role) {
        $test->actingAs(User::factory()->create(['role' => $role]));
        $response = $test->get(route($role.'.dashboard'))->assertOk();
        $response->assertInertia(function (Assert $page) use ($role, $expected, $counts) {
            $page->component(ucfirst($role).'/Dashboard');
            foreach ($expected as $field => $value) {
                $page->where('stats.'.$field, $value);
            }
            if ($role === 'admin') {
                foreach ($counts as $field => $value) {
                    $page->where('stats.'.$field, $value);
                }
            }
        });
    }
    expect($snapshot())->toBe($before);
    Process::assertNothingRan();
}

test('empty dashboards disclose absent quantities as null while counts remain numeric zero', function () {
    assertDashboardValuesForBothRoles($this, [
        'latest_average_consumption' => null, 'latest_predicted_consumption' => null,
        'forecast_change_percent' => null, 'previous_consumption' => null,
        'peak_demand_kw' => null, 'solar_irradiance' => null,
        'latest_period' => 'No data', 'forecast_period' => 'No forecast',
    ], ['datasets' => 0, 'processed_records' => 0, 'forecasts' => 0, 'reports' => 0]);
});

test('zero Dashboard measurements and saved forecasts stay zero without touching stale linked records', function () {
    dashboardValueActual();
    ForecastResult::create(['year' => 2026, 'month' => 9, 'predicted_consumption_kwh' => 0,
        'change_percent' => 0, 'previous_consumption_kwh' => 0]);
    $stale = ForecastResult::create(['year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 999]);
    DssResult::create(['forecast_result_id' => $stale->id,
        'demand_status' => 'Original demand', 'readiness_level' => 'Original readiness',
        'recommendations' => ['Original'], 'priority_actions' => ['Original']]);
    Report::create(['title' => 'Original report', 'type' => 'synthetic', 'summary' => ['original' => true]]);
    Storage::put('ml/model_state.json', '{"synthetic":"unchanged"}');
    assertDashboardValuesForBothRoles($this, [
        'latest_average_consumption' => 0, 'latest_predicted_consumption' => 0,
        'forecast_change_percent' => 0, 'previous_consumption' => 0,
        'peak_demand_kw' => 0, 'solar_irradiance' => 0,
        'latest_period' => '2026-08', 'forecast_period' => '2026-09',
        'demand_status' => 'No DSS result',
    ], ['datasets' => 0, 'processed_records' => 1, 'forecasts' => 1, 'reports' => 1]);
});

test('available actuals do not invent a forecast or borrow an out of horizon saved estimate', function () {
    dashboardValueActual(['consumption_kwh' => 1234.56, 'peak_demand_kw' => 23.45, 'solar_irradiance' => 4.56]);
    ForecastResult::create(['year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 999]);
    assertDashboardValuesForBothRoles($this, [
        'latest_average_consumption' => 1234.56, 'latest_predicted_consumption' => null,
        'forecast_change_percent' => null, 'previous_consumption' => null,
        'peak_demand_kw' => 23.45, 'solar_irradiance' => 4.56,
        'latest_period' => '2026-08', 'forecast_period' => 'No forecast',
    ], ['datasets' => 0, 'processed_records' => 1, 'forecasts' => 0, 'reports' => 0]);
});

test('Dashboard change remains nullable and preserves signed values independently of absent previous consumption', function ($change) {
    dashboardValueActual(['consumption_kwh' => 1000]);
    ForecastResult::create(['year' => 2026, 'month' => 9, 'predicted_consumption_kwh' => 1200,
        'previous_consumption_kwh' => null, 'change_percent' => $change]);
    assertDashboardValuesForBothRoles($this, [
        'latest_average_consumption' => 1000, 'latest_predicted_consumption' => 1200,
        'forecast_change_percent' => $change, 'previous_consumption' => null,
        'forecast_period' => '2026-09',
    ], ['datasets' => 0, 'processed_records' => 1, 'forecasts' => 1, 'reports' => 0]);
})->with([null, 0, 12.34, -12.34]);
