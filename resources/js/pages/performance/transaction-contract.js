/**
 * Reads the transaction contract outputs (docs/transaction-dates-coverage.md) into the `{ value, available }`
 * metric the formatters take. `result` is an aggregate (`metrics`) or a comparison (`values`):
 * - availability `unavailable`, or a value the result does not carry → not available
 * - available with a null value → a ratio with a zero divisor
 */

export function metricOf(result, path) {
    const value = path.split('.').reduce((source, key) => source?.[key], result?.metrics ?? result?.values);

    return { value: value ?? null, available: Boolean(result) && result.availability !== 'unavailable' && value !== undefined };
}
