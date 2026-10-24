<?php

namespace App\Support;

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;

class DssEvidence
{
    public const RULE_VERSION = 'dss_evidence_qualification_v1';

    public const NOT_ASSESSED = 'Not assessed';

    public static function inspect(?ForecastResult $forecast, ?ProcessedRecord $latest, ?DssResult $assessment = null): array
    {
        $ids = ForecastPeriod::current()->pluck('id');
        $linkage = ! $forecast || ! $forecast->exists || ! ForecastResult::whereKey($forecast->id)->exists()
            ? 'missing' : ($ids->count() > 1 ? 'ambiguous' : ($ids->contains($forecast->id) ? 'current' : 'mismatch'));
        $values = [
            'predicted_consumption_kwh' => [$forecast?->predicted_consumption_kwh, 'kWh'],
            'average_consumption_kwh' => [ProcessedRecord::avg('consumption_kwh'), 'kWh'],
            'peak_demand_kw' => [$latest?->peak_demand_kw, 'kW'],
            'solar_irradiance' => [$latest?->solar_irradiance, 'unverified source unit'],
        ];
        $inputs = [];
        $reasons = ['demand_semantics_unverified', 'unit_consistent_threshold_basis_unverified', 'feasibility_inputs_unverified'];
        if ($linkage !== 'current') {
            $reasons[] = 'forecast_linkage_'.$linkage;
        }
        if ($assessment && (! $forecast || $assessment->forecast_result_id !== $forecast->id)) {
            $reasons[] = 'assessment_forecast_mismatch';
        }
        foreach ($values as $name => [$raw, $unit]) {
            $value = AnalyticsSeries::number($raw);
            $validity = $raw === null ? 'missing' : ($value === null ? 'invalid_numeric' : ($value < 0 ? 'negative' : 'finite_nonnegative'));
            $inputs[$name] = ['value' => $value, 'unit' => $unit, 'availability' => $value === null ? 'unavailable' : 'available', 'validity' => $validity];
            if ($validity !== 'finite_nonnegative') {
                $reasons[] = $name.'_'.$validity;
            }
        }
        $metrics = [
            'mae' => AnalyticsSeries::number($forecast?->mae),
            'rmse' => AnalyticsSeries::number($forecast?->rmse),
            'r2_score' => AnalyticsSeries::number($forecast?->r2_score),
        ];
        $evaluation = in_array(null, $metrics, true) ? 'unavailable'
            : (($metrics['mae'] < 0 || $metrics['rmse'] < 0 || $metrics['r2_score'] > 1) ? 'invalid'
                : ($metrics['r2_score'] < 0 ? 'weak' : 'unvalidated'));
        $reasons[] = 'evaluation_'.$evaluation;
        if ($metrics['r2_score'] !== null && $metrics['r2_score'] < 0) {
            $reasons[] = 'negative_r2';
        }
        $reasons[] = 'prospective_performance_unverified';
        $provenance = $forecast?->artifact_provenance;
        if (($provenance['status'] ?? null) !== 'recorded') {
            $reasons[] = 'forecast_provenance_unavailable_or_incomplete';
        }

        return [
            'predicted_consumption_kwh' => $inputs['predicted_consumption_kwh']['value'],
            'average_consumption_kwh' => $inputs['average_consumption_kwh']['value'],
            'peak_demand_kw' => $inputs['peak_demand_kw']['value'],
            'solar_irradiance' => $inputs['solar_irradiance']['value'],
            'rule_version' => self::RULE_VERSION,
            'assessment_status' => 'not_assessed',
            'reliability_status' => 'insufficient_verified_basis',
            'forecast_linkage' => ['status' => $linkage, 'forecast_result_id' => $forecast?->id],
            'forecast_provenance' => $provenance,
            'issuance_label' => $forecast?->issuance_label,
            'input_period' => $latest ? sprintf('%04d-%02d', $latest->year, $latest->month) : null,
            'inputs' => $inputs,
            'evaluation' => $metrics + ['status' => $evaluation, 'scope' => 'saved_evaluation_not_prospective_validation', 'protocol' => $forecast?->evaluation_protocol],
            'units_verified' => false,
            'threshold_policy' => 'legacy_thresholds_not_applied_pending_6b',
            'reasons' => $reasons,
        ];
    }

    public static function guidance(): array
    {
        return [[
            'Demand and renewable readiness are not assessed: there is insufficient verified basis for a unit-consistent assessment. Forecast inputs and evaluation may be incomplete, weak or unvalidated; treat this estimate as qualified monitoring information.',
            'Solar irradiance is indicative resource information only. Feasibility, savings, roof area, shading, structural suitability, load profiles and economics have not been verified.',
        ], [
            'Verify source completeness, measurement units and whether demand is measured peak power or billed demand before applying operational thresholds.',
            'Continue collecting actual monthly consumption and compare issued estimates with observed outcomes. Obtain independent technical and economic evidence before investment or feasibility decisions.',
        ]];
    }

    public static function display(mixed $value, string $suffix = ''): string
    {
        $number = AnalyticsSeries::number($value);

        return $number === null ? 'Unavailable' : number_format($number, 2).$suffix;
    }

    public static function interpretation(array $basis): string
    {
        $metrics = $basis['evaluation'];
        $energy = fn ($value) => ForecastMetrics::display($value).($value === null ? '' : ' kWh');

        return 'Saved evaluation: MAE '.$energy($metrics['mae']).'; RMSE '.$energy($metrics['rmse']).'; R² '.ForecastMetrics::display($metrics['r2_score']).'. Evidence status: '.$metrics['status'].'. Missing or undefined scores remain unavailable; zero and negative values are retained. These evaluation results do not establish prospective forecast accuracy, decision suitability, renewable feasibility or savings.';
    }

    public static function demandReason(array $basis): string
    {
        $inputs = $basis['inputs'];

        return 'Demand not assessed: insufficient verified basis. Prediction: '.self::display($inputs['predicted_consumption_kwh']['value'], ' kWh').'; historical average: '.self::display($inputs['average_consumption_kwh']['value'], ' kWh').'; declared peak demand: '.self::display($inputs['peak_demand_kw']['value'], ' kW').'. Forecast linkage: '.$basis['forecast_linkage']['status'].'. Demand meaning and a unit-consistent threshold basis remain unverified; no kW-to-kWh fraction is applied. Qualification reasons: '.implode(', ', $basis['reasons']).'.';
    }

    public static function readinessReason(array $basis): string
    {
        return 'Readiness not assessed: insufficient verified basis. Solar irradiance: '.self::display($basis['inputs']['solar_irradiance']['value']).' (source unit unverified). This is indicative resource information only; roof area, shading, structural suitability, load profiles and economics remain unverified. No feasibility or savings conclusion is established.';
    }
}
