const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

function page(attributes = '') {
    const w = new JSDOM(`<form><fieldset><input name="quantity" ${attributes}></fieldset></form>`, {
        runScripts: 'outside-only', url: 'http://localhost/',
    }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'number-input/bootstrap-number-input.js']) {
        w.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets', asset), 'utf8'));
    }
    w.$('input').bootstrapNumber();
    return w;
}

for (const [attributes, value, expected] of [
    ['max="100"', '20', '20'], ['min="2"', '10', '10'],
    ['max="100"', '101', '100'], ['min="2"', '1', '2'],
    ['min="0"', '-1', '0'], ['max="0"', '1', '0'],
    ['min="-100" max="-2"', '-20', '-20'],
]) {
    test(`numeric bounds: ${attributes}, input ${value}`, () => {
        const w = page(attributes);
        try {
            const input = w.$('input');
            input.val(value).trigger('input').trigger('keyup');
            assert.equal(input.val(), value);
            input.trigger('blur');
            assert.equal(input.val(), expected);
        } finally { w.close(); }
    });
}

test('editing preserves empty values and a negative sign; blur validates the full integer', () => {
    const w = page('min="-100" max="100" value="12"');
    try {
        const input = w.$('input');
        for (const code of [189, 109]) {
            const event = w.$.Event('keydown', { keyCode: code });
            input.trigger(event);
            assert.equal(event.isDefaultPrevented(), false);
        }
        input.val('-').trigger('input');
        assert.equal(input.val(), '-');
        input.val('-12').trigger('input').trigger('blur');
        assert.equal(input.val(), '-12');
        for (const value of ['', '-', '12oops', '1.5']) {
            input.val(value).trigger('input').trigger('blur');
            assert.equal(input.val(), '');
        }
        assert.equal(new w.FormData(w.document.querySelector('form')).get('quantity'), '');
    } finally { w.close(); }
});

test('positive minimum does not prevent clearing or typing a multi-digit value', () => {
    const w = page('min="20"');
    try {
        const input = w.$('input');
        input.val('2').trigger('input');
        assert.equal(input.val(), '2');
        input.val('25').trigger('input').trigger('blur');
        assert.equal(input.val(), '25');
        input.val('').trigger('input').trigger('blur');
        assert.equal(input.val(), '');
    } finally { w.close(); }
});

test('buttons step and clamp numerically, including negative and zero boundaries', () => {
    const w = page('min="-1" max="1"');
    try {
        const input = w.$('input');
        let changes = 0;
        input.on('change', () => changes++);
        w.$('button').first().trigger('click').trigger('click');
        assert.equal(input.val(), '-1');
        w.$('button').last().trigger('click').trigger('click').trigger('click');
        assert.equal(input.val(), '1');
        assert.ok(changes >= 3);
        input.attr('max', '0');
        w.$('button').last().trigger('click');
        assert.equal(input.val(), '0');
    } finally { w.close(); }
});

for (const mode of ['readonly', 'disabled', 'fieldset']) {
    test(`${mode} locks buttons and preserves submission semantics, including dynamic changes`, async () => {
        const w = page(`value="12" ${mode === 'fieldset' ? '' : mode}`);
        try {
            const input = w.$('input');
            const target = mode === 'fieldset' ? w.$('fieldset') : input;
            const property = mode === 'readonly' ? 'readOnly' : 'disabled';
            target.prop(property, true);
            // Event guards must take effect before the attribute observer runs.
            w.$('button').last().trigger('click');
            input.trigger('blur');
            assert.equal(input.val(), '12');
            await Promise.resolve();
            assert.equal(w.$('button:disabled').length, 2);
            assert.equal(new w.FormData(w.document.querySelector('form')).get('quantity'), mode === 'readonly' ? '12' : null);
            target.prop(property, false);
            await Promise.resolve();
            assert.equal(w.$('button:disabled').length, 0);
            w.$('button').last().trigger('click');
            assert.equal(input.val(), '13');
            target.prop(property, true);
            w.$('button').first().trigger('click');
            assert.equal(input.val(), '13');
        } finally { w.close(); }
    });
}

test('navigation and clipboard shortcuts do not rewrite the current value or emit changes', () => {
    const w = page('value="20" max="100"');
    try {
        const input = w.$('input');
        let changes = 0;
        input.on('change', () => changes++);
        for (const options of [{ keyCode: 37 }, { keyCode: 65, ctrlKey: true },
            { keyCode: 86, ctrlKey: true }, { keyCode: 86, metaKey: true }]) {
            const event = w.$.Event('keydown', options);
            input.trigger(event).trigger(w.$.Event('keyup', options));
            assert.equal(event.isDefaultPrevented(), false);
            assert.equal(input.val(), '20');
        }
        assert.equal(changes, 0);
        input.val('30').trigger('input');
        assert.equal(changes, 1);
    } finally { w.close(); }
});

for (const enter of [false, true]) {
    test(`focused submission normalizes all number fields before serialization (Enter: ${enter})`, () => {
        const w = page('min="0" max="10" required');
        try {
            const form = w.document.querySelector('form');
            w.$(form).append('<input name="other" min="2" value="1">');
            w.$('[name="other"]').bootstrapNumber();
            const input = w.$('[name="quantity"]');
            input[0].focus();
            input.val('99').trigger('input');
            let submitted;
            // Model delegated AJAX handlers, which serialize without forcing blur.
            w.$(w.document).on('submit', 'form', event => {
                event.preventDefault();
                submitted = new w.FormData(form);
            });
            if (enter) input.trigger(w.$.Event('keydown', { keyCode: 13 }));
            form.requestSubmit();
            assert.equal(submitted.get('quantity'), '10');
            assert.equal(submitted.get('other'), '2');
            assert.equal(w.document.activeElement, input[0]);
        } finally { w.close(); }
    });
}

test('normalizing invalid required input blocks submission and permits correction', () => {
    const w = page('required max="10"');
    try {
        const input = w.$('input');
        const form = w.document.querySelector('form');
        let submissions = 0;
        w.$(w.document).on('submit', 'form', event => {
            event.preventDefault();
            submissions++;
        });
        input[0].focus();
        input.val('-').trigger('input');
        form.requestSubmit();
        assert.equal(submissions, 0);
        assert.equal(input.val(), '');
        assert.equal(input[0].validity.valueMissing, true);
        input.val('5').trigger('input');
        form.requestSubmit();
        assert.equal(submissions, 1);
        assert.equal(input.val(), '5');
    } finally { w.close(); }
});

test('Enter normalizes an unfinished required edit before native validation', () => {
    const w = page('required');
    try {
        const input = w.$('input');
        input.val('-').trigger('input').trigger(w.$.Event('keydown', { keyCode: 13 }));
        assert.equal(input.val(), '');
        assert.equal(w.document.querySelector('form').checkValidity(), false);
    } finally { w.close(); }
});

test('focused submission preserves an optional empty value', () => {
    const w = page('min="2"');
    try {
        const form = w.document.querySelector('form');
        let submitted;
        form.addEventListener('submit', event => {
            event.preventDefault();
            submitted = new w.FormData(form).get('quantity');
        });
        w.$('input')[0].focus();
        form.requestSubmit();
        assert.equal(submitted, '');
    } finally { w.close(); }
});
