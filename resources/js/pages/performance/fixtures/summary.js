/**
 * Dashboard scenarios in the output shape of TransactionAggregationService::aggregate() and
 * ComparisonSnapshotService::compare() (docs/transaction-dates-coverage.md). Read them with ../transaction-contract.
 */

import { completeCoverage, emptyDayCoverage, incompleteCoverage } from './coverage';

const PLATFORM_IDS = [1, 2];

const aggregate = (basis, date, availability, asOf, metrics) => ({
    basis,
    platform_ids: PLATFORM_IDS,
    date_from: date,
    date_to: date,
    availability,
    as_of: asOf,
    metrics,
});

const breakdown = (pending, success, expired, failed, canceled) => ({
    pending: { count: pending[0], nominal: pending[1] },
    success: { count: success[0], nominal: success[1] },
    expired: { count: expired[0], nominal: expired[1] },
    failed: { count: failed[0], nominal: failed[1] },
    canceled: { count: canceled[0], nominal: canceled[1] },
});

const createdMetrics = (total, attempts, claims, success, unsuccessful, unknown, unknownNominal, conversion, statuses) => ({
    total_checkouts: total,
    purchase_attempts: attempts,
    claim_checkouts: claims,
    success_purchases: success,
    unsuccessful_purchases: unsuccessful,
    unknown_status_count: unknown,
    unknown_status_nominal: unknownNominal,
    conversion_percentage: conversion,
    is_provisional: unknown === null ? null : unknown > 0,
    status_breakdown: statuses,
});

const paidMetrics = (payments, revenue, nominalTotal, averageOrderValue, uniqueBuyers) => ({
    success_payments: payments,
    revenue,
    nominal_total: nominalTotal,
    average_order_value: averageOrderValue,
    unique_buyers: uniqueBuyers,
});

const NONE = [null, null];
const ZERO = [0, 0];

const missingSnapshot = (cutoffAt) => ({ source: 'snapshot', availability: 'unavailable', cutoff_at: cutoffAt, as_of: null, values: null });
const noComparison = () => ({ source: null, availability: 'unavailable', cutoff_at: null, as_of: null, values: null });

const TODAY = '2026-11-11';
const TODAY_CUTOFF = '2026-11-11T13:00:00+07:00';
const SNAPSHOT_CUTOFF = '2026-11-10T13:00:00+07:00';

/** Hari Ini, 11 Nov 2026, Platform A + B, complete until 13.00 WIB. Matches the rows in transactions.js. */
export const todaySummary = {
    coverage: completeCoverage,
    created: aggregate('created', TODAY, 'partial', TODAY_CUTOFF, createdMetrics(
        16, 15, 1, 9, 3, 1, 175000, 60,
        breakdown([2, 270000], [9, 1587000], [1, 100000], [1, 200000], [1, 50000]),
    )),
    paid: aggregate('paid', TODAY, 'partial', TODAY_CUTOFF, paidMetrics(9, 1462500, 1472000, 162500, 7)),
    comparison: {
        created: {
            source: 'snapshot',
            availability: 'complete',
            cutoff_at: SNAPSHOT_CUTOFF,
            as_of: SNAPSHOT_CUTOFF,
            values: { total_checkouts: 12, purchase_attempts: 10, success_purchases: 5, unknown_status_count: 0, conversion_percentage: 50, is_provisional: false },
        },
        paid: {
            source: 'snapshot',
            availability: 'complete',
            cutoff_at: SNAPSHOT_CUTOFF,
            as_of: SNAPSHOT_CUTOFF,
            values: paidMetrics(8, 1250000, 1260000, 156250, 6),
        },
    },
};

/** Same period, but the equivalent-hour snapshot of the comparison day does not exist. */
export const missingComparisonSummary = {
    ...todaySummary,
    comparison: { created: missingSnapshot(SNAPSHOT_CUTOFF), paid: missingSnapshot(SNAPSHOT_CUTOFF) },
};

const EMPTY_DAY = '2026-11-15';
const EMPTY_AS_OF = '2026-11-16T00:00:00+07:00';
const PREVIOUS_AS_OF = '2026-11-15T00:00:00+07:00';
const emptyCreated = () => createdMetrics(0, 0, 0, 0, 0, 0, 0, null, breakdown(ZERO, ZERO, ZERO, ZERO, ZERO));
const emptyPaid = () => paidMetrics(0, 0, 0, null, 0);

/** Complete period without transactions: counts are 0, ratios cannot be computed. */
export const emptyCompleteSummary = {
    coverage: emptyDayCoverage,
    created: aggregate('created', EMPTY_DAY, 'complete', EMPTY_AS_OF, emptyCreated()),
    paid: aggregate('paid', EMPTY_DAY, 'complete', EMPTY_AS_OF, emptyPaid()),
    comparison: {
        created: { source: 'latest', availability: 'complete', cutoff_at: PREVIOUS_AS_OF, as_of: PREVIOUS_AS_OF, values: emptyCreated() },
        paid: { source: 'latest', availability: 'complete', cutoff_at: PREVIOUS_AS_OF, as_of: PREVIOUS_AS_OF, values: emptyPaid() },
    },
};

const UNKNOWN_DAY = '2026-11-16';

/** Coverage of Platform B is unknown: nothing is shown as 0. */
export const incompleteSummary = {
    coverage: incompleteCoverage,
    created: aggregate('created', UNKNOWN_DAY, 'unavailable', null, createdMetrics(
        null, null, null, null, null, null, null, null,
        breakdown(NONE, NONE, NONE, NONE, NONE),
    )),
    paid: aggregate('paid', UNKNOWN_DAY, 'unavailable', null, paidMetrics(null, null, null, null, null)),
    comparison: { created: noComparison(), paid: noComparison() },
};
