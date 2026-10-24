@php
    $summary = $report->summary ?? [];
    $analytics = data_get($summary, 'analytics', []);
    $forecast = data_get($summary, 'latest_forecast', []);
    // Use this report's saved snapshot, never the current forecast or report date.
    $issuanceLabel = \App\Support\ForecastIssuance::label(is_array($forecast) ? $forecast : []);
    $dss = data_get($summary, 'latest_dss', []);
    $datasetProcessing = data_get($summary, 'dataset_processing', []);
    $forecastAccuracy = data_get($summary, 'forecast_accuracy', []);
    $metricDefinitions = data_get($forecastAccuracy, 'metric_definitions', \App\Support\ForecastMetrics::definitions());
    $predictionExplanation = data_get($summary, 'prediction_explanation', []);
    $dssReasoning = data_get($summary, 'dss_reasoning', []);
    $statuses = data_get($summary, 'statuses', []);

    // New snapshots preserve missing quantities; legacy snapshots retain their
    // original formatting and saved explanations without retroactive rewriting.
    $evidenceAware = data_get($dssReasoning, 'rule_version') === \App\Support\DssEvidence::RULE_VERSION;
    $legacyNotice = $evidenceAware ? '' : 'Historical / unqualified evidence. Original saved conclusions are retained for historical reference; they are not a current assessment or validated evidence of forecast reliability, readiness, feasibility or savings. ';
    $quantity = fn ($value, $suffix = '') => $evidenceAware
        ? \App\Support\DssEvidence::display($value, $suffix)
        : number_format((float) ($value ?? 0), 2).$suffix;
    $averageConsumption = data_get($analytics, 'average_consumption_kwh', data_get($summary, 'average_consumption'));
    $recommendations = data_get($dss, 'recommendations', []);
    $priorityActions = data_get($dss, 'priority_actions', []);
    $features = data_get($predictionExplanation, 'features', []);

    $period = data_get($forecast, 'year') && data_get($forecast, 'month')
        ? data_get($forecast, 'year') . '-' . str_pad((string) data_get($forecast, 'month'), 2, '0', STR_PAD_LEFT)
        : 'No forecast period';
@endphp

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f6f8; color: #0f172a; font-family: Arial, sans-serif; }
        .page { max-width: 1120px; margin: 32px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 36px; }
        .actions { margin-bottom: 24px; }
        button { border: 1px solid #cbd5e1; background: #ffffff; border-radius: 10px; padding: 9px 14px; cursor: pointer; }
        .brand { display: flex; justify-content: space-between; gap: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 22px; }
        .logo { display: inline-flex; align-items: center; gap: 12px; }
        .mark { display: inline-flex; width: 44px; height: 44px; align-items: center; justify-content: center; border-radius: 12px; background: #10b981; color: #ffffff; font-weight: 700; }
        h1 { margin: 22px 0 8px; font-size: 28px; }
        h2 { margin: 0 0 14px; font-size: 18px; }
        p { line-height: 1.6; }
        .muted { color: #64748b; }
        .section { margin-top: 28px; border: 1px solid #e2e8f0; border-radius: 18px; padding: 22px; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
        .metric { border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; background: #f8fafc; }
        .metric span { display: block; color: #64748b; font-size: 13px; }
        .metric strong { display: block; margin-top: 10px; font-size: 21px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 12px 10px; text-align: left; vertical-align: top; }
        th { background: #f8fafc; font-size: 13px; }
        ul { margin: 10px 0 0; padding-left: 20px; }
        li { margin: 8px 0; line-height: 1.5; }
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .three { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .status { display: inline-block; border-radius: 999px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 6px 10px; font-size: 13px; font-weight: 700; margin: 4px 6px 4px 0; }
        .formula { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; font-family: Consolas, monospace; font-size: 13px; line-height: 1.6; overflow-wrap: anywhere; }
        @media print {
            body { background: #ffffff; }
            .page { margin: 0; max-width: none; border: 0; border-radius: 0; }
            button, .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="page">
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>

        <div class="brand">
            <div class="logo">
                <span class="mark">DE</span>
                <div>
                    <strong>DSS Energy</strong>
                    <div class="muted">Analytics, Forecasting, and Decision Support Report</div>
                </div>
            </div>
            <div class="muted">
                Generated by: {{ $report->user?->name ?? 'System' }}<br>
                Generated on: {{ $report->created_at->format('F d, Y h:i A') }}
            </div>
        </div>

        <h1>{{ $report->title }}</h1>
        <p class="muted">
            This report summarizes electricity consumption analytics, machine learning forecasting,
            and decision support recommendations for renewable energy planning.
        </p>

        <section class="section">
            <h2>Report Basis</h2>
            <p class="muted">
                {{ $legacyNotice }}{{ data_get($summary, 'report_basis', 'This report is based on analytics, forecasting, and DSS records available when the report was generated.') }}
            </p>
            <div>
                <span class="status">Data: {{ data_get($statuses, 'data_status', 'Pending') }}</span>
                <span class="status">Forecast: {{ data_get($statuses, 'forecast_status', 'Pending') }}</span>
                <span class="status">DSS: {{ data_get($statuses, 'dss_status', 'Pending') }}</span>
                <span class="status">Model: {{ data_get($statuses, 'model_used', data_get($forecast, 'model_type', 'No model')) }}</span>
            </div>
        </section>

        <section class="section">
            <h2>Dataset Processing Summary</h2>
            <div class="grid">
                <div class="metric">
                    <span>Uploaded Dataset</span>
                    <strong>{{ data_get($datasetProcessing, 'uploaded_dataset', 'No dataset') }}</strong>
                </div>
                <div class="metric">
                    <span>Raw Daily Records</span>
                    <strong>{{ number_format((int) data_get($datasetProcessing, 'raw_daily_records', 0)) }}</strong>
                </div>
                <div class="metric">
                    <span>Monthly Records</span>
                    <strong>{{ number_format((int) data_get($datasetProcessing, 'monthly_records_generated', 0)) }}</strong>
                </div>
                <div class="metric">
                    <span>Preprocessing Status</span>
                    <strong>{{ data_get($datasetProcessing, 'preprocessing_status', 'Pending') }}</strong>
                </div>
            </div>
            <p class="muted">
                Processed records: {{ number_format((int) data_get($datasetProcessing, 'processed_records', data_get($analytics, 'record_count', 0))) }}.
                Latest processed period: {{ data_get($datasetProcessing, 'latest_processed_period', data_get($analytics, 'latest_period', 'No processed data')) }}.
                Uploaded by: {{ data_get($datasetProcessing, 'uploaded_by', 'Admin') }}.
            </p>
        </section>

        <section class="section">
            <h2>Analytics Summary</h2>
            <div class="grid">
                <div class="metric">
                    <span>Average Consumption</span>
                    <strong>{{ $quantity($averageConsumption, ' kWh') }}</strong>
                </div>
                <div class="metric">
                    <span>Highest Consumption</span>
                    <strong>{{ $quantity(data_get($analytics, 'highest_consumption_kwh'), ' kWh') }}</strong>
                </div>
                <div class="metric">
                    <span>Average Peak Demand</span>
                    <strong>{{ $quantity(data_get($analytics, 'average_peak_demand_kw'), ' kW') }}</strong>
                </div>
                <div class="metric">
                    <span>Average Solar Irradiance</span>
                    <strong>{{ $quantity(data_get($analytics, 'average_solar_irradiance'), '') }}</strong>
                </div>
            </div>
            <p class="muted">
                Processed records: {{ number_format((int) data_get($analytics, 'record_count', 0)) }}.
                Latest processed period: {{ data_get($analytics, 'latest_period', 'No processed data') }}.
            </p>
        </section>

        <section class="section">
            <h2>Forecasting Result</h2>
            <p class="muted">{{ $evidenceAware ? '' : 'Recorded timing context (presentation only, derived from this snapshot): ' }}{{ $issuanceLabel }}</p>
            <table>
                <thead>
                    <tr>
                        <th>Forecast Period</th>
                        <th>Predicted Consumption</th>
                        <th>Previous Consumption</th>
                        <th>Change</th>
                        <th>Model</th>
                        <th>MAE</th>
                        <th>RMSE</th>
                        <th>R2 Score</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $period }}</td>
                        <td>{{ $quantity(data_get($forecast, 'predicted_consumption_kwh'), ' kWh') }}</td>
                        <td>{{ $quantity(data_get($forecast, 'previous_consumption_kwh'), ' kWh') }}</td>
                        <td>{{ $quantity(data_get($forecast, 'change_percent'), '%') }}</td>
                        <td>{{ data_get($forecast, 'model_type', 'No model') }}</td>
                        <td>{{ \App\Support\ForecastMetrics::display(data_get($forecast, 'mae', data_get($forecastAccuracy, 'mae'))) }}</td>
                        <td>{{ \App\Support\ForecastMetrics::display(data_get($forecast, 'rmse', data_get($forecastAccuracy, 'rmse'))) }}</td>
                        <td>{{ \App\Support\ForecastMetrics::display(data_get($forecast, 'r2_score', data_get($forecastAccuracy, 'r2_score'))) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="section">
            <h2>Model Accuracy</h2>
            <div class="three">
                <div class="metric">
                    <span>MAE</span>
                    <strong>{{ \App\Support\ForecastMetrics::display(data_get($forecastAccuracy, 'mae', data_get($forecast, 'mae'))) }}</strong>
                </div>
                <div class="metric">
                    <span>RMSE</span>
                    <strong>{{ \App\Support\ForecastMetrics::display(data_get($forecastAccuracy, 'rmse', data_get($forecast, 'rmse'))) }}</strong>
                </div>
                <div class="metric">
                    <span>R2 Score</span>
                    <strong>{{ \App\Support\ForecastMetrics::display(data_get($forecastAccuracy, 'r2_score', data_get($forecast, 'r2_score'))) }}</strong>
                </div>
            </div>
            <p class="muted">{{ data_get($forecastAccuracy, 'interpretation', 'Model accuracy interpretation is unavailable.') }}</p>
            <p class="muted">{{ $evidenceAware ? '' : (data_get($forecastAccuracy, 'metric_definitions') === null ? 'Current explanatory metric context (presentation only, not part of the saved assessment): ' : 'Saved metric context (historical, not revalidated): ') }}{{ data_get($metricDefinitions, 'mae') }} {{ data_get($metricDefinitions, 'rmse') }} {{ data_get($metricDefinitions, 'r2_score') }} {{ data_get($metricDefinitions, 'fold_average') }} {{ data_get($metricDefinitions, 'scope') }} {{ data_get($metricDefinitions, 'legacy') }}</p>
        </section>

        <section class="section">
            <h2>Prediction Explanation</h2>
            <p class="muted">
                {{ data_get($predictionExplanation, 'description', 'The system uses the trained model and latest processed monthly features to generate the next-month prediction.') }}
            </p>
            <div class="formula">
                predicted_consumption = intercept + b1(year) + b2(month) + b3(temperature) + b4(humidity) + b5(rainfall) + b6(solar_irradiance) + b7(peak_demand_kw) + b8(lag_1) + b9(lag_2) + b10(trend) + b11(month_sin) + b12(month_cos)
            </div>
            @if (count($features) > 0)
                <p class="muted">Features used: {{ implode(', ', $features) }}.</p>
            @endif
        </section>

        <section class="section">
            <h2>Decision Support Assessment</h2>
            <p class="muted">{{ $evidenceAware ? '' : 'Historical / unqualified evidence. Recorded timing context (presentation only): ' }}Estimate basis: {{ $issuanceLabel }} Assessment generated: {{ data_get($dss, 'updated_at') ?? data_get($dss, 'created_at') ?? 'unavailable' }}.</p>
            <div class="two">
                <div class="metric">
                    <span>Demand Status</span>
                    <strong>{{ data_get($dss, 'demand_status', 'No DSS result') }}</strong>
                </div>
                <div class="metric">
                    <span>Readiness Level</span>
                    <strong>{{ data_get($dss, 'readiness_level', 'No readiness assessment') }}</strong>
                </div>
            </div>
            <div class="two" style="margin-top: 18px;">
                <div>
                    <h2>Demand Reasoning</h2>
                    <p class="muted">{{ data_get($dssReasoning, 'demand_reason', 'Demand reasoning is unavailable.') }}</p>
                </div>
                <div>
                    <h2>Readiness Reasoning</h2>
                    <p class="muted">{{ data_get($dssReasoning, 'readiness_reason', 'Readiness reasoning is unavailable.') }}</p>
                </div>
            </div>
        </section>

        <section class="section">
            <h2>Recommendations</h2>
            @if (count($recommendations) > 0)
                <ul>
                    @foreach ($recommendations as $recommendation)
                        <li>{{ $recommendation }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No recommendations available.</p>
            @endif
        </section>

        <section class="section">
            <h2>Priority Actions</h2>
            @if (count($priorityActions) > 0)
                <ul>
                    @foreach ($priorityActions as $action)
                        <li>{{ $action }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted">No priority actions available.</p>
            @endif
        </section>
    </main>
</body>
</html>
