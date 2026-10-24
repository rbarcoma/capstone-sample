<?php

use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\Dataset;
use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\AnalyticsSeries;
use App\Support\DatasetIntegrity;
use App\Support\ForecastPeriod;
use App\Support\MlWorkflow;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function artifactState(): array
{
    return json_decode(Storage::get('ml/model_state.json'), true, flags: JSON_THROW_ON_ERROR);
}

function artifactSnapshot(): array
{
    $state = artifactState();

    return array_combine(
        ['ml/model_state.json', $state['model_path'], $state['metrics_path']],
        array_map(fn ($path) => Storage::get($path), ['ml/model_state.json', $state['model_path'], $state['metrics_path']])
    );
}

function assertPublishedArtifacts(array $state): void
{
    expect(dirname($state['model_path']))->toBe(dirname($state['metrics_path']));
    foreach (['model', 'metrics'] as $name) {
        expect(Storage::exists($state[$name.'_path']))->toBeTrue();
        expect(hash_file('sha256', Storage::path($state[$name.'_path'])))->toBe($state[$name.'_sha256']);
    }
}

function artifactFiles(): array
{
    $files = Storage::allFiles('ml');

    return array_combine($files, array_map(fn ($path) => Storage::get($path), $files));
}

function readArtifactFixture(): array
{
    // Build a synthetic v2 publication directly; read-path tests never train.
    $headers = ['year', 'month', 'consumption_kwh', 'temperature', 'humidity', 'rainfall',
        'solar_irradiance', 'peak_demand_kw', 'lag_1', 'lag_2', 'trend', 'month_sin', 'month_cos'];
    $records = ProcessedRecord::orderBy('year')->orderBy('month')->get();
    $lines = [implode(',', $headers)];
    foreach ($records as $record) {
        $lines[] = implode(',', array_map(fn ($field) => $record->{$field}, $headers));
    }
    $csv = implode(PHP_EOL, $lines);
    Storage::put('ml/processed_dataset.csv', $csv);
    $protocol = 'chronological_holdout_25pct_rolling_cv_v1';
    $identity = [
        'format_version' => 2,
        'dataset_hash' => hash('sha256', $csv),
        'feature_signature' => hash_file('sha256', base_path('Python/monthly_features.py')),
        'trainer_signature' => hash('sha256', json_encode([
            'source_sha256' => hash_file('sha256', base_path('Python/train_model.py')),
            'metric_definitions_sha256' => hash_file('sha256', base_path('resources/forecast-metrics.json')),
            'python' => config('services.ml.python'), 'evaluation_protocol' => $protocol,
        ])),
        'training_cutoff' => sprintf('%04d-%02d', $records->last()->year, $records->last()->month),
        'evaluation_protocol' => $protocol,
    ];
    $id = hash('sha256', json_encode($identity));
    $directory = 'ml/models/'.$id.'_00000000-0000-4000-8000-000000000001';
    $model = 'synthetic-model-1';
    $metrics = json_encode(['model_type' => 'Linear Regression', 'mae' => 11, 'rmse' => 21, 'r2_score' => -1]);
    Storage::put($directory.'/model.pkl', $model);
    Storage::put($directory.'/metrics.json', $metrics);
    $state = $identity + [
        'artifact_id' => $id, 'model_path' => $directory.'/model.pkl', 'metrics_path' => $directory.'/metrics.json',
        'model_sha256' => hash('sha256', $model), 'metrics_sha256' => hash('sha256', $metrics),
        'trained_at' => now()->toDateTimeString(), 'trained_by' => null,
    ];
    Storage::put('ml/model_state.json', json_encode($state));
    Storage::put('ml/workflow.lock', '');

    return $state;
}

function assertHistoricalArtifactRefused($testCase): void
{
    $before = artifactFiles();
    $records = ProcessedRecord::get()->toArray();
    $trainingRuns = $testCase->trainingRuns;
    $called = false;
    expect($testCase->workflow->withValidatedArtifact(function () use (&$called) {
        $called = true;
    }))->toBeNull();
    expect($called)->toBeFalse();
    foreach (['admin', 'user'] as $role) {
        $testCase->actingAs(User::factory()->create(['role' => $role]));
        $testCase->get(route($role.'.analytics'))->assertOk()->assertInertia(function (Assert $page) use ($role) {
            $page->component($role === 'admin' ? 'Admin/Analytics' : 'User/Analytics', false)
                ->has('actualPredictedTrend', 9)
                ->where('actualPredictedTrend.8.actual', 1009);
            foreach (range(0, 8) as $index) {
                $page->where('actualPredictedTrend.'.$index.'.fitted', null);
            }
        });
    }
    expect($testCase->historicalRuns)->toBe(0);
    expect($testCase->trainingRuns)->toBe($trainingRuns);
    expect($testCase->predictionRuns)->toBe(0);
    expect(ForecastResult::count())->toBe(0);
    expect(ProcessedRecord::get()->toArray())->toBe($records);
    expect(artifactFiles())->toBe($before);
}

beforeEach(function () {
    Storage::fake('local');
    $this->artifactDisk = Storage::disk('local');
    Log::spy();
    $this->withoutVite();
    $this->originalBasePath = base_path();
    // Edit isolated copies when exercising source invalidation, never project files.
    Storage::makeDirectory('fixture-source/Python');
    foreach (['train_model.py', 'monthly_features.py'] as $file) {
        Storage::put('fixture-source/Python/'.$file, file_get_contents(base_path('Python/'.$file)));
    }
    Storage::makeDirectory('fixture-source/resources');
    Storage::put('fixture-source/resources/forecast-metrics.json', file_get_contents(base_path('resources/forecast-metrics.json')));
    app()->setBasePath(Storage::path('fixture-source'));
    $this->trainingRuns = 0;
    $this->predictionRuns = 0;
    $this->historicalRuns = 0;
    $this->onTrain = null;
    $this->onPredict = null;
    $this->onHistory = null;
    foreach (range(1, 9) as $month) {
        ProcessedRecord::create([
            'year' => 2026, 'month' => $month, 'consumption_kwh' => 1000 + $month,
            'temperature' => 25, 'humidity' => 70, 'rainfall' => 10,
            'solar_irradiance' => 5, 'peak_demand_kw' => 20,
            'lag_1' => $month > 1 ? 999 + $month : null,
            'lag_2' => $month > 2 ? 998 + $month : null,
            'trend' => $month, 'month_sin' => sin(2 * pi() * $month / 12),
            'month_cos' => cos(2 * pi() * $month / 12),
        ]);
    }
    Process::preventStrayProcesses();
    Process::fake(function ($process) {
        [$python, $script, $dataset, $model] = $process->command;
        $metrics = $process->command[4] ?? null;
        if ($script === 'train_model.py') {
            $this->trainingRuns++;
            if ($this->onTrain && ($result = ($this->onTrain)($process)) !== null) {
                return $result;
            }
            file_put_contents($model, 'synthetic-model-'.$this->trainingRuns);
            file_put_contents($metrics, json_encode([
                'model_type' => 'Linear Regression', 'mae' => 10 + $this->trainingRuns,
                'rmse' => 20 + $this->trainingRuns, 'r2_score' => -$this->trainingRuns,
            ]));

            return Process::result(output: 'Synthetic training complete.');
        }
        if ($script === 'predict_history.py') {
            $this->historicalRuns++;
            if ($this->onHistory && ($result = ($this->onHistory)($process)) !== null) {
                return $result;
            }
            expect($model)->toBe(Storage::path(artifactState()['model_path']));

            return Process::result(output: json_encode([
                ['period' => '2026-09', 'year' => 2026, 'month' => 9, 'fitted' => 1004, 'kind' => 'in_sample_fit'],
            ]));
        }
        expect($script)->toBe('predict_next_month.py');
        $this->predictionRuns++;
        if ($this->onPredict && ($result = ($this->onPredict)($process)) !== null) {
            return $result;
        }
        $values = json_decode(file_get_contents($metrics), true);
        // Prove the arguments resolve the metrics belonging to this exact model.
        expect(file_get_contents($model))->toBe('synthetic-model-'.(int) -$values['r2_score']);
        $period = ForecastPeriod::next();

        return Process::result(output: json_encode($values + [
            'year' => $period->year, 'month' => $period->month,
            'predicted_consumption_kwh' => 1200, 'previous_consumption_kwh' => 1009,
            'change_percent' => 18.93,
        ]));
    });
    $this->workflow = app(MlWorkflow::class);
});

afterEach(function () {
    app()->setBasePath($this->originalBasePath);
});

test('artifact state records identity cutoff protocol pair hashes and attribution', function () {
    $user = User::factory()->create();
    expect($this->workflow->trainIfDatasetChanged($user->id)['trained'])->toBeTrue();
    $state = artifactState();
    expect($state)->toHaveKeys([
        'format_version', 'artifact_id', 'dataset_hash', 'feature_signature', 'trainer_signature',
        'training_cutoff', 'evaluation_protocol', 'model_path', 'metrics_path',
        'model_sha256', 'metrics_sha256', 'trained_at', 'trained_by',
    ]);
    expect($state['format_version'])->toBe(2);
    expect($state['dataset_hash'])->toBe(hash_file('sha256', Storage::path('ml/processed_dataset.csv')));
    expect($state['feature_signature'])->toBe(hash_file('sha256', base_path('Python/monthly_features.py')));
    expect($state['trainer_signature'])->toHaveLength(64);
    expect($state['training_cutoff'])->toBe('2026-09');
    expect($state['evaluation_protocol'])->toBe('chronological_holdout_25pct_rolling_cv_v1');
    expect($state['trained_by'])->toBe($user->id);
    expect($state['trained_at'])->toBe(now()->toDateTimeString());
    assertPublishedArtifacts($state);
    $before = artifactSnapshot();
    expect($this->workflow->trainIfDatasetChanged()['trained'])->toBeFalse();
    expect(artifactSnapshot())->toBe($before);
    expect($this->trainingRuns)->toBe(1);
});

test('trainer hyperparameter feature dataset and interpreter changes invalidate artifact identity', function (string $change) {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactState();
    $oldFiles = artifactSnapshot();
    match ($change) {
        'trainer' => file_put_contents(base_path('Python/train_model.py'), "\n# Synthetic trainer revision\n", FILE_APPEND),
        'hyperparameter' => file_put_contents(base_path('Python/train_model.py'), str_replace(
            'ridge = 0.1', 'ridge = 0.2', file_get_contents(base_path('Python/train_model.py'))
        )),
        'feature' => file_put_contents(base_path('Python/monthly_features.py'), "\n# Synthetic feature revision\n", FILE_APPEND),
        'metric definitions' => file_put_contents(base_path('resources/forecast-metrics.json'), "\n", FILE_APPEND),
        'dataset' => ProcessedRecord::where('month', 9)->update(['consumption_kwh' => 2009]),
        'interpreter' => config(['services.ml.python' => 'synthetic-python-revision']),
    };
    expect($this->workflow->trainIfDatasetChanged()['trained'])->toBeTrue();
    $after = artifactState();
    expect($after['artifact_id'])->not->toBe($before['artifact_id']);
    expect($after['model_path'])->not->toBe($before['model_path']);
    expect($this->trainingRuns)->toBe(2);
    foreach ([$before['model_path'], $before['metrics_path']] as $path) {
        expect(Storage::get($path))->toBe($oldFiles[$path]);
    }
    assertPublishedArtifacts($after);
})->with(['trainer', 'hyperparameter', 'feature', 'metric definitions', 'dataset', 'interpreter']);

test('partial failed training preserves the active pair and state and prediction can still use them', function () {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactSnapshot();
    $files = Storage::allFiles('ml/models');
    $this->onTrain = function ($process) use ($before) {
        expect(artifactSnapshot())->toBe($before);
        file_put_contents($process->command[3], 'partial model');

        return Process::result(errorOutput: 'Synthetic training failure after partial write.', exitCode: 1);
    };
    expect(fn () => $this->workflow->trainIfDatasetChanged(force: true))
        ->toThrow(RuntimeException::class, 'Synthetic training failure');
    expect(artifactSnapshot())->toBe($before);
    expect(Storage::allFiles('ml/models'))->toBe($files);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
    $result = $this->workflow->predictAndGenerate(generateReport: false);
    expect($result['forecast']->month)->toBe(10);
    expect((float) $result['forecast']->mae)->toBe(11.0);
    expect(artifactSnapshot())->toBe($before);
    expect($this->trainingRuns)->toBe(2);
    expect($this->predictionRuns)->toBe(1);
});

test('successful process with absent empty or invalid outputs cannot replace a valid publication', function (string $invalid) {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactSnapshot();
    $files = Storage::allFiles('ml/models');
    $this->onTrain = function ($process) use ($invalid) {
        file_put_contents($process->command[3], $invalid === 'empty model' ? '' : 'synthetic model');
        if ($invalid !== 'missing metrics') {
            file_put_contents($process->command[4], $invalid === 'invalid JSON' ? '{' : json_encode([
                'model_type' => 'Linear Regression', 'mae' => 'bad', 'rmse' => 1, 'r2_score' => -1,
            ]));
        }

        return Process::result();
    };
    expect(fn () => $this->workflow->trainIfDatasetChanged(force: true))->toThrow(RuntimeException::class);
    expect(artifactSnapshot())->toBe($before);
    expect(Storage::allFiles('ml/models'))->toBe($files);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
})->with(['missing metrics', 'empty model', 'invalid JSON', 'invalid metric']);

test('publication exposes the old complete pair until one atomic state pointer replacement', function () {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactSnapshot();
    $moves = 0;
    File::partialMock()->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($before, &$moves) {
        $moves++;
        expect(artifactSnapshot())->toBe($before);
        assertPublishedArtifacts(artifactState());
        if ($to === Storage::path('ml/model_state.json')) {
            $next = json_decode(file_get_contents($from), true);
            assertPublishedArtifacts($next);
            expect($next['model_path'])->not->toBe(artifactState()['model_path']);
        }

        return rename($from, $to);
    });
    $this->workflow->trainIfDatasetChanged(force: true);
    expect($moves)->toBe(3); // Atomic export, complete generation, then state pointer.
    assertPublishedArtifacts(artifactState());
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
    foreach (array_diff(array_keys($before), ['ml/model_state.json']) as $path) {
        expect(Storage::get($path))->toBe($before[$path]);
    }
});

test('a generation move state write or pointer commit failure preserves the previous publication', function (string $failure) {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactSnapshot();
    $files = Storage::allFiles('ml/models');
    if ($failure === 'state write') {
        $disk = Storage::disk('local');
        $storage = Mockery::mock(get_class($disk), [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
        $storage->shouldReceive('put')->andReturnUsing(
            fn ($path, $contents) => str_starts_with($path, 'ml/.model_state_') ? false : $disk->put($path, $contents)
        );
        Storage::set('local', $storage);
    } else {
        File::partialMock()->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($failure) {
            if (($failure === 'pointer commit' && $to === Storage::path('ml/model_state.json'))
                || ($failure === 'generation move' && str_starts_with($to, Storage::path('ml/models/')))) {
                return false;
            }

            return rename($from, $to);
        });
    }
    expect(fn () => $this->workflow->trainIfDatasetChanged(force: true))->toThrow(RuntimeException::class);
    expect(artifactSnapshot())->toBe($before);
    expect(Storage::allFiles('ml/models'))->toBe($files);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
    expect(array_filter(Storage::allFiles('ml'), fn ($path) => str_starts_with($path, 'ml/.model_state_')))->toBe([]);
    $this->workflow->predictAndGenerate(generateReport: false);
    expect($this->predictionRuns)->toBe(1);
})->with(['generation move', 'state write', 'pointer commit']);

test('missing or mismatched model metrics and state are retrained before prediction', function (string $damage) {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactState();
    match ($damage) {
        'missing model' => Storage::delete($before['model_path']),
        'missing metrics' => Storage::delete($before['metrics_path']),
        'changed model' => Storage::put($before['model_path'], 'model from another run'),
        'changed metrics' => Storage::put($before['metrics_path'], json_encode([
            'model_type' => 'Linear Regression', 'mae' => 999, 'rmse' => 999, 'r2_score' => -999,
        ])),
        'malformed state' => Storage::put('ml/model_state.json', '{'),
        'scalar state' => Storage::put('ml/model_state.json', 'true'),
        'legacy state' => Storage::put('ml/model_state.json', json_encode(array_intersect_key($before, array_flip([
            'dataset_hash', 'feature_signature', 'model_path', 'metrics_path', 'trained_at', 'trained_by',
        ])))),
    };
    $result = $this->workflow->predictAndGenerate(generateReport: false);
    expect($this->trainingRuns)->toBe(2);
    expect($this->predictionRuns)->toBe(1);
    expect(artifactState()['model_path'])->not->toBe($before['model_path']);
    expect((float) $result['forecast']->mae)->toBe(12.0);
    expect((float) $result['forecast']->rmse)->toBe(22.0);
    expect((float) $result['forecast']->r2_score)->toBe(-2.0);
    assertPublishedArtifacts(artifactState());
})->with(['missing model', 'missing metrics', 'changed model', 'changed metrics', 'malformed state', 'scalar state', 'legacy state']);

test('a bad pair and failed repair never invoke prediction or create a forecast', function () {
    $this->workflow->trainIfDatasetChanged();
    Storage::put(artifactState()['metrics_path'], '{}');
    $state = Storage::get('ml/model_state.json');
    $this->onTrain = fn () => Process::result(errorOutput: 'Repair failed. Run Train Model again.', exitCode: 1);
    expect(fn () => $this->workflow->predictAndGenerate(generateReport: false))
        ->toThrow(RuntimeException::class, 'Run Train Model again');
    expect($this->predictionRuns)->toBe(0);
    expect(ForecastResult::count())->toBe(0);
    expect(Storage::get('ml/model_state.json'))->toBe($state);
});

test('individually valid model and metrics from different generations cannot be mixed in state', function () {
    $this->workflow->trainIfDatasetChanged();
    $first = artifactState();
    $this->workflow->trainIfDatasetChanged(force: true);
    $mixed = artifactState();
    $mixed['metrics_path'] = $first['metrics_path'];
    $mixed['metrics_sha256'] = $first['metrics_sha256'];
    Storage::put('ml/model_state.json', json_encode($mixed));
    $result = $this->workflow->predictAndGenerate(generateReport: false);
    expect($this->trainingRuns)->toBe(3);
    expect((float) $result['forecast']->mae)->toBe(13.0);
    expect((float) $result['forecast']->r2_score)->toBe(-3.0);
    assertPublishedArtifacts(artifactState());
});

test('Python prediction fallback cannot overwrite the immutable published model or metrics', function () {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactSnapshot();
    $this->onPredict = function ($process) {
        file_put_contents($process->command[3], 'partial fallback training');

        return Process::result(errorOutput: 'Fallback training failed.', exitCode: 1);
    };
    expect(fn () => $this->workflow->predictAndGenerate(generateReport: false))
        ->toThrow(RuntimeException::class, 'Model/metrics pair does not match');
    expect(artifactSnapshot())->toBe($before);
    expect(ForecastResult::count())->toBe(0);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
});

test('a source change during training cannot publish an artifact with stale provenance', function () {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactSnapshot();
    $this->onTrain = function () {
        file_put_contents(base_path('Python/train_model.py'), "\n# Synthetic in-flight revision\n", FILE_APPEND);

        return null;
    };
    expect(fn () => $this->workflow->trainIfDatasetChanged(force: true))
        ->toThrow(RuntimeException::class, 'configuration changed during training');
    expect(artifactSnapshot())->toBe($before);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
});

test('a failed first publication leaves no state pointing at uncommitted files', function () {
    File::partialMock()->shouldReceive('move')->andReturnUsing(
        fn ($from, $to) => $to === Storage::path('ml/model_state.json') ? false : rename($from, $to)
    );
    expect(fn () => $this->workflow->trainIfDatasetChanged())
        ->toThrow(RuntimeException::class, 'Unable to publish model state');
    expect(Storage::exists('ml/model_state.json'))->toBeFalse();
    expect(Storage::allFiles('ml/models'))->toBe([]);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
});

test('historical reads refuse a tampered model without training or mutating artifacts', function () {
    $this->workflow->trainIfDatasetChanged();
    Storage::put(artifactState()['model_path'], 'tampered synthetic model');
    assertHistoricalArtifactRefused($this);
});

test('historical reads refuse tampered metrics without serving an unchecked model', function () {
    $this->workflow->trainIfDatasetChanged();
    Storage::put(artifactState()['metrics_path'], json_encode([
        'model_type' => 'Linear Regression', 'mae' => 999, 'rmse' => 999, 'r2_score' => -999,
    ]));
    assertHistoricalArtifactRefused($this);
});

test('historical reads refuse cross generation paths even when individual hashes are correct', function () {
    $this->workflow->trainIfDatasetChanged();
    $first = artifactState();
    $this->workflow->trainIfDatasetChanged(force: true);
    $mixed = artifactState();
    $mixed['metrics_path'] = $first['metrics_path'];
    $mixed['metrics_sha256'] = $first['metrics_sha256'];
    Storage::put('ml/model_state.json', json_encode($mixed));
    assertHistoricalArtifactRefused($this);
});

test('historical reads refuse a truncated export rather than using the previously trained model', function () {
    $this->workflow->trainIfDatasetChanged();
    Storage::put('ml/processed_dataset.csv', substr(Storage::get('ml/processed_dataset.csv'), 0, 80));
    assertHistoricalArtifactRefused($this);
});

test('historical reads degrade gracefully for absent malformed legacy and stale artifacts', function (string $damage) {
    $this->workflow->trainIfDatasetChanged();
    $state = artifactState();
    match ($damage) {
        'missing state' => Storage::delete('ml/model_state.json'),
        'malformed state' => Storage::put('ml/model_state.json', '{'),
        'missing model' => Storage::delete($state['model_path']),
        'missing metrics' => Storage::delete($state['metrics_path']),
        'legacy state' => Storage::put('ml/model_state.json', json_encode(array_intersect_key($state, array_flip([
            'dataset_hash', 'feature_signature', 'model_path', 'metrics_path', 'trained_at', 'trained_by',
        ])))),
        'trainer revision' => file_put_contents(base_path('Python/train_model.py'), "\n# Test-only read invalidation\n", FILE_APPEND),
        'feature revision' => file_put_contents(base_path('Python/monthly_features.py'), "\n# Test-only read invalidation\n", FILE_APPEND),
        'interpreter revision' => config(['services.ml.python' => 'test-only-interpreter']),
    };
    assertHistoricalArtifactRefused($this);
})->with(['missing state', 'malformed state', 'missing model', 'missing metrics', 'legacy state', 'trainer revision', 'feature revision', 'interpreter revision']);

test('valid artifacts preserve historical values for both Analytics pages without any workflow writes', function () {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactFiles();
    $records = ProcessedRecord::get()->toArray();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($role === 'admin' ? 'Admin/Analytics' : 'User/Analytics', false)
            ->has('actualPredictedTrend', 9)
            ->where('actualPredictedTrend.8.actual', 1009)
            ->where('actualPredictedTrend.8.fitted', 1004)
            ->missing('actualPredictedTrend.8.accuracy'));
    }
    expect($this->historicalRuns)->toBe(2);
    expect($this->trainingRuns)->toBe(1);
    expect($this->predictionRuns)->toBe(0);
    expect(artifactFiles())->toBe($before);
    expect(ProcessedRecord::get()->toArray())->toBe($records);
    expect(ForecastResult::count())->toBe(0);
});

test('historical results are discarded if validated inputs change during the read', function (string $pathKey) {
    $this->workflow->trainIfDatasetChanged();
    $result = $this->workflow->withValidatedArtifact(function ($artifact) use ($pathKey) {
        Storage::put($artifact[$pathKey], 'simulated external partial write');

        return ['unverified prediction'];
    });
    expect($result)->toBeNull();
    expect($this->trainingRuns)->toBe(1);
    expect($this->historicalRuns)->toBe(0);
})->with(['model_path', 'dataset_path']);

test('the complete export is staged and atomically replaced even on a model cache hit', function () {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactFiles();
    $export = Storage::get('ml/processed_dataset.csv');
    $moves = 0;
    File::partialMock()->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($export, &$moves) {
        $moves++;
        expect($to)->toBe(Storage::path('ml/processed_dataset.csv'));
        expect($from)->not->toBe($to);
        expect(dirname($from))->toBe(dirname($to));
        expect(file_get_contents($from))->toBe($export);
        expect(file_get_contents($to))->toBe($export);

        return rename($from, $to);
    });
    expect($this->workflow->trainIfDatasetChanged()['trained'])->toBeFalse();
    expect($moves)->toBe(1);
    expect($this->trainingRuns)->toBe(1);
    expect(artifactFiles())->toBe($before);
});

test('a partial temporary export or failed rename preserves the previous export pair and state', function (string $failure) {
    $this->workflow->trainIfDatasetChanged();
    $before = artifactFiles();
    ProcessedRecord::where('month', 9)->update(['consumption_kwh' => 2009]);
    if ($failure === 'rename') {
        File::partialMock()->shouldReceive('move')->andReturnUsing(function ($from, $to) {
            expect($to)->toBe(Storage::path('ml/processed_dataset.csv'));

            return false;
        });
    } else {
        $disk = Storage::disk('local');
        $storage = Mockery::mock(get_class($disk), [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
        $storage->shouldReceive('put')->andReturnUsing(function ($path, $contents) use ($disk, $failure) {
            if (str_starts_with($path, 'ml/.processed_dataset_')) {
                $disk->put($path, substr($contents, 0, 80));

                return $failure === 'truncated successful write';
            }

            return $disk->put($path, $contents);
        });
        Storage::set('local', $storage);
    }
    expect(fn () => $this->workflow->trainIfDatasetChanged())->toThrow(RuntimeException::class);
    expect($this->trainingRuns)->toBe(1);
    expect(artifactFiles())->toBe($before);
})->with(['partial failed write', 'truncated successful write', 'rename']);

test('a failed first export publishes neither a truncated dataset nor artifact state', function () {
    File::partialMock()->shouldReceive('move')->andReturn(false);
    expect(fn () => $this->workflow->trainIfDatasetChanged())
        ->toThrow(RuntimeException::class, 'Unable to publish the processed dataset');
    expect(Storage::exists('ml/processed_dataset.csv'))->toBeFalse();
    expect(Storage::exists('ml/model_state.json'))->toBeFalse();
    expect($this->trainingRuns)->toBe(0);
    expect(Storage::allFiles('ml'))->toBe(['ml/workflow.lock']);
});

test('historical readers consume the canonical export even when state supplies a dataset path', function () {
    $state = readArtifactFixture();
    Storage::put('ml/unchecked.csv', 'unchecked historical inputs');
    $state['dataset_path'] = 'ml/unchecked.csv';
    Storage::put('ml/model_state.json', json_encode($state));
    $before = artifactFiles();
    expect($this->workflow->withValidatedArtifact(fn ($artifact) => $artifact['dataset_path']))
        ->toBe('ml/processed_dataset.csv');
    $this->onHistory = function ($process) {
        expect($process->command[2])->toBe(Storage::path('ml/processed_dataset.csv'));

        return null;
    };
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($role === 'admin' ? 'Admin/Analytics' : 'User/Analytics', false)
            ->where('actualPredictedTrend.8.fitted', 1004));
    }
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
    expect($this->historicalRuns)->toBe(2);
    expect(artifactFiles())->toBe($before);
    Log::shouldNotHaveReceived('warning');
});

test('reentrant readers fail fast and leave the outer workflow lock held', function (string $instance) {
    readArtifactFixture();
    $result = $this->workflow->withValidatedArtifact(function () use ($instance) {
        $nested = $instance === 'same instance' ? $this->workflow : app(MlWorkflow::class);
        $called = false;
        expect(fn () => $nested->withValidatedArtifact(function () use (&$called) {
            $called = true;
        }))->toThrow(RuntimeException::class, 'Re-entrant ML workflow calls are not allowed');
        expect($called)->toBeFalse();
        $probe = fopen(Storage::path('ml/workflow.lock'), 'r');
        try {
            expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse();
        } finally {
            fclose($probe);
        }

        return 'outer reader completed';
    });
    expect($result)->toBe('outer reader completed');
    expect(app(MlWorkflow::class)->withValidatedArtifact(fn () => 'next read'))->toBe('next read');
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
})->with(['same instance', 'fresh instance']);

test('reader exceptions release the process guard and file lock for the next read', function () {
    readArtifactFixture();
    expect(fn () => $this->workflow->withValidatedArtifact(fn () => throw new RuntimeException('Reader aborted.')))
        ->toThrow(RuntimeException::class, 'Reader aborted.');
    expect(app(MlWorkflow::class)->withValidatedArtifact(fn () => 'recovered'))->toBe('recovered');
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
});

test('database ahead refusals are logged distinctly from an empty dataset on both Analytics pages', function () {
    readArtifactFixture();
    $record = ProcessedRecord::where('month', 9)->first()->replicate();
    $record->month = 10;
    $record->consumption_kwh = 1010;
    $record->save();
    $before = artifactFiles();
    $records = ProcessedRecord::get()->toArray();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(function (Assert $page) use ($role) {
            $page->component($role === 'admin' ? 'Admin/Analytics' : 'User/Analytics', false)
                ->has('actualPredictedTrend', 10)->where('actualPredictedTrend.9.actual', 1010);
            foreach (range(0, 9) as $index) {
                $page->where('actualPredictedTrend.'.$index.'.fitted', null);
            }
        });
    }
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', [
        'reason' => 'database_ahead_of_artifact', 'published_cutoff' => '2026-09', 'current_cutoff' => '2026-10',
    ])->twice();
    expect(ProcessedRecord::get()->toArray())->toBe($records);
    ProcessedRecord::query()->delete(); // Synthetic setup for the separate empty-dataset condition.
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($role === 'admin' ? 'Admin/Analytics' : 'User/Analytics', false)
            ->has('actualPredictedTrend', 0));
    }
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'empty_dataset'])->twice();
    expect(artifactFiles())->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
    expect($this->historicalRuns)->toBe(0);
    expect(ForecastResult::count())->toBe(0);
});

test('read refusal reasons distinguish missing and invalid inputs from an empty dataset', function (string $reason) {
    $state = readArtifactFixture();
    match ($reason) {
        'missing_export' => Storage::delete('ml/processed_dataset.csv'),
        'missing_state' => Storage::delete('ml/model_state.json'),
        'invalid_or_legacy_state' => Storage::put('ml/model_state.json', '{}'),
        'identity_mismatch' => config(['services.ml.python' => 'test-only-interpreter']),
        'invalid_artifact_pair' => Storage::put($state['model_path'], 'tampered fixture model'),
        'empty_dataset' => ProcessedRecord::query()->delete(),
    };
    $before = artifactFiles();
    $called = false;
    expect($this->workflow->withValidatedArtifact(function () use (&$called) {
        $called = true;
    }))->toBeNull();
    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => $reason])->once();
    expect(artifactFiles())->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
})->with(['missing_export', 'missing_state', 'invalid_or_legacy_state', 'identity_mismatch', 'invalid_artifact_pair', 'empty_dataset']);

test('validated reads create an exclusive workflow lock and release it after consumption', function () {
    readArtifactFixture();
    Storage::delete('ml/workflow.lock');
    $before = artifactFiles();
    $result = $this->workflow->withValidatedArtifact(function () {
        expect(Storage::exists('ml/workflow.lock'))->toBeTrue();
        expect(Storage::size('ml/workflow.lock'))->toBe(0);
        $probe = fopen(Storage::path('ml/workflow.lock'), 'r');
        try {
            expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse();
        } finally {
            fclose($probe);
        }

        return 'locked read';
    });
    expect($result)->toBe('locked read');
    $probe = fopen(Storage::path('ml/workflow.lock'), 'r');
    try {
        expect(flock($probe, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($probe, LOCK_UN);
    } finally {
        fclose($probe);
    }
    expect(array_diff_key(artifactFiles(), ['ml/workflow.lock' => true]))->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
});

// Return an unchecked candidate only at the consumer seam, after the existing
// cache check. This tests forecast validation without permitting automatic repair
// to mask it, and without training or changing the published pointer.
function overrideConsumerState(array $candidate, $disk, ?callable $beforeCandidate = null, bool $repeatCandidate = false): void
{
    $storage = Mockery::mock(get_class($disk), [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
    $reads = 0;
    $storage->shouldReceive('get')->andReturnUsing(function ($path) use ($disk, $candidate, $beforeCandidate, $repeatCandidate, &$reads) {
        if ($path === 'ml/model_state.json' && (++$reads === 2 || ($repeatCandidate && $reads > 2))) {
            if ($beforeCandidate) {
                $beforeCandidate();
            }

            return json_encode($candidate);
        }

        return $disk->get($path);
    });
    Storage::set('local', $storage);
}

test('both consumers refuse non canonical generations before consuming or persisting a prediction', function (string $damage) {
    $state = readArtifactFixture();
    $candidate = $state;
    $directory = dirname($state['model_path']);
    $sibling = null;
    switch ($damage) {
        case 'missing id':
            unset($candidate['artifact_id']);
            break;
        case 'empty id':
            $candidate['artifact_id'] = '';
            break;
        case 'uppercase id':
            $candidate['artifact_id'] = strtoupper($state['artifact_id']);
            break;
        case 'non string id':
            $candidate['artifact_id'] = [];
            break;
        case 'prefix only':
            $directory .= '_retained';
            break;
        case 'non uuid suffix':
            $directory = 'ml/models/'.$state['artifact_id'].'_retained';
            break;
        case 'uppercase uuid':
            $directory = 'ml/models/'.$state['artifact_id'].'_AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA';
            break;
        case 'traversal':
            $directory .= '/../'.basename($directory);
            break;
        case 'retained sibling':
            $directory = 'ml/models/'.$state['artifact_id'].'_00000000-0000-4000-8000-000000000002';
            break;
        case 'mixed sibling':
            $candidate['metrics_path'] = 'ml/models/'.$state['artifact_id'].'_00000000-0000-4000-8000-000000000002/metrics.json';
            Storage::put($candidate['metrics_path'], Storage::get($state['metrics_path']));
            break;
        case 'symlink to sibling':
            $sibling = 'ml/models/'.$state['artifact_id'].'_00000000-0000-4000-8000-000000000002';
            Storage::makeDirectory($sibling);
            foreach (['model.pkl', 'metrics.json'] as $file) {
                Storage::copy($directory.'/'.$file, $sibling.'/'.$file);
            }
            Storage::deleteDirectory($directory);
            symlink(Storage::path($sibling), Storage::path($directory));
            break;
        case 'symlink file to sibling':
            $sibling = 'ml/models/'.$state['artifact_id'].'_00000000-0000-4000-8000-000000000002';
            Storage::makeDirectory($sibling);
            Storage::copy($state['model_path'], $sibling.'/model.pkl');
            Storage::delete($state['model_path']);
            symlink(Storage::path($sibling.'/model.pkl'), Storage::path($state['model_path']));
            break;
    }
    if (in_array($damage, ['prefix only', 'non uuid suffix', 'uppercase uuid', 'traversal', 'retained sibling'])) {
        $candidate['model_path'] = $directory.'/model.pkl';
        $candidate['metrics_path'] = $directory.'/metrics.json';
        if ($damage !== 'traversal') {
            Storage::put($candidate['model_path'], Storage::get($state['model_path']));
            Storage::put($candidate['metrics_path'], Storage::get($state['metrics_path']));
        }
    }
    $snapshot = function () use ($damage, $state, $sibling) {
        if (! str_starts_with($damage, 'symlink')) {
            return artifactFiles();
        }
        $paths = ['ml/model_state.json', 'ml/processed_dataset.csv', $state['model_path'], $state['metrics_path'], $sibling.'/model.pkl'];
        if (Storage::exists($sibling.'/metrics.json')) {
            $paths[] = $sibling.'/metrics.json';
        }
        $files = array_combine($paths, array_map(fn ($path) => file_get_contents(Storage::path($path)), $paths));
        $files['link'] = readlink(Storage::path($damage === 'symlink to sibling' ? dirname($state['model_path']) : $state['model_path']));

        return $files;
    };
    $before = $snapshot();
    overrideConsumerState($candidate, $this->artifactDisk);
    $called = false;
    expect($this->workflow->withValidatedArtifact(function () use (&$called) {
        $called = true;
    }))->toBeNull();
    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'non_canonical_generation'])->once();

    expect($snapshot())->toBe($before);
    $redirect = null;
    if (str_starts_with($damage, 'symlink')) {
        // Restore the canonical files for the cache check, then redirect exactly
        // when the forecast consumer reads state, after that check has passed.
        $link = $damage === 'symlink to sibling' ? $directory : $state['model_path'];
        unlink(Storage::path($link));
        Storage::makeDirectory($directory);
        Storage::copy($sibling.'/model.pkl', $state['model_path']);
        if ($damage === 'symlink to sibling') {
            Storage::copy($sibling.'/metrics.json', $state['metrics_path']);
        }
        $redirect = function () use ($link, $sibling, $damage) {
            if ($damage === 'symlink to sibling') {
                Storage::deleteDirectory($link);
            } else {
                Storage::delete($link);
            }
            symlink(Storage::path($damage === 'symlink to sibling' ? $sibling : $sibling.'/model.pkl'), Storage::path($link));
        };
    }
    overrideConsumerState($candidate, $this->artifactDisk, $redirect);
    expect(fn () => $this->workflow->predictAndGenerate(generateReport: false))
        ->toThrow(RuntimeException::class, 'non_canonical_generation');
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
    expect(ForecastResult::count())->toBe(0);
    expect($snapshot())->toBe($before);
})->with(['missing id', 'empty id', 'uppercase id', 'non string id', 'prefix only', 'non uuid suffix', 'uppercase uuid', 'traversal', 'retained sibling', 'mixed sibling', 'symlink to sibling', 'symlink file to sibling']);

test('forecast consumption rederives identity from current inputs after the cache check', function (string $field) {
    $state = readArtifactFixture();
    $candidate = $state;
    $candidate[$field] = $field === 'format_version' ? 1 : str_repeat('0', 64);
    if ($field === 'training_cutoff') {
        $candidate[$field] = '2026-08';
    } elseif ($field === 'evaluation_protocol') {
        $candidate[$field] = 'unverified-test-protocol';
    }
    if ($field === 'artifact_id') {
        $directory = 'ml/models/'.$candidate[$field].'_00000000-0000-4000-8000-000000000001';
        foreach (['model' => 'pkl', 'metrics' => 'json'] as $name => $extension) {
            $candidate[$name.'_path'] = $directory.'/'.$name.'.'.$extension;
            Storage::copy($state[$name.'_path'], $candidate[$name.'_path']);
        }
    }
    $before = artifactFiles();
    overrideConsumerState($candidate, $this->artifactDisk, repeatCandidate: true);
    expect(fn () => $this->workflow->predictAndGenerate(generateReport: false))
        ->toThrow(RuntimeException::class, 'identity_mismatch');
    Storage::set('local', $this->artifactDisk); // Inspect actual bytes, not the injected read response.
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
    expect(ForecastResult::count())->toBe(0);
    expect(artifactFiles())->toBe($before);
})->with(['artifact_id', 'dataset_hash', 'feature_signature', 'trainer_signature', 'training_cutoff', 'evaluation_protocol', 'format_version']);

test('canonical published generation serves both Analytics pages and forecasts from isolated copies without retraining', function () {
    $state = readArtifactFixture();
    $before = artifactFiles();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actualPredictedTrend.8.fitted', 1004));
    }
    $this->onPredict = function ($process) use ($state) {
        foreach ([3 => 'model_path', 4 => 'metrics_path'] as $index => $key) {
            expect($process->command[$index])->not->toBe(Storage::path($state[$key]));
            expect(dirname($process->command[$index]))->toStartWith(Storage::path('ml/.staging/prediction_'));
            expect(file_get_contents($process->command[$index]))->toBe(Storage::get($state[$key]));
        }

        return null;
    };
    $result = $this->workflow->predictAndGenerate(generateReport: false);
    expect($result['forecast']->year)->toBe(2026);
    expect($result['forecast']->month)->toBe(10);
    expect((float) $result['forecast']->predicted_consumption_kwh)->toBe(1200.0);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(1);
    expect($this->historicalRuns)->toBe(2);
    expect(artifactFiles())->toBe($before);
    expect(Storage::allFiles('ml/.staging'))->toBe([]);
    expect(artifactState())->toBe($state);
});

test('non canonical state keeps both Analytics routes available without historical predictions', function (string $damage) {
    $state = readArtifactFixture();
    if ($damage === 'missing id') {
        unset($state['artifact_id']);
    } elseif ($damage === 'prefix only') {
        $directory = dirname($state['model_path']).'_retained';
        foreach (['model' => 'pkl', 'metrics' => 'json'] as $name => $extension) {
            Storage::copy($state[$name.'_path'], $directory.'/'.$name.'.'.$extension);
            $state[$name.'_path'] = $directory.'/'.$name.'.'.$extension;
        }
    } else {
        $state['model_path'] = dirname($state['model_path']).'/../'.basename(dirname($state['model_path'])).'/model.pkl';
    }
    Storage::put('ml/model_state.json', json_encode($state));
    assertHistoricalArtifactRefused($this);
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'non_canonical_generation'])->times(3);
})->with(['missing id', 'prefix only', 'traversal']);

test('a complete canonical pointer replacement is indistinguishable without an independent publication anchor', function () {
    $state = readArtifactFixture();
    $sibling = 'ml/models/'.$state['artifact_id'].'_00000000-0000-4000-8000-000000000002';
    foreach (['model' => 'pkl', 'metrics' => 'json'] as $name => $extension) {
        Storage::copy($state[$name.'_path'], $sibling.'/'.$name.'.'.$extension);
        $state[$name.'_path'] = $sibling.'/'.$name.'.'.$extension;
    }
    Storage::put('ml/model_state.json', json_encode($state)); // Synthetic full-pointer replacement.
    $before = artifactFiles();
    expect($this->workflow->withValidatedArtifact(fn ($artifact) => $artifact['model_path']))->toBe($state['model_path']);
    expect(artifactFiles())->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
});

test('the forecast label fallback is Ridge while published metadata and numeric output are preserved', function () {
    readArtifactFixture();
    $before = artifactFiles();
    $this->onPredict = fn () => Process::result(output: json_encode([
        'year' => 2026, 'month' => 10, 'predicted_consumption_kwh' => 1200,
        'previous_consumption_kwh' => 1009, 'change_percent' => 18.93,
        'mae' => 11, 'rmse' => 21, 'r2_score' => -1,
    ]));
    $result = $this->workflow->predictAndGenerate(generateReport: false);
    expect($result['forecast']->model_type)->toBe('Ridge (penalty 0.1)');
    expect((float) $result['forecast']->predicted_consumption_kwh)->toBe(1200.0);
    expect((float) $result['forecast']->r2_score)->toBe(-1.0);
    expect($result['forecast']->month)->toBe(10);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(1);
    expect(artifactFiles())->toBe($before);
});

test('a trainer label correction invalidates the old identity without migrating or repairing artifacts on read', function () {
    $currentSource = file_get_contents(base_path('Python/train_model.py'));
    file_put_contents(base_path('Python/train_model.py'), str_replace('Ridge (penalty 0.1)', 'Linear Regression', $currentSource));
    readArtifactFixture(); // Synthetic publication bound to the old label source.
    $before = artifactFiles();
    file_put_contents(base_path('Python/train_model.py'), $currentSource);
    $called = false;
    expect($this->workflow->withValidatedArtifact(function () use (&$called) {
        $called = true;
    }))->toBeNull();
    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'identity_mismatch'])->once();
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
    expect(artifactFiles())->toBe($before);
});

test('null metrics pass both read and forecast validation and persist without zero substitution', function () {
    $state = readArtifactFixture();
    $metrics = json_encode(['model_type' => 'Linear Regression', 'mae' => null, 'rmse' => null, 'r2_score' => null]);
    Storage::put($state['metrics_path'], $metrics);
    $state['metrics_sha256'] = hash('sha256', $metrics);
    Storage::put('ml/model_state.json', json_encode($state));
    $before = array_map(fn ($bytes) => hash('sha256', $bytes), artifactFiles());
    expect($this->workflow->withValidatedArtifact(fn ($artifact) => $artifact['metrics_sha256']))->toBe($state['metrics_sha256']);
    $this->onPredict = function ($process) use ($state, $metrics) {
        expect(hash_file('sha256', $process->command[3]))->toBe($state['model_sha256']);
        expect(file_get_contents($process->command[4]))->toBe($metrics);

        return Process::result(output: json_encode(json_decode($metrics, true) + [
            'year' => 2026, 'month' => 10, 'predicted_consumption_kwh' => 1200,
            'previous_consumption_kwh' => 1009, 'change_percent' => 18.93,
        ]));
    };
    $this->workflow->predictAndGenerate(generateReport: false);
    $forecast = ForecastResult::first();
    expect([$forecast->mae, $forecast->rmse, $forecast->r2_score])->toBe([null, null, null]);
    expect([$forecast->year, $forecast->month, $forecast->predicted_consumption_kwh])->toBe([2026, 10, 1200]);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(1);
    expect(array_map(fn ($bytes) => hash('sha256', $bytes), artifactFiles()))->toBe($before);
});

test('invalid and nonfinite metrics remain refused even with matching checksums', function (string $field, mixed $invalid) {
    $state = readArtifactFixture();
    $metrics = ['model_type' => 'Linear Regression', 'mae' => null, 'rmse' => null, 'r2_score' => null];
    $metrics[$field] = $invalid;
    $bytes = json_encode($metrics);
    Storage::put($state['metrics_path'], $bytes);
    $state['metrics_sha256'] = hash('sha256', $bytes);
    Storage::put('ml/model_state.json', json_encode($state));
    $before = artifactFiles();
    $called = false;
    expect($this->workflow->withValidatedArtifact(function () use (&$called) {
        $called = true;
    }))->toBeNull();
    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'invalid_artifact_pair'])->once();
    // The publication boundary uses the same validator; failed synthetic output
    // must preserve the old bytes even when the process reports success.
    $this->onTrain = function ($process) use ($bytes) {
        file_put_contents($process->command[3], 'synthetic-model');
        file_put_contents($process->command[4], $bytes);

        return Process::result();
    };
    expect(fn () => $this->workflow->trainIfDatasetChanged(force: true))->toThrow(RuntimeException::class, 'Model metrics are invalid');
    expect(artifactFiles())->toBe($before);
    expect($this->predictionRuns)->toBe(0);
    expect(ForecastResult::count())->toBe(0);
})->with(['mae', 'rmse', 'r2_score'])->with(['non-number', '1e999', true, [[]]]);

test('metric definition changes invalidate reads without rewriting or repairing saved artifacts', function () {
    readArtifactFixture();
    $before = artifactFiles();
    file_put_contents(base_path('resources/forecast-metrics.json'), "\n", FILE_APPEND);
    expect($this->workflow->withValidatedArtifact(fn () => 'must not run'))->toBeNull();
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'identity_mismatch'])->once();
    expect(artifactFiles())->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
});

test('Analytics keeps overlapping actual fitted and issued values distinct with provenance and no writes', function () {
    $state = readArtifactFixture();
    $forecast = ForecastResult::create([
        'year' => 2026, 'month' => 9, 'predicted_at' => '2026-10-21 05:55:25',
        'predicted_consumption_kwh' => 1200, 'model_type' => 'Legacy saved label',
    ])->refresh();
    $future = ForecastResult::create([
        'year' => 2026, 'month' => 10, 'predicted_consumption_kwh' => 0,
    ])->refresh();
    $stale = ForecastResult::create(['year' => 2026, 'month' => 11, 'predicted_consumption_kwh' => 999]);
    $dss = DssResult::create([
        'forecast_result_id' => $stale->id, 'demand_status' => 'Original', 'readiness_level' => 'Original',
        'recommendations' => ['Original'], 'priority_actions' => ['Original'],
    ]);
    $report = Report::create(['title' => 'Original', 'type' => 'synthetic', 'summary' => ['original' => true]]);
    $snapshot = fn () => hash('sha256', json_encode([
        ProcessedRecord::get()->toArray(), ForecastResult::get()->toArray(),
        $dss->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal(), artifactFiles(),
    ]));
    $before = $snapshot();
    foreach (['admin', 'user'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route($role.'.analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('actualPredictedTrend', 10)
            ->where('actualPredictedTrend.8.actual', 1009)
            ->where('actualPredictedTrend.8.fitted', 1004)
            ->where('actualPredictedTrend.8.issued_forecast', 1200)
            ->where('actualPredictedTrend.8.provenance.fitted.kind', 'in_sample_fit')
            ->where('actualPredictedTrend.8.provenance.fitted.artifact_id', $state['artifact_id'])
            ->where('actualPredictedTrend.8.provenance.fitted.training_cutoff', '2026-09')
            ->where('actualPredictedTrend.8.provenance.fitted.dataset_hash', $state['dataset_hash'])
            ->where('actualPredictedTrend.8.provenance.issued_forecast.record_id', $forecast->id)
            ->where('actualPredictedTrend.8.provenance.issued_forecast.model_type', 'Legacy saved label')
            ->where('actualPredictedTrend.8.provenance.issued_forecast.predicted_at', $forecast->predicted_at->toISOString())
            ->where('actualPredictedTrend.8.issuance_label', $forecast->issuance_label)
            ->where('actualPredictedTrend.9.actual', null)->where('actualPredictedTrend.9.fitted', null)
            ->where('actualPredictedTrend.9.issued_forecast', 0)
            ->where('actualPredictedTrend.9.provenance.actual', null)
            ->where('actualPredictedTrend.9.provenance.fitted', null)
            ->missing('actualPredictedTrend.8.accuracy')->missing('actualPredictedTrend.8.predicted')
            ->missing('actualPredictedTrend.9.accuracy'));
    }
    expect($snapshot())->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
    expect($this->historicalRuns)->toBe(2);
});

test('issued forecast provenance comes from the validated consumed publication not prediction JSON or a changed pointer', function () {
    $state = readArtifactFixture();
    $before = artifactFiles();
    $this->onPredict = function () use ($state) {
        // Simulate another publisher changing the sole pointer after copies were
        // consumed. It must not relabel this forecast's captured issuance facts.
        $newer = $state;
        $newer['artifact_id'] = str_repeat('f', 64);
        $newer['dataset_hash'] = str_repeat('e', 64);
        $newer['training_cutoff'] = '2027-12';
        Storage::put('ml/model_state.json', json_encode($newer));

        return Process::result(output: json_encode([
            'year' => 2026, 'month' => 10, 'predicted_consumption_kwh' => 1200,
            'previous_consumption_kwh' => 1009, 'change_percent' => 18.93,
            'mae' => 11, 'rmse' => 21, 'r2_score' => -1,
            'artifact_id' => 'untrusted prediction payload', 'dataset_hash' => 'untrusted',
            'training_cutoff' => '2099-12', 'evaluation_protocol' => 'untrusted',
        ]));
    };
    $result = $this->workflow->predictAndGenerate();
    $forecast = $result['forecast']->refresh();
    foreach (ForecastResult::ARTIFACT_PROVENANCE_FIELDS as $field) {
        expect($forecast->{$field})->toBe($state[$field]);
    }
    $expected = ['status' => 'recorded'];
    foreach (ForecastResult::ARTIFACT_PROVENANCE_FIELDS as $field) {
        $expected[$field] = $state[$field];
    }
    expect($forecast->artifact_provenance)->toBe($expected);
    expect([$forecast->year, $forecast->month, (float) $forecast->predicted_consumption_kwh])->toBe([2026, 10, 1200.0]);
    expect($result['report']->summary['latest_forecast']['artifact_provenance'])->toBe($forecast->artifact_provenance);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(1);
    foreach ([$state['model_path'], $state['metrics_path'], 'ml/processed_dataset.csv'] as $path) {
        expect(Storage::get($path))->toBe($before[$path]);
    }
});

test('a replacement dataset and new artifact cannot relabel an older issued forecast or saved report', function () {
    readArtifactFixture();
    $first = $this->workflow->predictAndGenerate();
    $forecast = $first['forecast']->refresh();
    $report = $first['report']->refresh();
    $before = [$forecast->getRawOriginal(), $report->getRawOriginal()];
    $oldFacts = $forecast->artifact_provenance;
    ProcessedRecord::where('month', 9)->update(['consumption_kwh' => 4009]);
    $this->workflow->trainIfDatasetChanged(); // Synthetic process/fake storage only.
    expect(artifactState()['artifact_id'])->not->toBe($forecast->artifact_id);
    expect(artifactState()['dataset_hash'])->not->toBe($forecast->dataset_hash);
    expect($forecast->fresh()->artifact_provenance)->toBe($oldFacts);
    expect([$forecast->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal()])->toBe($before);
    $row = AnalyticsSeries::rows(collect(), collect(['2026-10' => $forecast->fresh()]), collect())->first();
    foreach (ForecastResult::ARTIFACT_PROVENANCE_FIELDS as $field) {
        expect($row['provenance']['issued_forecast'][$field])->toBe($oldFacts[$field]);
    }
    expect($row['provenance']['issued_forecast']['status'])->toBe('recorded');
});

test('forecast endpoint ignores caller supplied provenance and uses published facts', function () {
    $state = readArtifactFixture();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->post(route('admin.forecasting.predict'), [
        'artifact_id' => 'forged', 'dataset_hash' => 'forged',
        'training_cutoff' => '2099-12', 'evaluation_protocol' => 'forged',
    ])->assertRedirect();
    $forecast = ForecastResult::first();
    expect($forecast)->not->toBeNull();
    foreach (ForecastResult::ARTIFACT_PROVENANCE_FIELDS as $field) {
        expect($forecast->{$field})->toBe($state[$field]);
    }
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(1);
});

test('a valid historical pair is refused when managed actual values change at the same cutoff without rewriting artifacts', function () {
    $state = readArtifactFixture();
    $dataset = Dataset::create(['type' => 'combined', 'original_name' => 'synthetic.csv',
        'path' => 'datasets/validated/synthetic.csv', 'status' => 'valid']);
    Storage::put($dataset->path, 'Synthetic source only');
    foreach (ProcessedRecord::get() as $record) {
        ConsumptionRecord::create(['dataset_id' => $dataset->id, 'year' => $record->year, 'month' => $record->month,
            'consumption_kwh' => $record->consumption_kwh, 'peak_demand_kw' => $record->peak_demand_kw]);
        ClimateRecord::create(['dataset_id' => $dataset->id, 'year' => $record->year, 'month' => $record->month,
            'temperature' => $record->temperature, 'humidity' => $record->humidity, 'rainfall' => $record->rainfall, 'solar_irradiance' => $record->solar_irradiance]);
    }
    $metadata = ['format_version' => 1, 'dataset_id' => $dataset->id,
        'source_sha256' => hash('sha256', 'Synthetic source only'),
        'monthly_sha256' => DatasetIntegrity::monthlyHash()];
    Storage::put($dataset->path.'.json', json_encode($metadata));
    expect(DatasetIntegrity::currentHash())->toBe($state['dataset_hash']);
    expect($this->workflow->withValidatedArtifact(fn ($artifact) => $artifact['artifact_id']))->toBe($state['artifact_id']);
    ProcessedRecord::where('month', 9)->update(['consumption_kwh' => 4009]);
    ConsumptionRecord::where('month', 9)->update(['consumption_kwh' => 4009]);
    $metadata['monthly_sha256'] = DatasetIntegrity::monthlyHash();
    Storage::put($dataset->path.'.json', json_encode($metadata));
    $before = artifactFiles();
    $called = false;
    expect($this->workflow->withValidatedArtifact(function () use (&$called) {
        $called = true;
    }))->toBeNull();
    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Historical ML predictions unavailable.', ['reason' => 'active_dataset_mismatch'])->once();
    expect(artifactFiles())->toBe($before);
    expect($this->trainingRuns)->toBe(0);
    expect($this->predictionRuns)->toBe(0);
});
