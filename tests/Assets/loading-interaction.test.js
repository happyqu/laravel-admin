const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function page(t) {
    const w = new JSDOM('<style>' + read('resources/assets/laravel-admin/laravel-admin.css') + '</style>' +
        '<button id="save" type="button">Save</button><a id="edit" href="#edit">Edit</a><form id="form"></form>' +
        '<div class="swal2-container"><button id="confirm" type="button">Confirm</button></div>', {
        url: 'http://localhost/admin/auth/menu', runScripts: 'outside-only', virtualConsole: new VirtualConsole(),
    }).window;
    t.after(() => w.close());
    w.eval(read('resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'));
    w.$.fn.editable = { defaults: {} };
    w.$.fn.popover = function () { return this; };
    w.LA = function () {};
    w.toastr = { error() {}, success() {} };
    w.swal = function () {};
    w.NProgress = { configure() {}, start() {}, done() {} };
    w.eval(read('resources/assets/laravel-admin/laravel-admin.js'));
    return w;
}

test('loading blocks repeated clicks immediately and restores page actions after completion', t => {
    const w = page(t);
    let saves = 0, edits = 0, finish;
    w.$('#save').on('click', () => { saves++; finish = w.$.admin.startLoading(); });
    w.$('#edit').on('click', () => { edits++; });
    w.document.getElementById('save').click();
    assert.equal(saves, 1);
    assert.equal(w.document.querySelector('.admin-ajax-loading'), null, 'indicator is still delayed');
    w.document.getElementById('save').click();
    w.document.getElementById('edit').click();
    assert.equal(saves, 1);
    assert.equal(edits, 0);
    finish();
    w.document.getElementById('edit').click();
    assert.equal(edits, 1);
});

test('loading intercepts keyboard, pointer and submit events before page handlers', t => {
    const w = page(t);
    const finish = w.$.admin.startLoading();
    for (const type of ['keydown', 'mousedown', 'pointerdown', 'touchstart', 'dblclick', 'auxclick', 'submit']) {
        const element = w.document.getElementById(type === 'submit' ? 'form' : 'save');
        let handled = 0;
        element.addEventListener(type, () => { handled++; });
        const event = new w.Event(type, { bubbles: true, cancelable: true });
        assert.equal(element.dispatchEvent(event), false, type + ' must be cancelled');
        assert.equal(handled, 0, type + ' must not reach button/form handlers');
    }
    finish();
});

test('confirmation dialogs remain interactive while page actions are blocked', async t => {
    const w = page(t);
    let confirmations = 0, saves = 0;
    w.$('#confirm').on('click', () => { confirmations++; });
    w.$('#save').on('click', () => { saves++; });
    const finish = w.$.admin.startLoading();
    await new Promise(resolve => setTimeout(resolve, 200));
    const overlay = w.document.querySelector('.admin-ajax-loading');
    assert.ok(overlay);
    const style = w.getComputedStyle(overlay);
    assert.equal(style.position, 'fixed');
    for (const edge of ['top', 'right', 'bottom', 'left']) assert.equal(style[edge], '0px');
    assert.equal(style.pointerEvents, 'auto');
    assert.ok(overlay.querySelector('.admin-ajax-loading-message'));
    w.document.body.classList.add('swal2-shown');
    assert.equal(w.getComputedStyle(overlay).display, 'none');
    w.document.getElementById('confirm').click();
    w.document.getElementById('save').click();
    assert.equal(confirmations, 1);
    assert.equal(saves, 0);
    finish();
    assert.equal(w.document.querySelector('.admin-ajax-loading'), null);
});

test('failed foreground requests unlock the page and silent requests do not lock it', t => {
    const w = page(t);
    let saves = 0;
    w.$('#save').on('click', () => { saves++; });
    const original = w.$.ajax;
    w.$.ajaxTransport('+*', () => ({ send() {}, abort() {} }));
    const foreground = original({ url: '/pending' });
    w.document.getElementById('save').click();
    assert.equal(saves, 0);
    foreground.abort();
    w.document.getElementById('save').click();
    assert.equal(saves, 1);
    const silent = original({ url: '/poll', adminLoading: false });
    w.document.getElementById('save').click();
    assert.equal(saves, 2);
    silent.abort();
});
