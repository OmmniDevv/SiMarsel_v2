import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';
import { cloneFilter, defaultFilter, filterFromUrl, periodLabel, reportUrl, selectionSummary, withMode } from '../../resources/js/pages/promo/analysis-filter.js';

const NOW = new Date(2026, 10, 3);
const source = (path) => readFile(new URL(`../../resources/js/pages/promo/${path}`, import.meta.url), 'utf8');

function syntheticModule(exports, context) {
    return new vm.SyntheticModule(Object.keys(exports), function () {
        for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
    }, { context });
}

async function analysisFixture() {
    const context = vm.createContext({ Date, URLSearchParams, Object, String, Number, Boolean });
    const filterModule = new vm.SourceTextModule(await source('analysis-filter.js'), { context });
    const module = new vm.SourceTextModule(await source('analysis.js'), { context });
    await module.link((specifier) => {
        if (specifier === './analysis-filter') return filterModule;
        if (specifier === '../../http') return syntheticModule({ errorMessage: (error, fallback) => fallback }, context);
        return syntheticModule({
            fetchAnalysisFilters: async () => ({ data: { platforms: [{ slug: 'jadiasn', name: 'JadiASN' }], payment_methods: ['BCA'] } }),
        }, context);
    });
    await module.evaluate();

    const events = [];
    const analysis = module.namespace.default();
    analysis.$nextTick = (callback) => callback();
    analysis.$dispatch = (name, detail) => events.push({ name, detail });
    analysis.init();
    await new Promise((resolve) => setImmediate(resolve));

    return { analysis, filterEvents: () => events.filter(({ name }) => name === 'promo-analysis-filter') };
}

test('period label names the month, the running month, or the date range', () => {
    assert.equal(periodLabel({ ...defaultFilter(NOW), month: '9' }, NOW), 'September 2026');
    assert.equal(periodLabel(defaultFilter(NOW), NOW), 'November 2026 (sampai hari ini)');
    assert.equal(periodLabel({ ...withMode(defaultFilter(NOW), 'date_range', NOW), date_to: '2026-11-03' }, NOW), '1 November 2026 – 3 November 2026');
});

test('a cloned filter does not follow later edits', () => {
    const filter = defaultFilter(NOW);
    const copy = cloneFilter(filter);

    filter.platforms.push('jadiasn');
    filter.search = 'CPNS';

    assert.deepEqual(copy.platforms, []);
    assert.equal(copy.search, '');
});

test('report links restore the full filter and preserve unrelated page navigation', () => {
    const filter = {
        ...withMode(defaultFilter(NOW), 'date_range', NOW),
        platforms: ['jadiasn', 'cerebrum'], payment_methods: ['BCA'], search: 'HEMAT & BARU',
    };
    const url = reportUrl('http://localhost/promo?tab=analysis&breakdown=codes', filter);
    assert.deepEqual(filterFromUrl(url.search, NOW), filter);
    assert.equal(url.searchParams.get('tab'), 'analysis');
    assert.equal(url.searchParams.get('breakdown'), 'codes');
    const reset = reportUrl(url.href, defaultFilter(NOW));
    assert.equal(reset.searchParams.has('pa_platforms[]'), false);
    assert.equal(reset.searchParams.has('pa_search'), false);
});

test('restored selections never claim all platforms while filter options are still loading', () => {
    assert.equal(selectionSummary([], ['jadiasn'], 'Semua Platform', 'platform'), 'jadiasn');
    assert.equal(selectionSummary([{ value: 'jadiasn', label: 'JadiASN' }], ['jadiasn'], 'Semua Platform', 'platform'), 'JadiASN');
});

test('the analysis loads once on open and only reloads on Terapkan filter', async () => {
    const { analysis, filterEvents } = await analysisFixture();
    assert.equal(filterEvents().length, 1);

    analysis.toggle('platforms', 'jadiasn');
    analysis.toggle('payment_methods', 'BCA');
    analysis.filter.search = 'CPNS';
    analysis.filter.mode = 'date_range';
    analysis.changeMode();

    assert.equal(filterEvents().length, 1);
    assert.equal(analysis.activeFilters.find(({ label }) => label === 'Platform').value, 'Semua Platform');

    analysis.apply();

    assert.equal(filterEvents().length, 2);
    assert.match(filterEvents()[1].detail, /platforms%5B%5D=jadiasn/);
    assert.deepEqual(
        [...analysis.activeFilters].map(({ label, value }) => [label, value]).slice(1),
        [['Platform', 'JadiASN'], ['Metode pembayaran', 'BCA'], ['Kode promo', 'CPNS']],
    );
});

test('Atur ulang restores the default filter and reloads right away', async () => {
    const { analysis, filterEvents } = await analysisFixture();

    analysis.toggle('platforms', 'jadiasn');
    analysis.apply();
    analysis.resetFilter();

    assert.equal(filterEvents().length, 3);
    assert.equal(filterEvents()[2].detail, filterEvents()[0].detail);
    assert.equal(analysis.filter.platforms.length, 0);
});

test('an invalid filter is not sent and opens the advanced filters when the error is there', async () => {
    const { analysis, filterEvents } = await analysisFixture();

    analysis.filter.search = 'X'.repeat(101);
    analysis.apply();

    assert.equal(filterEvents().length, 1);
    assert.ok(analysis.errors.search);
    assert.equal(analysis.advancedOpen, true);
});

async function tabsFixture(search) {
    const replaced = [];
    const context = vm.createContext({
        URL,
        URLSearchParams,
        window: {
            location: { search, href: `http://localhost/promo${search}` },
            history: { replaceState: (state, title, url) => replaced.push(String(url)) },
        },
        document: { getElementById: () => null },
    });
    const module = new vm.SourceTextModule(await source('tabs.js'), { context });
    await module.link(() => {});
    await module.evaluate();

    return { promoTabs: module.namespace.default, replaced };
}

test('main tabs open Analisis by default and keep the selected tab in the URL', async () => {
    const { promoTabs, replaced } = await tabsFixture('');
    const tabs = promoTabs('promo', ['analysis', 'promo-codes', 'master-data'], 'tab');

    assert.equal(tabs.tab, 'analysis');

    tabs.select('master-data');
    assert.equal(tabs.tab, 'master-data');
    assert.match(replaced.at(-1), /tab=master-data/);

    tabs.select('unknown');
    assert.equal(tabs.tab, 'master-data');
});

test('the old Kode Promo link still opens Kelola Kode, and subtabs leave the URL alone', async () => {
    const { promoTabs, replaced } = await tabsFixture('?tab=promo-codes&code=CPNS26');

    assert.equal(promoTabs('promo', ['analysis', 'promo-codes', 'master-data'], 'tab').tab, 'promo-codes');

    const subtabs = promoTabs('promo-master', ['funnel', 'source', 'platform']);
    subtabs.$nextTick = (callback) => callback();
    subtabs.move(-1);

    assert.equal(subtabs.tab, 'platform');
    assert.equal(replaced.length, 0);
});

test('Top 5 remains selectable in the breakdown tabs and can be reopened by its report link', async () => {
    const { promoTabs, replaced } = await tabsFixture('?tab=analysis&breakdown=funnel');
    const keys = ['top-codes', 'funnel', 'source', 'product-type', 'codes'];
    const tabs = promoTabs('promo-breakdown', keys, 'breakdown');
    assert.equal(tabs.tab, 'funnel');
    tabs.select('top-codes');
    assert.equal(tabs.tab, 'top-codes');
    assert.equal(new URL(replaced.at(-1)).searchParams.get('breakdown'), 'top-codes');
    const reopened = await tabsFixture('?tab=analysis&breakdown=top-codes');
    assert.equal(reopened.promoTabs('promo-breakdown', keys, 'breakdown').tab, 'top-codes');
});
