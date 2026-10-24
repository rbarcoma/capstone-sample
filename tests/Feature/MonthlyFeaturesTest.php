<?php

use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Support\EnergyPreprocessor;
use App\Support\ForecastPeriod;
use App\Support\MlWorkflow;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function monthlyActual(int $year, int $month, float $consumption): void
{
    ConsumptionRecord::create([
        'year' => $year, 'month' => $month, 'consumption_kwh' => $consumption, 'peak_demand_kw' => 20,
    ]);
    ClimateRecord::create([
        'year' => $year, 'month' => $month, 'temperature' => 25,
        'humidity' => 70, 'rainfall' => 10, 'solar_irradiance' => 5,
    ]);
}

test('preprocessing retains every actual and stores unavailable calendar lags as null', function () {
    monthlyActual(2026, 1, 100);
    monthlyActual(2025, 11, 0);
    monthlyActual(2025, 12, 200);

    expect(EnergyPreprocessor::run())->toBe(3);
    $rows = ProcessedRecord::orderBy('year')->orderBy('month')->get();
    expect($rows[0]->lag_1)->toBeNull();
    expect($rows[0]->lag_2)->toBeNull();
    expect((float) $rows[1]->lag_1)->toBe(0.0);
    expect($rows[1]->lag_2)->toBeNull();
    expect((float) $rows[2]->lag_1)->toBe(200.0);
    expect((float) $rows[2]->lag_2)->toBe(0.0);
    expect((int) $rows[2]->trend)->toBe(3);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-02');
});

test('preprocessing uses the exact calendar lag and elapsed trend across missing months', function () {
    monthlyActual(2025, 12, 100);
    monthlyActual(2026, 2, 200);
    monthlyActual(2026, 3, 300);
    EnergyPreprocessor::run();
    $february = ProcessedRecord::where('month', 2)->first();
    $march = ProcessedRecord::where('month', 3)->first();
    expect($february->lag_1)->toBeNull();
    expect((float) $february->lag_2)->toBe(100.0);
    expect((int) $february->trend)->toBe(3);
    expect((float) $march->lag_1)->toBe(200.0);
    expect($march->lag_2)->toBeNull();
    expect((int) $march->trend)->toBe(4);
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-04');
});

test('changing later actuals leaves earlier preprocessed features unchanged', function () {
    foreach (range(1, 9) as $month) {
        monthlyActual(2026, $month, 100 + $month);
    }
    EnergyPreprocessor::run();
    $fields = ['year', 'month', 'lag_1', 'lag_2', 'trend', 'month_sin', 'month_cos'];
    $before = ProcessedRecord::where('month', '<=', 7)->orderBy('month')->get($fields)->toArray();
    ConsumptionRecord::where('month', '>=', 8)->update(['consumption_kwh' => 99999]);
    ClimateRecord::where('month', '>=', 8)->update(['temperature' => 99]);
    EnergyPreprocessor::run();
    expect(ProcessedRecord::where('month', '<=', 7)->orderBy('month')->get($fields)->toArray())->toBe($before);
});

test('a failed shared feature process preserves the previous processed actual records', function () {
    foreach (range(1, 3) as $month) {
        monthlyActual(2026, $month, 100 + $month);
    }
    EnergyPreprocessor::run();
    $before = ProcessedRecord::get()->toArray();
    Process::fake([Process::result(errorOutput: 'Feature process unavailable.', exitCode: 1)]);
    expect(fn () => EnergyPreprocessor::run())->toThrow(RuntimeException::class, 'Feature process unavailable.');
    expect(ProcessedRecord::get()->toArray())->toBe($before);
});

test('the configured Docker ML workflow excludes warmup rows and invalidates old feature artifacts', function () {
    Storage::fake('local');
    foreach (range(1, 9) as $month) {
        monthlyActual(2026, $month, 1000 + $month);
    }
    EnergyPreprocessor::run();
    $workflow = app(MlWorkflow::class);
    expect($workflow->trainIfDatasetChanged()['trained'])->toBeTrue();
    $state = json_decode(Storage::get('ml/model_state.json'), true);
    $metrics = json_decode(Storage::get($state['metrics_path']), true);
    expect($metrics['actual_rows'])->toBe(9);
    expect($metrics['total_rows'])->toBe(7);
    expect($metrics['excluded_missing_lags'])->toBe(2);
    expect($metrics['feature_version'])->toBe('calendar-past-only-v1');
    expect($workflow->trainIfDatasetChanged()['trained'])->toBeFalse();
    $oldState = $state;
    $oldState['feature_signature'] = 'old-feature-definition';
    Storage::put('ml/model_state.json', json_encode($oldState));
    expect($workflow->trainIfDatasetChanged()['trained'])->toBeTrue();
    expect(json_decode(Storage::get('ml/model_state.json'), true)['feature_signature'])->toBe($state['feature_signature']);
    $result = $workflow->predictAndGenerate();
    expect($result['forecast']->month)->toBe(10);
    expect((float) $result['forecast']->previous_consumption_kwh)->toBe(1009.0);
    expect(ProcessedRecord::count())->toBe(9);
});

test('a latest-month gap returns insufficiency without advancing the forecast period', function () {
    Storage::fake('local');
    foreach (range(1, 8) as $month) {
        monthlyActual(2026, $month, 1000 + $month);
    }
    monthlyActual(2026, 10, 1010);
    EnergyPreprocessor::run();
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-11');
    expect(fn () => app(MlWorkflow::class)->predictAndGenerate())->toThrow(RuntimeException::class, 'Cannot forecast 2026-11');
    expect(ForecastResult::count())->toBe(0);
});
