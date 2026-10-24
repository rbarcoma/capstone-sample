<?php

namespace App\Support;

class ForecastMetrics
{
    /** Read-only definitions shared with the trainer and existing consumers. */
    public static function definitions(): array
    {
        return json_decode(file_get_contents(base_path('resources/forecast-metrics.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function rounded(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 4) : null;
    }

    public static function display(mixed $value): string
    {
        $value = self::rounded($value);

        return $value === null ? 'Unavailable' : number_format($value, 4);
    }
}
