import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function fixture(overrides = {}) {
    const context = vm.createContext({});
    const mocks = {
        '../../components/toast': { showToast() {} },
        '../../http': { errorMessage: (error, fallback) => error.data?.message ?? fallback, validationErrors: ({ data }) => data.errors },
        './api': {
            fetchMasters: async () => ({ data: [{ id: 1, name: 'Webinar', promo_codes_count: 3 }, { id: 2, name: 'Aplikasi', promo_codes_count: 0 }] }),
            fetchPlatforms: async () => ({ data: [] }),
            createMaster: async (type, name) => ({ data: { id: 3, name, promo_codes_count: 0 } }),
            updateMaster: async (type, id, name) => ({ data: { id, name, promo_codes_count: 3 } }),
            deleteMaster: async () => ({}),
            ...overrides,
        },
    };
    const module = new vm.SourceTextModule(await readFile(new URL('../../resources/js/pages/promo/table.js', import.meta.url), 'utf8'), { context });
    await module.link((specifier) => new vm.SyntheticModule(Object.keys(mocks[specifier]), function () {
        for (const [key, value] of Object.entries(mocks[specifier])) this.setExport(key, value);
    }, { context }));
    await module.evaluate();
    return module.namespace;
}

for (const [type, label] of [['funnel', 'Funnel'], ['source', 'Source']]) {
    test(`${label}: search scopes results without changing the full list or associated code counts`, async () => {
        const { promoMasterTable } = await fixture();
        const table = promoMasterTable(type, label);
        await table.fetchItems();
        table.search = ' WEB ';
        assert.equal(table.filteredItems.length, 1);
        assert.equal(table.filteredItems[0].promo_codes_count, 3);
        assert.equal(table.items.length, 2);
        table.search = 'tidak ada';
        assert.equal(table.filteredItems.length, 0);
        table.search = '';
        assert.equal(table.filteredItems.length, 2);
        assert.equal(table.filteredItems[1].promo_codes_count, 0);
    });

    test(`${label}: edit keeps associations and a rejected deletion stays in confirmation`, async () => {
        const { promoMasterTable } = await fixture({ deleteMaster: async () => { throw { status: 409, data: { message: 'Masih dipakai.' } }; } });
        const table = promoMasterTable(type, label);
        await table.fetchItems();
        table.openCreate();
        table.name = '  Baru  ';
        await table.submit();
        assert.equal(table.items.at(-1).name, 'Baru');
        table.openEdit(table.items[0]);
        table.name = 'Webinar Baru';
        await table.submit();
        assert.equal(table.items[0].promo_codes_count, 3);
        table.openDelete(table.items[0]);
        await table.confirmDelete();
        assert.equal(table.modal, 'delete');
        assert.equal(table.formError, 'Masih dipakai.');
        assert.equal(table.items.some((item) => item.id === 1), true);
        assert.equal(table.items.find((item) => item.id === 1).promo_codes_count, 3);
    });
}

test('Platform: empty and failed global lists have no mutation methods', async () => {
    let fail = false;
    const { promoPlatformTable } = await fixture({ fetchPlatforms: async () => {
        if (fail) throw { status: 500 };
        return { data: [] };
    } });
    const table = promoPlatformTable();
    await table.fetchPlatforms();
    assert.equal(table.platforms.length, 0);
    assert.equal(table.loading, false);
    fail = true;
    await table.fetchPlatforms();
    assert.equal(table.loadError, 'Gagal memuat daftar platform.');
    assert.equal(table.openCreate, undefined);
    assert.equal(table.openEdit, undefined);
    assert.equal(table.openDelete, undefined);
});
