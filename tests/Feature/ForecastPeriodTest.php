<?php

use App\Models\ConsumptionRecord;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\User;
use App\Support\EnergyDss;
use App\Support\ForecastPeriod;
use App\Support\MlWorkflow;
use App\Support\ReportGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function forecastActual(int $year, int $month): ProcessedRecord
{
    return ProcessedRecord::create([
        'year' => $year, 'month' => $month, 'consumption_kwh' => 1000 + $month,
        'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
        'solar_irradiance' => 5, 'peak_demand_kw' => 20,
        'lag_1' => 1000, 'lag_2' => 1000, 'trend' => $month,
        'month_sin' => sin(2 * pi() * $month / 12),
        'month_cos' => cos(2 * pi() * $month / 12),
    ]);
}

function forecastCsv(array $periods, ?int $lastDay = null): string
{
    $csv = "year,month,day,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n";
    foreach ($periods as $index => [$year, $month]) {
        $days = $lastDay !== null && $index === count($periods) - 1
            ? $lastDay : Carbon::createMidnightDate($year, $month, 1)->daysInMonth;
        foreach (range(1, $days) as $day) {
            $csv .= "$year,$month,$day,100,20,25,70,10,5\n";
        }
    }

    return $csv;
}

beforeEach(function () {
    Storage::fake('local');
    $this->withoutVite();
});

test('the target follows actual data regardless of the clock or stored predictions', function (string $today) {
    $this->travelTo(Carbon::parse($today));
    forecastActual(2026, 9);
    ForecastResult::create(['year' => 2027, 'month' => 6, 'predicted_consumption_kwh' => 9999, 'predicted_at' => now()]);

    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-10');
    expect(ForecastPeriod::current()->first())->toBeNull();
    forecastActual(2026, 10);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-11');
})->with(['2026-08-15', '2026-11-15', '2028-06-01']);

test('missing historical months do not skip the month after the latest actual', function () {
    forecastActual(2026, 3);
    forecastActual(2026, 9);
    forecastActual(2026, 7);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-10');
});

test('December rolls over and no actual records means no forecast period', function () {
    expect(ForecastPeriod::next())->toBeNull();
    forecastActual(2026, 12);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2027-01');
});

test('daily uploads only advance after the next month has every actual day', function () {
    $this->mock(MlWorkflow::class)->shouldReceive('runAfterDatasetUpload')->twice()
        ->andReturn(['predicted' => false, 'message' => 'Test skips training.']);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $periods = [[2026, 7], [2026, 8], [2026, 9], [2026, 10]];

    $this->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent('daily.csv', forecastCsv($periods, 15)),
    ])->assertSessionHasNoErrors();
    expect(ConsumptionRecord::where('month', 10)->exists())->toBeFalse();
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-10');

    $this->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent('daily.csv', forecastCsv($periods)),
    ])->assertSessionHasNoErrors();
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-11');
});

test('an incomplete or invalid replacement cannot erase complete actual data', function (string $csv) {
    forecastActual(2026, 9);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent('invalid.csv', $csv),
        'replace_existing' => true,
    ])->assertSessionHasErrors('dataset');
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-10');
})->with([
    'partial October' => fn () => forecastCsv([[2026, 10]], 30),
    'duplicated day masking a missing day' => fn () => forecastCsv([[2026, 10]], 30)."2026,10,30,100,20,25,70,10,5\n",
    'blank consumption' => "year,month,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n2026,10,,20,25,70,10,5\n",
    'invalid month' => "year,month,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n2026,13,100,20,25,70,10,5\n",
]);

test('monthly actual uploads are accepted and leap year daily data requires every day', function () {
    $this->mock(MlWorkflow::class)->shouldReceive('runAfterDatasetUpload')->twice()
        ->andReturn(['predicted' => false, 'message' => 'Test skips training.']);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $csv = "year,month,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n";
    foreach ([7, 8, 9] as $month) {
        $csv .= "2026,$month,100,20,25,70,10,5\n";
    }
    $this->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent('monthly.csv', $csv),
    ])->assertSessionHasNoErrors();
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-10');

    $this->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent('partial-leap.csv', forecastCsv([[2028, 2]], 28)),
    ])->assertSessionHasErrors('dataset');
    $this->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent('complete-leap.csv', forecastCsv([[2028, 2]])),
    ])->assertSessionHasNoErrors();
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2028-03');
});

test('all current views and new reports use the same dataset based period and DSS', function () {
    forecastActual(2026, 9);
    $forecast = ForecastResult::create([
        'year' => 2026, 'month' => 10, 'predicted_consumption_kwh' => 1100, 'predicted_at' => now()->subDay(),
    ]);
    $dss = EnergyDss::generateForForecast($forecast, null, now()->subDay());
    $wrong = ForecastResult::create([
        'year' => 2026, 'month' => 12, 'predicted_consumption_kwh' => 9999, 'predicted_at' => now(),
    ]);
    EnergyDss::generateForForecast($wrong);
    $report = ReportGenerator::create();
    expect($report->summary['prediction_explanation']['forecast_period'])->toBe('2026-10');
    expect($report->summary['latest_dss']['forecast_result_id'])->toBe($forecast->id);

    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('stats.forecast_period', '2026-10')->has('recentForecasts', 1));
    $this->get(route('admin.forecasting'))->assertInertia(fn (Assert $page) => $page
        ->where('latestForecast.id', $forecast->id)->has('forecastHistory', 1));
    $this->get(route('admin.analytics'))->assertInertia(fn (Assert $page) => $page
        ->has('actualPredictedTrend', 2)->where('actualPredictedTrend.1.period', '2026-10'));
    $this->get(route('admin.decision-support'))->assertInertia(fn (Assert $page) => $page
        ->where('latestDss.id', $dss->id)->where('latestDss.forecast_result.month', 10));
    $this->get(route('admin.reports'))->assertInertia(fn (Assert $page) => $page
        ->where('latestForecast.id', $forecast->id)->where('latestDss.id', $dss->id));

    $this->actingAs(User::factory()->create());
    $this->get(route('user.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('stats.forecast_period', '2026-10')->has('forecastHistory', 1));
    $this->get(route('user.forecast-results'))->assertInertia(fn (Assert $page) => $page
        ->where('latestForecast.id', $forecast->id));
    $this->get(route('user.analytics'))->assertInertia(fn (Assert $page) => $page
        ->has('actualPredictedTrend', 2)->where('actualPredictedTrend.1.period', '2026-10'));
    $this->get(route('user.decision-support-results'))->assertInertia(fn (Assert $page) => $page
        ->where('latestDss.id', $dss->id));
    $this->get(route('user.reports'))->assertInertia(fn (Assert $page) => $page
        ->where('reports.0.summary.prediction_explanation.forecast_period', '2026-10'));
});

test('the workflow rejects a prediction for any period beyond the dataset horizon', function () {
    foreach (range(4, 9) as $month) {
        forecastActual(2026, $month);
    }
    Storage::put('ml/model.pkl', 'test-model');
    Storage::put('ml/model_state.json', json_encode(['model_path' => 'ml/model.pkl', 'metrics_path' => 'ml/metrics.json']));
    Process::fake([Process::result(output: json_encode(['year' => 2026, 'month' => 12, 'predicted_consumption_kwh' => 1000]))]);

    expect(fn () => app(MlWorkflow::class)->predictAndGenerate())
        ->toThrow(RuntimeException::class, 'Prediction period must immediately follow');
    expect(ForecastResult::count())->toBe(0);
});

test('real Python workflow repeats October until October actual data exists', function () {
    config(['services.ml.python' => 'python3']);
    $this->travelTo(Carbon::parse('2028-06-01'));
    foreach (range(4, 9) as $month) {
        forecastActual(2026, $month);
    }
    $workflow = app(MlWorkflow::class);
    $first = $workflow->predictAndGenerate();
    $second = $workflow->predictAndGenerate();
    expect($first['forecast']->month)->toBe(10);
    expect($second['forecast']->id)->toBe($first['forecast']->id);
    expect((float) $second['forecast']->previous_consumption_kwh)->toBe(1009.0);
    expect(ForecastResult::count())->toBe(1);
    expect($second['report']->summary['prediction_explanation']['forecast_period'])->toBe('2026-10');
    forecastActual(2026, 10);
    $next = $workflow->runScheduledMonthlyPrediction();
    expect($next['forecast']->month)->toBe(11);
    expect((float) $next['forecast']->previous_consumption_kwh)->toBe(1010.0);
});

test('a stale forecast and its DSS are not used while the dataset next month is pending', function () {
    forecastActual(2026, 10);
    $old = ForecastResult::create(['year' => 2026, 'month' => 10, 'predicted_consumption_kwh' => 1000]);
    EnergyDss::generateForForecast($old);
    expect(ForecastPeriod::current()->first())->toBeNull();
    expect(ForecastPeriod::currentDss()->first())->toBeNull();
    $report = ReportGenerator::create();
    expect($report->summary['latest_forecast'])->toBeNull();
    expect($report->summary['latest_dss'])->toBeNull();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->post(route('admin.decision-support.generate'))->assertSessionHas('error');
});
