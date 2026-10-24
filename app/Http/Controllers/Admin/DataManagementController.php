<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\Dataset;
use App\Models\ProcessedRecord;
use App\Support\AuditLogger;
use App\Support\DatasetIntegrity;
use App\Support\EnergyPreprocessor;
use App\Support\MlWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class DataManagementController extends Controller
{
    private array $required = [
        'year',
        'month',
        'consumption_kwh',
        'peak_demand_kw',
        'temperature',
        'humidity',
        'rainfall',
        'solar_irradiance',
    ];

    public function index()
    {
        return Inertia::render('Admin/DataManagement', [
            'datasets' => Dataset::with('user:id,name')->latest()->get(),
            'consumptionRecords' => ConsumptionRecord::query()->orderByDesc('year')->orderByDesc('month')->take(20)->get(),
            'climateRecords' => ClimateRecord::query()->orderByDesc('year')->orderByDesc('month')->take(20)->get(),
            'requiredColumns' => $this->required,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'dataset' => ['required', 'file', 'mimes:csv,txt'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);
        $path = $request->file('dataset')->store(DatasetIntegrity::DIRECTORY);
        if (! $path) {
            throw ValidationException::withMessages(['dataset' => 'Unable to stage the uploaded CSV.']);
        }
        $lock = null;
        $committed = false;
        try {
            [$headers, $rows] = $this->readCsv(Storage::path($path));
            $monthlyRows = $this->aggregateMonthlyRows($rows);
            $sourceHash = hash_file('sha256', Storage::path($path));
            $lock = fopen(Storage::path('datasets/.import.lock'), 'c');
            if (! $lock || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock dataset activation.');
            }
            $replaceExisting = $request->boolean('replace_existing');
            $activeIds = ConsumptionRecord::pluck('dataset_id')->merge(ClimateRecord::pluck('dataset_id'))->unique();
            if (! $replaceExisting && (ConsumptionRecord::exists() || ClimateRecord::exists() || ProcessedRecord::exists())) {
                $active = $activeIds->count() === 1 && $activeIds->first() ? Dataset::find($activeIds->first()) : null;
                if ($active && $active->status === 'valid' && Storage::exists($active->path)
                    && hash_file('sha256', Storage::path($active->path)) === $sourceHash
                    && DatasetIntegrity::sourceMatches($active)) {
                    return back()->with('success', 'This exact dataset is already active; no data or forecasts changed.');
                }
                throw ValidationException::withMessages([
                    'dataset' => 'Select Replace existing records to activate a changed dataset. Series identity cannot be established from a filename or matching months.',
                ]);
            }
            [$dataset, $processedCount, $existingDataset] = DB::transaction(function () use ($request, $path, $rows, $monthlyRows, $sourceHash, $headers, $replaceExisting) {
                $existingDataset = $replaceExisting ? Dataset::where('type', 'combined')
                    ->where('original_name', $request->file('dataset')->getClientOriginalName())->latest()->first() : null;
                $dataset = $existingDataset ?? new Dataset;
                if ($existingDataset) {
                    $dataset->forceFill(['created_at' => now()]);
                }
                $dataset->fill([
                    'user_id' => $request->user()->id, 'type' => 'combined',
                    'original_name' => $request->file('dataset')->getClientOriginalName(),
                    'path' => $path, 'status' => 'valid', 'validation_errors' => null, 'record_count' => count($rows),
                ])->save();
                // DELETE is transactional on MySQL; TRUNCATE would implicitly commit.
                ConsumptionRecord::query()->delete();
                ClimateRecord::query()->delete();
                ProcessedRecord::query()->delete();
                foreach ($monthlyRows as $row) {
                    ConsumptionRecord::create(['dataset_id' => $dataset->id] + array_intersect_key($row, array_flip(['year', 'month', 'consumption_kwh', 'peak_demand_kw'])));
                    ClimateRecord::create(['dataset_id' => $dataset->id] + array_intersect_key($row, array_flip(['year', 'month', 'temperature', 'humidity', 'rainfall', 'solar_irradiance'])));
                }
                $processedCount = count($monthlyRows) >= 3 ? EnergyPreprocessor::run() : 0;
                $periods = array_map(fn ($row) => sprintf('%04d-%02d', $row['year'], $row['month']), $monthlyRows);
                $metadata = [
                    'format_version' => 1, 'dataset_id' => $dataset->id,
                    'source_sha256' => $sourceHash, 'monthly_sha256' => DatasetIntegrity::monthlyHash(),
                    'raw_records' => count($rows), 'complete_periods' => $periods,
                    'calendar_gaps' => $this->calendarGaps($monthlyRows),
                    'excluded_incomplete_periods' => array_values(array_diff(array_unique(array_map(fn ($row) => sprintf('%04d-%02d', $row['year'], $row['month']), $rows)), $periods)),
                    'aggregation' => 'consumption_and_rainfall_sum; peak_max; temperature_humidity_solar_mean; existing_decimal_storage_precision',
                    'period_contract' => in_array('day', $headers) || in_array('date', $headers)
                        ? 'daily_distinct_calendar_days' : 'monthly_rows_assert_complete',
                    'missing_measurements' => 'required_values_rejected_not_zero_filled',
                    'units' => 'existing_kwh_kw_contract_source_semantics_not_independently_verified',
                ];
                $metadataBytes = json_encode($metadata, JSON_THROW_ON_ERROR);
                if (! Storage::put($path.'.json', $metadataBytes)
                    || hash_file('sha256', Storage::path($path.'.json')) !== hash('sha256', $metadataBytes)) {
                    throw new RuntimeException('Unable to preserve dataset provenance.');
                }
                AuditLogger::log('Data Management', $existingDataset ? 'Replace Dataset' : 'Upload Dataset',
                    'Activated validated dataset: '.count($rows).' raw records; '.count($monthlyRows).' complete monthly records; SHA256 '.$sourceHash.'.');

                return [$dataset, $processedCount, $existingDataset];
            });
            $committed = true;
            $rawCount = count($rows);
            $monthlyCount = count($monthlyRows);
            try {
                if ($processedCount === 0) {
                    throw new RuntimeException('At least 3 monthly consumption records are required; previous processed data is inactive.');
                }
                AuditLogger::log('Preprocessing', 'Auto Run Preprocessing', $processedCount.' processed records generated after dataset upload.');
                $workflow = app(MlWorkflow::class)->runAfterDatasetUpload($request->user()->id);
                $workflowMessage = $workflow['predicted']
                    ? ' Model retrained, next-month prediction, DSS result, and report generated automatically.'
                    : ' Model retraining was skipped: '.$workflow['message'];

                return back()->with('success', ($existingDataset ? 'Existing dataset replaced. ' : '')."{$rawCount} records uploaded; {$monthlyCount} complete monthly records generated (incomplete daily months excluded). {$processedCount} processed records generated automatically.{$workflowMessage}");
            } catch (\Throwable $exception) {
                Log::warning('Automated workflow incomplete after dataset activation.', ['exception_type' => $exception::class]);
                $message = $exception instanceof RuntimeException && ! $exception instanceof QueryException
                    ? $exception->getMessage() : 'Dataset saved; automated workflow requires a separate retry.';

                return back()->with('success', ($existingDataset ? 'Existing dataset replaced. ' : '')."{$rawCount} records uploaded; {$monthlyCount} complete monthly records generated (incomplete daily months excluded). Automated workflow was not completed: {$message}");
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Dataset activation failed; previous data preserved.', ['exception_type' => $exception::class]);
            throw ValidationException::withMessages(['dataset' => 'Dataset activation failed. Previous data was preserved.']);
        } finally {
            if (! $committed) {
                Storage::delete([$path, $path.'.json']);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function destroy(Request $request, Dataset $dataset)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'The password is incorrect.',
            ]);
        }

        if (ConsumptionRecord::where('dataset_id', $dataset->id)->exists() || ClimateRecord::where('dataset_id', $dataset->id)->exists()) {
            throw ValidationException::withMessages(['password' => 'The active dataset source cannot be deleted. Replace the active records first.']);
        }

        $fileName = $dataset->original_name;
        $type = $dataset->type;

        $dataset->delete();

        AuditLogger::log(
            'Data Management',
            'Delete Dataset',
            'Deleted '.$type.' dataset: '.$fileName.'.'
        );

        return back()->with('success', 'Dataset entry deleted.');
    }

    private function readCsv(string $path): array
    {
        $text = file_get_contents($path);
        if ($text === false || str_contains($text, "\0")) {
            throw ValidationException::withMessages(['dataset' => 'CSV is unreadable or contains invalid bytes.']);
        }
        // RFC-style quoting: a quote opens only at a field boundary; doubled
        // quotes escape it. Reject unterminated quotes and trailing junk.
        $state = 'start';
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($i === 0 && str_starts_with($text, "\xEF\xBB\xBF")) {
                $i = 2;

                continue;
            }
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }
            } elseif ($char === ',' || $char === "\n" || $char === "\r") {
                $state = 'start';
            } elseif ($char === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                throw ValidationException::withMessages(['dataset' => 'CSV contains malformed quoting.']);
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            throw ValidationException::withMessages(['dataset' => 'CSV contains an unterminated quoted field.']);
        }
        $handle = fopen($path, 'r');
        try {
            if (str_starts_with($text, "\xEF\xBB\xBF")) {
                fseek($handle, 3);
            }
            $header = fgetcsv($handle, escape: '') ?: [];
            if ($header !== []) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
            }
            $headers = array_map(fn ($value) => strtolower(trim($value ?? '')), $header);
            if ($headers === [] || in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)
                || array_diff($this->required, $headers) !== []) {
                throw ValidationException::withMessages(['dataset' => 'CSV requires unique nonempty headers including: '.implode(', ', $this->required).'.']);
            }
            $rows = [];
            while (($line = fgetcsv($handle, escape: '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                if (count($line) !== count($headers)) {
                    throw ValidationException::withMessages(['dataset' => 'Every CSV row must match the header column count.']);
                }
                $rows[] = array_combine($headers, array_map(fn ($value) => trim($value ?? ''), $line));
            }
        } finally {
            fclose($handle);
        }

        return [$headers, $rows];
    }

    private function calendarGaps(array $rows): array
    {
        $gaps = [];
        $previous = null;
        foreach ($rows as $row) {
            $month = Carbon::createMidnightDate($row['year'], $row['month'], 1);
            if ($previous && $previous->copy()->addMonth()->lt($month)) {
                $gaps[] = ['from' => $previous->copy()->addMonth()->format('Y-m'), 'through' => $month->copy()->subMonth()->format('Y-m')];
            }
            $previous = $month;
        }

        return $gaps;
    }

    private function aggregateMonthlyRows(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $rules = [
                'year' => ['required', 'integer', 'between:1,9998'],
                'month' => ['required', 'integer', 'between:1,12'],
            ];
            foreach (array_diff($this->required, ['year', 'month']) as $field) {
                $rules[$field] = ['required', 'numeric'];
            }
            $rules['consumption_kwh'][] = 'min:0';
            $rules['peak_demand_kw'][] = 'min:0';
            if (Validator::make($row, $rules)->fails()
                || collect($this->required)->contains(fn ($field) => ! is_finite((float) ($row[$field] ?? 0)))) {
                throw ValidationException::withMessages([
                    'dataset' => 'Every row must contain a valid year, month, and complete numeric consumption and climate values.',
                ]);
            }

            $year = (int) $row['year'];
            $month = (int) $row['month'];
            $key = "{$year}-{$month}";
            $daysInMonth = Carbon::createMidnightDate($year, $month, 1)->daysInMonth;
            $daily = array_key_exists('day', $row) || array_key_exists('date', $row);
            $day = null;

            if ($daily) {
                $day = $row['day'] ?? null;
                if (array_key_exists('day', $row) && (! preg_match('/^\d+$/D', (string) $day))) {
                    throw ValidationException::withMessages(['dataset' => 'Daily records must have an integer day.']);
                }
                if (array_key_exists('date', $row)) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['date'] ?? '');
                    if (! $date || $date->format('Y-m-d') !== $row['date']
                        || (int) $date->format('Y') !== $year || (int) $date->format('n') !== $month
                        || ($day !== null && (int) $day !== (int) $date->format('j'))) {
                        throw ValidationException::withMessages(['dataset' => 'Daily dates must match their year and month.']);
                    }
                    $day = $date->format('j');
                }
                if (! is_numeric($day) || (float) $day !== (float) (int) $day || $day < 1 || $day > $daysInMonth) {
                    throw ValidationException::withMessages(['dataset' => 'Daily records must have a valid day of the month.']);
                }
                if (isset($groups[$key]['actual_days'][(int) $day])) {
                    throw ValidationException::withMessages(['dataset' => 'Duplicate daily records cannot establish a complete month.']);
                }
            } elseif (isset($groups[$key])) {
                throw ValidationException::withMessages(['dataset' => 'Monthly CSV files must contain one row per month; daily files must include day or date.']);
            }

            $groups[$key] ??= [
                'year' => $year,
                'month' => $month,
                'days' => 0,
                'actual_days' => [],
                'required_days' => $daily ? $daysInMonth : 1,
                'consumption_kwh' => 0.0,
                'peak_demand_kw' => 0.0,
                'temperature' => 0.0,
                'humidity' => 0.0,
                'rainfall' => 0.0,
                'solar_irradiance' => 0.0,
            ];

            $groups[$key]['actual_days'][$daily ? (int) $day : 1] = true;
            $groups[$key]['days']++;
            $groups[$key]['consumption_kwh'] += (float) $row['consumption_kwh'];
            $groups[$key]['peak_demand_kw'] = max(
                $groups[$key]['peak_demand_kw'],
                (float) $row['peak_demand_kw']
            );
            $groups[$key]['temperature'] += (float) $row['temperature'];
            $groups[$key]['humidity'] += (float) $row['humidity'];
            $groups[$key]['rainfall'] += (float) $row['rainfall'];
            $groups[$key]['solar_irradiance'] += (float) $row['solar_irradiance'];
        }

        $completeGroups = collect($groups)->filter(fn (array $group) => count($group['actual_days']) === $group['required_days']);

        if ($completeGroups->isEmpty()) {
            throw ValidationException::withMessages(['dataset' => 'The dataset must contain at least one complete month of actual consumption.']);
        }

        return $completeGroups
            ->sortBy([['year', 'asc'], ['month', 'asc']])
            ->map(function (array $group) {
                $row = [
                    'year' => $group['year'],
                    'month' => $group['month'],
                    'consumption_kwh' => $group['consumption_kwh'],
                    'peak_demand_kw' => $group['peak_demand_kw'],
                    'temperature' => $group['temperature'] / $group['days'],
                    'humidity' => $group['humidity'] / $group['days'],
                    'rainfall' => $group['rainfall'],
                    'solar_irradiance' => $group['solar_irradiance'] / $group['days'],
                ];
                foreach (['consumption_kwh' => 9999999999999.99, 'peak_demand_kw' => 9999999999999.99,
                    'temperature' => 999999.99, 'humidity' => 999999.99, 'rainfall' => 99999999.99, 'solar_irradiance' => 99999999.99] as $field => $limit) {
                    if (! is_finite($row[$field]) || abs($row[$field]) > $limit) {
                        throw ValidationException::withMessages(['dataset' => 'Monthly values must be finite and fit the existing numeric storage range.']);
                    }
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
