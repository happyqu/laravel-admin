const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../resources/assets/laravel-admin/laravel-admin.js'), 'utf8');

function page() {
    let prefilter;
    let now = 0;
    let nextTimer = 0;
    const timers = new Map();
    const visible = new Set();
    const progress = { starts: 0, finishes: 0 };
    const chain = new Proxy({}, { get: () => () => chain });
    const $ = target => {
        if (typeof target !== 'string') return chain;
        if (target === 'meta[name="admin-loading-text"]') {
            return { attr: () => '加载中...' };
        }
        if (target.startsWith('<div class="admin-ajax-loading"')) {
            const indicator = {
                label: '',
                find() { return { text(value) { indicator.label = value; } }; },
                appendTo() { visible.add(indicator); },
                remove() { visible.delete(indicator); },
            };
            return indicator;
        }
        return chain;
    };
    $.fn = { editable: { defaults: {} } };
    $.ajaxPrefilter = callback => { prefilter = callback; };
    vm.runInNewContext(source, {
        $, jQuery: $, LA: function () {}, document: {}, window: {}, toastr: {}, swal() {},
        NProgress: {
            configure(options) { progress.parent = options.parent; },
            start() { progress.starts++; },
            done() { progress.finishes++; },
        },
        setTimeout(callback, delay) {
            const id = ++nextTimer;
            timers.set(id, { callback, at: now + delay });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
    });

    function request(settings = {}, context = {}) {
        let state = 'pending';
        const callbacks = [];
        const xhr = {
            state: () => state,
            always(callback) { callbacks.push(callback); return xhr; },
            complete(result = 'resolved') {
                if (state !== 'pending') return;
                state = result;
                callbacks.forEach(callback => callback());
            },
            abort() { xhr.complete('rejected'); },
        };
        const options = { ...settings };
        prefilter(options, settings, xhr);
        if (options.beforeSend && options.beforeSend.call(context, xhr, options) === false) xhr.abort();
        return xhr;
    }

    function advance(milliseconds) {
        now += milliseconds;
        for (const [id, timer] of timers) {
            if (timer.at <= now) {
                timers.delete(id);
                timer.callback();
            }
        }
    }

    return { request, advance, visible, progress };
}

test('slow requests display a localized indicator and finish after success', () => {
    const app = page();
    const xhr = app.request();
    assert.equal(app.progress.parent, 'body');
    assert.equal(app.progress.starts, 1);
    app.advance(180);
    assert.equal(app.visible.size, 1);
    assert.equal([...app.visible][0].label, '加载中...');
    xhr.complete();
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.finishes, 1);
});

test('fast requests cancel the delayed indicator', () => {
    const app = page();
    app.request().complete();
    app.advance(500);
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.finishes, 1);
});

test('concurrent requests keep loading until every foreground request ends', () => {
    const app = page();
    const first = app.request();
    const second = app.request();
    app.advance(180);
    first.complete();
    assert.equal(app.visible.size, 1);
    assert.equal(app.progress.starts, 1);
    assert.equal(app.progress.finishes, 0);
    second.complete();
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.finishes, 1);
});

test('failures and aborted requests both remove loading', () => {
    for (const action of ['error', 'abort']) {
        const app = page();
        const xhr = app.request();
        app.advance(180);
        if (action === 'abort') xhr.abort();
        else xhr.complete('rejected');
        assert.equal(app.visible.size, 0);
        assert.equal(app.progress.finishes, 1);
    }
});

test('silent background polling does not start or extend foreground loading', () => {
    const app = page();
    const polling = app.request({ adminLoading: false });
    app.advance(500);
    assert.equal(app.progress.starts, 0);
    assert.equal(app.visible.size, 0);
    const foreground = app.request();
    app.advance(180);
    polling.complete();
    assert.equal(app.visible.size, 1);
    foreground.complete();
    assert.equal(app.visible.size, 0);
});

test('beforeSend cancellation or abort does not leave loading stuck', () => {
    const app = page();
    app.request({ beforeSend() { return false; } });
    app.request({ beforeSend(xhr) { xhr.abort(); } });
    app.advance(500);
    assert.equal(app.progress.starts, 0);
    assert.equal(app.progress.finishes, 0);
    assert.equal(app.visible.size, 0);
    app.request().complete();
    assert.equal(app.progress.starts, 1);
    assert.equal(app.progress.finishes, 1);
});

test('custom contexts and global:false preserve beforeSend and display loading', () => {
    const app = page();
    const context = { expected: true };
    const xhr = app.request({ global: false, beforeSend(xhr, settings) {
        assert.equal(this, context);
        assert.equal(settings.global, false);
        assert.equal(xhr.state(), 'pending');
    } }, context);
    app.advance(180);
    assert.equal(app.visible.size, 1);
    xhr.complete();
    assert.equal(app.visible.size, 0);
});
