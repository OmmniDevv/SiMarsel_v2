/**
 * Transaction rows in the read-contract shape (docs/transaction-dates-coverage.md). All data is fictitious.
 *
 * - markaz_created_at   created in Markaz; groups Total, Konversi and the status breakdown
 * - markaz_paid_at      latest `paid_date` from the Markaz API; may be empty
 * - paid_date/paid_time last valid payment date and time (WIB); groups payment metrics, may be empty
 * - synced_at           when SiMarsel pulled the row; never used as an event date
 * - report_status       resolved by the backend: effective_status when set (0 included), else source_status
 *
 * A group member row has markaz_ref_id = the buyer's markaz_transaction_id on the same platform.
 */

const LABELS = { 0: 'Pending', 1: 'Success', 2: 'Expired', 3: 'Failed', 4: 'Canceled' };

function row({ platform, id, customer, created, paid = null, keptPaid = null, source = 1, effective = null, claim = false, ref = null, nominal = 0, adminFee = 0 }) {
    const status = effective ?? source;
    const [paidDate, paidTime] = paid ?? keptPaid ?? [null, null];

    return {
        platform_id: platform,
        markaz_transaction_id: id,
        markaz_ref_id: ref,
        markaz_customer_id: customer,
        markaz_created_at: `${created}+07:00`,
        markaz_paid_at: paid ? `${paidDate}T${paidTime}+07:00` : null,
        paid_date: paidDate,
        paid_time: paidTime,
        synced_at: '2026-11-11T14:05:00+07:00',
        source_status: source,
        effective_status: effective,
        is_claim_coupon: claim,
        product_price: nominal - adminFee,
        voucher_discount: 0,
        admin_fee: adminFee,
        nominal,
        net_nominal: nominal - adminFee,
        report_status: status,
        report_status_label: LABELS[status] ?? 'Status belum tersedia',
    };
}

export const transactions = [
    // Created 10 Nov 23.50, paid 11 Nov 00.10 WIB: Total on the 10th, Omzet on the 11th.
    row({ platform: 1, id: 'MKZ-A-1001', customer: 501, created: '2026-11-10T23:50:00', paid: ['2026-11-11', '00:10:00'], nominal: 135000 }),
    // Two Success payments: nominal 367.000, net 360.000.
    row({ platform: 1, id: 'MKZ-A-1002', customer: 501, created: '2026-11-11T08:15:00', paid: ['2026-11-11', '08:20:00'], nominal: 182000, adminFee: 2000 }),
    row({ platform: 1, id: 'MKZ-A-1003', customer: 502, created: '2026-11-11T08:55:00', paid: ['2026-11-11', '09:05:00'], nominal: 185000, adminFee: 5000 }),
    // Pending without a payment date.
    row({ platform: 1, id: 'MKZ-A-1004', customer: 507, created: '2026-11-11T09:10:00', source: 0, nominal: 150000 }),
    // Markaz says Pending, effective_status says Expired.
    row({ platform: 1, id: 'MKZ-A-1005', customer: 508, created: '2026-11-11T09:20:00', source: 0, effective: 2, nominal: 100000 }),
    row({ platform: 1, id: 'MKZ-A-1006', customer: 509, created: '2026-11-11T09:30:00', source: 3, nominal: 200000 }),
    row({ platform: 1, id: 'MKZ-A-1007', customer: 510, created: '2026-11-11T09:40:00', source: 4, nominal: 50000 }),
    // Both status fields empty: still in Total and the Konversi denominator.
    row({ platform: 1, id: 'MKZ-A-1008', customer: 511, created: '2026-11-11T09:50:00', source: null, nominal: 175000 }),
    // Coupon claim: one checkout, outside Konversi and payment metrics.
    row({ platform: 1, id: 'MKZ-A-1009', customer: 512, created: '2026-11-11T09:55:00', paid: ['2026-11-11', '10:00:00'], claim: true }),
    // effective_status 0 wins over source_status 1.
    row({ platform: 1, id: 'MKZ-A-1010', customer: 513, created: '2026-11-11T10:05:00', source: 1, effective: 0, nominal: 120000 }),
    row({ platform: 1, id: 'MKZ-A-1011', customer: 503, created: '2026-11-11T10:20:00', paid: ['2026-11-11', '10:30:00'], nominal: 100000 }),
    // Latest response has an empty payment date; the last valid date and time are kept.
    row({ platform: 1, id: 'MKZ-A-1012', customer: 501, created: '2026-11-11T11:40:00', keptPaid: ['2026-11-11', '11:45:00'], nominal: 120000 }),
    // Group purchase: one buyer row and two member rows.
    row({ platform: 2, id: 'MKZ-B-2001', customer: 504, created: '2026-11-11T10:50:00', paid: ['2026-11-11', '11:00:00'], nominal: 300000 }),
    row({ platform: 2, id: 'MKZ-B-2002', customer: 514, created: '2026-11-11T10:50:00', paid: ['2026-11-11', '11:00:00'], ref: 'MKZ-B-2001' }),
    row({ platform: 2, id: 'MKZ-B-2003', customer: 515, created: '2026-11-11T10:50:00', paid: ['2026-11-11', '11:00:00'], ref: 'MKZ-B-2001' }),
    // Success that never had a valid payment date: in Konversi, not in payment metrics.
    row({ platform: 2, id: 'MKZ-B-2004', customer: 505, created: '2026-11-11T11:10:00', nominal: 250000 }),
    // Same customer ID as on Platform A: a separate unique buyer on Platform B.
    row({ platform: 2, id: 'MKZ-B-2005', customer: 501, created: '2026-11-11T12:00:00', paid: ['2026-11-11', '12:10:00'], nominal: 200000 }),
    row({ platform: 2, id: 'MKZ-B-2006', customer: 506, created: '2026-11-11T12:20:00', paid: ['2026-11-11', '12:30:00'], nominal: 150000 }),
    row({ platform: 2, id: 'MKZ-B-2007', customer: 516, created: '2026-11-11T12:40:00', paid: ['2026-11-11', '12:50:00'], nominal: 100000, adminFee: 2500 }),
];
