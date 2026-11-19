import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function fixture(http = async () => ({ data: [] })) {
    let factory;
    const context = vm.createContext({});
    const mocks = {
        alpinejs: { default: { data: (_name, value) => { factory = value; } } },
        '../../components/toast': { showToast() {} },
        '../../http': { http, errorMessage: (error, fallback) => error.data?.message ?? fallback, validationErrors: ({ data }) => data.errors },
    };
    const module = new vm.SourceTextModule(await readFile(new URL('../../resources/js/pages/settings/divisions.js', import.meta.url), 'utf8'), { context });
    await module.link((specifier) => new vm.SyntheticModule(Object.keys(mocks[specifier]), function () {
        for (const [key, value] of Object.entries(mocks[specifier])) this.setExport(key, value);
    }, { context }));
    await module.evaluate();
    return Object.assign(factory(true), { $nextTick: (callback) => callback() });
}

const divisions = [
    { id: 1, code_division: 'MKT', name: 'Marketing', accounts_count: 3 },
    { id: 2, code_division: 'SLS', name: 'Sales', accounts_count: 0 },
];

test('search by code or name leaves organization totals intact and can return no matches', async () => {
    const page = await fixture();
    page.divisions = [...divisions];
    page.search = ' mkt ';
    assert.equal(page.filteredDivisions[0].id, 1);
    page.search = 'sales';
    assert.equal(page.filteredDivisions[0].id, 2);
    page.search = 'no match';
    assert.equal(page.filteredDivisions.length, 0);
    assert.equal(page.totalMembers, 3);
    assert.equal(page.emptyDivisions, 1);
    page.search = '';
    assert.equal(page.filteredDivisions.length, 2);
});

test('create and edit validation retain input and focus their own invalid field', async () => {
    const page = await fixture(async () => { throw { status: 422, data: { errors: { code_division: 'Kode sudah digunakan.' } } }; });
    const focused = [];
    page.$root = { querySelector: (selector) => ({ focus: () => focused.push(selector) }) };
    page.openCreate();
    page.draft.code_division = 'MKT';
    await page.submitCreate();
    assert.equal(page.modal, 'create');
    assert.equal(page.draft.code_division, 'MKT');
    assert.equal(focused.at(-1), '#create-division-code_division');
    page.openEdit(divisions[0]);
    await page.submitEdit();
    assert.equal(page.modal, 'edit');
    assert.equal(focused.at(-1), '#edit-division-code_division');
});

test('create and edit use the correct IDs and preserve returned member counts', async () => {
    const calls = [];
    const page = await fixture(async (url, options) => {
        calls.push({ url, ...options });
        return { data: { id: 1, ...options.body, accounts_count: options.method === 'POST' ? 0 : 3 } };
    });
    page.openCreate();
    page.draft = { code_division: ' MKT ', name: ' Marketing ' };
    await page.submitCreate();
    assert.equal(calls[0].url, '/api/divisions');
    assert.equal(calls[0].body.code_division, 'MKT');
    assert.equal(page.modal, null);
    page.openEdit(page.divisions[0]);
    page.form.name = 'Marketing Sales';
    await page.submitEdit();
    assert.equal(calls[1].url, '/api/divisions/1');
    assert.equal(calls[1].method, 'PUT');
    assert.equal(page.divisions[0].accounts_count, 3);
});

test('failed delete retains the correct division and success restores focus after row removal', async () => {
    let fail = true;
    const calls = [];
    const page = await fixture(async (url, options) => {
        calls.push({ url, ...options });
        if (fail) throw { status: 500, data: { message: 'Gagal menghapus divisi.' } };
        return {};
    });
    page.divisions = [...divisions];
    let focused = false;
    page.$refs = { divisionCreateButton: { focus: () => { focused = true; } } };
    page.openDelete(divisions[0]);
    await page.confirmDelete();
    assert.equal(page.modal, 'delete');
    assert.equal(page.deletingDivision.accounts_count, 3);
    assert.equal(page.divisions.length, 2);
    assert.equal(page.formError, 'Gagal menghapus divisi.');
    fail = false;
    await page.confirmDelete();
    assert.equal(calls[1].url, '/api/divisions/1');
    assert.equal(calls[1].method, 'DELETE');
    assert.equal(page.divisions.length, 1);
    assert.equal(page.divisions[0].id, 2);
    assert.equal(page.modal, null);
    assert.equal(focused, true);
});

test('failed loading remains distinct from empty results and supports retry', async () => {
    let fail = true;
    const page = await fixture(async () => {
        if (fail) throw { status: 500 };
        return { data: [] };
    });
    await page.fetchDivisions();
    assert.equal(page.loaded, false);
    assert.equal(page.loadError, 'Gagal memuat daftar divisi.');
    fail = false;
    await page.fetchDivisions();
    assert.equal(page.loaded, true);
    assert.equal(page.loadError, '');
    assert.equal(page.divisions.length, 0);
});
