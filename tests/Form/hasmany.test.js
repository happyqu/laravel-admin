const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/hasmany.php'], { cwd: root, encoding: 'utf8' }));

function page(fixture) {
    const w = new JSDOM('<form>' + fixture.html + '</form>', { runScripts: 'outside-only', url: 'http://localhost/' }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/bootstrap/js/bootstrap.min.js',
        'AdminLTE/plugins/select2/select2.full.min.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    w.editors = new Set();
    w.CKEDITOR = { replace(id) {
        assert.ok(w.document.getElementById(id));
        assert.equal(w.editors.has(id), false, 'Each editor must initialize its own input');
        w.editors.add(id);
    } };
    w.requests = [];
    w.$.get = (url, params, callback) => {
        w.requests.push(url);
        callback([{ id: 'loaded', text: 'City' }]);
    };
    for (const script of fixture.scripts) w.eval(script);
    return w;
}

function add(w, mode) {
    const root = w.$('#has-many-children');
    (mode === 'tab' ? root.children('.header') : root.children('.form-group')).find('.add').trigger('click');
}

function remove(w, mode, key) {
    if (mode === 'tab') {
        w.$(`#has-many-children > .nav a[href="#children_${key}"]`).siblings('.close-tab').trigger('click');
    } else {
        w.$(`[name="children[${key}][title]"]`).closest('.has-many-children-form').find('.remove').trigger('click');
    }
}

for (const mode of ['default', 'table', 'tab']) {
    test(`${mode}: restored new rows keep names, values and independent plugin instances`, () => {
        const w = page(fixtures[mode + '_old']);
        try {
            const form = w.document.querySelector('form');
            const data = new w.FormData(form);
            assert.equal(data.get('children[new_1][title]'), 'Unsaved one');
            assert.equal(data.get('children[new_2][title]'), 'Unsaved two');
            assert.equal(data.getAll('children[new_1][title]').length, 1);
            assert.equal(data.getAll('children[new_2][title]').length, 1);
            add(w, mode);
            const names = [...form.querySelectorAll('input[name$="[title]"]')].map(input => input.name);
            assert.equal(names.length, 3);
            assert.equal(new Set(names).size, 3);
            const ids = [...form.querySelectorAll('[id]')].map(input => input.id);
            assert.equal(new Set(ids).size, ids.length);
            assert.equal(w.editors.size, 3);
            w.$('[name="children[new_1][country]"]').val('1').trigger('change');
            assert.deepEqual(w.requests, ['/api/cities']);
            assert.equal(w.$('[name="children[new_1][city]"] option[value="loaded"]').length, 1);
            assert.equal(w.$('[name="children[new_2][city]"] option[value="loaded"]').length, 0);
        } finally { w.close(); }
    });

    test(`${mode}: saved and dynamically added rows initialize separate editors and labels`, () => {
        const w = page(fixtures[mode + '_saved']);
        try {
            add(w, mode);
            const ids = [...w.document.querySelectorAll('[id]')].map(input => input.id);
            assert.equal(new Set(ids).size, ids.length);
            assert.equal(w.editors.size, 3);
            for (const input of w.document.querySelectorAll('input[name$="[title]"]')) {
                assert.equal(w.document.querySelector(`label[for="${input.id}"]`).htmlFor, input.id);
            }
        } finally { w.close(); }
    });

    test(`${mode}: disableDelete preserves saved rows but permits canceling a new row`, () => {
        const w = page(fixtures[mode + '_disabled']);
        try {
            const form = w.document.querySelector('form');
            assert.equal(w.document.querySelectorAll('.remove, .close-tab').length, 0);
            add(w, mode);
            const newInput = [...form.querySelectorAll('input[name$="[title]"]')].find(input => input.name.includes('[new_'));
            const key = newInput.name.match(/children\[([^\]]+)\]/)[1];
            newInput.value = '';
            assert.equal(form.checkValidity(), false);
            remove(w, mode, key);
            assert.equal(new w.FormData(form).get(newInput.name), null);
            assert.equal(form.checkValidity(), true);
            assert.equal(form.querySelectorAll('input[name$="[title]"]').length, 2);
            assert.equal(w.$('[name="children[7][_remove_]"]').val(), '0');
        } finally { w.close(); }
    });

    test(`${mode}: restored unsaved rows can also be canceled with deletion disabled`, () => {
        const w = page(fixtures[mode + '_oldDisabled']);
        try {
            remove(w, mode, 'new_1');
            const data = new w.FormData(w.document.querySelector('form'));
            assert.equal(data.get('children[new_1][title]'), null);
            assert.equal(data.get('children[new_2][title]'), 'Unsaved two');
        } finally { w.close(); }
    });
}
