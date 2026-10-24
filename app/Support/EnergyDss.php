<?php

namespace App\Support;

use App\Models\DssResult;
use App\Models\ForecastResult;
use App\Models\ProcessedRecord;
use Carbon\CarbonInterface;

class EnergyDss
{
    public static function classifyDemand(float $predicted, ?float $average = null): string
    {
        $baseline = max($average ?: $predicted, 1);
        $ratio = $predicted / $baseline;

        return match (true) {
            $ratio >= 1.15 => 'High Demand',
            $ratio >= 0.90 => 'Moderate Demand',
            default => 'Low Demand',
        };
    }

    public static function readiness(float $solarIrradiance, float $peakDemand, float $predicted): string
    {
        $score = 0;
        $score += $solarIrradiance >= 5 ? 2 : ($solarIrradiance >= 3.5 ? 1 : 0);
        $score += $peakDemand <= ($predicted * 0.20) ? 2 : 1;

        return match (true) {
            $score >= 4 => 'High Readiness',
            $score >= 2 => 'Moderate Readiness',
            default => 'Low Readiness',
        };
    }

    /** Retained without threshold/unit changes; inactive pending verified 6b basis. */
    private static function legacyRecommendations(ForecastResult $forecast, ?ProcessedRecord $latest, string $demand, string $readiness): array
    {
        $items = [];
        $actions = [];
        $solar = (float) ($latest?->solar_irradiance ?? 0);
        $peak = (float) ($latest?->peak_demand_kw ?? 0);
        $predicted = (float) $forecast->predicted_consumption_kwh;

        if ($demand === 'High Demand' && $solar >= 4) {

            $items[] = 'The system detected a high electricity demand together with good solar energy potential. Because of this, the area may benefit from starting renewable energy transition planning, especially for government buildings, public facilities, and other high-consumption establishments.';

            $actions[] = 'The administration should begin conducting solar photovoltaic feasibility studies, evaluate available rooftop or open-space areas for solar panel installation, and identify facilities with the highest electricity consumption for possible renewable energy integration.';
        }

        if ($peak > ($predicted * 0.18)) {

            $items[] = 'The system identified that the peak demand is relatively high compared to the predicted electricity consumption. This may indicate heavy electricity usage during certain hours, which can increase operational costs and pressure on the energy supply system.';

            $actions[] = 'The administration is encouraged to implement energy efficiency programs, monitor peak-hour electricity usage, replace inefficient equipment, and apply load management strategies to reduce excessive demand during high-consumption periods.';
        }

        if (($forecast->change_percent ?? 0) > 5) {

            $items[] = 'The forecasted electricity consumption shows a noticeable increase compared to the previous period. This may indicate growing energy demand, population activity, or operational expansion that could require additional renewable energy planning and energy management preparation.';

            $actions[] = 'The administration should review facilities or locations with increasing electricity consumption, monitor areas with rapid energy growth, and prioritize renewable energy projects that can help offset future electricity demand.';
        }

        if ($readiness === 'Low Readiness') {

            $items[] = 'The system assessed that the current renewable energy readiness level is still low. This may be caused by limited solar energy potential, insufficient monitoring data, or high peak electricity demand that may affect renewable energy transition planning.';

            $actions[] = 'The administration should first improve electricity and environmental data collection, strengthen energy monitoring practices, and establish a more consistent monthly reporting process before implementing large-scale renewable energy investments.';
        }

        if ($items === []) {

            $items[] = 'The system assessment shows that the current electricity demand and renewable energy readiness are within manageable conditions. At this stage, continuous monitoring and gradual renewable energy preparation are recommended to maintain stable energy planning.';

            $actions[] = 'The administration should continue updating electricity forecasts regularly, compare actual and predicted consumption results, maintain energy monitoring activities, and gradually prepare long-term renewable energy transition strategies.';
        }

        return [$items, $actions];
    }

    public static function recommendations(ForecastResult $forecast, ?ProcessedRecord $latest, string $demand, string $readiness): array
    {
        // Existing threshold helpers are retained, but are not an evidence basis.
        return DssEvidence::guidance();
    }

    public static function generateForForecast(ForecastResult $forecast, ?int $userId = null, ?CarbonInterface $generatedAt = null): DssResult
    {
        if (! $forecast->exists || $forecast->isDirty() || ! ($forecast = $forecast->fresh())) {
            throw new \RuntimeException('A saved, unchanged forecast is required for DSS linkage.');
        }
        $latest = ProcessedRecord::query()->orderByDesc('year')->orderByDesc('month')->first();
        $basis = DssEvidence::inspect($forecast, $latest);
        [$recommendations, $actions] = DssEvidence::guidance();
        // No engineering/unit thresholds are selected or changed by this gate.
        $dss = DssResult::updateOrCreate(['forecast_result_id' => $forecast->id], [
            'user_id' => $userId,
            'demand_status' => DssEvidence::NOT_ASSESSED,
            'readiness_level' => DssEvidence::NOT_ASSESSED,
            'recommendations' => $recommendations,
            'priority_actions' => $actions,
            'basis' => $basis + ['source' => 'dss_generation'],
        ]);

        $generatedAt ??= now();

        $dss->forceFill([
            'updated_at' => $generatedAt,
        ])->saveQuietly();

        return $dss->refresh();
    }
}
