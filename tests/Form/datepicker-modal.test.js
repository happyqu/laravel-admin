const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

function page(action = false, fade = false) {
    const w = new JSDOM(`<div class="modal${fade ? ' fade' : ''}" tabindex="-1" ${action ? 'data-admin-action-shell="1"' : ''}>
      <div class="modal-dialog"><div class="modal-content">
        <button type="button" class="close" data-dismiss="modal">Close</button>
        <form><div class="input-group"><input id="date" name="date" value="2026-10-10">
          <span class="input-group-addon">Calendar</span></div></form>
      </div></div></div>`, { runScripts: 'outside-only', pretendToBeVisual: true }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'AdminLTE/bootstrap/js/bootstrap.min.js',
        'laravel-admin/flatpickr-local.js', 'laravel-admin/flatpickr-locales.js', 'laravel-admin/datepicker.js']) {
        w.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets', asset), 'utf8'));
    }
    w.$.support.transition = false;
    const picker = w.$.admin.datepicker.init(w.document.querySelector('#date'), {});
    w.picker = picker;
    w.modal = w.$('.modal');
    w.finish = () => { w.$.admin.datepicker.destroy(); w.close(); };
    return w;
}

function mouse(w, target, type) {
    target.dispatchEvent(new w.MouseEvent(type, { bubbles: true, cancelable: true, button: 0 }));
}

function escape(w, target) {
    target.dispatchEvent(new w.KeyboardEvent('keydown', {
        key: 'Escape', keyCode: 27, which: 27, bubbles: true, cancelable: true,
    }));
}

for (const action of [false, true]) {
    const kind = action ? 'Action' : 'Tool';
    test(`${kind} modal stays open when a calendar press ends on the backdrop`, () => {
        const w = page(action);
        try {
            w.modal.modal('show');
            w.picker.show();
            const day = w.picker.instance.daysContainer.querySelector('.flatpickr-day');
            mouse(w, day, 'mousedown');
            // Model a moving dialog or a calendar hidden between pointer down and up.
            w.picker.hide();
            mouse(w, w.modal[0], 'mouseup');
            mouse(w, w.modal[0], 'click');
            assert.equal(w.modal.data('bs.modal').isShown, true);
            assert.equal(w.document.querySelector('#date').value, '2026-10-10');
            // A later intentional backdrop click still closes the modal.
            mouse(w, w.modal[0], 'click');
            assert.equal(w.modal.data('bs.modal').isShown, false);
        } finally { w.finish(); }
    });

    test(`${kind} date selection does not close its form and the Close button still works`, () => {
        const w = page(action);
        try {
            w.modal.modal('show');
            w.picker.show();
            const day = [...w.picker.instance.daysContainer.querySelectorAll('.flatpickr-day')]
                .find(el => !el.classList.contains('prevMonthDay') && !el.classList.contains('nextMonthDay') && el.dateObj.getDate() === 11);
            mouse(w, day, 'mousedown'); mouse(w, day, 'mouseup'); mouse(w, day, 'click');
            assert.equal(w.picker.input.value, '2026-10-11');
            assert.equal(w.modal.data('bs.modal').isShown, true);
            w.document.querySelector('.close').click();
            assert.equal(w.modal.data('bs.modal').isShown, false);
        } finally { w.finish(); }
    });

    test(`${kind} calendar Escape closes the picker before closing the form`, () => {
        const w = page(action);
        try {
            w.modal.modal('show');
            for (const target of [w.picker.input, w.picker.instance.daysContainer.querySelector('.flatpickr-day')]) {
                w.picker.show();
                escape(w, target);
                assert.equal(w.picker.instance.isOpen, false);
                assert.equal(w.modal.data('bs.modal').isShown, true);
            }
            escape(w, w.picker.input);
            assert.equal(w.modal.data('bs.modal').isShown, false);
        } finally { w.finish(); }
    });

    test(`${kind} opens the requested calendar after the real Bootstrap fade transition`, async () => {
        const w = page(action, true);
        try {
            // Bootstrap registers its transition-end special event on DOM ready.
            await new Promise(resolve => w.setTimeout(resolve, 0));
            w.$.support.transition = { end: 'transitionend' };
            let shown = 0;
            w.modal.on('shown.bs.modal', () => shown++);
            w.modal.modal('show');
            w.$('.modal-backdrop').trigger('transitionend');
            assert.equal(shown, 0);
            assert.equal(w.modal.data('bs.modal').isShown, true);
            w.picker.input.focus();
            w.$('.input-group-addon').trigger('click');
            assert.equal(w.picker.instance.isOpen, false);
            w.$('.modal-dialog').trigger('transitionend');
            assert.equal(shown, 1);
            assert.equal(w.picker.instance.isOpen, true);
            assert.equal(w.modal.data('bs.modal').isShown, true);
            assert.ok(w.picker.instance.calendarContainer.classList.contains('open'));
        } finally { w.finish(); }
    });
}

test('closing a transitioning modal cancels its pending calendar request', async () => {
    const w = page(false, true);
    try {
        await new Promise(resolve => w.setTimeout(resolve, 0));
        w.$.support.transition = { end: 'transitionend' };
        w.modal.modal('show');
        w.$('.modal-backdrop').trigger('transitionend');
        w.picker.show();
        w.modal.modal('hide');
        w.$('.modal-dialog').trigger('transitionend');
        assert.equal(w.picker.instance.isOpen, false);
    } finally { w.finish(); }
});
