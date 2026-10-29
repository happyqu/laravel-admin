const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {JSDOM} = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/cascade.php'], {cwd: root, encoding: 'utf8'}));
const jquery = fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8');

function page(html) {
    const w = new JSDOM(html, {runScripts: 'outside-only'}).window;
    w.eval(jquery);
    return w;
}

function choose(w, fixture, value, column = 'status') {
    const controls = w.$('.' + column);
    if (controls.is('select')) controls.val(value);
    else controls.prop('checked', false).filter('[value="' + value + '"]').prop('checked', true);
    controls.first().trigger(fixture.events[column]);
}

test('date and time pickers initialized in a hidden branch can open after it is revealed', () => {
    const fixture = fixtures.select;
    for (const format of ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss', 'HH:mm:ss']) {
        for (const altInput of [false, true]) {
            const w = page(fixture.html);
            try {
                for (const asset of ['laravel-admin/flatpickr-local.js', 'laravel-admin/flatpickr-locales.js', 'laravel-admin/datepicker.js']) {
                    w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
                }
                w.$('.status').val('2');
                w.eval(fixture.script);
                const input = w.document.querySelector('[name=extra]');
                const picker = w.$.admin.datepicker.init(input, {format, altInput, allowInput: false});
                picker.show();
                assert.equal(picker.instance.isOpen, false, 'a hidden disabled field cannot open');
                choose(w, fixture, '1');
                const visibleInput = picker.instance.altInput || input;
                assert.equal(visibleInput.disabled, false);
                visibleInput.dispatchEvent(new w.FocusEvent('focus'));
                assert.equal(picker.instance.isOpen, true, `${format}, altInput=${altInput}: focus opens`);
                picker.hide();
                picker.show();
                assert.equal(picker.instance.isOpen, true, 'calendar button API opens');
                picker.hide();
                choose(w, fixture, '2');
                choose(w, fixture, '1');
                assert.equal(visibleInput.disabled, false, 'repeated switching restores the visible input');
                picker.show();
                assert.equal(picker.instance.isOpen, true);
                const locked = w.$.admin.datepicker.init(w.document.querySelector('[name=locked]'), {format});
                locked.show();
                assert.equal(locked.instance.isOpen, false, 'permanently disabled fields stay locked');
                picker.destroy();
                locked.destroy();
            } finally { w.close(); }
        }
    }
});

for (const type of ['select', 'multipleSelect', 'belongsTo', 'belongsToMany', 'radio', 'radioButton', 'radioCard', 'checkbox', 'checkboxButton', 'checkboxCard']) {
    test(`${type}: conditional required controls recover after a failed submit without losing values`, () => {
        const fixture = fixtures[type];
        const w = page(fixture.html);
        try {
            w.eval(fixture.script);
            const form = w.document.querySelector('form');
            const extra = form.elements.namedItem('extra');
            const locked = form.elements.namedItem('locked');
            assert.equal(extra.disabled, false);
            assert.equal(form.checkValidity(), false);
            extra.value = 'kept';
            choose(w, fixture, '2');
            assert.equal(extra.disabled, true);
            assert.equal(form.checkValidity(), true);
            assert.equal(new w.FormData(form).has('extra'), false);
            form.addEventListener('submit', event => event.preventDefault());
            form.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
            choose(w, fixture, '1');
            assert.equal(extra.disabled, false);
            assert.equal(locked.disabled, true, 'original disabled state survives switching');
            assert.equal(new w.FormData(form).get('extra'), 'kept');
            extra.value = '';
            choose(w, fixture, '2');
            assert.equal(form.checkValidity(), true, 'hidden required inputs cannot block native submit');
            // Repeated initialization must retain only one handler for this instance.
            w.eval(fixture.script);
            choose(w, fixture, '1');
            assert.equal(extra.disabled, false);
        } finally { w.close(); }
    });
}

test('branches with equal values and different operators remain independent', () => {
    const fixture = fixtures.collision;
    const w = page(fixture.html);
    try {
        w.eval(fixture.script);
        const groups = [...w.document.querySelectorAll('.cascade-group')];
        assert.notEqual(groups[0].className, groups[1].className);
        for (const [value, expected] of [['1', [false, true]], ['19', [true, false]]]) {
            choose(w, fixture, value);
            assert.deepEqual(groups.map(group => !group.classList.contains('hide')), expected);
        }
    } finally { w.close(); }
});

test('numeric comparisons use numeric ordering for both initial state and changes', () => {
    const fixture = fixtures.numeric;
    const w = page(fixture.html);
    try {
        w.$('.status').val('10');
        w.eval(fixture.script);
        const visible = () => [...w.document.querySelectorAll('.cascade-group')].map(group => !group.classList.contains('hide'));
        assert.deepEqual(visible(), [true, false, true, false]);
        choose(w, fixture, '2');
        assert.deepEqual(visible(), [false, false, true, true]);
    } finally { w.close(); }
});

test('same-named controls in separate forms cannot affect each other', () => {
    const first = fixtures.select, second = fixtures.otherForm;
    const w = page(first.html + second.html);
    try {
        w.eval(first.script);
        w.eval(second.script);
        const forms = w.document.querySelectorAll('form');
        w.$(forms[0]).find('.status').val('2').trigger('change');
        assert.equal(forms[0].querySelector('[name=extra]').disabled, true);
        assert.equal(forms[1].querySelector('[name=extra]').disabled, false);
    } finally { w.close(); }
});

test('nested groups restore controls only when all ancestor conditions allow them', () => {
    const fixture = fixtures.nested;
    const w = page(fixture.html);
    try {
        w.eval(fixture.script);
        const extra = w.document.querySelector('[name=extra]');
        choose(w, fixture, '2', 'outer');
        assert.equal(extra.disabled, true);
        choose(w, fixture, '2', 'inner');
        choose(w, fixture, '1', 'outer');
        assert.equal(extra.disabled, true, 'inner branch remains inactive');
        choose(w, fixture, '1', 'inner');
        assert.equal(extra.disabled, false);
        assert.equal(w.document.querySelector('.inner').disabled, false);
    } finally { w.close(); }
});

test('iCheck controls retain their enabled state when a parent branch is restored', () => {
    const fixture = fixtures.nestedCheckbox;
    const w = page(fixture.html);
    try {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/iCheck/icheck.min.js'), 'utf8'));
        w.$('.inner').iCheck({checkboxClass: 'icheckbox_minimal-blue'});
        w.eval(fixture.script);
        choose(w, fixture, '2', 'outer');
        assert.equal(w.$('.inner').first().prop('disabled'), true);
        choose(w, fixture, '1', 'outer');
        assert.equal(w.$('.inner').first().prop('disabled'), false);
        assert.equal(w.$('.inner').first().parent().hasClass('disabled'), false);
        w.$('.inner').first().iCheck('uncheck');
        assert.equal(w.document.querySelector('[name=extra]').disabled, true);
        w.$('.inner').first().iCheck('check');
        assert.equal(w.document.querySelector('[name=extra]').disabled, false);
    } finally { w.close(); }
});

for (const type of ['BelongsTo', 'BelongsToMany']) {
    test(`${type} removal triggers the dependent condition immediately`, () => {
        const fixture = fixtures[type === 'BelongsTo' ? 'belongsTo' : 'belongsToMany'];
        const html = fixture.html.replace('</form>', '<div class="' + type.toLowerCase() + '-status"><table class="grid-table"><tbody><tr><td><a class="grid-row-remove" data-key="1">remove</a></td></tr></tbody></table><template class="empty"><tr><td>empty</td></tr></template></div></form>');
        const w = page(html);
        try {
            w.$.fn.select2 = function () { return this; };
            w.eval(fixture.script);
            // The real picker removal script, with the fixture column substituted.
            w.eval(fixtures['remove' + type].replace(/related/g, 'status'));
            assert.equal(w.document.querySelector('[name=extra]').disabled, false);
            w.$('.grid-row-remove').trigger('click');
            assert.equal(w.document.querySelector('[name=extra]').disabled, true);
        } finally { w.close(); }
    });
}
