<?php

use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\Dataset;
use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use App\Models\Report;
use App\Models\User;
use App\Support\DatasetIntegrity;
use App\Support\ForecastPeriod;
use App\Support\MlWorkflow;
use App\Support\ProcessedDataset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function integrityCsv(array $months = [6, 7, 8], int $value = 100): string
{
    return "year,month,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n".
        implode('', array_map(fn ($month) => "2026,$month,$value,20,25,70,10,5\n", $months));
}

function integrityUpload($test, string $csv, bool $replace = false, string $name = 'series.csv')
{
    return $test->post(route('admin.data-management.store'), [
        'dataset' => UploadedFile::fake()->createWithContent($name, $csv), 'replace_existing' => $replace,
    ]);
}

function integritySnapshot(): string
{
    $snapshot = [];
    foreach (['datasets', 'consumption_records', 'climate_records', 'processed_records', 'forecast_results', 'dss_results', 'reports', 'audit_trails'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    foreach (Storage::allFiles() as $path) {
        $snapshot['files'][$path] = Storage::get($path);
    }

    return json_encode($snapshot, JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    Storage::fake('local');
    $this->withoutVite();
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->featureRuns = 0;
    $this->workflowRuns = 0;
    $this->featureFailure = false;
    $this->workflowFailure = false;
    // Synthetic feature output only: these cases test import/persistence, not fitting.
    Process::preventStrayProcesses();
    Process::fake(function ($process) {
        expect($process->command[1])->toBe('monthly_features.py');
        $this->featureRuns++;
        if ($this->featureFailure) {
            return Process::result(errorOutput: 'Synthetic feature failure.', exitCode: 1);
        }
        $rows = json_decode($process->input, true);
        foreach ($rows as $index => &$row) {
            $row += ['lag_1' => null, 'lag_2' => null, 'trend' => $index + 1, 'month_sin' => 0, 'month_cos' => 1];
        }

        return Process::result(output: json_encode($rows));
    });
    $this->mock(MlWorkflow::class)->shouldReceive('runAfterDatasetUpload')->andReturnUsing(function () {
        $this->workflowRuns++;
        if ($this->workflowFailure) {
            throw new RuntimeException('Synthetic automated workflow failure.');
        }

        return ['predicted' => false, 'message' => 'Synthetic test; no ML workflow.'];
    });
});

test('malformed or incomplete replacements preserve every active row and file', function (string $csv) {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $before = integritySnapshot();
    integrityUpload($this, $csv, true)->assertSessionHasErrors('dataset');
    expect(integritySnapshot())->toBe($before);
    expect($this->featureRuns)->toBe(1);
    expect($this->workflowRuns)->toBe(1);
})->with([
    'empty' => '',
    'header only' => fn () => strtok(integrityCsv(), "\n")."\n",
    'missing header' => "year,month,consumption_kwh\n2026,8,10\n",
    'duplicate normalized header' => fn () => str_replace('peak_demand_kw', ' CONSUMPTION_KWH ', integrityCsv()),
    'empty header' => fn () => str_replace('temperature', '', integrityCsv()),
    'long row' => fn () => integrityCsv()."2026,9,1,2,3,4,5,6,extra\n",
    'short row' => fn () => integrityCsv()."2026,9,1\n",
    'unterminated quote' => fn () => integrityCsv().'"2026,9,1,2,3,4,5,6',
    'junk after quote' => fn () => integrityCsv()."\"2026\"x,9,1,2,3,4,5,6\n",
    'invalid month' => fn () => integrityCsv([13]),
    'fractional year' => fn () => str_replace('2026', '2026.5', integrityCsv()),
    'duplicate month' => fn () => integrityCsv([8, 8]),
    'missing measurement' => fn () => str_replace(',25,', ',,', integrityCsv()),
    'NaN' => fn () => str_replace(',100,', ',NaN,', integrityCsv()),
    'infinite' => fn () => str_replace(',100,', ',1e999,', integrityCsv()),
    'out of storage range' => fn () => str_replace(',100,', ',1e20,', integrityCsv()),
    'negative consumption' => fn () => integrityCsv([8], -1),
    'negative peak cannot be silently clipped' => fn () => str_replace(',20,', ',-1,', integrityCsv()),
    'invalid daily date' => "year,month,date,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n2026,2,2026-02-30,1,2,3,4,5,6\n",
    'date and fractional day' => "year,month,day,date,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n2026,2,1.5,2026-02-01,1,2,3,4,5,6\n",
    'NUL byte' => fn () => integrityCsv()."\0",
]);

test('sorted calendar gaps quoted headers BOM CRLF and genuine zero are retained without inventing measurements', function () {
    $csv = integrityCsv([12, 8, 6], 0);
    $csv = "\xEF\xBB\xBF".str_replace('year,month', '"YEAR","month"', str_replace("\n", "\r\n", $csv));
    integrityUpload($this, $csv)->assertSessionHasNoErrors();
    expect(ConsumptionRecord::orderBy('month')->pluck('month')->all())->toBe([6, 8, 12]);
    expect(ConsumptionRecord::get()->every(fn ($row) => (float) $row->consumption_kwh === 0.0))->toBeTrue();
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2027-01');
    $dataset = Dataset::first();
    $metadata = json_decode(Storage::get($dataset->path.'.json'), true);
    expect($metadata['source_sha256'])->toBe(hash('sha256', $csv));
    expect($metadata['monthly_sha256'])->toBe(DatasetIntegrity::monthlyHash());
    expect($metadata['complete_periods'])->toBe(['2026-06', '2026-08', '2026-12']);
    expect($metadata['period_contract'])->toBe('monthly_rows_assert_complete');
    expect($metadata['calendar_gaps'])->toBe([['from' => '2026-07', 'through' => '2026-07'], ['from' => '2026-09', 'through' => '2026-11']]);
    expect(DatasetIntegrity::currentHash())->toBe(hash('sha256', ProcessedDataset::contents(ProcessedRecord::orderBy('year')->orderBy('month')->get())));
});

test('changed uploads including the same filename cannot append independent observations without explicit replacement', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $before = integritySnapshot();
    foreach (['series.csv', 'another.csv'] as $name) {
        integrityUpload($this, integrityCsv([9, 10, 11], 900), false, $name)->assertSessionHasErrors('dataset');
        expect(integritySnapshot())->toBe($before);
    }
    // Exact raw hash plus verified active binding is an idempotent replay, not a filename match.
    integrityUpload($this, integrityCsv(), false, 'renamed.csv')->assertSessionHasNoErrors();
    expect(integritySnapshot())->toBe($before);
    expect($this->workflowRuns)->toBe(1);
});

test('database failure after deletes and a partial insert rolls back active records metadata and source paths', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $before = integritySnapshot();
    $entered = false;
    ClimateRecord::creating(function () use (&$entered) {
        $entered = true;
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        expect((float) ConsumptionRecord::first()->consumption_kwh)->toBe(900.0);
        throw new RuntimeException('Synthetic persistence failure.');
    });
    try {
        integrityUpload($this, integrityCsv([9, 10, 11], 900), true)->assertSessionHasErrors('dataset');
    } finally {
        ClimateRecord::flushEventListeners();
    }
    expect($entered)->toBeTrue();
    expect(integritySnapshot())->toBe($before);
    expect($this->workflowRuns)->toBe(1);
});

test('preprocessing failure rolls back raw processed and catalog rows and preserves original files', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $before = integritySnapshot();
    $this->featureFailure = true;
    integrityUpload($this, integrityCsv([9, 10, 11], 900), true)->assertSessionHasErrors('dataset');
    expect(integritySnapshot())->toBe($before);
    expect($this->workflowRuns)->toBe(1);
});

test('provenance write failure cannot commit a replacement even after preprocessing succeeded', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $before = integritySnapshot();
    $storage = Storage::getFacadeRoot();
    $proxy = Mockery::mock($storage);
    $proxy->shouldReceive('put')->withArgs(fn ($path, $contents) => str_ends_with($path, '.csv.json'))->once()->andReturnFalse();
    Storage::swap($proxy);
    try {
        integrityUpload($this, integrityCsv([9, 10, 11], 900), true)->assertSessionHasErrors('dataset');
    } finally {
        Storage::swap($storage);
    }
    expect(integritySnapshot())->toBe($before);
    expect($this->featureRuns)->toBe(2);
    expect($this->workflowRuns)->toBe(1);
});

test('replacement isolates old forecasts DSS reports and fitted reads while retaining all saved bytes and rows', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $oldDataset = Dataset::first();
    $oldPath = $oldDataset->path;
    $hash = DatasetIntegrity::currentHash();
    $old = ForecastResult::create(['year' => 2026, 'month' => 9, 'predicted_consumption_kwh' => 123, 'dataset_hash' => $hash]);
    $legacy = ForecastResult::create(['year' => 2026, 'month' => 7, 'predicted_consumption_kwh' => 321]);
    $dss = DssResult::create(['forecast_result_id' => $old->id, 'demand_status' => 'Original', 'readiness_level' => 'Original',
        'recommendations' => ['Original'], 'priority_actions' => ['Original']]);
    $report = Report::create(['title' => 'Original', 'type' => 'synthetic', 'summary' => ['original' => true]]);
    Storage::put('ml/processed_dataset.csv', 'Original saved export');
    Storage::put('ml/model_state.json', json_encode(['format_version' => 2, 'dataset_hash' => $hash]));
    Storage::put('ml/retained.pkl', 'Original saved model');
    $saved = [$old->fresh()->getRawOriginal(), $legacy->fresh()->getRawOriginal(), $dss->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal()];
    expect(ForecastPeriod::current()->first()->id)->toBe($old->id);
    // Same cutoff, changed values: period-only filtering must not reuse old output.
    $this->travel(1)->days();
    integrityUpload($this, integrityCsv([6, 7, 8], 900), true)->assertSessionHasNoErrors();
    expect(Dataset::first()->id)->toBe($oldDataset->id);
    expect(Dataset::first()->created_at->timestamp)->toBe(now()->timestamp);
    expect(Dataset::first()->path)->not->toBe($oldPath);
    expect(Storage::get($oldPath))->toBe(integrityCsv());
    expect(ForecastPeriod::next()->format('Y-m'))->toBe('2026-09');
    expect(ForecastPeriod::current()->exists())->toBeFalse();
    expect(ForecastPeriod::throughNext()->count())->toBe(0);
    expect(ForecastPeriod::currentDss()->count())->toBe(0);
    expect((new MlWorkflow)->withValidatedArtifact(fn () => throw new RuntimeException('Must not consume old artifact.')))->toBeNull();
    expect([$old->fresh()->getRawOriginal(), $legacy->fresh()->getRawOriginal(), $dss->fresh()->getRawOriginal(), $report->fresh()->getRawOriginal()])->toBe($saved);
    expect(Storage::get('ml/processed_dataset.csv'))->toBe('Original saved export');
    expect(Storage::get('ml/retained.pkl'))->toBe('Original saved model');
    $this->get(route('admin.forecasting'))->assertInertia(fn (Assert $page) => $page->where('latestForecast', null)->has('forecastHistory', 0));
    $this->get(route('admin.decision-support'))->assertInertia(fn (Assert $page) => $page->where('latestDss', null)->has('history', 0));
    // A future synthetic issuance bound to the current processed bytes becomes eligible.
    $old->update(['dataset_hash' => DatasetIntegrity::currentHash()]);
    expect(ForecastPeriod::current()->first()->id)->toBe($old->id);
});

test('short complete replacements retain raw actuals but cannot leave previous processed data active', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    integrityUpload($this, integrityCsv([12]), true, 'short.csv')->assertSessionHasNoErrors();
    expect(ConsumptionRecord::count())->toBe(1);
    expect(ProcessedRecord::count())->toBe(0);
    expect(ForecastPeriod::next())->toBeNull();
    expect(DatasetIntegrity::currentHash())->toBeNull();
    expect($this->workflowRuns)->toBe(1);
});

test('active source deletion and tampered source or derived inputs cannot bypass dataset binding', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $dataset = Dataset::first();
    $before = integritySnapshot();
    $this->delete(route('admin.data-management.destroy', $dataset), ['password' => 'password'])->assertSessionHasErrors('password');
    expect(integritySnapshot())->toBe($before);
    $bytes = Storage::get($dataset->path);
    Storage::put($dataset->path, $bytes."\n");
    expect(DatasetIntegrity::currentHash())->toBeNull();
    Storage::put($dataset->path, $bytes);
    ProcessedRecord::where('month', 8)->update(['consumption_kwh' => 999]);
    expect(DatasetIntegrity::currentHash())->toBeNull();
    expect(fn () => (new MlWorkflow)->trainIfDatasetChanged())->toThrow(RuntimeException::class, 'completely preprocessed');
    expect($this->workflowRuns)->toBe(1);
});

test('zero measurements stay zero and a complete short dataset can be replayed without activating old processed rows', function () {
    $csv = "year,month,consumption_kwh,peak_demand_kw,temperature,humidity,rainfall,solar_irradiance\n2026,12,0,0,0,0,0,0\n";
    integrityUpload($this, $csv)->assertSessionHasNoErrors();
    $values = DatasetIntegrity::actualRows()[0];
    foreach (['consumption_kwh', 'peak_demand_kw', 'temperature', 'humidity', 'rainfall', 'solar_irradiance'] as $field) {
        expect($values[$field])->toBe(0.0);
    }
    $before = integritySnapshot();
    integrityUpload($this, $csv)->assertSessionHasNoErrors();
    expect(integritySnapshot())->toBe($before);
    expect($this->featureRuns)->toBe(0);
    expect($this->workflowRuns)->toBe(0);
});

test('missing or damaged provenance is refused without repair', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $dataset = Dataset::first();
    Storage::delete($dataset->path.'.json');
    $before = integritySnapshot();
    expect(DatasetIntegrity::currentHash())->toBeNull();
    expect(ForecastPeriod::throughNext()->count())->toBe(0);
    integrityUpload($this, integrityCsv())->assertSessionHasErrors('dataset');
    expect(integritySnapshot())->toBe($before);
    Storage::put($dataset->path.'.json', 'not JSON');
    expect(DatasetIntegrity::currentHash())->toBeNull();
});

test('processed CSV identity bytes remain exactly the pre-hardening export format', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    $records = ProcessedRecord::orderBy('year')->orderBy('month')->get();
    $headers = ['year', 'month', 'consumption_kwh', 'temperature', 'humidity', 'rainfall',
        'solar_irradiance', 'peak_demand_kw', 'lag_1', 'lag_2', 'trend', 'month_sin', 'month_cos'];
    $lines = [implode(',', $headers)];
    foreach ($records as $record) {
        $lines[] = implode(',', array_map(fn ($field) => $record->{$field}, $headers));
    }
    expect(ProcessedDataset::contents($records))->toBe(implode(PHP_EOL, $lines));
    expect(DatasetIntegrity::currentHash())->toBe(hash('sha256', implode(PHP_EOL, $lines)));
});

test('an automated workflow failure after activation preserves the committed valid dataset and retained artifacts', function () {
    integrityUpload($this, integrityCsv())->assertSessionHasNoErrors();
    Storage::put('ml/model_state.json', 'Original saved state');
    Storage::put('ml/retained.pkl', 'Original saved model');
    $this->workflowFailure = true;
    integrityUpload($this, integrityCsv([9, 10, 11], 900), true)->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Automated workflow was not completed'));
    expect(ConsumptionRecord::count())->toBe(3);
    expect((float) ConsumptionRecord::first()->consumption_kwh)->toBe(900.0);
    expect(DatasetIntegrity::currentHash())->not->toBeNull();
    expect(Storage::get('ml/model_state.json'))->toBe('Original saved state');
    expect(Storage::get('ml/retained.pkl'))->toBe('Original saved model');
});
