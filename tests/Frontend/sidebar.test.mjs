import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function fixture(desktop = false) {
    const listeners = new Set();
    let logoutInitializations = 0;
    const media = {
        matches: desktop,
        addEventListener: (name, listener) => listeners.add(listener),
        removeEventListener: (name, listener) => listeners.delete(listener),
    };
    const context = vm.createContext({ window: { matchMedia: () => media } });
    const module = new vm.SourceTextModule(await readFile(new URL('../../resources/js/components/sidebar.js', import.meta.url), 'utf8'), { context });
    const mocks = { './toast': { showToast() {} }, './logout': { initLogout: () => { logoutInitializations++; } } };
    await module.link((specifier) => new vm.SyntheticModule(Object.keys(mocks[specifier]), function () {
        for (const [key, value] of Object.entries(mocks[specifier])) this.setExport(key, value);
    }, { context }));
    await module.evaluate();
    const sidebar = module.namespace.default();
    sidebar.init();
    return {
        sidebar,
        listeners,
        get logoutInitializations() { return logoutInitializations; },
        resize(matches) { media.matches = matches; for (const listener of listeners) listener({ matches }); },
    };
}

test('mobile starts closed and desktop initialization never opens a modal drawer', async () => {
    const mobile = await fixture(false);
    assert.equal(mobile.sidebar.desktop, false);
    assert.equal(mobile.sidebar.open, false);
    const desktop = await fixture(true);
    assert.equal(desktop.sidebar.desktop, true);
    assert.equal(desktop.sidebar.open, false);
    assert.equal(desktop.logoutInitializations, 1);
});

test('changing viewport closes the modal drawer and keeps mobile closed on return', async () => {
    const f = await fixture();
    f.sidebar.open = true;
    f.resize(true);
    assert.equal(f.sidebar.desktop, true);
    assert.equal(f.sidebar.open, false);
    f.resize(false);
    assert.equal(f.sidebar.desktop, false);
    assert.equal(f.sidebar.open, false);
});

test('component cleanup removes the responsive listener', async () => {
    const f = await fixture();
    assert.equal(f.listeners.size, 1);
    f.sidebar.destroy();
    assert.equal(f.listeners.size, 0);
});

test('the selected navigation token provides AA contrast for white small text', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const hex = css.match(/--color-primary-hover:\s*#([0-9a-f]{6})/i)[1];
    const linear = [0, 2, 4].map((start) => {
        const value = parseInt(hex.slice(start, start + 2), 16) / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    const luminance = linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
    assert.ok(1.05 / (luminance + 0.05) >= 4.5);
});
