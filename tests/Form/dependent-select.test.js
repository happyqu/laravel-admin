const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/dependent-select.php'], { cwd: root, encoding: 'utf8' }));

function page(...args) {
    const prepare = typeof args[0] === 'function' ? args.shift() : () => {};
    const views = args;
    const w = new JSDOM(views.map(view => view.html).join(''), {
        runScripts: 'outside-only', url: 'http://localhost/admin/records', pretendToBeVisual: true,
    }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
        'AdminLTE/plugins/select2/select2.full.min.js', 'AdminLTE/plugins/iCheck/icheck.min.js',
        'laravel-admin/dependent-select.js']) {
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
    prepare(w);
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

test('dependent options declared inside embeds() query the parent in their own scope', () => {
    const { embeddedConfig, embeddedEndpoint } = fixtures;
    assert.equal(embeddedConfig.parent, 'province_id');
    assert.equal(embeddedConfig.initialParent, '0');
    assert.equal(typeof embeddedConfig.url, 'string', 'an embeds() child must own a queryable parent scope');
    assert.match(embeddedConfig.url, /_select_options_\?/);
    assert.equal(embeddedEndpoint.first.status, 200);
    assert.deepEqual(embeddedEndpoint.first.items.map(item => item.id), ['a', 'b']);
    assert.deepEqual(embeddedEndpoint.second.items.map(item => item.id), ['c']);
    assert.deepEqual(embeddedEndpoint.outside, { status: 403, queries: [] });
    assert.ok(fixtures.embeddedForm.assets.some(asset => asset.includes('dependent-select.js')));

    const { w, requests } = page(fixtures.embeddedForm);
    try {
        const province = w.$('select[name="address[province_id]"]');
        const city = w.$('select[name="address[city_id]"]');
        assert.equal(city.val(), 'b');
        province.val('1').trigger('change');
        assert.equal(requests.length, 1);
        assert.equal(requests[0].value, '1');
        assert.equal(city.val(), '');
    } finally { w.close(); }
});

test('a child with no queryable parent keeps its saved value and never requests options', () => {
    const { unscopedConfig, unscopedHtml, unscopedQueries } = fixtures;

    // The parent list arrives over AJAX, so no parent ID is known server-side yet.
    assert.equal(unscopedConfig.static, true);
    assert.equal(unscopedConfig.url, undefined);
    assert.equal(unscopedConfig.initialParent, '0');
    assert.match(unscopedHtml, /<option value="b" selected>Beta<\/option>/);
    assert.equal(unscopedQueries.length, 1);
    assert.match(unscopedQueries[0][0], /`cities`\.`code` in \(\?\)/);

    const warnings = [];
    const { w, requests } = page(
        w => { w.console.warn = (...args) => warnings.push(args); },
        fixtures.unscopedForm
    );
    try {
        assert.equal(warnings.length, 1);
        assert.match(warnings[0][0], /fifth argument of optionsBy/);

        const province = w.$('select[name="province_id"]');
        const city = w.$('select[name="city_id"]');
        assert.equal(city.val(), 'b');
        province.val('1').trigger('change');
        assert.deepEqual(requests.filter(request => request.url.includes('_select_options_')), []);
        assert.deepEqual(ids(city), ['', 'b']);
        assert.equal(city.val(), 'b');
        assert.equal(city.siblings('[role="status"]').length, 0);
    } finally { w.close(); }
});

test('an ungrouped local options array is rejected instead of rendering empty groups', () => {
    assert.match(fixtures.flatGroups, /must be an array of \[childId => label\]/);
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

for (const [fixture, prefix] of [[fixtures.asyncForm, ''], [fixtures.asyncFilter, 'async_']]) {
    test(`a failed parent options request keeps the saved city (${prefix || 'form'})`, () => {
        const { w, requests } = page(fixture);
        try {
            const parent = w.$(`select[name="${prefix}province_id"]`);
            const city = w.$(`select[name="${prefix}city_id"]`);
            const form = city.closest('form')[0];
            assert.equal(city.val(), 'b');

            // The parent list never arrives, so its value stays empty.
            requests[0].reject();
            assert.equal(parent.val(), '');
            assert.equal(city.val(), 'b');
            assert.deepEqual(ids(city), ['', 'b']);
            assert.equal(city.attr('aria-busy'), undefined);
            assert.equal(new w.FormData(form).getAll(`${prefix}city_id`).at(-1), 'b');
            assert.equal(form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })), true);

            // Opening the child must not request options for an unknown parent.
            city.select2('open');
            assert.equal(requests.length, 1);
        } finally { w.close(); }
    });
}

test('a blocked submit keeps other submit listeners working and tells the user', () => {
    const { w, requests } = page(fixtures.asyncForm);
    try {
        const city = w.$('select[name="city_id"]');
        const form = city.closest('form')[0];
        const seen = [];
        const toast = [];
        w.$.admin.toastr = { warning: message => toast.push(message) };
        // Registered after the guard, so it only runs when propagation is not stopped.
        w.document.addEventListener('submit', () => seen.push('later-listener'), true);

        city.val('b').trigger('change');
        assert.equal(form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })), false);
        assert.deepEqual(seen, ['later-listener']);
        assert.equal(toast.length, 1);
        assert.ok(toast[0].length > 0);
        assert.deepEqual(requests.filter(request => request.url.includes('_select_options_')), []);
    } finally { w.close(); }
});

test('one malformed configuration does not stop the other selects from initializing', () => {
    const broken = {
        html: `<form><div class="fields-group">
            <select name="province_id" data-admin-select-column="province_id"><option value=""></option><option value="0">First</option></select>
            <select name="city_id" data-admin-select-column="city_id" data-admin-select-options-by="{not json"><option value=""></option></select>
            </div></form>`,
        scripts: [],
    };
    const errors = [];
    const { w, requests } = page(w => { w.console.error = (...args) => errors.push(args); }, broken);
    try {
        w.$.admin.initDependentSelects();
        assert.equal(errors.length, 1);
        assert.equal(w.$('select[name="city_id"]').data('adminDependentSelect'), undefined);
        assert.deepEqual(requests, []);
    } finally { w.close(); }

    // The same run still initializes a healthy dependent select.
    const { w: ok, requests: okRequests } = page(fixtures.fresh);
    try {
        assert.notEqual(ok.$('select[name="city_id"]').data('adminDependentSelect'), undefined);
        ok.$('select[name="province_id"]').val('0').trigger('change');
        assert.equal(okRequests.length, 1);
    } finally { ok.close(); }
});

test('a dependent select without a resolvable parent warns instead of failing silently', () => {
    const view = {
        html: `<form><div class="fields-group">
            <select name="city_id" data-admin-select-column="city_id" data-admin-select-options-by='{"parent":"province_id","initialParent":""}'>
                <option value=""></option>
            </select>
            </div></form>`,
        scripts: [],
    };
    const warnings = [];
    const { w } = page(w => { w.console.warn = (...args) => warnings.push(args); }, view);
    try {
        w.$.admin.initDependentSelects();
        assert.equal(warnings.length, 1);
        assert.match(warnings[0][0], /province_id/);
    } finally { w.close(); }
});
