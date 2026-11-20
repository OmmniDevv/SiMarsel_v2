import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { loadPerformanceModule, plain } from './support/performance-module.mjs';

const fixtures = await loadPerformanceModule('fixtures/index.js');
const contract = JSON.parse(await readFile(new URL('../Fixtures/contracts/transaction-dates-coverage.json', import.meta.url), 'utf8'));

const rows = plain(fixtures.transactions);
const byId = (id) => rows.find((row) => row.markaz_transaction_id === id);

const CREATED_METRICS = [
    'total_checkouts', 'purchase_attempts', 'claim_checkouts', 'success_purchases', 'unsuccessful_purchases',
    'unknown_status_count', 'unknown_status_nominal', 'conversion_percentage', 'is_provisional', 'status_breakdown',
];
const PAID_METRICS = ['success_payments', 'revenue', 'nominal_total', 'average_order_value', 'unique_buyers'];
const SCENARIOS = ['todaySummary', 'missingComparisonSummary', 'emptyCompleteSummary', 'incompleteSummary'];

test('rows carry exactly the backend contract fields plus the resolved report status', () => {
    const expected = [...Object.keys(contract.fields.transactions), 'report_status', 'report_status_label'].sort();

    for (const row of rows) {
        assert.deepEqual(Object.keys(row).sort(), expected, row.markaz_transaction_id);
        assert.equal(Number.isInteger(row.markaz_customer_id), true);
    }
});

test('coverage rows carry exactly the backend contract fields', () => {
    const expected = Object.keys(contract.fields.coverage).sort();

    for (const scenario of SCENARIOS) {
        for (const row of plain(fixtures[scenario].coverage.platforms)) assert.deepEqual(Object.keys(row).sort(), expected);
    }
});

test('every scenario follows the aggregate and comparison output of the backend', () => {
    for (const name of SCENARIOS) {
        const scenario = plain(fixtures[name]);

        assert.deepEqual(Object.keys(scenario.created.metrics).sort(), [...CREATED_METRICS].sort(), name);
        assert.deepEqual(Object.keys(scenario.paid.metrics).sort(), [...PAID_METRICS].sort(), name);
        assert.deepEqual(Object.keys(scenario.created.metrics.status_breakdown).sort(), ['canceled', 'expired', 'failed', 'pending', 'success']);

        for (const comparison of [scenario.comparison.created, scenario.comparison.paid]) {
            assert.deepEqual(Object.keys(comparison).sort(), ['as_of', 'availability', 'cutoff_at', 'source', 'values'], name);
        }
    }
});

test('AC-01: the fixtures keep 0 apart from an unavailable value', () => {
    const zero = plain(fixtures.emptyCompleteSummary);
    const missing = plain(fixtures.incompleteSummary);

    assert.deepEqual([zero.created.availability, zero.created.metrics.total_checkouts], ['complete', 0]);
    assert.deepEqual([missing.created.availability, missing.created.metrics.total_checkouts], ['unavailable', null]);
    assert.equal(missing.coverage.complete_until, null);
});

test('AC-03: a coupon claim row is marked is_claim_coupon', () => {
    assert.equal(rows.filter((row) => row.is_claim_coupon).length, 1);
});

test('AC-03: a group purchase has one buyer row, and member rows point at it on the same platform', () => {
    const buyer = byId('MKZ-B-2001');
    const members = rows.filter((row) => row.markaz_ref_id === buyer.markaz_transaction_id);

    assert.equal(buyer.markaz_ref_id, null);
    assert.equal(members.length, 2);
    for (const member of members) assert.equal(member.platform_id, buyer.platform_id);
});

test('AC-03: the status shows the effective status or the source fallback', () => {
    const read = (id) => {
        const row = byId(id);

        return [row.source_status, row.effective_status, row.report_status, row.report_status_label];
    };

    assert.deepEqual(read('MKZ-A-1005'), [0, 2, 2, 'Expired']);
    assert.deepEqual(read('MKZ-A-1010'), [1, 0, 0, 'Pending']);
    assert.deepEqual(read('MKZ-A-1006'), [3, null, 3, 'Failed']);
    assert.deepEqual(read('MKZ-A-1007'), [4, null, 4, 'Canceled']);
    assert.deepEqual(read('MKZ-A-1008'), [null, null, null, 'Status belum tersedia']);
});

test('AC-03: a Pending row has an empty payment date', () => {
    const pending = byId('MKZ-A-1004');

    assert.deepEqual([pending.report_status_label, pending.markaz_paid_at, pending.paid_date, pending.paid_time], ['Pending', null, null, null]);
});

test('AC-03: the rows carry nominal, admin_fee and net_nominal for net Omzet', () => {
    const read = (id) => [byId(id).nominal, byId(id).admin_fee, byId(id).net_nominal];

    assert.deepEqual(read('MKZ-A-1002'), [182000, 2000, 180000]);
    assert.deepEqual(read('MKZ-A-1003'), [185000, 5000, 180000]);
});

test('AC-03: the equivalent-hour comparison carries its snapshot time, and a missing one is marked', () => {
    const available = plain(fixtures.todaySummary.comparison.created);
    assert.deepEqual([available.source, available.availability, available.as_of], ['snapshot', 'complete', '2026-11-10T13:00:00+07:00']);

    const missing = plain(fixtures.missingComparisonSummary.comparison.created);
    assert.deepEqual([missing.availability, missing.as_of, missing.values], ['unavailable', null, null]);
});

test('the fixtures hold no customer name or email', () => {
    for (const row of rows) {
        assert.equal('customer_name' in row, false);
        assert.equal('customer_email' in row, false);
    }
});
