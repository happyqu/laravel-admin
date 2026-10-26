const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const jquery = read('resources/assets/jquery/jquery-3.7.1.min.js');
const adminUi = read('resources/assets/laravel-admin/admin-ui.js');

function page() {
    const dom = new JSDOM('<!doctype html><html><body><button id="save">Save</button><div id="modal"></div></body></html>', {
        url: 'http://localhost/admin', runScripts: 'outside-only', virtualConsole: new VirtualConsole(),
    });
    const window = dom.window;
    const calls = [];

    function component(name) {
        const instances = new Map();
        return {
            getOrCreateInstance(element) {
                calls.push([name, 'getOrCreateInstance']);
                if (!instances.has(element)) {
                    instances.set(element, {
                        show() { calls.push([name, 'show']); },
                        hide() { calls.push([name, 'hide']); },
                        toggle() { calls.push([name, 'toggle']); },
                        dispose() { calls.push([name, 'dispose']); },
                    });
                }
                return instances.get(element);
            },
            getInstance(element) {
                return instances.get(element) || null;
            },
        };
    }

    window.bootstrap = {
        Modal: component('Modal'),
        Popover: component('Popover'),
        Tooltip: component('Tooltip'),
        Tab: component('Tab'),
    };

    window.eval(jquery);
    window.eval(adminUi);

    return { window, $: window.jQuery, calls };
}

test('admin-ui exposes BS5 helpers without patching jQuery', () => {
    const { window, $ } = page();

    assert.equal(typeof window.AdminUI.modal, 'function');
    assert.equal(typeof window.AdminUI.popover, 'function');
    assert.equal(typeof window.AdminUI.tooltip, 'function');
    assert.equal(typeof window.AdminUI.tab, 'function');
    assert.equal(typeof window.AdminUI.setButtonLoading, 'function');

    assert.equal($.fn.modal, undefined);
    assert.equal($.fn.popover, undefined);
    assert.equal($.fn.tab, undefined);
});

test('admin-ui maps modal, popover and tab actions to Bootstrap 5 instances', () => {
    const { window, $, calls } = page();
    const modal = window.document.getElementById('modal');
    const button = window.document.getElementById('save');

    window.AdminUI.modal(modal, 'show');
    window.AdminUI.modal(modal, 'hide');
    window.AdminUI.modal(modal, 'toggle');
    window.AdminUI.popover(button, {content: 'x'});
    window.AdminUI.disposePopover(button);
    window.AdminUI.tab(button);

    assert.deepEqual(calls, [
        ['Modal', 'getOrCreateInstance'], ['Modal', 'show'],
        ['Modal', 'getOrCreateInstance'], ['Modal', 'hide'],
        ['Modal', 'getOrCreateInstance'], ['Modal', 'toggle'],
        ['Popover', 'getOrCreateInstance'],
        ['Popover', 'dispose'],
        ['Tab', 'getOrCreateInstance'], ['Tab', 'show'],
    ]);
});

test('admin-ui preserves and restores button content while loading', () => {
    const { window } = page();
    const button = window.document.getElementById('save');
    button.innerHTML = 'Save changes';

    window.AdminUI.setButtonLoading(button, true);
    assert.equal(button.disabled, true);
    assert.equal(button.dataset.adminLoading, '1');
    assert.notEqual(button.innerHTML, 'Save changes');

    window.AdminUI.setButtonLoading(button, true);
    assert.equal(button.dataset.adminLoading, '1');

    window.AdminUI.setButtonLoading(button, false);
    assert.equal(button.disabled, false);
    assert.equal(button.innerHTML, 'Save changes');
    assert.equal(button.dataset.adminLoading, undefined);
});
