<?php

namespace App\Support;

class ProcessedDataset
{
    /** Preserve the workflow's existing CSV bytes for identity and read-only checks. */
    public static function contents(iterable $records): string
    {
        $headers = ['year', 'month', 'consumption_kwh', 'temperature', 'humidity', 'rainfall',
            'solar_irradiance', 'peak_demand_kw', 'lag_1', 'lag_2', 'trend', 'month_sin', 'month_cos'];
        $lines = [implode(',', $headers)];
        foreach ($records as $record) {
            $lines[] = implode(',', array_map(fn ($field) => $record->{$field}, $headers));
        }

        return implode(PHP_EOL, $lines);
    }
}
