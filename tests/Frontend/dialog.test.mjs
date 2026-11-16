import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createDialogManager } from '../../resources/js/components/dialog.js';

function fixture() {
    const listeners = new Map();
    const doc = {
        addEventListener: (name, listener) => listeners.set(name, listener),
        removeEventListener: (name, listener) => { if (listeners.get(name) === listener) listeners.delete(name); },
    };
    function node(parent = null, { focusable = false, initial = false, disabled = false, shown = true } = {}) {
        const element = {
            parentElement: parent, children: [], inert: false, style: {}, disabled,
            focusable, initial, shown,
            get isConnected() { return this === doc.body || Boolean(this.parentElement?.isConnected); },
            contains(other) { return other === this || this.children.some((child) => child.contains(other)); },
            closest() { return this.inert ? this : this.parentElement?.closest() ?? null; },
            getClientRects() { return this.shown ? [{}] : []; },
            querySelectorAll() { return this.children.flatMap((child) => [...(child.focusable ? [child] : []), ...child.querySelectorAll()]); },
            querySelector() { return this.querySelectorAll().find((child) => child.initial) ?? null; },
            focus() { doc.activeElement = this; listeners.get('focusin')?.({ target: this }); },
        };
        parent?.children.push(element);
        return element;
    }
    doc.body = node();
    const page = node(doc.body);
    const opener = node(page, { focusable: true });
    const dialog = node(page);
    const first = node(dialog, { focusable: true });
    const last = node(dialog, { focusable: true });
    opener.focus();
    const manager = createDialogManager(doc);
    const tab = (shiftKey = false) => {
        const event = { key: 'Tab', shiftKey, prevented: false, preventDefault() { this.prevented = true; } };
        listeners.get('keydown')?.(event);
        return event;
    };
    return { doc, node, page, opener, dialog, first, last, manager, tab, listeners };
}

test('dialog traps both Tab directions, excludes disabled controls and restores the opener', () => {
    const f = fixture();
    f.node(f.dialog, { focusable: true, disabled: true });
    f.node(f.dialog, { focusable: true, shown: false });
    f.manager.open(f.dialog);
    assert.equal(f.doc.activeElement, f.first);
    assert.equal(f.opener.inert, true);
    assert.equal(f.doc.body.style.overflow, 'hidden');
    assert.equal(f.tab(true).prevented, true);
    assert.equal(f.doc.activeElement, f.last);
    assert.equal(f.tab().prevented, true);
    assert.equal(f.doc.activeElement, f.first);
    f.opener.focus();
    assert.equal(f.doc.activeElement, f.first);
    f.manager.close(f.dialog);
    assert.equal(f.opener.inert, false);
    assert.equal(f.doc.activeElement, f.opener);
    assert.equal(f.listeners.size, 0);
});

test('stacked confirmations isolate the top dialog and restore focus through both layers', () => {
    const f = fixture();
    f.manager.open(f.dialog);
    f.last.focus();
    const confirm = f.node(f.page);
    const proceed = f.node(confirm, { focusable: true });
    const cancel = f.node(confirm, { focusable: true, initial: true });
    f.manager.open(confirm);
    assert.equal(f.doc.activeElement, cancel);
    assert.equal(f.dialog.inert, true);
    assert.equal(proceed.inert, false);
    f.manager.close(confirm);
    assert.equal(f.dialog.inert, false);
    assert.equal(f.doc.activeElement, f.last);
    f.manager.close(f.dialog);
    assert.equal(f.doc.activeElement, f.opener);
});

test('closing a lower dialog first preserves the original trigger and existing background state', () => {
    const f = fixture();
    const hidden = f.node(f.doc.body);
    hidden.inert = true;
    f.doc.body.style.overflow = 'auto';
    f.manager.open(f.dialog);
    const confirm = f.node(f.page);
    f.node(confirm, { focusable: true });
    f.manager.open(confirm);
    f.manager.close(f.dialog);
    f.manager.close(confirm);
    assert.equal(f.doc.activeElement, f.opener);
    assert.equal(hidden.inert, true);
    assert.equal(f.doc.body.style.overflow, 'auto');
});

test('native disclosure summaries remain reachable at the end of the dialog tab order', () => {
    const f = fixture();
    const summary = f.node(f.dialog);
    // Model the browser selector: summary is natively focusable without an explicit tabindex.
    f.dialog.querySelectorAll = (selector = '') => [f.first, f.last, ...(selector.includes('summary') ? [summary] : [])];
    f.manager.open(f.dialog);
    f.last.focus();
    assert.equal(f.tab().prevented, false, 'Tab must reach the disclosure after the last button');
    summary.focus();
    assert.equal(f.tab().prevented, true);
    assert.equal(f.doc.activeElement, f.first);
    assert.equal(f.tab(true).prevented, true);
    assert.equal(f.doc.activeElement, summary);
});
