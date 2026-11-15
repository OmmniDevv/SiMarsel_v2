import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { analysisCopyModule } from './support/analysis-copy-module.mjs';

const source = (path) => readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

function syntheticModule(exports, context) {
    return new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
    }, { context });
}

const httpMock = {
    errorMessage: (error, fallback) => (error?.status >= 500 ? fallback : error?.data?.message ?? fallback),
    validationErrors: ({ data }) => Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])),
};

async function loadModule(path, mocks = {}, globals = {}) {
    const context = vm.createContext({ Intl, Date, Number, Math, Boolean, Object, String, URLSearchParams, Set, Promise, Blob, ...globals });
    const format = new vm.SourceTextModule(await source('format.js'), { context });
    const copy = path.endsWith('analysis-code-usage.js') ? await analysisCopyModule(context) : null;
    const module = new vm.SourceTextModule(await source(path), { context });

    await module.link((specifier) => {
        if (specifier === '../../format') return format;
        if (specifier === './analysis-copy') return copy;
        if (!mocks[specifier]) throw new Error(`Unexpected import ${specifier}`);
        return syntheticModule(mocks[specifier], context);
    });
    await module.evaluate();

    return module.namespace;
}

async function copyModule(globals = {}) {
    const copy = await analysisCopyModule(vm.createContext({ String, Object, ...globals }));
    await copy.evaluate();

    return copy.namespace;
}

/**
 * Objects made inside a vm context have another Object prototype, so compare them as plain data.
 */
const same = (actual, expected, message) => assert.deepEqual(JSON.parse(JSON.stringify(actual ?? null)), expected, message);

/**
 * A browser clipboard (secure context) that records what is written. `onWrite` can throw to simulate a denied clipboard.
 */
function fakeClipboard(copies, onWrite = async () => {}) {
    class ClipboardItem {
        constructor(items) {
            this.items = items;
        }
    }
    const record = async (text) => {
        await onWrite(text);
        copies.push(text);
    };

    return {
        ClipboardItem,
        window: { isSecureContext: true, ClipboardItem },
        navigator: {
            clipboard: {
                writeText: record,
                write: async ([item]) => record(await (await item.items['text/plain']).text()),
            },
        },
    };
}

/**
 * The export control with every side effect recorded. `answers` plays the components:
 * event name => function(detail) that fills the detail like the real listener does.
 */
async function actionsBar({ exportAnalysis, answers = {}, scope = {} }) {
    const calls = { exports: [], downloads: [], toasts: [], events: [] };
    const { default: promoAnalysisActions } = await loadModule('pages/promo/analysis-actions.js', {
        '../../export': {
            download: (blob, fileName) => calls.downloads.push({ blob, fileName }),
        },
        '../../http': httpMock,
        '../../components/toast': { showToast: (toast) => calls.toasts.push(toast) },
        './api': {
            exportAnalysis: async (query) => {
                calls.exports.push(query);
                return exportAnalysis(query);
            },
        },
    });
    const bar = Object.assign(promoAnalysisActions(), { query: 'year=2026&month=9', filterInvalid: false, ...scope });
    bar.$dispatch = (name, detail) => {
        calls.events.push({ name, detail });
        answers[name]?.(detail);
    };

    return { bar, calls };
}

test('decimals use the decimal separator of the browser language so spreadsheets read them as numbers', async () => {
    const { toTsv } = (await (async () => {
        const context = vm.createContext({ Intl, Number, String, navigator: { language: 'id-ID' } });
        const module = new vm.SourceTextModule(await source('export.js'), { context });
        await module.link(() => {});
        await module.evaluate();
        return module.namespace;
    })());
    const columns = [{ header: 'Kontribusi (%)', value: (row) => row.share }, { header: 'Revenue', value: (row) => row.revenue }];

    assert.equal(toTsv(columns, [{ share: 46.67, revenue: 700000 }]), 'Kontribusi (%)\tRevenue\n46,67\t700000');
    assert.equal(toTsv(columns, [{ share: 46.67, revenue: 700000 }], { decimal: '.' }), 'Kontribusi (%)\tRevenue\n46.67\t700000');
});

test('Export Excel sends the active filter with the trend period and code sort, then downloads the backend file', async () => {
    const blob = { size: 10 };
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({ blob, fileName: 'promo-analisis_2026-09-01_2026-09-30.xlsx' }),
        scope: { query: 'platforms[]=jadiasn&payment_methods[]=BCA&year=2026&month=9&search=cpns' },
        answers: {
            'promo-analysis-export-params': ({ params }) => {
                params.set('period', 'weekly');
                params.set('sort', 'usages');
                params.set('direction', 'desc');
            },
        },
    });

    const pending = bar.exportExcel();
    assert.equal(bar.exporting, true);
    assert.equal(bar.status.type, 'loading');
    await pending;

    same(calls.exports, ['platforms%5B%5D=jadiasn&payment_methods%5B%5D=BCA&year=2026&month=9&search=cpns&period=weekly&sort=usages&direction=desc']);
    same(calls.downloads, [{ blob, fileName: 'promo-analisis_2026-09-01_2026-09-30.xlsx' }]);
    same(calls.toasts, [{ title: 'Ekspor Berhasil', text: 'File promo-analisis_2026-09-01_2026-09-30.xlsx berhasil diunduh.' }]);
    assert.equal(bar.exporting, false);
    assert.equal(bar.status, null);
});

test('Export Excel shows the backend message on failure and hands filter errors back to the fields', async () => {
    const tooLarge = 'Data terlalu besar untuk diekspor: sheet Detail Penggunaan berisi 1048576 baris, maksimal 1048575 baris. Persempit periode atau filter, lalu coba lagi.';
    let failure = { status: 422, data: { message: tooLarge } };
    const { bar, calls } = await actionsBar({ exportAnalysis: async () => { throw failure; } });

    await bar.exportExcel();
    same(bar.status, { type: 'error', message: tooLarge });
    assert.equal(calls.downloads.length, 0);

    failure = { status: 422, data: { message: 'Filter tidak valid.', errors: { 'platforms.0': ['Platform tidak ditemukan.'] } } };
    await bar.exportExcel();
    same(calls.events.find(({ name }) => name === 'promo-analysis-errors').detail, { 'platforms.0': 'Platform tidak ditemukan.' });

    failure = { status: 500, data: { message: 'SQLSTATE[42S02] internal detail' } };
    await bar.exportExcel();
    same(bar.status, { type: 'error', message: 'Gagal mengekspor data analisis promo. Silakan coba lagi.' });
    assert.equal(bar.exporting, false);

    bar.clearStatus();
    assert.equal(bar.status, null, 'a new filter clears the old message');
});

test('Export uses the applied filter while the open form contains invalid unapplied edits', async () => {
    const { bar, calls } = await actionsBar({
        exportAnalysis: async () => ({ blob: {}, fileName: 'promo.xlsx' }),
        scope: {
            query: 'year=2026&month=9&platforms[]=jadiasn&payment_methods[]=BCA',
            filtersOpen: true,
            filterInvalid: true,
            filter: { year: '', month: '13', platforms: ['unapplied'], payment_methods: [] },
        },
    });

    assert.equal(bar.actionsDisabled, false);
    await bar.exportExcel();
    same(calls.exports, ['year=2026&month=9&platforms%5B%5D=jadiasn&payment_methods%5B%5D=BCA']);
    assert.equal(calls.downloads.length, 1);
});

test('Export does nothing before a filter has been applied', async () => {
    const { bar, calls } = await actionsBar({ exportAnalysis: async () => ({}), scope: { query: null } });

    assert.equal(bar.actionsDisabled, true);
    await bar.exportExcel();
    assert.equal(calls.exports.length + calls.events.length, 0);
});

test('an export in progress cannot be submitted twice and keeps its loading status', async () => {
    let resolveExport;
    const { bar, calls } = await actionsBar({ exportAnalysis: () => new Promise((resolve) => { resolveExport = resolve; }) });
    const pending = bar.exportExcel();
    bar.clearStatus();
    await bar.exportExcel();
    assert.equal(calls.exports.length, 1);
    assert.equal(bar.status.type, 'loading');
    resolveExport({ blob: {}, fileName: null });
    await pending;
    assert.equal(bar.exporting, false);
    assert.equal(calls.downloads[0].fileName, 'promo-analisis.xlsx');
});

test('usage detail clipboard helpers preserve row formatting and block unavailable snapshots', async () => {
    const { USAGE_COLUMNS, copyAllSnapshot, copyProblem } = await copyModule();
    const rows = [{ paid_date: '2026-09-10', platform: { name: 'Jadi\tASN\nApp' }, usages: 2, revenue: 700000 }];
    const loadRows = async () => rows;
    const snapshot = copyAllSnapshot({ loading: false, loadError: '', isEmpty: false }, USAGE_COLUMNS, 1, loadRows);
    assert.equal(await snapshot.text(), 'Tanggal\tPlatform/Aplikasi\tJumlah Penggunaan\tRevenue\n2026-09-10\tJadi ASN App\t2\t700000');
    same(copyAllSnapshot({ loading: true }, USAGE_COLUMNS, 1, loadRows), { status: 'loading' });
    same(copyAllSnapshot({ loadError: 'Gagal' }, USAGE_COLUMNS, 1, loadRows), { status: 'error' });
    same(copyAllSnapshot({ isEmpty: true }, USAGE_COLUMNS, 0, loadRows), { status: 'empty' });
    assert.match(copyProblem({ status: 'loading' }, 'detail penggunaan'), /masih dimuat/);
    assert.match(copyProblem({ status: 'error' }, 'detail penggunaan'), /gagal dimuat/);
});

test('usage detail clipboard failures show a safe recovery message without writing data', async () => {
    const copies = [];
    const { runCopy } = await copyModule(fakeClipboard(copies, async () => { throw new Error('denied'); }));
    const failedClipboard = await runCopy({ status: 'ready', rows: 1, text: 'Tanggal\n2026-09-10' }, 'detail penggunaan');
    assert.match(failedClipboard.error, /Gagal menyalin ke clipboard/);
    const failedLoad = await runCopy({ status: 'ready', rows: 1, text: () => Promise.reject({ status: 500, data: { message: 'SQLSTATE secret' } }) }, 'detail penggunaan');
    assert.equal(failedLoad.error, 'Gagal mengambil data detail penggunaan. Silakan coba lagi.');
    assert.equal(copies.length, 0);
});

test('download file name is read from Content-Disposition', async () => {
    const context = vm.createContext({ decodeURIComponent, document: { querySelector: () => null } });
    const http = new vm.SourceTextModule(await source('http.js'), { context });
    await http.link(() => {});
    await http.evaluate();
    const { dispositionFileName } = http.namespace;

    assert.equal(dispositionFileName('attachment; filename=promo-analisis_2026-09-01_2026-09-30.xlsx'), 'promo-analisis_2026-09-01_2026-09-30.xlsx');
    assert.equal(dispositionFileName('attachment; filename="promo analisis.xlsx"'), 'promo analisis.xlsx');
    assert.equal(dispositionFileName("attachment; filename=x.xlsx; filename*=UTF-8''promo%20analisis.xlsx"), 'promo analisis.xlsx');
    assert.equal(dispositionFileName(null), null);
});

test('usage detail copies every transaction of the code for the active filter', async () => {
    const queries = [];
    const toasts = [];
    const copies = [];
    const day = (date, trx) => ({ date, transactions: [{ trx_id: trx, paid_date: date, platform: { id: 2, name: 'JADIASN' }, usages: 1, revenue: 250000 }] });
    const { default: promoAnalysisCodeUsage } = await loadModule('pages/promo/analysis-code-usage.js', {
        '../../http': httpMock,
        '../../components/toast': { showToast: (toast) => toasts.push(toast) },
        './api': {
            fetchAnalysisCodeUsages: async (query) => {
                queries.push(query);
                const page = Number(new URLSearchParams(query).get('page'));

                return { data: [page === 1 ? day('2026-09-10', 'a') : day('2026-09-09', 'b')], meta: { current_page: page, last_page: 2, total: 2 }, totals: { usages: 2, revenue: 500000 }, is_empty: false };
            },
        },
    }, fakeClipboard(copies));
    const detail = promoAnalysisCodeUsage();

    await detail.show({ row: { code: 'RESELLER01', platform: { name: 'JADIASN' }, detail: { platform_id: 2, code: 'RESELLER01', group: 'g1' } }, filterQuery: 'year=2026&month=9' });
    queries.length = 0;
    await detail.copy();

    same(queries, [
        'year=2026&month=9&platform_id=2&code=RESELLER01&group=g1&page=1&per_page=100',
        'year=2026&month=9&platform_id=2&code=RESELLER01&group=g1&page=2&per_page=100',
    ]);
    same(copies, ['Tanggal\tPlatform/Aplikasi\tJumlah Penggunaan\tRevenue\n2026-09-10\tJADIASN\t1\t250000\n2026-09-09\tJADIASN\t1\t250000']);
    same(toasts, [{ title: 'Data Disalin', text: '2 baris detail penggunaan RESELLER01 berhasil disalin.' }]);
    assert.equal(detail.copyError, '');
});

test('usage detail copies nothing and says so when the code has no usage for the filter', async () => {
    const copies = [];
    const { default: promoAnalysisCodeUsage } = await loadModule('pages/promo/analysis-code-usage.js', {
        '../../http': httpMock,
        '../../components/toast': { showToast: () => {} },
        './api': { fetchAnalysisCodeUsages: async () => ({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, totals: null, is_empty: true }) },
    }, fakeClipboard(copies));
    const detail = promoAnalysisCodeUsage();

    await detail.show({ row: { code: 'X1', platform: { name: 'JADIASN' }, detail: { platform_id: 2, code: 'X1' } }, filterQuery: '' });
    await detail.copy();

    assert.equal(copies.length, 0);
    assert.equal(detail.copyError, 'Tidak ada data detail penggunaan X1 untuk filter yang dipilih, sehingga tidak ada yang disalin.');
});
