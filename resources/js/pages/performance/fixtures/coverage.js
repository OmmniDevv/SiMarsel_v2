/**
 * Completeness metadata per (platform_id, dataset) in the contract shape (docs/transaction-dates-coverage.md).
 *
 * `complete_until` is the exclusive end of complete data; the combined value is the earliest selected platform.
 * `sync_finished_at` only records when the pull finished and does not prove completeness.
 * The 14.00 and 13.00 hours are test data; the real boundary always follows this metadata.
 */

export const platforms = Object.freeze([
    { id: 1, name: 'Platform A' },
    { id: 2, name: 'Platform B' },
]);

const coverage = (date, completeUntil, availability, rows) => ({
    dataset: 'transactions',
    date_from: date,
    date_to: date,
    complete_until: completeUntil,
    availability,
    platforms: rows.map(([platformId, until, rowAvailability, syncFinishedAt]) => ({
        platform_id: platformId,
        dataset: 'transactions',
        date_from: date,
        date_to: date,
        complete_until: until,
        availability: rowAvailability,
        sync_finished_at: syncFinishedAt,
    })),
});

export const completeCoverage = coverage('2026-11-11', '2026-11-11T13:00:00+07:00', 'partial', [
    [1, '2026-11-11T14:00:00+07:00', 'partial', '2026-11-11T14:05:00+07:00'],
    [2, '2026-11-11T13:00:00+07:00', 'partial', '2026-11-11T14:20:00+07:00'],
]);

export const emptyDayCoverage = coverage('2026-11-15', '2026-11-16T00:00:00+07:00', 'complete', [
    [1, '2026-11-16T00:00:00+07:00', 'complete', '2026-11-16T00:30:00+07:00'],
    [2, '2026-11-16T00:00:00+07:00', 'complete', '2026-11-16T00:30:00+07:00'],
]);

/** Platform B has been pulled but its completeness is still unknown. */
export const incompleteCoverage = coverage('2026-11-16', null, 'unavailable', [
    [1, '2026-11-16T14:00:00+07:00', 'partial', '2026-11-16T14:05:00+07:00'],
    [2, null, 'unavailable', '2026-11-16T14:05:00+07:00'],
]);
