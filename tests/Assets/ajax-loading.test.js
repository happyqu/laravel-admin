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
    const document = { addEventListener() {} };
    const window = {};
    const events = {};
    const admin = function () {};
    const chain = new Proxy({}, { get: () => () => chain });
    const $ = target => {
        if (target === document || target === window) {
            return {
                ajaxError() { return this; }, click() { return this; }, scroll() { return this; },
                on(name, selector, handler) { events[name] = handler || selector; return this; },
            };
        }
        if (target && target.buttons) {
            return { find() { return { each(callback) { target.buttons.forEach(button => callback.call(button)); } }; } };
        }
        if (typeof target !== 'string') return chain;
        if (target === 'meta[name="admin-loading-text"]') {
            return { attr: name => name === 'data-submitting' ? '提交中...' : '加载中...' };
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
        $, jQuery: $, LA: admin, document, window, toastr: {}, swal() {},
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

    function submit(form, cancelled = false) {
        const event = {
            isDefaultPrevented: () => cancelled,
            preventDefault() { cancelled = true; },
        };
        events['submit.adminLoading'].call(form, event);
        return event;
    }

    return { request, advance, visible, progress, submit,
        restore: () => events['pageshow.adminLoading'](),
        directSubmit: form => admin.startFormLoading(form) };
}

function form() {
    let content = '<strong>Save</strong>';
    return { buttons: [{
        tagName: 'BUTTON', disabled: false,
        get innerHTML() { return content; }, set innerHTML(value) { content = value; },
        get textContent() { return content; }, set textContent(value) { content = value; },
    }, { tagName: 'INPUT', disabled: false, value: 'Save and close' },
    { tagName: 'BUTTON', disabled: true, innerHTML: 'Unavailable' }] };
}

test('slow requests display a localized indicator and finish after success', () => {
    const app = page();
    const xhr = app.request();
    assert.equal(app.progress.starts, 0);
    app.advance(180);
    assert.equal(app.visible.size, 1);
    assert.equal([...app.visible][0].label, '加载中...');
    xhr.complete();
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.finishes, 0);
});

test('fast requests cancel the delayed indicator', () => {
    const app = page();
    app.request().complete();
    app.advance(500);
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.finishes, 0);
});

test('concurrent requests keep loading until every foreground request ends', () => {
    const app = page();
    const first = app.request();
    const second = app.request();
    app.advance(180);
    first.complete();
    assert.equal(app.visible.size, 1);
    assert.equal(app.progress.starts, 0);
    assert.equal(app.progress.finishes, 0);
    second.complete();
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.finishes, 0);
});

test('failures and aborted requests both remove loading', () => {
    for (const action of ['error', 'abort']) {
        const app = page();
        const xhr = app.request();
        app.advance(180);
        if (action === 'abort') xhr.abort();
        else xhr.complete('rejected');
        assert.equal(app.visible.size, 0);
        assert.equal(app.progress.finishes, 0);
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
    assert.equal(app.progress.starts, 0);
    assert.equal(app.progress.finishes, 0);
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

test('native submits show loading immediately and preserve submitter values until serialization', () => {
    const app = page();
    const model = form();
    const event = app.submit(model);
    assert.equal(event.isDefaultPrevented(), false);
    assert.equal(app.visible.size, 1);
    assert.equal(model.buttons[0].disabled, false);
    assert.equal(model.buttons[1].value, 'Save and close');
    app.advance(0);
    assert.equal(model.buttons[0].disabled, true);
    assert.equal(model.buttons[0].textContent, '提交中...');
    assert.equal(model.buttons[1].disabled, true);
    assert.equal(model.buttons[1].value, '提交中...');
    assert.equal(model.buttons[2].innerHTML, 'Unavailable');
});

test('duplicate native submissions are blocked without adding another loading counter', () => {
    const app = page();
    const model = form();
    app.submit(model);
    assert.equal(app.submit(model).isDefaultPrevented(), true);
    assert.equal(app.visible.size, 1);
    app.restore();
    assert.equal(app.visible.size, 0);
});

test('cancelled confirmation or validation leaves the form usable', () => {
    const app = page();
    const model = form();
    app.submit(model, true);
    assert.equal(app.progress.starts, 0);
    assert.equal(model.buttons[0].disabled, false);
    // A validator registered later may cancel the same submit event.
    const event = app.submit(model);
    event.preventDefault();
    app.advance(0);
    assert.equal(app.visible.size, 0);
    assert.equal(model.buttons[0].disabled, false);
    assert.equal(app.submit(model).isDefaultPrevented(), false);
});

test('back/forward restoration resets labels and controls while preserving active AJAX loading', () => {
    const app = page();
    const model = form();
    app.submit(model);
    const xhr = app.request();
    app.advance(0);
    app.restore();
    assert.equal(model.buttons[0].disabled, false);
    assert.equal(model.buttons[0].innerHTML, '<strong>Save</strong>');
    assert.equal(model.buttons[1].value, 'Save and close');
    assert.equal(model.buttons[2].disabled, true);
    assert.equal(app.visible.size, 1);
    xhr.complete();
    assert.equal(app.visible.size, 0);
});

test('confirmed widgets can start loading for native form.submit()', () => {
    const app = page();
    const widget = form();
    assert.equal(app.directSubmit(widget), true);
    assert.equal(app.visible.size, 1);
    assert.equal(app.directSubmit(widget), false);
    app.advance(0);
    assert.equal(widget.buttons[0].disabled, true);
    app.restore();
    assert.equal(widget.buttons[0].disabled, false);
});

test('submitting to a different window does not leave the current page locked', () => {
    const app = page();
    const model = { ...form(), target: '_blank' };
    app.submit(model);
    assert.equal(app.directSubmit(model), true);
    app.advance(180);
    assert.equal(app.visible.size, 0);
    assert.equal(app.progress.starts, 0);
    assert.equal(model.buttons[0].disabled, false);
});
