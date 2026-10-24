<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

class ForecastIssuance
{
    /** Describe recorded issuance only; never consult the clock or live data. */
    public static function label(array $forecast): string
    {
        $year = $forecast['year'] ?? null;
        $month = $forecast['month'] ?? null;
        $period = is_numeric($year) && is_numeric($month)
            && (int) $year == $year && (int) $month == $month
            && $year >= 1 && $year <= 9998 && $month >= 1 && $month <= 12
                ? sprintf('%04d-%02d', $year, $month) : null;
        $label = 'Target: '.($period ?? 'unavailable').'. ';
        $produced = self::timestamp($forecast['predicted_at'] ?? null);
        if (! $produced) {
            $updated = self::timestamp($forecast['updated_at'] ?? null);

            return $label.'Produced: unavailable. '
                .($updated ? 'Record updated: '.$updated->format('Y-m-d H:i:s T').'. ' : '')
                .'Advance issuance unverified.';
        }
        $label .= 'Produced: '.$produced->format('Y-m-d H:i:s T').'. ';
        if (! $period) {
            return $label.'Advance issuance unverified.';
        }

        return $label.match (true) {
            $produced->format('Y-m') < $period => 'Produced before target month.',
            $produced->format('Y-m') > $period => 'Produced after target month; not issued in advance.',
            default => 'Produced during target month; not issued before the month.',
        };
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        // Reject relative/partial dates: Carbon must not fill in today's fields.
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
