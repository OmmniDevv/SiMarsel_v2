import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

async function fixture({ observer = true } = {}) {
    const observers = [];

    class FakeIntersectionObserver {
        constructor(callback, options) {
            this.callback = callback;
            this.options = options;
            this.targets = [];
            this.disconnected = false;
            observers.push(this);
        }

        observe(target) { this.targets.push(target); }

        disconnect() { this.disconnected = true; }
    }

    const window = observer ? { IntersectionObserver: FakeIntersectionObserver } : {};
    const context = vm.createContext({ window, IntersectionObserver: FakeIntersectionObserver });
    const source = await readFile(new URL('../../resources/js/pages/performance/shell.js', import.meta.url), 'utf8');
    const module = new vm.SourceTextModule(source, { context });
    await module.link(() => { throw new Error('shell.js must not import anything'); });
    await module.evaluate();

    const shell = module.namespace.default();
    shell.$refs = { pageHeader: { id: 'page-header' } };
    shell.init();

    return {
        shell,
        observers,
        scrollPastHeader: (past) => observers[0].callback([{ isIntersecting: !past }]),
    };
}

test('the compact header is hidden at the top and appears once the page header scrolls away', async () => {
    const f = await fixture();
    assert.equal(f.shell.compact, false);
    assert.equal(f.observers.length, 1);
    assert.equal(f.observers[0].targets[0].id, 'page-header');
    assert.match(f.observers[0].options.rootMargin, /^-\d+px 0px 0px 0px$/);

    f.scrollPastHeader(true);
    assert.equal(f.shell.compact, true);

    f.scrollPastHeader(false);
    assert.equal(f.shell.compact, false);
});

test('the Filter panel opens and closes from the compact header', async () => {
    const f = await fixture();
    f.scrollPastHeader(true);

    f.shell.openFilterPanel();
    assert.equal(f.shell.filterPanelOpen, true);

    f.shell.closeFilterPanel();
    assert.equal(f.shell.filterPanelOpen, false);
});

test('cleanup disconnects the observer, and browsers without IntersectionObserver keep the full header only', async () => {
    const f = await fixture();
    f.shell.destroy();
    assert.equal(f.observers[0].disconnected, true);

    const legacy = await fixture({ observer: false });
    assert.equal(legacy.observers.length, 0);
    assert.equal(legacy.shell.compact, false);
});
