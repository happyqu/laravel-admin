const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/json-fields.php'], { cwd: root, encoding: 'utf8' }));

function page(fixture, configure = () => {}) {
    const w = new JSDOM('<form>' + fixture.html + '</form>' + (fixture.modals || []).join(''), {
        runScripts: 'outside-only', url: 'http://localhost/', pretendToBeVisual: true,
    }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/bootstrap/js/bootstrap.min.js',
        'AdminLTE/plugins/select2/select2.full.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js', 'laravel-admin/flatpickr-local.js',
        'laravel-admin/flatpickr-locales.js', 'laravel-admin/datepicker.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    configure(w);
    for (const script of fixture.scripts) w.eval(script);
    return w;
}

test('saved legacy Table keys remain deletable and an empty Table is submitted', () => {
    const w = page(fixtures.table);
    try {
        w.$('#has-many-rows .remove').trigger('click');
        const data = new w.FormData(w.document.querySelector('form'));
        assert.equal(data.get('rows[new_1][_remove_]'), '1');
        assert.equal(data.get('rows[_present]'), '1');
        w.$('#has-many-rows .add').trigger('click');
        assert.equal(w.$('[name="rows[new_2][title]"]').length, 1);
        w.$('#has-many-rows .remove').last().trigger('click');
        assert.equal(w.$('[name="rows[new_2][title]"]').length, 0);
    } finally { w.close(); }
});

test('nested Embeds initializes its date input with a valid selector', () => {
    const w = page(fixtures.embeds);
    try {
        const input = w.document.querySelector('[name="profile[nested][day]"]');
        assert.ok(input._flatpickr);
        assert.equal(input.value, '2026-10-29');
    } finally { w.close(); }
});

test('Table load and loads bindings work once per change on existing and new rows', () => {
    const requests = [];
    const w = page(fixtures.selectLoad, w => {
        w.$.get = (url, params, callback) => {
            requests.push(url);
            const data = [{ id: 'loaded', text: 'Loaded option' }];
            if (callback) callback(data);
            return w.$.Deferred().resolve(data).promise();
        };
    });
    try {
        w.$('[name="locations[0][province]"]').trigger('change');
        assert.deepEqual(requests, ['/api/cities']);
        w.$('#has-many-locations .add').trigger('click').trigger('click');
        for (const key of ['new_1', 'new_2']) {
            requests.length = 0;
            w.$(`[name="locations[${key}][province]"]`).val('1').trigger('change');
            assert.deepEqual(requests, ['/api/cities']);
            assert.equal(w.$(`[name="locations[${key}][city]"] option[value="loaded"]`).length, 1);
            requests.length = 0;
            w.$(`[name="locations[${key}][region]"]`).val('1').trigger('change');
            assert.deepEqual(requests, ['/api/districts?q=1', '/api/towns?q=1']);
            for (const target of ['district', 'town']) {
                assert.equal(w.$(`[name="locations[${key}][${target}]"] option[value="loaded"]`).length, 1);
            }
        }
        assert.equal(w.$('[name="locations[0][town]"] option[value="loaded"]').length, 0);
    } finally { w.close(); }
});

test('Embeds pickers open and submit to their own field in the page and action', () => {
    const requests = [];
    const w = page({
        html: fixtures.picker_page.html + fixtures.picker_action.html,
        scripts: fixtures.picker_page.scripts.concat(fixtures.picker_action.scripts),
        modals: fixtures.picker_page.modals.concat(fixtures.picker_action.modals),
    }, w => {
        w.$.support.transition = false;
        w.$.get = (url, callback) => {
            requests.push(url);
            callback('<input class="select" type="radio" value="chosen">');
        };
    });
    try {
        const buttons = w.document.querySelectorAll('[data-toggle="modal"]');
        const inputs = w.document.querySelectorAll('input[name="profile[asset]"]');
        assert.equal(buttons.length, 2);
        assert.notEqual(buttons[0].getAttribute('data-target'), buttons[1].getAttribute('data-target'));
        for (let index = 0; index < buttons.length; index++) {
            const target = buttons[index].getAttribute('data-target');
            assert.doesNotMatch(target, /\s/);
            const modal = w.document.querySelector(target);
            assert.ok(modal);
            buttons[index].click();
            assert.equal(modal.classList.contains('in'), true);
            w.$(modal).find('input.select').iCheck('check');
            w.$(modal).find('.submit').trigger('click');
            assert.equal(inputs[index].value, 'chosen');
            if (index === 0) assert.equal(inputs[1].value, '');
        }
        assert.deepEqual(requests, ['/picker', '/picker']);
    } finally { w.close(); }
});

test('same-named Embeds in the page and action have separate selectors and ids', () => {
    const w = page({ html: fixtures.embeds.html + fixtures.scopedEmbeds.html,
        scripts: fixtures.embeds.scripts.concat(fixtures.scopedEmbeds.scripts) });
    try {
        const inputs = w.document.querySelectorAll('input[name="profile[nested][day]"]');
        assert.equal(inputs.length, 2);
        assert.notEqual(inputs[0].id, inputs[1].id);
        for (const input of inputs) assert.ok(input._flatpickr);
        assert.equal(inputs[0].value, '2026-10-29');
        assert.equal(inputs[1].value, '2026-11-10');
        const ranges = w.document.querySelectorAll('input[name$="[starts_at]"], input[name$="[ends_at]"]');
        assert.equal(ranges.length, 4);
        for (const input of ranges) assert.ok(input._flatpickr);
        assert.notEqual(ranges[0].className, ranges[2].className);
    } finally { w.close(); }
});

for (const mode of ['default', 'table', 'tab']) {
    test(`Table inside a new ${mode} hasMany row initializes independently`, () => {
        const requests = [];
        const w = page(fixtures['relation_' + mode], w => {
            w.$.get = (url, params, callback) => {
                requests.push(url);
                callback([{ id: 'city-1', text: 'City' }]);
            };
        });
        try {
            w.$('#has-many-records .add').first().trigger('click');
            const child = w.$('#has-many-records [data-json-table]');
            assert.equal(child.length, 1);
            child.find('.add').trigger('click');
            const input = w.document.querySelector('[name="records[new_1][children][new_1][day]"]');
            assert.ok(input);
            assert.ok(input._flatpickr);
            child.find('select[name$="[province]"]').val('1').trigger('change');
            assert.deepEqual(requests, ['/api/cities']);
            assert.equal(child.find('select[name$="[city]"] option[value="city-1"]').length, 1);
            for (const range of child[0].querySelectorAll('input[name$="[starts_at]"], input[name$="[ends_at]"]')) {
                assert.ok(range._flatpickr);
            }
            assert.equal(w.$('#has-many-records [data-json-table]').length, 1);
        } finally { w.close(); }
    });
}

test('nested Table additions isolate parent rows, names, plugins and removal flags', () => {
    const w = page(fixtures.nested);
    try {
        const parents = w.$('#has-many-parents').children('table').children('tbody');
        assert.equal(parents.children('tr').length, 2);
        const children = () => parents.children('tr').first().find('[data-json-table]').first();
        assert.equal(w.$('[name="parents[0][children][0][details][day]"]').val(), '2026-10-29');
        for (const input of w.document.querySelectorAll('input[name$="[day]"]')) assert.ok(input._flatpickr);
        children().find('.add').last().trigger('click');
        assert.equal(parents.children('tr').length, 2);
        assert.equal(w.$('[name="parents[0][children][new_1][details][day]"]').length, 1);
        assert.equal(w.$('[name="parents[1][children][new_1][details][day]"]').length, 0);
        assert.ok(w.document.querySelector('[name="parents[0][children][new_1][details][day]"]')._flatpickr);
        w.$('#has-many-parents').children('.form-group').find('.add').trigger('click');
        assert.equal(parents.children('tr').length, 3);
        const addedParent = parents.children('tr').last();
        addedParent.find('[data-json-table] .add').trigger('click');
        const addedDate = w.document.querySelector('[name="parents[new_1][children][new_1][details][day]"]');
        assert.ok(addedDate);
        assert.ok(addedDate._flatpickr);
        for (const range of addedParent[0].querySelectorAll('input[name$="[starts_at]"], input[name$="[ends_at]"]')) {
            assert.ok(range._flatpickr);
        }
        const ids = Array.from(w.document.querySelectorAll('[id]'), input => input.id);
        assert.equal(new Set(ids).size, ids.length);
        children().find('.remove').first().trigger('click');
        assert.equal(w.$('[name="parents[0][children][0][_remove_]"]').val(), '1');
        assert.equal(w.$('[name="parents[0][_remove_]"]').val(), '0');
        assert.equal(w.$('[name="parents[1][children][0][_remove_]"]').val(), '0');
    } finally { w.close(); }
});
