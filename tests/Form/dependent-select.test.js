const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/dependent-select.php'], { cwd: root, encoding: 'utf8' }));

function page(...views) {
    const w = new JSDOM(views.map(view => view.html).join(''), {
        runScripts: 'outside-only', url: 'http://localhost/admin/records', pretendToBeVisual: true,
    }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
        'AdminLTE/plugins/select2/select2.full.min.js', 'laravel-admin/dependent-select.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    const requests = [];
    w.$.ajax = ({ url, data = {} }) => {
        const response = {
            url, value: data.parent,
            done(callback) { this.resolve = callback; return this; },
            fail(callback) { this.reject = callback; return this; },
            abort() { this.aborted = true; this.reject(); },
        };
        requests.push(response);
        return response;
    };
    for (const view of views) for (const script of view.scripts) w.eval(script);
    return { w, requests };
}

const ids = city => Array.from(city.find('option'), option => option.value);

test('the built-in option route only accepts parent IDs in its server-issued configuration', () => {
    const { first, second, tampered, invalidParent, missingTicket, outsideFormScope, outsideFilterScope } = fixtures.endpoint;
    assert.equal(first.status, 200);
    assert.deepEqual(first.items, [{ id: 'a', text: '<b>Alpha</b>' }, { id: 'b', text: 'Beta' }]);
    assert.deepEqual(second.items, [{ id: 'c', text: 'Gamma' }]);
    assert.deepEqual(first.queries[0][1], ['0', true]);
    assert.deepEqual(second.queries[0][1], ['1', true]);
    assert.match(first.queries[0][0], /where `cities`\.`parent_id` = \? and `visible` = \?/);
    assert.deepEqual(tampered, first);
    assert.deepEqual([outsideFormScope, outsideFilterScope, invalidParent, missingTicket], [
        { status: 403, queries: [] }, { status: 403, queries: [] },
        { status: 422, queries: [] }, { status: 419, queries: [] },
    ]);
    for (const view of [fixtures.restrictedForm, fixtures.restrictedFilter]) {
        assert.deepEqual(view.queries, []);
        assert.doesNotMatch(view.html, /Gamma/);
    }
});

test('form query starts on province selection and stale responses cannot overwrite a later choice', () => {
    assert.deepEqual(fixtures.fresh.queries, []);
    assert.ok(fixtures.fresh.assets.some(asset => asset.includes('dependent-select.js')));
    const { w, requests } = page(fixtures.fresh);
    try {
        const province = w.$('select[name="province_id"]');
        const city = w.$('select[name="city_id"]');
        city.select2('open');
        assert.equal(requests.length, 0);
        assert.equal(city.data('select2').isOpen(), false);

        province.val('0').trigger('change').val('1').trigger('change').val('0').trigger('change');
        assert.deepEqual(requests.map(request => request.value), ['0', '1', '0']);
        assert.equal(new URL(requests[0].url).pathname, '/admin/_select_options_');
        assert.ok(requests[0].aborted && requests[1].aborted);
        requests[2].resolve(fixtures.endpoint.first.items);
        requests[1].resolve(fixtures.endpoint.second.items);
        requests[0].resolve([{ id: 'obsolete', text: 'Old reply' }]);
        assert.deepEqual(ids(city), ['', 'a', 'b']);
        assert.equal(city.find('option[value="a"]').text(), '<b>Alpha</b>');
        assert.equal(city.data('select2').options.get('minimumResultsForSearch'), 99);

        city.val('a').trigger('change');
        province.val('1').trigger('change');
        assert.equal(city.val(), '');
        province.val('').trigger('change');
        requests[3].resolve(fixtures.endpoint.second.items);
        assert.deepEqual(ids(city), ['']);
        assert.equal(requests.length, 4);
        assert.equal(city.attr('aria-busy'), undefined);
    } finally { w.close(); }
});

test('form edit renders only the saved option and expands the list when the child is opened', () => {
    const { edit, defaults, mismatch, oldInput } = fixtures;
    assert.equal(edit.queries.length, 1);
    assert.match(edit.queries[0][0], /and `cities`\.`code` in \(\?\)/);
    assert.deepEqual(edit.queries[0][1], ['0', 'b', true]);
    assert.match(defaults.queries[0][0], /`cities`\.`name`/);
    assert.match(defaults.html, /Default A/);
    assert.doesNotMatch(mismatch.html, /<option value="b"/);
    assert.deepEqual(oldInput.queries[0][1], ['1', 'c', true]);
    assert.match(oldInput.html, /<option value="c" selected>Gamma<\/option>/);
    const { w, requests } = page(edit);
    try {
        const city = w.$('select[name="city_id"]');
        assert.equal(requests.length, 0);
        assert.deepEqual(ids(city), ['', 'b']);
        assert.equal(city.val(), 'b');
        city.select2('open');
        assert.equal(requests[0].value, '0');
        requests[0].resolve(fixtures.endpoint.first.items);
        assert.deepEqual(ids(city), ['', 'a', 'b']);
        assert.equal(city.val(), 'b');
        assert.equal(city.data('select2').isOpen(), true);
    } finally { w.close(); }
});

test('named filters restore URL values and link across columns without updating another grid', () => {
    const { firstFilter, secondFilter } = fixtures;
    assert.deepEqual(firstFilter.queries[0][1], ['0', 'b', true]);
    assert.deepEqual(secondFilter.queries, []);
    const { w, requests } = page(firstFilter, secondFilter);
    try {
        const first = w.$('select[name="first_city_id"]');
        const second = w.$('select[name="second_city_id"]');
        assert.equal(first.val(), 'b');
        assert.equal(requests.length, 0);
        w.$('select[name="second_province_id"]').val('1').trigger('change');
        assert.equal(requests[0].value, '1');
        requests[0].reject();
        assert.ok(second.siblings('[role="status"]').text());
        second.select2('open');
        assert.equal(requests[1].value, '1');
        requests[1].resolve(fixtures.endpoint.second.items);
        assert.deepEqual(ids(second), ['', 'c']);
        assert.equal(first.val(), 'b');
        assert.deepEqual(ids(first), ['', 'b']);

        w.$('select[name="first_province_id"]').val('').trigger('change');
        const query = new w.FormData(first.closest('form')[0]);
        assert.equal(query.get('first_city_id'), '');
        assert.equal(requests.length, 2);
    } finally { w.close(); }
});

for (const [fixture, prefix] of [[fixtures.asyncForm, ''], [fixtures.asyncFilter, 'async_']]) {
    test(`initial remote parent options preserve the selected city (${prefix || 'form'})`, async () => {
        const { w, requests } = page(fixture);
        try {
            const parent = w.$(`select[name="${prefix}province_id"]`);
            const city = w.$(`select[name="${prefix}city_id"]`);
            const form = city.closest('form')[0];
            assert.equal(city.val(), 'b');
            assert.equal(form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })), false);
            city.select2('open');
            assert.equal(requests.length, 1);
            requests[0].resolve([{ id: '0', text: 'First' }, { id: '1', text: 'Second' }]);
            assert.equal(parent.val(), '0');
            assert.equal(city.val(), 'b');
            assert.equal(requests.length, 1);

            parent.val('1').trigger('change');
            assert.equal(city.val(), '');
            assert.equal(requests[1].value, '1');
            form.reset();
            await new Promise(resolve => w.setTimeout(resolve, 0));
            assert.ok(requests[1].aborted);
            assert.equal(parent.val(), '0');
            assert.equal(city.val(), 'b');
            assert.equal(form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })), true);
        } finally { w.close(); }
    });
}

test('reset restores the original province and city and discards a pending options request', async () => {
    const { w, requests } = page(fixtures.edit);
    try {
        const province = w.$('select[name="province_id"]');
        const city = w.$('select[name="city_id"]');
        const reset = async () => {
            city.closest('form')[0].reset();
            await new Promise(resolve => w.setTimeout(resolve, 0));
        };
        city.select2('open');
        requests[0].resolve(fixtures.endpoint.first.items);
        await reset();
        assert.equal(city.val(), 'b');

        province.val('1').trigger('change');
        assert.equal(city.val(), '');
        await reset();
        requests[1].resolve(fixtures.endpoint.second.items);
        assert.ok(requests[1].aborted);
        assert.equal(province.val(), '0');
        assert.equal(city.val(), 'b');
        assert.deepEqual(ids(city), ['', 'b']);
        assert.equal(city.next().find('.select2-selection__rendered').text().includes('Beta'), true);
        assert.equal(requests.length, 2);
    } finally { w.close(); }
});
