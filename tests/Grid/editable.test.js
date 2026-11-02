const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const components = JSON.parse(execFileSync('php', ['tests/Grid/fixtures/editable.php'], { cwd: root, encoding: 'utf8' }));
const read = file => fs.readFileSync(path.join(root, 'resources/assets', file), 'utf8');

function page(types = ['title']) {
    const w = new JSDOM('<meta name="admin-loading-text" data-failed="Localized request failure">' +
        types.map(type => components[type].html).join(''), { runScripts: 'outside-only', url: 'http://localhost/' }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/bootstrap/js/bootstrap.min.js',
        'bootstrap3-editable/js/bootstrap-editable.min.js']) w.eval(read(asset));
    const notices = [];
    w.LA = { token: 'csrf' };
    w.toastr = { success: message => notices.push(['success', message]), error: message => notices.push(['error', message]) };
    w.swal = function () {};
    w.NProgress = { configure() {}, start() {}, done() {} };
    w.eval(read('laravel-admin/laravel-admin.js'));
    w.$.fn.editable.defaults.mode = 'inline';
    types.forEach(type => w.eval(components[type].script));
    const calls = [];
    w.$.ajax = options => {
        const request = w.$.Deferred();
        calls.push({ options, request });
        return request.promise();
    };
    return { w, notices, calls };
}

function open(w) {
    const link = w.$('[data-url="/admin/title/7"]');
    link.editable('show');
    const editable = link.data('editable');
    return { link, editable, form: editable.container.$form.data('editableform') };
}

function submit(w, form, value) {
    form.input.$input.val(value);
    form.submit(w.$.Event('submit'));
}

test('editable keeps rejected values open and commits only after a successful retry', () => {
    const { w, notices, calls } = page();
    try {
        const { link, editable, form } = open(w);
        submit(w, form, 'new');
        calls[0].request.resolve({ status: false, message: '<img src=x onerror=alert(1)> Rejected' });
        assert.equal(link.text(), 'old');
        assert.equal(editable.value, 'old');
        assert.equal(form.value, 'old');
        assert.equal(form.input.$input.val(), 'new');
        assert.notEqual(form.$form.css('display'), 'none');
        assert.match(form.$form.find('.editable-error-block').text(), /Rejected/);
        assert.equal(form.$form.find('.editable-error-block img').length, 0);
        assert.equal(notices[0][0], 'error');

        submit(w, form, 'corrected');
        assert.equal(calls.length, 2);
        assert.equal(calls[1].options.url, '/admin/title/7');
        assert.equal(calls[1].options.data.name, 'title');
        assert.equal(calls[1].options.data.value, 'corrected');
        assert.equal(calls[1].options.data._token, 'csrf');
        assert.equal(calls[1].options.data._method, 'PUT');
        assert.equal(calls[1].options.data._editable, 1);
        calls[1].request.resolve({ status: true, message: 'Saved' });
        assert.equal(link.text(), 'corrected');
        assert.equal(editable.value, 'corrected');
        assert.equal(w.$('[data-url="/admin/title/8"]').text(), 'other');
        assert.deepEqual(notices[1], ['success', 'Saved']);
    } finally { w.close(); }
});

test('editable uses the localized fallback for a rejected response without a message', () => {
    const { w, calls } = page();
    try {
        const { link, form } = open(w);
        submit(w, form, 'new');
        calls[0].request.resolve({ status: false });
        assert.equal(link.text(), 'old');
        assert.equal(form.$form.find('.editable-error-block').text(), 'Localized request failure');
    } finally { w.close(); }
});

for (const [name, response, expected] of [
    ['network failure', { status: 0 }, 'Localized request failure'],
    ['HTML error page', { status: 500, responseText: '<h1>Internal error</h1>' }, 'Localized request failure'],
    ['JSON error message', { status: 403, responseJSON: { message: 'Forbidden' } }, 'Forbidden'],
    ['validation errors', { status: 422, responseJSON: { errors: { title: ['First error', 'Second error'] } } }, 'First error\nSecond error'],
    ['legacy validation errors', { status: 422, responseJSON: { validation: { title: ['Invalid title'] } } }, 'Invalid title'],
]) {
    test('editable recovers from ' + name + ' and allows another save', () => {
        const { w, calls } = page();
        try {
            const { link, form } = open(w);
            submit(w, form, 'new');
            assert.doesNotThrow(() => calls[0].request.reject(response));
            assert.equal(link.text(), 'old');
            assert.equal(form.value, 'old');
            assert.equal(form.input.$input.val(), 'new');
            assert.notEqual(form.$form.css('display'), 'none');
            assert.equal(form.$loading.css('display'), 'none');
            assert.equal(form.$form.find('.editable-error-block').text().replace(/\r/g, ''), expected.replace(/\n/g, ''));
            submit(w, form, 'retry');
            assert.equal(calls.length, 2);
            calls[1].request.resolve({ status: true, message: 'Saved' });
            assert.equal(link.text(), 'retry');
        } finally { w.close(); }
    });
}

test('same-named editable columns in separate grids retain their own select options', () => {
    const { w } = page(['first', 'second']);
    try {
        for (const name of ['first', 'second']) {
            for (const id of [7, 8]) {
                const link = w.$('[data-url="/admin/' + name + '/' + id + '"]');
                const editable = link.data('editable');
                assert.ok(editable);
                assert.equal(editable.input.options.source[0].text, name + ' label');
                assert.equal(link.text(), name + ' label');
            }
        }
    } finally { w.close(); }
});

test('editable select closures keep their row-specific options after initialization', () => {
    const { w } = page(['dynamic']);
    try {
        for (const id of [7, 8]) {
            const link = w.$('[data-url="/admin/dynamic/' + id + '"]');
            assert.equal(link.data('editable').input.options.source[0].text, 'Row ' + id);
            assert.equal(link.text(), 'Row ' + id);
        }
    } finally { w.close(); }
});
