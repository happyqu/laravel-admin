const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Grid/fixtures/filter.php'], { cwd: root, encoding: 'utf8' }));

function page(html) {
    const w = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/admin/records' }).window;
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/select2/select2.full.min.js'), 'utf8'));
    return w;
}

test('filter AJAX results and selections render untrusted labels as text', () => {
    const fixture = fixtures.ajax;
    const w = page(fixture.html);
    try {
        w.eval(fixture.script);
        const select = w.$('.owner').data('select2');
        const payload = '<img src=x onerror="window.filterXss=1">';
        for (const adapter of [select.results, select.selection]) {
            const node = w.document.createElement('span');
            if (adapter === select.results) adapter.template({ id: 1, text: payload }, node);
            else w.$(node).append(adapter.display({ id: 1, text: payload }, node));
            assert.equal(node.querySelector('img'), null);
            assert.equal(node.textContent, payload);
        }
    } finally { w.close(); }
});

test('remote filter options keep a selected zero value', () => {
    const fixture = fixtures.remoteZero;
    const w = page(fixture.html);
    try {
        w.$.ajax = () => ({ done(callback) { callback([{ id: '0', text: 'Disabled' }, { id: '1', text: 'Enabled' }]); } });
        w.eval(fixture.script);
        assert.equal(w.$('.status').val(), '0');
        assert.equal(new w.FormData(w.document.querySelector('form')).get('status'), '0');
    } finally { w.close(); }
});

test('date and range filters initialize and constrain only their own named grid', () => {
    const first = fixtures.dates_first;
    const second = fixtures.dates_second;
    const w = page(first.html + second.html);
    try {
        for (const asset of ['laravel-admin/flatpickr-local.js', 'laravel-admin/flatpickr-locales.js', 'laravel-admin/datepicker.js']) {
            w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
        }
        w.eval(first.script);
        w.eval(second.script);
        const picker = id => w.$('#' + id).data('adminDatepicker');
        for (const id of ['first_created_at', 'first_updated_at_start', 'first_updated_at_end',
            'second_created_at', 'second_updated_at_start', 'second_updated_at_end']) assert.ok(picker(id), id);
        picker('first_updated_at_start').date('2026-10-28 10:00:00');
        picker('second_updated_at_end').date('2026-10-29 11:00:00');
        assert.equal(picker('first_updated_at_end').instance.config.minDate.getDate(), 28);
        assert.equal(picker('second_updated_at_start').instance.config.maxDate.getDate(), 29);
        assert.equal(picker('second_updated_at_end').instance.config.minDate, undefined);
        assert.equal(picker('first_updated_at_start').instance.config.maxDate, undefined);

    } finally { w.close(); }
});

test('group operators survive rendering and edits stay inside the current grid', () => {
    const first = fixtures.group_first;
    const second = fixtures.group_second;
    const w = page(first.html + second.html);
    try {
        w.eval(first.script);
        w.eval(second.script);
        assert.equal(w.$('[name="first_age_group"]').val(), '1');
        assert.equal(w.$('[name="second_age_group"]').val(), '1');
        w.$('.first_age-filter-group a[data-index="0"]').trigger('click');
        assert.equal(w.$('.first_age-filter-group-label').text().trim(), 'Equals');
        assert.equal(w.$('.second_age-filter-group-label').text(), 'Greater');
        assert.equal(w.$('[name="first_age_group"]').val(), '0');
        assert.equal(w.$('[name="second_age_group"]').val(), '1');
        assert.equal(new w.FormData(w.document.querySelector('#second-filter-box form')).get('second_age_group'), '1');
    } finally { w.close(); }
});

for (const type of ['single', 'multiple']) {
    for (const name of ['', 'first']) {
        test(`${type} cascades keep multiple targets and replace only a repeated target (${name || 'unnamed'})`, () => {
            const fixture = fixtures[`cascade_multitarget_${type}_${name}`];
            const w = page(fixture.html);
            try {
                const prefix = name ? `${name}_` : '';
                const requests = [];
                w.$.get = (url, data, callback) => {
                    requests.push([url, data.q]);
                    callback([{ id: 'new', text: url }]);
                };
                w.eval(fixture.script);
                w.eval(fixture.script);
                const source = w.$(`.${prefix}country`);
                source.val(type === 'multiple' ? ['us', 'ca'] : 'us').trigger('change');
                const query = type === 'multiple' ? 'us,ca' : 'us';
                assert.deepEqual(requests, [['/states', query], ['/cities', query]]);
                assert.equal(w.$(`.${prefix}city option[value="new"]`).text(), '/cities');
                assert.equal(w.$(`.${prefix}state option[value="new"]`).text(), '/states');
                w.$(w.document).off('.adminPage');
                source.trigger('change');
                assert.equal(requests.length, 2);
            } finally { w.close(); }
        });
    }

    test(`${type} cascades update only their named grid target without duplicate requests`, () => {
        const first = fixtures[`cascade_${type}_first`];
        const second = fixtures[`cascade_${type}_second`];
        const w = page(first.html + second.html);
        try {
            const requests = [];
            w.$.get = (url, data, callback) => { requests.push([url, data.q]); callback([{ id: 'ny', text: '<b>New York</b>' }]); };
            w.eval(first.script);
            w.eval(first.script);
            w.eval(second.script);
            w.$('.first_country').val(type === 'multiple' ? ['us', 'ca'] : 'us').trigger('change');
            assert.deepEqual(requests, [['/cities', type === 'multiple' ? 'us,ca' : 'us']]);
            assert.equal(w.$('.first_city option[value="ny"]').length, 1);
            assert.equal(w.$('.first_city option[value="ny"]').text(), '<b>New York</b>');
            assert.equal(w.document.querySelector('.first_city b'), null);
            assert.equal(w.$('.second_city option[value="ny"]').length, 0);
        } finally { w.close(); }
    });

    test(`${type} cascades still support an unnamed grid`, () => {
        const fixture = fixtures[`cascade_${type}_`];
        const w = page(fixture.html);
        try {
            w.$.get = (url, data, callback) => callback([{ id: 'ny', text: 'New York' }]);
            w.eval(fixture.script);
            w.$('.country').val(type === 'multiple' ? ['us'] : 'us').trigger('change');
            assert.equal(w.$('.city option[value="ny"]').length, 1);
        } finally { w.close(); }
    });
}
