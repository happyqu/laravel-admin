const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const scripts = JSON.parse(execFileSync('php', ['tests/Form/fixtures/complex-field-scripts.php'], {
    cwd: root, encoding: 'utf8',
}));

function page(html, select2 = false) {
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
    const w = dom.window;
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    if (select2) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/select2/select2.full.min.js'), 'utf8'));
    }
    w.$.fn.tab = function () { return this; };
    return w;
}

function close(w) {
    w.$('select').each(function () {
        if (w.$(this).data('select2')) w.$(this).select2('destroy');
    });
    w.close();
}

for (const mode of ['default', 'table', 'tab']) {
    test(`${mode} hasMany avoids restored row keys and duplicate handlers`, () => {
        const field = '<input name="rows[new___LA_KEY__][title]" value="new">';
        const w = page(`<form><div id="has-many-rows">
          <div class="header"><button type="button" class="add">Add</button></div><ul class="nav"></ul>
          <div class="tab-content has-many-rows-forms">
            <div class="has-many-rows-form"><input name="rows[new_1][title]" value="one"></div>
            <div class="has-many-rows-form"><input name="rows[new_3][title]" value="three"></div>
          </div>
          <template class="rows-tpl"><div class="has-many-rows-form">${field}</div></template>
          <template class="nav-tab-tpl"><li><a href="#rows_new___LA_KEY__">New</a></li></template>
          <template class="pane-tpl"><div id="rows_new___LA_KEY__">${field}</div></template>
        </div></form>`);
        try {
            w.eval(scripts[mode]);
            w.eval(scripts[mode]);
            for (let i = 0; i < 3; i++) w.$('.add').trigger('click');
            const names = [...w.document.querySelectorAll('input')].map(input => input.name);
            assert.equal(names.length, 5);
            assert.equal(new Set(names).size, 5);
            assert.equal(w.$('input').first().val(), 'one');
        } finally { close(w); }
    });
}

test('deleting a tab releases native required constraints on all controls', () => {
    const w = page(`<form><div id="has-many-rows"><ul class="nav"><li class="active">
      <a href="#rows_7">Row</a><i class="close-tab"></i></li></ul>
      <div class="tab-content"><div class="tab-pane active" id="rows_7">
        <input name="rows[7][title]" required><textarea required></textarea>
        <select required><option value="">Choose</option></select>
        <input class="fom-removed" name="rows[7][_remove_]" value="0">
      </div></div></div></form>`);
    try {
        assert.equal(w.document.querySelector('form').checkValidity(), false);
        w.eval(scripts.tab);
        w.$('.close-tab').trigger('click');
        assert.equal(w.$('.fom-removed').val(), '1');
        assert.equal(w.document.querySelector('form').checkValidity(), true);
    } finally { close(w); }
});

test('tags allow Enter creation without adding options to ordinary multipleSelect', () => {
    const w = page('<select class="labels" multiple></select><select id="roles" multiple><option value="1">Admin</option></select>', true);
    try {
        w.$('#roles').select2();
        w.eval(scripts.tags);
        w.$('.labels').select2('open');
        const input = w.$('.labels').next().find('.select2-search__field');
        input.val('new-tag').trigger('input');
        input.trigger(w.$.Event('keydown', { which: 13, keyCode: 13 }));
        assert.deepEqual(Array.from(w.$('.labels').val() || []), ['new-tag']);
        const rolesInput = w.$('#roles').next().find('.select2-search__field');
        rolesInput.val('unexpected-role').trigger(w.$.Event('keyup', { keyCode: 13 }));
        assert.equal(w.$('#roles option').length, 1);
        assert.equal(w.$('#roles').val(), null);
    } finally { close(w); }
});

test('ajax select renders remote labels as text', () => {
    const w = page('<select class="owner"></select>', true);
    try {
        w.eval(scripts.select);
        const li = w.document.createElement('li');
        const payload = '<img src=x onerror="window.reviewXss=1">';
        w.$('.owner').data('select2').results.template({ id: 1, text: payload }, li);
        assert.equal(li.querySelector('img'), null);
        assert.equal(li.textContent, payload);
    } finally { close(w); }
});

test('collection editors isolate nested rows and retain an empty submission marker', () => {
    const editor = key => `<div data-collection-field id="${key}">
      <input type="hidden" name="rows[${key}][items][_present]" value="1">
      <table><tbody data-collection-body></tbody></table><button data-collection-add>Add</button>
      <template data-collection-template><tr><td><input name="rows[${key}][items][values][]"><button data-collection-remove>Remove</button></td></tr></template>
    </div>`;
    const w = page(`<form>${editor('new_1')}${editor('new_2')}</form>`);
    try {
        w.eval(scripts.collection);
        w.eval(scripts.collection);
        w.$('#new_1 [data-collection-add]').trigger('click');
        assert.equal(w.$('#new_1 tbody tr').length, 1);
        assert.equal(w.$('#new_2 tbody tr').length, 0);
        w.$('#new_1 [data-collection-remove]').trigger('click');
        const data = new w.FormData(w.document.querySelector('form'));
        assert.equal(w.$('#new_1 tbody tr').length, 0);
        assert.equal(data.get('rows[new_1][items][_present]'), '1');
        assert.equal(data.get('rows[new_1][items][values][]'), null);
    } finally { close(w); }
});

test('checkbox buttons toggle once with multiple fields and support new nested rows', () => {
    const w = page('<div class="checkbox-group-toggle"><label><input type="checkbox" value="x"></label></div>');
    try {
        w.eval(scripts.checkboxButton);
        w.eval(scripts.checkboxButton);
        w.$('label').trigger('click');
        assert.equal(w.$('input').prop('checked'), true);
        w.$('label').trigger('click');
        assert.equal(w.$('input').prop('checked'), false);
        w.$('body').append('<div class="checkbox-group-toggle"><label id="added"><input type="checkbox"></label></div>');
        w.$('#added').trigger('click');
        assert.equal(w.$('#added input').prop('checked'), true);
    } finally { close(w); }
});

test('array cascade initial values and clearing a multiSelect do not throw', () => {
    const w = page('<select class="choices" multiple><option value="x" selected>X</option></select><div class="cascade-group cascade-choices-78 hide"></div>');
    try {
        w.eval(scripts.cascade);
        assert.equal(w.$('.cascade-group').hasClass('hide'), false);
        w.$('.choices').val(null).trigger('change');
        assert.equal(w.$('.cascade-group').hasClass('hide'), true);
    } finally { close(w); }
});

test('multiple select loads keep their own endpoints and target fields', () => {
    const w = page('<div class="fields-group"><select class="first"><option value="1">1</option></select><select class="second"><option value="2">2</option></select><select class="firstChild"></select><select class="secondChild"></select></div>');
    try {
        const calls = [];
        w.$.get = url => { calls.push(url); return { then() {} }; };
        w.eval(scripts.loads);
        w.$('.first').trigger('change');
        w.$('.second').trigger('change');
        assert.deepEqual(calls, ['/api/first?q=1', '/api/second?q=2']);
    } finally { close(w); }
});

test('listbox remote options treat labels and keys as text', () => {
    const w = page('<select class="items" data-value="safe"></select>');
    try {
        w.$.fn.bootstrapDualListbox = function () { return this; };
        w.$.ajax = () => ({ done(callback) { callback({ safe: '<img src=x onerror="alert(1)">' }); } });
        w.eval(scripts.listbox);
        assert.equal(w.$('.items option').text(), '<img src=x onerror="alert(1)">');
        assert.equal(w.document.querySelector('img'), null);
        assert.equal(w.$('.items').val(), 'safe');
    } finally { close(w); }
});

test('relation modals exclude passwords and tokens from GET context and use their own form', () => {
    const w = page(`<form><input name="unrelated" value="wrong"></form>
      <form><input type="password" name="secret" value="sensitive"><input name="_token" value="csrf">
      <input name="country" value="cn"><div class="belongstomany-related"><div class="grid-table"><tbody></tbody></div></div></form>
      <div id="related-modal"></div>`);
    try {
        const calls = [];
        w.$.get = url => { calls.push(url); };
        w.eval(scripts.belongsToMany);
        w.$('#related-modal').trigger('show.bs.modal');
        assert.deepEqual(calls, ['/admin/selectable?multiple=1&country=cn']);
    } finally { close(w); }
});
