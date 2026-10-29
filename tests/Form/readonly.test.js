const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/readonly.php'], { cwd: root, encoding: 'utf8' }));

function page(fixture, deferred = false) {
    const w = new JSDOM('<form><fieldset>' + fixture.html + '</fieldset></form>' + fixture.modals.join(''), {
        runScripts: 'outside-only', url: 'http://localhost/',
    }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/bootstrap/js/bootstrap.min.js',
        'AdminLTE/plugins/iCheck/icheck.min.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    w.$.support.transition = false;
    w.requests = [];
    w.pending = [];
    w.$.get = (url, callback) => {
        w.requests.push(url);
        const respond = () => callback('<input class="select" type="checkbox" value="changed">');
        if (deferred) w.pending.push(respond); else respond();
    };
    for (const script of fixture.scripts) w.eval(script);
    return w;
}

for (const type of ['text', 'many', 'file', 'textarea']) {
    for (const mode of ['readonly', 'disable']) {
        test(`${type} ${mode}: picker entry is disabled and native submission semantics are retained`, () => {
            const w = page(fixtures[type + '_' + mode]);
            try {
                const input = w.document.querySelector('[name="asset"]');
                const original = type === 'many' ? 'original;other' : 'original';
                const button = w.document.querySelector('[aria-disabled="true"]');
                assert.ok(button);
                assert.equal(button.hasAttribute('data-toggle'), false);
                button.click();
                assert.equal(input.value, original);
                assert.equal(w.document.querySelectorAll('.modal').length, 0);
                assert.deepEqual(w.requests, []);
                const data = new w.FormData(w.document.querySelector('form'));
                assert.equal(data.get('asset'), mode === 'readonly' ? original : null);
                if (type === 'file') assert.equal(w.document.querySelectorAll('.picker-file-preview .remove').length, 0);
            } finally { w.close(); }
        });
    }
}

for (const state of ['readOnly', 'disabled', 'fieldset']) {
    test(`picker refuses opening while ${state} and recovers when unlocked`, () => {
        const w = page(fixtures.text_normal);
        try {
            const input = w.document.querySelector('[name="asset"]');
            const target = state === 'fieldset' ? w.document.querySelector('fieldset') : input;
            const flag = state === 'fieldset' ? 'disabled' : state;
            target[flag] = true;
            const button = w.document.querySelector('[data-toggle="modal"]');
            button.click();
            assert.equal(w.document.querySelector('.modal').classList.contains('in'), false);
            assert.deepEqual(w.requests, []);
            target[flag] = false;
            button.click();
            assert.equal(w.document.querySelector('.modal').classList.contains('in'), true);
            assert.deepEqual(w.requests, ['/picker']);
        } finally { w.close(); }
    });
}

for (const type of ['text', 'many', 'file']) {
    for (const flag of ['readOnly', 'disabled']) {
        test(`${type}: locking ${flag} after opening prevents confirmation from changing the value`, () => {
            const w = page(fixtures[type + '_normal']);
            try {
                const input = w.document.querySelector('[name="asset"]');
                const original = input.value;
                w.document.querySelector('[data-toggle="modal"]').click();
                const modal = w.$('.modal');
                modal.find('input.select').iCheck('check');
                input[flag] = true;
                modal.find('.submit').trigger('click');
                assert.equal(input.value, original);
                assert.equal(modal.hasClass('in'), false);
                if (type !== 'file') {
                    input[flag] = false;
                    w.document.querySelector('[data-toggle="modal"]').click();
                    modal.find('input.select').iCheck('check');
                    modal.find('.submit').trigger('click');
                    assert.equal(input.value, type === 'many' ? 'original;other;changed' : 'changed');
                }
            } finally { w.close(); }
        });
    }
}

test('a late picker response cannot populate controls after the input is disabled', () => {
    const w = page(fixtures.text_normal, true);
    try {
        const input = w.document.querySelector('[name="asset"]');
        w.document.querySelector('[data-toggle="modal"]').click();
        input.disabled = true;
        w.pending[0]();
        assert.equal(w.document.querySelectorAll('.modal input.select').length, 0);
        w.$('.modal .submit').trigger('click');
        assert.equal(input.value, 'original');
    } finally { w.close(); }
});

test('file preview removal obeys current input state and does not bubble into a containing row', () => {
    const w = page(fixtures.file_normal);
    try {
        const input = w.document.querySelector('[name="asset"]');
        const preview = w.$('.picker-file-preview');
        let parentRemovals = 0;
        w.$('form').on('click', '.remove', () => parentRemovals++);
        input.readOnly = true;
        preview.find('.remove').trigger('click');
        assert.equal(input.value, 'original');
        assert.equal(preview.find('.file-preview-frame').hasClass('hide'), false);
        input.readOnly = false;
        preview.find('.remove').trigger('click');
        assert.equal(input.value, '');
        assert.equal(parentRemovals, 0);
    } finally { w.close(); }
});
