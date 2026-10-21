<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClimateRecord;
use App\Models\ConsumptionRecord;
use App\Models\Dataset;
use App\Support\AuditLogger;
use App\Support\EnergyPreprocessor;
use App\Support\MlWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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
        $data = $request->validate([
            'dataset' => ['required', 'file', 'mimes:csv,txt'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $replaceExisting = $request->boolean('replace_existing');
        $originalName = $request->file('dataset')->getClientOriginalName();
        $path = $request->file('dataset')->store('datasets');
        [$headers, $rows] = $this->readCsv(Storage::path($path));
        $missing = array_values(array_diff($this->required, $headers));
        $existingDataset = $replaceExisting
            ? Dataset::query()
                ->where('type', 'combined')
                ->where('original_name', $originalName)
                ->latest()
                ->first()
            : null;

        if ($missing !== []) {
            if ($existingDataset) {
                Storage::delete($path);

                return back()->with(
                    'error',
                    'Replacement was not saved. The uploaded dataset is invalid. Missing: '.implode(', ', $missing)
                );
            }

            $dataset = Dataset::create([
                'user_id' => $request->user()->id,
                'type' => 'combined',
                'original_name' => $originalName,
                'path' => $path,
                'status' => 'invalid',
                'validation_errors' => ['Missing columns: '.implode(', ', $missing)],
            ]);

            return back()->with('error', 'Dataset is invalid. Missing: '.implode(', ', $missing));
        }

        try {
            $monthlyRows = $this->aggregateMonthlyRows($rows);
        } catch (ValidationException $exception) {
            Storage::delete($path);

            throw $exception;
        }

        if ($existingDataset) {
            $oldPath = $existingDataset->path;
            $dataset = $existingDataset;

            $dataset->forceFill([
                'user_id' => $request->user()->id,
                'path' => $path,
                'status' => 'validating',
                'validation_errors' => null,
                'record_count' => 0,
                'created_at' => now(),
            ])->save();

            if ($oldPath !== $path) {
                Storage::delete($oldPath);
            }

            Dataset::query()
                ->where('type', 'combined')
                ->where('original_name', $originalName)
                ->where('id', '!=', $dataset->id)
                ->get()
                ->each(function (Dataset $duplicate) {
                    Storage::delete($duplicate->path);
                    $duplicate->delete();
                });
        } else {
            $dataset = Dataset::create([
                'user_id' => $request->user()->id,
                'type' => 'combined',
                'original_name' => $originalName,
                'path' => $path,
                'status' => 'validating',
            ]);
        }

        if ($replaceExisting) {
            ConsumptionRecord::truncate();
            ClimateRecord::truncate();
        }

        foreach ($monthlyRows as $row) {
            ConsumptionRecord::updateOrCreate(
                ['year' => $row['year'], 'month' => $row['month']],
                [
                    'dataset_id' => $dataset->id,
                    'consumption_kwh' => $row['consumption_kwh'],
                    'peak_demand_kw' => $row['peak_demand_kw'],
                ]
            );

            ClimateRecord::updateOrCreate(
                ['year' => $row['year'], 'month' => $row['month']],
                [
                    'dataset_id' => $dataset->id,
                    'temperature' => $row['temperature'],
                    'humidity' => $row['humidity'],
                    'rainfall' => $row['rainfall'],
                    'solar_irradiance' => $row['solar_irradiance'],
                ]
            );
        }

        $rawCount = count($rows);
        $monthlyCount = count($monthlyRows);

        $dataset->update(['status' => 'valid', 'record_count' => $rawCount]);

        AuditLogger::log(
            'Data Management',
            $existingDataset ? 'Replace Dataset' : 'Upload Dataset',
            ($existingDataset ? 'Replaced' : 'Uploaded') . ' combined electricity and climate dataset CSV with ' . $rawCount . ' raw records aggregated into complete months: ' . $monthlyCount . ' monthly records.'
        );

        try {
            $processedCount = EnergyPreprocessor::run();

            AuditLogger::log(
                'Preprocessing',
                'Auto Run Preprocessing',
                $processedCount . ' processed records generated after dataset upload.'
            );

            $workflow = app(MlWorkflow::class)->runAfterDatasetUpload($request->user()?->id);
            $workflowMessage = $workflow['predicted']
                ? ' Model retrained, next-month prediction, DSS result, and report generated automatically.'
                : ' Model retraining was skipped: ' . $workflow['message'];

            return back()->with(
                'success',
                ($existingDataset ? 'Existing dataset replaced. ' : '') . "{$rawCount} records uploaded; {$monthlyCount} complete monthly records generated (incomplete daily months excluded). {$processedCount} processed records generated automatically.{$workflowMessage}"
            );
        } catch (RuntimeException $exception) {
            return back()->with(
                'success',
                ($existingDataset ? 'Existing dataset replaced. ' : '') . "{$rawCount} records uploaded; {$monthlyCount} complete monthly records generated (incomplete daily months excluded). Automated workflow was not completed: {$exception->getMessage()}"
            );
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

        $fileName = $dataset->original_name;
        $type = $dataset->type;

        Storage::delete($dataset->path);
        $dataset->delete();

        AuditLogger::log(
            'Data Management',
            'Delete Dataset',
            'Deleted ' . $type . ' dataset: ' . $fileName . '.'
        );

        return back()->with('success', 'Dataset entry deleted.');
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = array_map(fn ($value) => strtolower(trim($value)), fgetcsv($handle) ?: []);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($headers, array_pad($line, count($headers), null));
        }

        fclose($handle);

        return [$headers, $rows];
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
                (float) ($row['peak_demand_kw'] ?? 0)
            );
            $groups[$key]['temperature'] += (float) ($row['temperature'] ?? 0);
            $groups[$key]['humidity'] += (float) ($row['humidity'] ?? 0);
            $groups[$key]['rainfall'] += (float) ($row['rainfall'] ?? 0);
            $groups[$key]['solar_irradiance'] += (float) ($row['solar_irradiance'] ?? 0);
        }

        $completeGroups = collect($groups)->filter(fn (array $group) => count($group['actual_days']) === $group['required_days']);

        if ($completeGroups->isEmpty()) {
            throw ValidationException::withMessages(['dataset' => 'The dataset must contain at least one complete month of actual consumption.']);
        }

        return $completeGroups
            ->sortBy([['year', 'asc'], ['month', 'asc']])
            ->map(function (array $group) {
                return [
                    'year' => $group['year'],
                    'month' => $group['month'],
                    'consumption_kwh' => $group['consumption_kwh'],
                    'peak_demand_kw' => $group['peak_demand_kw'],
                    'temperature' => $group['temperature'] / $group['days'],
                    'humidity' => $group['humidity'] / $group['days'],
                    'rainfall' => $group['rainfall'],
                    'solar_irradiance' => $group['solar_irradiance'] / $group['days'],
                ];
            })
            ->values()
            ->all();
    }
}
