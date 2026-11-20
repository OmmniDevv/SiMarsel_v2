import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadPerformanceModule } from './support/performance-module.mjs';

const format = await loadPerformanceModule('formatters.js');
const { metricOf } = await loadPerformanceModule('transaction-contract.js');
const fixtures = await loadPerformanceModule('fixtures/index.js');

const byId = (id) => fixtures.transactions.find((row) => row.markaz_transaction_id === id);

test('AC-01: 0 and an unavailable value render differently side by side on a card and in a table', () => {
    const zero = fixtures.emptyCompleteSummary;
    const missing = fixtures.incompleteSummary;

    assert.equal(format.formatCount(metricOf(zero.created, 'total_checkouts')), '0');
    assert.equal(format.formatCount(metricOf(missing.created, 'total_checkouts')), 'Belum tersedia');
    assert.equal(format.formatRupiah(metricOf(zero.paid, 'revenue')), 'Rp 0');
    assert.equal(format.formatRupiah(metricOf(missing.paid, 'revenue')), 'Belum tersedia');

    for (const status of ['pending', 'success', 'expired', 'failed', 'canceled']) {
        assert.equal(format.formatRupiah(metricOf(zero.created, `status_breakdown.${status}.nominal`)), 'Rp 0');
        assert.equal(format.formatRupiah(metricOf(missing.created, `status_breakdown.${status}.nominal`)), 'Belum tersedia');
    }
});

test('a ratio with a zero divisor is empty, unlike 0% and an unavailable value', () => {
    const zero = fixtures.emptyCompleteSummary;

    assert.equal(format.formatPercent(metricOf(zero.created, 'conversion_percentage')), 'Belum dapat dihitung');
    assert.equal(format.formatRupiah(metricOf(zero.paid, 'average_order_value')), 'Belum dapat dihitung');
    assert.equal(format.formatPercent({ value: 0, available: true }), '0%');
    assert.equal(format.formatPercent(metricOf(fixtures.incompleteSummary.created, 'conversion_percentage')), 'Belum tersedia');
});

test('a value the comparison snapshot does not carry is unavailable, not a zero divisor', () => {
    const { created } = fixtures.todaySummary.comparison;

    assert.equal(format.formatCount(metricOf(created, 'claim_checkouts')), 'Belum tersedia');
});

test('AC-02: a payment across midnight shows its created and paid date and time in WIB', () => {
    const row = byId('MKZ-A-1001');

    assert.equal(format.formatWibDateTime(row.markaz_created_at), '10 Nov 2026, 23.50 WIB');
    assert.equal(format.formatPaidAt(row.paid_date, row.paid_time), '11 Nov 2026, 00.10 WIB');
    assert.equal(format.formatWibDate(row.markaz_created_at), '10 Nov 2026');
    assert.equal(format.formatWibDate(row.paid_date), '11 Nov 2026');
    assert.equal(format.formatWibDateTime('2026-11-10T16:50:00Z'), '10 Nov 2026, 23.50 WIB');
});

test('AC-02: a conversion change from 50% to 60% reads as percent and as 10 percentage points', () => {
    const today = fixtures.todaySummary;

    assert.equal(format.formatPercent(metricOf(today.comparison.created, 'conversion_percentage')), '50%');
    assert.equal(format.formatPercent(metricOf(today.created, 'conversion_percentage')), '60%');
    assert.equal(format.formatPercentagePoints(10), '+10 poin persentase');
    assert.equal(format.formatPercentagePoints(-10), '-10 poin persentase');
    assert.equal(format.formatPercent(33.33), '33,33%');
});

test('rupiah and counts use Indonesian grouping and whole rupiah', () => {
    assert.equal(format.formatRupiah(360000), 'Rp 360.000');
    assert.equal(format.formatRupiah(metricOf(fixtures.todaySummary.paid, 'revenue')), 'Rp 1.462.500');
    assert.equal(format.formatCount(1250), '1.250');
});

test('AC-03: status labels cover the five statuses, including Failed and Canceled, and an empty status', () => {
    assert.deepEqual([0, 1, 2, 3, 4].map(format.statusLabel), ['Pending', 'Success', 'Expired', 'Failed', 'Canceled']);
    assert.equal(format.statusLabel(null), 'Status belum tersedia');

    for (const row of fixtures.transactions) assert.equal(format.statusLabel(row.report_status), row.report_status_label);
});

test('a provisional conversion is labelled Sementara with the count', () => {
    assert.equal(format.conversionNote(fixtures.todaySummary.created.metrics), 'Sementara · 1 status belum tersedia');
    assert.equal(format.conversionNote(fixtures.emptyCompleteSummary.created.metrics), '');
    assert.equal(format.conversionNote(fixtures.incompleteSummary.created.metrics), '');
});

test('an empty payment date stays readable', () => {
    const pending = byId('MKZ-A-1004');

    assert.equal(format.formatPaidAt(pending.paid_date, pending.paid_time), 'Tanggal bayar belum tersedia');
});

test('a missing comparison is marked, and an available one shows its snapshot time', () => {
    assert.equal(fixtures.missingComparisonSummary.comparison.created.availability, 'unavailable');
    assert.equal(format.COMPARISON_UNAVAILABLE, 'Pembanding belum tersedia');
    assert.equal(format.formatWibDateTime(fixtures.todaySummary.comparison.created.as_of), '10 Nov 2026, 13.00 WIB');
});
