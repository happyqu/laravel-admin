const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const components = JSON.parse(execFileSync('php', ['tests/Grid/fixtures/components.php'], { cwd: root, encoding: 'utf8' }));

function page(types) {
    const w = new JSDOM(types.map(type => components[type].html).join(''), {
        runScripts: 'outside-only', url: 'http://localhost/',
    }).window;
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    w.$.fn.popover = function () { return this; };
    w.LA = { token: 'csrf' };
    w.toastr = { success() {}, error() {} };
    w.$.admin = { isResponseError: data => data.status === false, responseMessage: data => data && data.message, notifyThenNavigate() {} };
    const calls = [];
    w.$.ajax = options => { calls.push(options); };
    for (const type of types) w.eval(components[type].script);
    return { w, calls };
}

function open(w, type) {
    const trigger = w.$('.trigger-' + type);
    const tip = w.$('<div class="popover in">').append(w.$('#target-' + type).html()).appendTo('body');
    trigger.data('bs.popover', { $tip: tip });
    trigger.trigger('shown.bs.popover');
    return tip.find('.ie-content');
}

test('different inline editor types keep their own handlers, and double clicks send once', () => {
    const { w, calls } = page(['input', 'radio']);
    try {
        const content = open(w, 'input');
        content.find('.ie-input').val('<img src=x onerror=alert(1)>');
        content.find('.ie-submit').trigger('click').trigger('click');
        assert.equal(calls.length, 1);
        assert.equal(calls[0].data.field_input, '<img src=x onerror=alert(1)>');
        calls[0].success({ status: true, message: 'Saved' });
        calls[0].complete();
        assert.equal(w.$('.trigger-input .ie-display').text(), '<img src=x onerror=alert(1)>');
        assert.equal(w.document.querySelector('img'), null);
    } finally { w.close(); }
});

test('inline failures preserve the value and render validation messages as text', () => {
    const { w, calls } = page(['input']);
    try {
        const content = open(w, 'input');
        content.find('.ie-input').val('new');
        content.find('.ie-submit').trigger('click');
        calls[0].success({ status: false, message: 'Rejected' });
        calls[0].complete();
        assert.equal(w.$('.trigger-input .ie-display').text(), 'Old');
        assert.equal(w.$('.trigger-input').data('value'), 'old');
        content.find('.ie-submit').trigger('click');
        calls[1].error({ status: 422, responseJSON: { errors: { value: ['<img src=x onerror=alert(1)>'] } } });
        calls[1].complete();
        assert.equal(content.find('.error img').length, 0);
        assert.match(content.find('.error').text(), /<img/);
        assert.equal(content.find('.ie-submit').prop('disabled'), false);
    } finally { w.close(); }
});

test('clearing an inline multiSelect includes an explicit field in the request', () => {
    const { w, calls } = page(['multiple-select']);
    try {
        const content = open(w, 'multiple-select');
        content.find('option').prop('selected', false);
        content.find('.ie-submit').trigger('click');
        assert.deepEqual(Array.from(calls[0].data['field_multiple-select']), ['']);
    } finally { w.close(); }
});

test('inline multi upload uses its event FileList and sends one partial update', () => {
    const { w, calls } = page(['upload']);
    try {
        w.eval(components.upload.script);
        const input = w.document.querySelector('input.inline-upload');
        const files = { 0: new w.File(['a'], 'a.txt'), 1: new w.File(['b'], 'b.txt'), length: 2 };
        Object.defineProperty(input, 'files', { value: files });
        w.$(input).trigger('change');
        assert.equal(calls.length, 1);
        assert.equal(calls[0].data.getAll('field_upload[]').length, 2);
        assert.equal(calls[0].data.get('_edit_inline'), 'true');
    } finally { w.close(); }
});
