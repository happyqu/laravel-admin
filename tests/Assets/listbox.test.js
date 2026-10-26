const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

function fixture(settings = {}) {
    const dom = new JSDOM('<form><select name="items[]" multiple><option value="0">Zero</option><option value="a" selected>A</option><optgroup disabled><option value="b">B</option></optgroup></select></form>', { runScripts: 'outside-only' });
    dom.window.eval(fs.readFileSync('resources/assets/laravel-admin/listbox.js', 'utf8'));
    const source = dom.window.document.querySelector('select');
    dom.window.AdminListbox.mount(source, settings);
    return { dom, source, document: dom.window.document, api: dom.window.AdminListbox };
}

test('listbox moves values including zero, submits only original controls, and respects disabled groups', () => {
    const { dom, source, document } = fixture();
    const lists = document.querySelectorAll('.admin-listbox-select');
    assert.equal(lists[1].options[0].value, 'a');
    assert.equal(lists[0].options[1].disabled, true);
    document.querySelector('[aria-label="Add all filtered options"]').click();
    assert.deepEqual(Array.from(new dom.window.FormData(source.form).getAll('items[]')), ['0', 'a']);
    assert.equal(lists[0].options.length, 1);
});

test('filters limit bulk moves and readonly blocks changes', () => {
    const { dom, source, document } = fixture();
    const search = document.querySelector('input');
    search.value = 'nothing';
    search.dispatchEvent(new dom.window.Event('input'));
    document.querySelector('[aria-label="Add all filtered options"]').click();
    assert.equal(source.selectedOptions.length, 1);
    source.setAttribute('readonly', 'readonly');
    document.querySelector('input').value = '';
    document.querySelector('input').dispatchEvent(new dom.window.Event('input'));
    assert.equal(document.querySelector('.admin-listbox-select').disabled, true);
    document.querySelector('[aria-label="Add all filtered options"]').click();
    assert.equal(source.selectedOptions.length, 1);
});

test('refreshes remote options and reset; repeated mount and destroy leave no orphan controls', async () => {
    const { dom, source, document, api } = fixture();
    api.mount(source);
    assert.equal(document.querySelectorAll('.admin-listbox').length, 1);
    source.append(new dom.window.Option('<safe>', 'remote', true, true));
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(document.querySelectorAll('.admin-listbox-select')[1].options.length, 2);
    assert.equal(document.querySelectorAll('.admin-listbox safe').length, 0);
    source.options[0].selected = true;
    source.form.reset();
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(document.querySelectorAll('.admin-listbox-select')[0].options[0].value, '0');
    api.destroy(source);
    assert.equal(document.querySelectorAll('.admin-listbox').length, 0);
    assert.equal(source.hidden, false);
    dom.window.close();
});
