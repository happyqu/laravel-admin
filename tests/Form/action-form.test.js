const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const payloads = JSON.parse(execFileSync('php', ['tests/Form/fixtures/action-form.php'], { cwd: root, encoding: 'utf8' }));
const read = name => fs.readFileSync(path.join(root, name), 'utf8');

test('date fields open from the calendar icon and from an action input without a group', () => {
    const dom = new JSDOM(payloads[0].html, { runScripts: 'outside-only', url: 'http://localhost/admin/items' });
    const w = dom.window;
    w.eval(read('resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'));
    w.eval(read('resources/assets/laravel-admin/flatpickr-local.js'));
    w.eval(read('resources/assets/laravel-admin/flatpickr-locales.js'));
    w.eval(read('resources/assets/laravel-admin/datepicker.js'));
    const $ = w.jQuery;
    const input = $('[name="start"]');
    $('.modal-body').css('position', 'relative');
    const group = $('<div class="input-group" style="position:relative"><span class="input-group-addon"><i class="fa fa-calendar"></i></span><input></div>').appendTo(w.document.body);
    group.find('input').attr('class', input.attr('class'));
    $('<span>').attr('class', input.attr('class')).appendTo(w.document.body);
    let iconOpened = 0;
    let inputOpened = 0;
    group.on('dp.show', () => iconOpened++);
    input.on('dp.show', () => inputOpened++);
    try {
        w.eval(payloads[0].scripts.find(script => script.includes('.adminDatepicker(')));
        assert.ok(group.data('DateTimePicker'));
        assert.ok(input.data('DateTimePicker'));
        group.find('.input-group-addon').trigger('click');
        assert.equal(iconOpened, 1);
        group.data('DateTimePicker').hide();
        input.trigger('focus');
        assert.equal(inputOpened, 1);
    } finally {
        if (group.data('DateTimePicker')) group.data('DateTimePicker').destroy();
        if (input.data('DateTimePicker')) input.data('DateTimePicker').destroy();
        w.close();
    }
});

test('action widgets isolate their controls, nested rows and cascading options from other forms', () => {
    const dom = new JSDOM('<form id="page"><input class="title"><select class="source"><option>Page</option></select>' +
        '<div id="has-many-rows"><div class="has-many-rows-forms"><input class="rows title" value="Page"></div><button type="button" class="add">Add</button></div></form>' +
        payloads.map(p => p.html).join(''), { runScripts: 'outside-only', url: 'http://localhost/admin/items' });
    const w = dom.window;
    w.eval(read('resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'));
    w.eval(read('resources/assets/AdminLTE/plugins/select2/select2.full.min.js'));
    const $ = w.jQuery;
    const calls = { inputmask: [], adminDatepicker: [], fileinput: [], iCheck: [] };
    Object.keys(calls).forEach(plugin => {
        $.fn[plugin] = function () { calls[plugin].push(...this.toArray()); return this; };
    });
    const requests = [];
    $.get = (url, data, done) => { requests.push({ url, data }); done([{ id: 3, text: 'Three' }]); };
    try {
        payloads.forEach(p => p.scripts.forEach(script => w.eval(script)));
        const modals = $('.modal');
        assert.equal(modals.length, 2);
        assert.equal($('#page .select2-container').length, 0);
        assert.equal($('input[name="title"]').next('.select2-container').length, 0);
        assert.equal($('input[name="record_id"]').filter('[type="hidden"]').length, 2);
        assert.equal(calls.fileinput.length, 8);
        assert.ok(calls.fileinput.every(input => input.type === 'file'));
        assert.equal(new Set(calls.adminDatepicker).size, 4);
        assert.ok(calls.adminDatepicker.every(node => $(node).is('input') || $(node).find('input').length === 1));
        assert.ok(calls.inputmask.every(input => input.name.endsWith('quantity]') || input.name === 'quantity'));
        assert.ok(calls.iCheck.every(input => ['checkbox', 'radio'].includes(input.type)));
        const ids = $('[id]').map(function () { return this.id; }).get();
        assert.equal(new Set(ids).size, ids.length, 'Rendered fields must not duplicate IDs');
        const first = modals.first();
        const second = modals.last();
        first.find('[name="source"]').val('1').trigger('change');
        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, '/options');
        assert.equal(first.find('[name="target[]"] option[value="3"]').length, 1);
        assert.equal(second.find('[name="target[]"] option[value="3"]').length, 0);
        assert.equal(($._data(w.document, 'events').change || []).length, 0, 'Action cascade handlers belong to the form');
        assert.equal($._data(w.document, 'events')['select2:opening'].length, 1, 'Readonly handler is registered once');
        const firstTable = first.find('[id^="has-many-"]');
        const secondTable = second.find('[id^="has-many-"]');
        firstTable.find('.add').trigger('click').trigger('click');
        assert.equal(firstTable.find('tbody > tr').length, 4);
        assert.equal(secondTable.find('tbody > tr').length, 2);
        assert.equal($('#has-many-rows .has-many-rows-forms input').val(), 'Page');
        assert.equal(firstTable.find('tbody select').length, 4);
        assert.equal(firstTable.find('tbody .select2-container').length, 4);
        const names = firstTable.find('tbody input[name$="[title]"]').map(function () { return this.name; }).get();
        assert.equal(new Set(names).size, names.length);
    } finally {
        $('select').each(function () { if ($(this).data('select2')) $(this).select2('destroy'); });
        w.close();
    }
});
