const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/choices.php'], { cwd: root, encoding: 'utf8' }));
const types = ['select', 'checkbox', 'radio', 'radioButton', 'radioCard', 'checkboxButton', 'checkboxCard', 'multipleSelect', 'listbox'];

function page(fixture) {
    const w = new JSDOM('<form><fieldset><div class="fields-group">' + fixture.html + '</div></fieldset></form>', {
        runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/',
    }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/plugins/select2/select2.full.min.js',
        'AdminLTE/plugins/iCheck/icheck.min.js', 'bootstrap-duallistbox/dist/jquery.bootstrap-duallistbox.min.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    w.pending = [];
    w.$.ajax = () => ({ done(callback) { w.pending.push(callback); } });
    for (const script of fixture.scripts) w.eval(script);
    w.respond = () => {
        for (const callback of w.pending.splice(0)) {
            callback(fixture.html.includes('multiple="multiple"') && fixture.scripts.some(s => s.includes('new Option'))
                ? { 0: 'Zero', 1: 'One', 2: 'Two' } : [{ id: 0, text: 'Zero' }, { id: 1, text: 'One' }, { id: 2, text: 'Two' }]);
        }
    };
    return w;
}

function values(w, name = 'choice') {
    const data = new w.FormData(w.document.querySelector('form'));
    return [...data.getAll(name), ...data.getAll(name + '[]')].filter(value => value !== '');
}

function changeChoice(w, type) {
    if (type === 'select') w.$('.select2-selection__clear')[0]?.dispatchEvent(new w.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
    else if (type === 'multipleSelect') w.$('.select2-selection__choice__remove')[0]?.click();
    else if (type === 'listbox') w.$('.moveall')[0].click();
    else w.$('input[value="2"]').closest('label')[0].click();
}

for (const type of types) {
    test(`${type} restores nested old input without taking the same-named parent value`, () => {
        const w = page(fixtures[type + '_nested']);
        try { assert.deepEqual(values(w, 'rows[new_1][choice]'), ['1']); }
        finally { w.close(); }
    });

    test(`${type} readonly blocks UI changes after remote initialization and recovers when unlocked`, async () => {
        const w = page(fixtures[type + '_readonly']);
        try {
            await new Promise(resolve => w.setTimeout(resolve, 0));
            w.respond();
            assert.deepEqual(values(w), ['1']);
            changeChoice(w, type);
            assert.deepEqual(values(w), ['1']);
            const keyboardTarget = w.$('input[value="2"][data-admin-choice],select[data-admin-choice]')[0];
            for (const event of ['keydown', 'keyup', 'keypress']) {
                keyboardTarget.dispatchEvent(new w.KeyboardEvent(event, { key: ' ', keyCode: 32, bubbles: true, cancelable: true }));
            }
            assert.deepEqual(values(w), ['1']);
            const tab = new w.KeyboardEvent('keydown', { key: 'Tab', keyCode: 9, bubbles: true, cancelable: true });
            keyboardTarget.dispatchEvent(tab);
            assert.equal(tab.defaultPrevented, false);
            w.$('[data-admin-choice]').removeAttr('readonly');
            changeChoice(w, type);
            assert.notDeepEqual(values(w), ['1']);
        } finally { w.close(); }
    });

    test(`${type} obeys dynamic readonly and a disabled fieldset`, () => {
        const w = page(fixtures[type + '_normal']);
        try {
            w.$('[data-admin-choice]').attr('readonly', 'readonly');
            changeChoice(w, type);
            assert.deepEqual(values(w), ['1']);
            w.$('[data-admin-choice]').removeAttr('readonly');
            w.document.querySelector('fieldset').disabled = true;
            changeChoice(w, type);
            const source = w.$('[data-admin-choice]').not('[type=hidden]');
            if (source.is('select')) assert.deepEqual([].concat(source.val()), ['1']);
            else assert.deepEqual(Array.from(source.filter(':checked'), el => el.value), ['1']);
            w.document.querySelector('fieldset').disabled = false;
            assert.deepEqual(values(w), ['1']);
        } finally { w.close(); }
    });
}

for (const type of ['checkbox', 'checkboxButton', 'checkboxCard']) {
    test(`${type} retains the selected zero value`, () => {
        const w = page(fixtures[type + '_zero']);
        try { assert.deepEqual(values(w), ['0']); }
        finally { w.close(); }
    });
}

for (const type of ['radio', 'radioButton', 'radioCard']) {
    test(`${type} checked selects by value rather than label or an implicit zero`, () => {
        const w = page(fixtures[type + '_checked']);
        try {
            assert.deepEqual(values(w), ['2']);
            if (type !== 'radio') assert.equal(w.$('input[value="2"]').closest('label').hasClass('active'), true);
        } finally { w.close(); }
    });
}

for (const type of ['select', 'multipleSelect', 'listbox']) {
    for (const mode of ['old', 'clear', 'callback']) {
        test(`${type} ${mode} restores the submitted value instead of the model value`, () => {
            const w = page(fixtures[type + '_' + mode]);
            try {
                w.respond();
                assert.deepEqual(values(w), mode === 'clear' ? [] : ['2']);
            } finally { w.close(); }
        });
    }
}

for (const method of ['load', 'loads']) {
    test(`${method} ignores stale responses, including A to B to A changes`, () => {
        const w = page({ html: '<select class="country"><option>A</option><option>B</option></select><select class="city"></select><select class="district"></select>', scripts: [] });
        try {
            const requests = [];
            w.$.get = (url, query, callback) => {
                const request = { url, query, callback };
                requests.push(request);
                return { then(done) { request.callback = done; } };
            };
            for (const script of fixtures[method]) w.eval(script);
            const source = w.$('.country');
            source.val('A').trigger('change');
            source.val('B').trigger('change');
            source.val('A').trigger('change');
            const count = method === 'load' ? 1 : 2;
            for (let index = requests.length - 1; index >= 0; index--) {
                const generation = Math.floor(index / count);
                requests[index].callback([{ id: generation === 2 ? 'latest' : 'stale', text: 'City' }]);
            }
            assert.deepEqual(Array.from(w.$('.city option'), el => el.value), ['latest']);
            if (method === 'loads') {
                assert.ok(requests[0].url.includes('?active=1&q=A'));
                assert.deepEqual(Array.from(w.$('.district option'), el => el.value), ['latest']);
            }
        } finally { w.close(); }
    });
}

test('check all respects individually locked checkboxes', () => {
    const w = page(fixtures.checkbox_normal);
    try {
        w.$('input[value="2"]').attr('readonly', 'readonly');
        w.$('input[type="checkbox"]').first().closest('label')[0].click();
        assert.deepEqual(values(w), ['0', '1']);
    } finally { w.close(); }
});
