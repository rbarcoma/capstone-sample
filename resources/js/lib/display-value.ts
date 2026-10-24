/** Display absence explicitly while preserving genuine numeric zero. */
export function displayValue(value: number | string | null | undefined, suffix = ''): string {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
        return 'Unavailable';
    }

    return `${value}${suffix}`;
}
