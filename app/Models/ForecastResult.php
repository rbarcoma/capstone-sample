<?php

namespace App\Models;

use App\Support\ForecastIssuance;
use App\Support\ForecastMetrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastResult extends Model
{
    public const ARTIFACT_PROVENANCE_FIELDS = ['artifact_id', 'dataset_hash', 'training_cutoff', 'evaluation_protocol'];

    protected $appends = ['issuance_label', 'metric_definitions', 'artifact_provenance'];

    /** Saved issuance facts only; never consult today's dataset or artifact. */
    public function getArtifactProvenanceAttribute(): array
    {
        $facts = [];
        foreach (self::ARTIFACT_PROVENANCE_FIELDS as $field) {
            $facts[$field] = $this->getAttributes()[$field] ?? null;
        }
        $present = count(array_filter($facts, fn ($value) => is_string($value) && $value !== ''));

        return ['status' => match ($present) {
            0 => 'unavailable',
            count(self::ARTIFACT_PROVENANCE_FIELDS) => 'recorded',
            default => 'incomplete',
        }] + $facts;
    }

    public function getMetricDefinitionsAttribute(): array
    {
        return ForecastMetrics::definitions();
    }

    public function getIssuanceLabelAttribute(): string
    {
        return ForecastIssuance::label($this->getAttributes());
    }

    protected $fillable = [
        'user_id',
        'predicted_at',
        'year',
        'month',
        'predicted_consumption_kwh',
        'previous_consumption_kwh',
        'change_percent',
        'mae',
        'rmse',
        'r2_score',
        'model_type',
        'artifact_id',
        'dataset_hash',
        'training_cutoff',
        'evaluation_protocol',
    ];

    protected function casts(): array
    {
        return [
            'predicted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
