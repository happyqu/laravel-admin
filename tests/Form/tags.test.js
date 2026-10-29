const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/tags.php'], { cwd: root, encoding: 'utf8' }));

function page(fixture) {
    const w = new JSDOM('<form>' + fixture.html + '</form>', { runScripts: 'outside-only', url: 'http://localhost/' }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/plugins/select2/select2.full.min.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    for (const script of fixture.scripts) w.eval(script);
    return w;
}

function close(w) {
    w.$('select').each(function () { if (w.$(this).data('select2')) w.$(this).select2('destroy'); });
    w.close();
}

function submitValues(w, name = 'labels[]') {
    return [...new w.FormData(w.document.querySelector('form')).getAll(name)].filter(value => value !== '');
}

function enter(w, select, term) {
    const field = w.$(select);
    field.select2('open');
    const input = field.next().find('.select2-search__field');
    input.trigger(w.$.Event('keydown', { which: 65, keyCode: 65 }));
    input.val(term).trigger('input');
    input.trigger(w.$.Event('keydown', { which: 13, keyCode: 13 }));
}

for (const [fixture, expected] of [
    ['oldNew', ['new']], ['oldCleared', []], ['defaultArray', ['preset']], ['defaultString', ['one', 'two']],
    ['numericText', ['01']], ['duplicateLabels', ['1']], ['oldPluck', ['2', 'new-key']], ['defaultPluck', ['2']],
]) {
    test(`tags submits the correct selection: ${fixture}`, () => {
        const w = page(fixtures[fixture]);
        try { assert.deepEqual(submitValues(w), expected); } finally { close(w); }
    });
}

test('text tags reject comma creation and predefined comma options with custom separators', () => {
    const w = page(fixtures.comma);
    try {
        const select = w.document.querySelector('select');
        enter(w, select, 'ACME,Inc');
        assert.deepEqual(submitValues(w), []);
        const instance = w.$(select).data('select2');
        instance.trigger('select', { data: { id: 'ACME,Inc', text: 'ACME,Inc' } });
        assert.deepEqual(submitValues(w), []);
        enter(w, select, 'valid-tag');
        assert.deepEqual(submitValues(w), ['valid-tag']);
    } finally { close(w); }
});

test('pluck mode can still create comma IDs because it stores an array', () => {
    const w = page(fixtures.commaPluck);
    try {
        enter(w, w.document.querySelector('select'), 'ACME,Inc');
        assert.deepEqual(submitValues(w), ['ACME,Inc']);
    } finally { close(w); }
});

test('tags added to a dynamic Table row stay separate from existing rows', () => {
    const w = page(fixtures.table);
    try {
        w.$('#has-many-rows .add').trigger('click');
        const select = w.document.querySelector('[name="rows[new_1][labels][]"]');
        assert.ok(w.$(select).data('select2'));
        enter(w, select, 'added');
        assert.deepEqual(submitValues(w, 'rows[new_1][labels][]'), ['added']);
        assert.deepEqual(submitValues(w, 'rows[0][labels][]'), ['first']);
        assert.deepEqual(submitValues(w, 'rows[1][labels][]'), ['second']);
    } finally { close(w); }
});

test('embedded tags read old input using the complete nested path', () => {
    const w = page(fixtures.embeds);
    try { assert.deepEqual(submitValues(w, 'profile[labels][]'), ['nested-new']); } finally { close(w); }
});
