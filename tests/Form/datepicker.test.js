const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, 'resources/assets', name), 'utf8');

function page(html = '<input id="date" name="date">', editable = false, beforeLoad = () => {}) {
    const w = new JSDOM('<div id="admin-content"><form>' + html + '</form></div>', {
        runScripts: 'outside-only', url: 'http://localhost/admin/items', pretendToBeVisual: true,
    }).window;
    beforeLoad(w);
    w.eval(read('AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'));
    if (editable) {
        w.eval(read('AdminLTE/bootstrap/js/bootstrap.min.js'));
        w.eval(read('bootstrap3-editable/js/bootstrap-editable.min.js'));
    }
    w.eval(read('laravel-admin/flatpickr-local.js'));
    w.eval(read('laravel-admin/flatpickr-locales.js'));
    w.eval(read('laravel-admin/datepicker.js'));
    w.finish = () => { w.$.admin.datepicker.destroy(); w.close(); };
    return w;
}

for (const offset of [-8, 0, 8]) {
    test(`calendar navigation and selected dates survive a ${offset}h constructor offset`, () => {
        let browserDate;
        const w = page(undefined, false, window => {
            window.eval(`window.Date = new Proxy(Date, {
                construct: function (Target, args) {
                    var date = Reflect.construct(Target, args);
                    if (args.length > 1) date.setHours(date.getHours() + ${offset});
                    return date;
                }
            });`);
            browserDate = window.Date;
        });
        try {
            const picker = w.$.admin.datepicker.init(w.document.querySelector('#date'), {locale: 'zh-CN'});
            const fp = picker.instance;
            picker.show();
            assert.equal(picker.input.value, '');
            fp.jumpToDate('2026-08-01', true);
            for (let month = 7; month <= 12; month++) {
                const year = month === 12 ? 2027 : 2026;
                const actualMonth = month % 12;
                const expectedDays = [31, 30, 31, 30, 31, 31][month - 7];
                assert.equal(fp.currentYear, year);
                assert.equal(fp.currentMonth, actualMonth);
                const cells = [...fp.daysContainer.querySelectorAll('.flatpickr-day')];
                const current = cells.filter(c => !c.classList.contains('prevMonthDay') && !c.classList.contains('nextMonthDay'));
                assert.equal(current.length, expectedDays);
                for (const cell of cells) {
                    const date = cell.dateObj;
                    assert.equal(Number(cell.textContent), date.getDate());
                    assert.equal(cell.classList.contains('prevMonthDay'), date.getFullYear() < year || (date.getFullYear() === year && date.getMonth() < actualMonth));
                    assert.equal(cell.classList.contains('nextMonthDay'), date.getFullYear() > year || (date.getFullYear() === year && date.getMonth() > actualMonth));
                    assert.equal(date.getDay(), (cells.indexOf(cell) + fp.l10n.firstDayOfWeek) % 7);
                }
                // Covers August 30 and November 10, both reported as wrong months.
                const day = actualMonth === 7 ? 30 : 10;
                current[day - 1].click();
                assert.equal(picker.input.value, `${year}-${String(actualMonth + 1).padStart(2, '0')}-${day}`);
                assert.equal(new w.FormData(w.document.querySelector('form')).get('date'), picker.input.value);
                picker.show();
                fp.calendarContainer.querySelector('.admin-datepicker-next').click();
            }
            fp.jumpToDate('2024-02-01', true);
            const leapDay = [...fp.daysContainer.querySelectorAll('.flatpickr-day')]
                .find(c => c.dateObj.getMonth() === 1 && c.dateObj.getDate() === 29);
            leapDay.click();
            assert.equal(picker.input.value, '2024-02-29');
            assert.equal(w.Date, browserDate, 'component does not replace the browser Date');
        } finally { w.finish(); }
    });
}

test('legacy formats retain submitted date, datetime, time, year and month values', () => {
    const w = page();
    try {
        for (const [format, value] of [['YYYY-MM-DD', '2024-02-29'], ['YYYY-MM-DD HH:mm:ss', '2026-10-28 23:59:58'],
            ['HH:mm:ss', '23:59:58'], ['YYYY', '2026'], ['MM', '10'], ['DD', '28'],
            ['YYYY-MM-DD hh:mm:ss A', '2026-10-28 01:05:06 PM'],
            ['DD/MM/YYYY', '28/10/2026'], ['YYYY-MM-DD[T]HH:mm:ss', '2026-10-28T09:08:07']]) {
            const picker = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format });
            picker.date(value);
            assert.equal(picker.input.value, value, format);
            assert.equal(picker.date().format(format), value, format);
            assert.equal(new w.FormData(w.document.querySelector('form')).get('date'), value);
            assert.equal(w.moment, undefined);
            picker.destroy();
        }
    } finally { w.finish(); }
});

test('date formatting preserves bracket literals and rejects unsupported tokens explicitly', () => {
    const w = page();
    try {
        assert.equal(w.$.admin.datepicker.convertFormat('YYYY-MM-DD [at] HH:mm:ss'), 'Y-m-d \\a\\t H:i:S');
        assert.throws(() => w.$.admin.datepicker.convertFormat('YYYY-[Q]Q'), /Unsupported date format token/);
        assert.throws(() => w.$('#date').adminDatepicker({ timeZone: 'Asia/Shanghai' }), /not supported/);
    } finally { w.finish(); }
});

test('focusing an empty date field does not fill it or enable browser autofill', () => {
    const w = page();
    try {
        for (const options of [
            {format: 'YYYY-MM-DD'},
            {format: 'YYYY-MM-DD HH:mm:ss'},
            {format: 'HH:mm:ss'},
            {format: 'YYYY-MM-DD', mode: 'multiple'},
            {format: 'YYYY-MM-DD', altInput: true},
        ]) {
            const input = w.document.querySelector('#date');
            input.value = '';
            input.setAttribute('autocomplete', 'on');
            const p = w.$.admin.datepicker.init(input, options);
            const visibleInput = p.instance.altInput || input;
            let changes = 0;
            const changed = () => changes++;
            w.$(input).on('dp.change', changed);
            assert.equal(input.autocomplete, 'off');
            assert.equal(visibleInput.autocomplete, 'off');
            visibleInput.focus();
            assert.equal(p.instance.isOpen, true);
            assert.equal(input.value, '');
            assert.equal(visibleInput.value, '');
            p.hide();
            visibleInput.blur();
            assert.equal(input.value, '');
            assert.equal(p.instance.selectedDates.length, 0);
            assert.equal(changes, 0);
            w.$(input).off('dp.change', changed);
            p.destroy();
        }
    } finally { w.finish(); }
});

test('calendar drills up from days to months and years, then returns without changing the saved date', () => {
    const w = page('<input id="date" value="2026-10-15">');
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD', locale: 'zh-CN' });
        p.show();
        const calendar = p.instance.calendarContainer;
        const title = calendar.querySelector('.admin-datepicker-title');
        const panel = calendar.querySelector('.admin-datepicker-periods');
        const next = calendar.querySelector('.admin-datepicker-next');
        const prev = calendar.querySelector('.admin-datepicker-prev');
        const view = () => calendar.getAttribute('data-admin-calendar-view');
        assert.deepEqual([...calendar.querySelectorAll('.flatpickr-weekday')].map(el => el.textContent.trim()), ['一', '二', '三', '四', '五', '六', '日']);
        assert.equal(w.flatpickr.l10ns.zh.firstDayOfWeek, undefined, 'shared upstream locale stays unchanged');
        assert.equal(title.textContent, '十月 2026');
        next.click();
        assert.equal(title.textContent, '十一月 2026');
        prev.click();
        title.click();
        assert.equal(view(), 'months');
        assert.equal(title.textContent, '2026');
        assert.equal(panel.children.length, 12);
        assert.equal(panel.children[9].textContent, '10月');
        assert.equal(panel.children[9].getAttribute('aria-pressed'), 'true');
        next.click();
        assert.equal(title.textContent, '2027');
        prev.click();
        title.click();
        assert.equal(view(), 'years');
        assert.equal(title.textContent, '2021-2032');
        assert.equal(panel.children[5].textContent, '2026');
        next.click();
        assert.equal(title.textContent, '2033-2044');
        prev.click();
        panel.children[6].click();
        assert.equal(view(), 'months');
        assert.equal(title.textContent, '2027');
        panel.children[1].click();
        assert.equal(view(), 'days');
        assert.equal(title.textContent, '二月 2027');
        assert.equal(p.input.value, '2026-10-15');
        assert.equal(p.instance.isOpen, true);
        const day = p.instance.daysContainer.querySelector('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)');
        day.click();
        assert.equal(p.input.value.startsWith('2027-02-'), true);
    } finally { w.finish(); }
});

test('calendar month and year panels respect bounds, keyboard navigation and reset on reopening', () => {
    const w = page('<input id="date" value="2026-07-15">');
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), {
            format: 'YYYY-MM-DD', minDate: '2026-03-10', maxDate: '2026-10-20',
        });
        p.show();
        const calendar = p.instance.calendarContainer;
        const title = calendar.querySelector('.admin-datepicker-title');
        const panel = calendar.querySelector('.admin-datepicker-periods');
        title.click();
        assert.equal(panel.children[1].disabled, true);
        assert.equal(panel.children[2].disabled, false);
        assert.equal(panel.children[10].disabled, true);
        assert.equal(calendar.querySelector('.admin-datepicker-prev').disabled, true);
        assert.equal(calendar.querySelector('.admin-datepicker-next').disabled, true);
        panel.children[6].focus();
        panel.children[6].dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        assert.equal(w.document.activeElement, panel.children[7]);
        title.click();
        assert.equal(panel.children[4].disabled, true);
        assert.equal(panel.children[5].disabled, false);
        panel.children[5].dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        assert.equal(calendar.getAttribute('data-admin-calendar-view'), 'months');
        title.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        assert.equal(calendar.getAttribute('data-admin-calendar-view'), 'days');
        assert.equal(p.instance.isOpen, true);
        title.click();
        p.hide();
        p.show();
        assert.equal(panel.hidden, true);
        assert.equal(calendar.getAttribute('data-admin-calendar-view'), 'days');
    } finally { w.finish(); }
});

test('calendar titles follow silent month jumps before a day can be selected', () => {
    const w = page();
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD', locale: 'zh-CN' });
        for (const [initial, month, day] of [['2026-09-10', 7, 10], ['2026-11-10', 11, 10], ['2026-08-30', 8, 30]]) {
            p.date(initial);
            p.show();
            let changes = 0;
            const changed = () => changes++;
            w.$(p.input).on('dp.change', changed);
            p.instance.jumpToDate(new w.Date(2026, month, 1));
            const calendar = p.instance.calendarContainer;
            assert.equal(calendar.querySelector('.admin-datepicker-title').textContent, `${p.instance.l10n.months.longhand[month]} 2026`);
            assert.equal(p.input.value, initial, 'Browsing must leave the saved date unchanged');
            assert.equal(changes, 0, 'A silent jump must remain silent');
            const cell = [...p.instance.daysContainer.querySelectorAll('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)')]
                .find(el => el.dateObj.getDate() === day);
            assert.equal(cell.dateObj.getMonth(), month);
            cell.click();
            assert.equal(p.input.value, `2026-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
            assert.equal(changes, 1);
            w.$(p.input).off('dp.change', changed);
        }
    } finally { w.finish(); }
});

test('silent setDate, clear and direct redraw keep calendar titles consistent across years', () => {
    const w = page('<input id="date" value="2026-08-30">');
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD', locale: 'zh-CN' });
        const originalRedraw = p.instance.redraw;
        p.show();
        const title = p.instance.calendarContainer.querySelector('.admin-datepicker-title');
        let changes = 0;
        w.$(p.input).on('dp.change', () => changes++);
        p.instance.setDate('2026-09-30', false);
        assert.equal(title.textContent, '九月 2026');
        p.instance.setDate('2027-01-10', false);
        assert.equal(title.textContent, '一月 2027');
        p.instance.clear(false);
        assert.equal(title.textContent, '八月 2026');
        p.instance.currentYear = 2025;
        p.instance.currentMonth = 10;
        p.instance.redraw();
        assert.equal(title.textContent, '十一月 2025');
        assert.equal(changes, 0);
        // Reinitialization must not keep a stale header or a wrapper from a destroyed picker.
        const next = w.$.admin.datepicker.init(p.input, { format: 'YYYY-MM-DD', locale: 'en' });
        next.instance.jumpToDate(new w.Date(2026, 8, 1));
        assert.equal(next.instance.calendarContainer.querySelector('.admin-datepicker-title').textContent, 'September 2026');
        assert.notEqual(next.instance.redraw, originalRedraw);
    } finally { w.finish(); }
});

test('locale aliases and existing multiple-date format options are honored', () => {
    const w = page();
    try {
        for (const [locale, firstMonth] of [['zh-CN', '一月'], ['zh_TW', '一月'], ['fr', 'janvier'], ['en_US', 'January']]) {
            const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'DD/MM/YYYY', locale, mode: 'multiple' });
            p.date(['28/10/2026', '29/10/2026']);
            assert.equal(p.input.value, '28/10/2026, 29/10/2026');
            assert.equal(p.instance.l10n.months.longhand[0], firstMonth);
            assert.equal(p.instance.calendarContainer.querySelectorAll('.admin-datepicker-buttons').length, 1);
        }
    } finally { w.finish(); }
});

test('invalid dates are retained and fail native validation instead of rolling into another month', () => {
    const w = page('<input id="date" name="date" value="2026-02-30">');
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD' });
        assert.equal(p.input.value, '2026-02-30');
        assert.equal(p.input.checkValidity(), false);
        p.date('2026-10-28');
        assert.equal(p.input.value, '2026-10-28');
        assert.equal(p.input.checkValidity(), true);
        p.date('2026-13-01');
        assert.equal(p.input.value, '2026-13-01');
        assert.equal(p.input.checkValidity(), false);
        p.clear();
        assert.equal(p.input.value, '');
        assert.equal(p.input.checkValidity(), true);
    } finally { w.finish(); }
});

test('manual input updates the selected date, rejects impossible values and can recover', () => {
    const w = page();
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD' });
        for (const [value, valid] of [['2026-10-28', true], ['2026-02-30', false], ['2024-02-29', true]]) {
            p.input.value = value;
            p.input.dispatchEvent(new w.Event('input', { bubbles: true }));
            assert.equal(p.input.checkValidity(), valid, 'Validity is checked before submission, even without blur');
            p.input.dispatchEvent(new w.FocusEvent('blur', { bubbles: true, relatedTarget: w.document.body }));
            assert.equal(p.input.value, value);
            assert.equal(p.input.checkValidity(), valid);
        }
    } finally { w.finish(); }
});

test('a real calendar selection recovers from preloaded and manually entered invalid dates', () => {
    const w = page('<input id="date" value="2026-02-30">');
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD' });
        const changes = [];
        w.$('#date').on('dp.change', event => changes.push(event.date.format('YYYY-MM-DD')));
        for (const initial of ['2026-02-30', '2026-13-01']) {
            if (initial === '2026-13-01') {
                p.input.value = initial;
                p.input.dispatchEvent(new w.Event('input', { bubbles: true }));
                p.input.dispatchEvent(new w.FocusEvent('blur', { bubbles: true, relatedTarget: w.document.body }));
            }
            assert.equal(p.input.checkValidity(), false);
            assert.equal(changes.length, initial === '2026-02-30' ? 0 : 1);
            p.show();
            const day = p.instance.daysContainer.querySelector('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)');
            const expected = w.flatpickr.formatDate(day.dateObj, 'Y-m-d');
            day.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
            assert.equal(p.input.value, expected);
            assert.equal(p.input.checkValidity(), true);
            assert.equal(p.date().format('YYYY-MM-DD'), expected);
            assert.equal(changes[changes.length - 1], expected);
        }
        assert.equal(changes.length, 2);
    } finally { w.finish(); }
});

test('partially invalid multiple-date input is preserved until corrected with the calendar', () => {
    const w = page();
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD', mode: 'multiple' });
        const value = '2026-10-28, 2026-02-30';
        p.input.value = value;
        p.input.dispatchEvent(new w.Event('input', { bubbles: true }));
        p.input.dispatchEvent(new w.FocusEvent('blur', { bubbles: true, relatedTarget: w.document.body }));
        assert.equal(p.input.value, value);
        assert.equal(p.input.checkValidity(), false);
        p.show();
        const day = p.instance.daysContainer.querySelector('.flatpickr-day:not(.selected):not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)');
        day.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
        assert.equal(p.input.checkValidity(), true);
        assert.equal(p.input.value.includes('2026-02-30'), false);
    } finally { w.finish(); }
});

test('range bounds initialize from values, reset on clearing and retain configured limits', () => {
    const w = page('<input id="start" value="2026-10-10"><input id="end" value="2026-10-20">');
    try {
        w.$.admin.datepicker.range('#start', '#end', { format: 'YYYY-MM-DD', minDate: '2026-10-01', maxDate: '2026-10-31' });
        const a = w.$('#start').data('adminDatepicker'), b = w.$('#end').data('adminDatepicker');
        assert.equal(a.instance.config.maxDate.getDate(), 20);
        assert.equal(b.instance.config.minDate.getDate(), 10);
        a.clear();
        assert.equal(b.instance.config.minDate.getDate(), 1);
        b.clear();
        assert.equal(a.instance.config.maxDate.getDate(), 31);
        assert.equal(w.$('#start').val(), '');
        assert.equal(w.$('#end').val(), '');
        a.date('2026-10-15');
        b.date('2026-10-16');
        assert.equal(a.instance.config.maxDate.getDate(), 16);
        assert.equal(b.instance.config.minDate.getDate(), 15);
    } finally { w.finish(); }
});

test('datetime ranges keep time precision and time-only fields enable seconds and stepping', () => {
    const w = page('<input id="start"><input id="end"><input id="time">');
    try {
        w.$.admin.datepicker.range('#start', '#end', { format: 'YYYY-MM-DD HH:mm:ss' });
        const a = w.$('#start').data('adminDatepicker'), b = w.$('#end').data('adminDatepicker');
        a.date('2026-10-28 12:34:56');
        assert.equal(b.instance.config.minDate.getHours(), 12);
        assert.equal(b.instance.config.minDate.getMinutes(), 34);
        assert.equal(b.instance.config.minDate.getSeconds(), 56);
        w.$('#time').adminDatepicker({ format: 'HH:mm:ss', stepping: 15 });
        const p = w.$('#time').data('adminDatepicker');
        assert.equal(p.instance.config.noCalendar, true);
        assert.equal(p.instance.config.enableSeconds, true);
        assert.equal(p.instance.config.time_24hr, true);
        assert.equal(p.instance.config.minuteIncrement, 15);
        p.date('23:45:59');
        assert.equal(p.input.value, '23:45:59');
    } finally { w.finish(); }
});

test('empty datetime and time pickers start at midnight while explicit defaults and saved values are retained', () => {
    const w = page('<input id="date"><input id="time"><input id="custom"><input id="saved" value="2026-10-28 15:34:56">');
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), { format: 'YYYY-MM-DD HH:mm:ss' });
        assert.equal(p.instance.hourElement.value, '00');
        assert.equal(p.instance.minuteElement.value, '00');
        assert.equal(p.instance.secondElement.value, '00');
        p.show();
        const day = p.instance.daysContainer.querySelector('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)');
        day.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
        assert.match(p.input.value, / 00:00:00$/);
        const time = w.$.admin.datepicker.init(w.document.querySelector('#time'), { format: 'HH:mm:ss' });
        assert.equal(time.instance.hourElement.value, '00');
        assert.equal(time.instance.minuteElement.value, '00');
        assert.equal(time.instance.secondElement.value, '00');
        const custom = w.$.admin.datepicker.init(w.document.querySelector('#custom'), {
            format: 'YYYY-MM-DD HH:mm:ss', defaultHour: 9, defaultMinute: 15, defaultSeconds: 30,
        });
        assert.equal(custom.instance.hourElement.value, '09');
        assert.equal(custom.instance.minuteElement.value, '15');
        assert.equal(custom.instance.secondElement.value, '30');
        const saved = w.$.admin.datepicker.init(w.document.querySelector('#saved'), { format: 'YYYY-MM-DD HH:mm:ss' });
        assert.equal(saved.input.value, '2026-10-28 15:34:56');
        assert.equal(saved.instance.hourElement.value, '15');
    } finally { w.finish(); }
});

test('transition datetimepicker API exposes change dates without a Moment dependency', () => {
    const w = page('<div class="input-group"><input id="date"><span class="input-group-addon">Open</span></div>');
    try {
        const group = w.$('.input-group');
        group.datetimepicker({ format: 'YYYY-MM-DD', showClear: true });
        const p = group.data('DateTimePicker');
        let changes = 0, shown = 0;
        group.on('dp.show', () => shown++).on('dp.change', e => {
            changes++;
            assert.equal(e.date ? e.date.format('YYYY-MM-DD') : false, changes === 1 ? '2026-10-28' : false);
        });
        p.date('2026-10-28');
        group.find('.input-group-addon').trigger('click');
        assert.equal(shown, 1);
        p.instance.calendarContainer.querySelector('.admin-datepicker-buttons button').click();
        assert.equal(changes, 2);
        assert.equal(p.input.value, '');
        p.minDate(false).maxDate(false).hide();
    } finally { w.finish(); }
});

test('reinitialization and repeated range binding leave one picker and one event handler per input', () => {
    const w = page('<input id="start"><input id="end">');
    try {
        w.$.admin.datepicker.range('#start', '#end', { format: 'YYYY-MM-DD' });
        w.$.admin.datepicker.range('#start', '#end', { format: 'YYYY-MM-DD' });
        let changes = 0;
        w.$('#start').on('dp.change', () => changes++);
        w.$('#start').data('adminDatepicker').date('2026-10-28');
        assert.equal(changes, 1);
        assert.equal(w.document.querySelectorAll('.flatpickr-calendar').length, 2);
    } finally { w.finish(); }
});

test('Action modal calendars stay in the focus trap and are destroyed when the modal closes', () => {
    const w = page('<div class="modal" data-admin-action-shell="1"><input id="date"></div>');
    try {
        w.$('#date').adminDatepicker({ format: 'YYYY-MM-DD' });
        const p = w.$('#date').data('adminDatepicker');
        assert.equal(p.instance.calendarContainer.parentNode, w.document.querySelector('.modal'));
        w.$('.modal').trigger('hidden.bs.modal');
        assert.equal(w.$('#date').data('adminDatepicker'), undefined);
        assert.equal(w.document.querySelectorAll('.flatpickr-calendar').length, 0);
    } finally { w.finish(); }
});

test('reusable modal fields keep their picker when hidden and can reopen', () => {
    const w = page('<div class="modal"><input id="date"></div>');
    try {
        w.$('#date').adminDatepicker({ format: 'YYYY-MM-DD' });
        const p = w.$('#date').data('adminDatepicker');
        p.show();
        w.$('.modal').trigger('hidden.bs.modal');
        assert.equal(p.instance.isOpen, false);
        assert.equal(w.$('#date').data('adminDatepicker'), p);
        p.show();
        assert.equal(p.instance.isOpen, true);
    } finally { w.finish(); }
});

test('navigation and removal of dynamic editors clean up calendars and permit new inputs', async () => {
    const w = page('<div class="row"><input id="date"></div>');
    try {
        w.$('#date').adminDatepicker({ format: 'YYYY-MM-DD' });
        w.$('.row').remove();
        await new Promise(resolve => w.setTimeout(resolve, 0));
        assert.equal(w.document.querySelectorAll('.flatpickr-calendar').length, 0);
        w.$('form').append('<input id="next">');
        w.$('#next').adminDatepicker({ format: 'YYYY-MM-DD' });
        assert.equal(w.document.querySelectorAll('.flatpickr-calendar').length, 1);
        w.$(w).trigger('admin:before-navigate');
        assert.equal(w.document.querySelectorAll('.flatpickr-calendar').length, 0);
    } finally { w.finish(); }
});

test('read-only fields cannot open from input focus or the calendar icon', () => {
    const w = page('<div class="input-group"><input id="date" readonly><span class="input-group-addon">Open</span></div>');
    try {
        w.$('#date').adminDatepicker({ format: 'YYYY-MM-DD' });
        w.$('.input-group-addon').trigger('click');
        w.$('#date').trigger('focus');
        assert.equal(w.$('#date').data('adminDatepicker').instance.isOpen, false);
    } finally { w.finish(); }
});

test('X-Editable dates use Flatpickr and retain plain string submission without Moment', () => {
    const w = page('', true);
    try {
        const Input = w.$.fn.editabletypes.adminDate;
        const editor = new Input({ format: 'YYYY-MM-DD HH:mm:ss', locale: 'zh-CN', combodate: { minYear: 2000, maxYear: 2035 } });
        editor.$input = w.$('<input>').appendTo('form');
        editor.render();
        editor.value2input('2026-10-28 12:34:56');
        assert.equal(editor.input2value(), '2026-10-28 12:34:56');
        assert.equal(editor.$input.data('adminDatepicker').instance.config.minDate.getFullYear(), 2000);
        assert.equal(editor.$input.data('adminDatepicker').instance.config.maxDate.getFullYear(), 2035);
        assert.equal(w.$.fn.editabletypes.combodate, Input);
        assert.equal(w.moment, undefined);
        editor.destroy();
        assert.equal(w.document.querySelectorAll('.flatpickr-calendar').length, 0);
    } finally { w.finish(); }
});

test('native Flatpickr date formats, parsing callbacks and input restrictions remain available', () => {
    const w = page();
    try {
        const p = w.$.admin.datepicker.init(w.document.querySelector('#date'), {
            dateFormat: 'Y/m/d', minDate: '2026-10-01', maxDate: '2026-10-31',
            disabledDates: ['2026-10-15'], daysOfWeekDisabled: [0, 6], calendarWeeks: true,
        });
        p.date('2026/10/28');
        assert.equal(p.input.value, '2026/10/28');
        assert.equal(p.instance.config.weekNumbers, true);
        assert.equal(p.instance.isEnabled('2026/10/15'), false);
        assert.equal(p.instance.isEnabled('2026/10/25'), false);
        assert.equal(p.instance.isEnabled('2026/11/01'), false);
        assert.equal(p.instance.isEnabled('2026/10/28'), true);
        const custom = w.$.admin.datepicker.init(p.input, {
            dateFormat: 'Y-m-d', parseDate(value) {
                if (value === 'payday') return new w.Date(2026, 9, 28);
                return w.flatpickr.parseDate(value, 'Y-m-d');
            },
        });
        custom.date('payday');
        assert.equal(custom.input.value, '2026-10-28');
        assert.equal(custom.input.checkValidity(), true);
    } finally { w.finish(); }
});
