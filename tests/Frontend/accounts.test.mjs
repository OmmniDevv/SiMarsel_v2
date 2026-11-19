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
    const module = new vm.SourceTextModule(await readFile(new URL('../../resources/js/pages/settings/accounts.js', import.meta.url), 'utf8'), { context });
    await module.link((specifier) => new vm.SyntheticModule(Object.keys(mocks[specifier]), function () {
        for (const [key, value] of Object.entries(mocks[specifier])) this.setExport(key, value);
    }, { context }));
    await module.evaluate();
    return Object.assign(factory([{ id: 1, code_role: 'MGR' }, { id: 2, code_role: 'EMP' }], [], true), {
        $store: { session: { user: { id: 1 } } },
        $nextTick: (callback) => callback(),
    });
}

const users = [
    { id: 1, fullname: 'Manager', username: 'manager', role: { code_role: 'MGR' }, division: null },
    { id: 2, fullname: 'Staff', username: 'staff', role: { code_role: 'EMP' }, division: { code_division: 'SLS', name: 'Sales' } },
];

test('unassigned and named division filters combine with search without changing global totals', async () => {
    const page = await fixture();
    page.users = users;
    page.filterDivision = '__unassigned__';
    assert.equal(page.filteredUsers.length, 1);
    assert.equal(page.filteredUsers[0].id, 1);
    page.search = 'staff';
    assert.equal(page.filteredUsers.length, 0);
    page.filterDivision = 'SLS';
    page.search = 'sales';
    assert.equal(page.filteredUsers[0].id, 2);
    assert.equal(page.accountSummary.total, 2);
    page.resetFilters();
    assert.equal(page.filteredUsers.length, 2);
});

test('server validation focuses the correct create/edit field and retains entered data', async () => {
    const page = await fixture(async () => { throw { status: 422, data: { errors: { username: 'Username sudah digunakan.' } } }; });
    const focused = [];
    page.$root = { querySelector: (selector) => ({ focus: () => focused.push(selector) }) };
    page.openCreate();
    page.draft.username = 'existing';
    await page.submitCreate();
    assert.equal(page.modal, 'create');
    assert.equal(page.draft.username, 'existing');
    assert.equal(focused.at(-1), '#create-username');
    page.openEdit(users[1]);
    await page.submitEdit();
    assert.equal(page.modal, 'edit');
    assert.equal(focused.at(-1), '#edit-username');
});

test('editing self omits role and unchanged password; rejected deletion preserves confirmation', async () => {
    const page = await fixture(async () => { throw { status: 409, data: { message: 'Akun memiliki riwayat tugas.' } }; });
    page.openEdit(users[0]);
    const payload = page.payload(page.form, { isEdit: true });
    assert.equal('role_id' in payload, false);
    assert.equal('password' in payload, false);
    page.closeModal();
    page.openDelete(users[0]);
    assert.equal(page.modal, null);
    page.openDelete(users[1]);
    await page.confirmDelete();
    assert.equal(page.modal, 'delete');
    assert.equal(page.deletingUser.id, 2);
    assert.equal(page.formError, 'Akun memiliki riwayat tugas.');
});

test('failed loading remains distinct from a successfully loaded empty list and can retry', async () => {
    let fail = true;
    const page = await fixture(async () => {
        if (fail) throw { status: 500 };
        return { data: [] };
    });
    await page.fetchUsers();
    assert.equal(page.loaded, false);
    assert.equal(page.loadError, 'Gagal memuat daftar akun.');
    fail = false;
    await page.fetchUsers();
    assert.equal(page.loaded, true);
    assert.equal(page.loadError, '');
    assert.equal(page.accountSummary.total, 0);
});
