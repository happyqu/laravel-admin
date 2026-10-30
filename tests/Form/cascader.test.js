const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/cascader.php'], { cwd: root, encoding: 'utf8' }));
const nodes = [
    { value: '0', label: 'Province', leaf: false },
    { value: '1', label: '<img src=x onerror=alert(1)>', leaf: false },
    { value: '2', label: 'District', leaf: true },
];
function page(type = 'empty', count = 1, html = null, preload = false) {
    const w = new JSDOM(html || `<form>${fixtures[type].repeat(count)}<button type="submit">Save</button></form>`, { runScripts: 'outside-only', url: 'http://localhost/admin/items' }).window;
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    // Most cases exercise the remote-provider path; explicit preload cases use the local tree.
    w.$('[data-admin-cascader]').each(function () {
        const config = JSON.parse(this.getAttribute('data-admin-cascader'));
        config.preload = preload;
        this.setAttribute('data-admin-cascader', JSON.stringify(config));
    });
    const requests = [];
    w.$.ajax = options => {
        const deferred = w.$.Deferred();
        const request = { options, done: value => deferred.resolve(value), fail: status => deferred.reject({ status }, 'error') };
        requests.push(request);
        const promise = deferred.promise();
        // Deliberately allow a completed response after abort to test the generation guard.
        promise.abort = () => {};
        return promise;
    };
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/laravel-admin/cascader.js'), 'utf8'));
    return { w, requests, controller: index => w.$('.admin-cascader').eq(index || 0).data('adminCascader') };
}
function values(w, index = 0) { return Array.from(w.document.querySelectorAll('.admin-cascader')[index].querySelectorAll('[data-cascader-value]'), input => input.value); }
function choose(controller, level, index = 0) { controller.columns[level].element.find('.admin-cascader-node').eq(index)[0].click(); }

test('three levels commit together, escape labels, and clear without restoring old values', () => {
    const { w, requests, controller } = page();
    try {
        const c = controller();
        let changed = 0;
        c.$root.on('cascader:change', () => changed++);
        c.open(); requests[0].done({ items: [nodes[0]], nextCursor: null });
        assert.equal(requests[0].options.adminLoading, false);
        choose(c, 0); requests[1].done({ items: [nodes[1]], nextCursor: null });
        assert.equal(c.panel.find('img').length, 0);
        choose(c, 1); requests[2].done({ items: [nodes[2]], nextCursor: null });
        assert.deepEqual(values(w), ['', '', '']);
        choose(c, 2);
        assert.deepEqual(values(w), ['0', '1', '2']);
        assert.equal(c.opened, false);
        assert.equal(changed, 1);
        assert.equal(new w.FormData(w.document.querySelector('form')).get('province_id'), '0');
        c.clear[0].click();
        assert.deepEqual(values(w), ['', '', '']);
        assert.equal(changed, 2);
    } finally { w.close(); }
});

test('bundled China regions submit province/city/district names rather than codes', () => {
    const { w, requests, controller } = page('china', 1, null, true);
    try {
        const c = controller(), names = ['浙江省', '杭州市', '西湖区'];
        assert.equal(requests.length, 0);
        assert.equal(c.status.text(), '');
        c.open();
        assert.equal(c.opened, true);
        assert.equal(c.panel.attr('aria-busy'), 'true');
        assert.equal(c.panel.find('[role="status"]').text(), c.messages.loading);
        assert.equal(requests[0].options.data.operation, 'tree');
        requests[0].done({ items: fixtures.chinaTree });
        c.open();
        for (let level = 0; level < 3; level++) {
            choose(c, level, c.columns[level].items.findIndex(node => node.value === names[level]));
            assert.equal(c.panel.find('[aria-busy="true"]').length, 0);
        }
        assert.equal(requests.length, 1);
        assert.deepEqual(values(w), names);
        const data = new w.FormData(w.document.querySelector('form'));
        assert.deepEqual(['province', 'city', 'district'].map(field => data.get(field)), names);
        assert.equal(c.label.text(), names.join(' / '));
    } finally { w.close(); }
});

test('regions without district children finish at the city and submit an empty district', () => {
    const { w, requests, controller } = page('china', 1, null, true);
    try {
        const c = controller();
        c.open();
        requests[0].done({ items: fixtures.chinaTree });
        c.open();
        choose(c, 0, c.columns[0].items.findIndex(node => node.value === '广东省'));
        choose(c, 1, c.columns[1].items.findIndex(node => node.value === '东莞市'));
        assert.equal(requests.length, 1);
        assert.equal(c.opened, false);
        assert.deepEqual(values(w), ['广东省', '东莞市', '']);
        const data = new w.FormData(w.document.querySelector('form'));
        assert.equal(data.get('district'), '');
        assert.equal(c.label.text(), '广东省 / 东莞市');
    } finally { w.close(); }
});

test('static instances share one download and resolve, search, reopen and reset locally', async () => {
    const { w, requests, controller } = page('edit', 2, null, true);
    try {
        assert.equal(requests.length, 0);
        const c = controller(), other = controller(1);
        assert.equal(c.label.text(), '0 / 1 / 2');
        assert.equal(c.valid, true);
        assert.equal(w.document.querySelector('form').dispatchEvent(new w.Event('submit', { cancelable: true })), true);
        c.open();
        other.open();
        assert.equal(c.opened, false);
        assert.equal(requests.length, 1);
        requests[0].done({ items: fixtures.staticTree });
        assert.equal(c.valid, true);
        assert.equal(other.valid, true);
        assert.equal(c.label.find('b').length, 0);
        c.open();
        assert.equal(c.columns.length, 3);
        c.columns[0].search.val('Other').trigger('input');
        assert.equal(c.columns.length, 1);
        assert.equal(c.columns[0].items.length, 1);
        assert.equal(c.columns[0].items[0].value, '3');
        c.hide(); c.open();
        assert.equal(c.columns.length, 3);
        c.clear[0].click();
        w.document.querySelector('form').reset();
        await new Promise(resolve => setTimeout(resolve, 10));
        assert.deepEqual(values(w), ['0', '1', '2']);
        assert.equal(c.valid, true);
        assert.equal(requests.length, 1);
    } finally { w.close(); }
});

test('a failed static download retries once and clearing a pending value does not stall loading', () => {
    const { w, requests, controller } = page('edit', 1, null, true);
    try {
        const c = controller();
        c.open();
        requests[0].fail(503);
        assert.equal(c.valid, true);
        assert.equal(c.status.text(), '');
        assert.equal(c.panel.attr('aria-busy'), 'false');
        c.panel.find('.admin-cascader-retry')[0].click();
        assert.equal(requests.length, 2);
        c.clear[0].click();
        requests[1].done({ items: fixtures.staticTree });
        c.open();
        assert.equal(c.opened, true);
        assert.equal(c.columns.length, 1);
        assert.equal(requests.length, 2);
        assert.deepEqual(values(w), ['', '', '']);
    } finally { w.close(); }
});

test('closing a pending static picker never reopens it, and reopening uses the cached tree', () => {
    const { w, requests, controller } = page('china', 1, null, true);
    try {
        const c = controller();
        assert.equal(requests.length, 0);
        c.toggle[0].click();
        assert.equal(c.opened, true);
        c.toggle[0].click();
        assert.equal(c.opened, false);
        requests[0].done({ items: fixtures.chinaTree });
        assert.equal(c.opened, false);
        assert.equal(c.panel.prop('hidden'), true);
        assert.equal(c.status.text(), '');
        c.open();
        assert.equal(requests.length, 1);
        assert.equal(c.columns[0].items.length, 34);
    } finally { w.close(); }
});

test('untouched static fields and form resets do not download a tree', async () => {
    const { w, requests, controller } = page('edit', 1, null, true);
    try {
        const c = controller();
        c.clear[0].click();
        w.document.querySelector('form').reset();
        await new Promise(resolve => setTimeout(resolve, 10));
        assert.deepEqual(values(w), ['0', '1', '2']);
        assert.equal(c.label.text(), '0 / 1 / 2');
        assert.equal(c.valid, true);
        assert.equal(requests.length, 0);
        assert.equal(c.status.text(), '');
    } finally { w.close(); }
});

test('editing resolves labels, blocks submission while unresolved, and preserves values on failure', () => {
    const { w, requests, controller } = page('edit');
    try {
        const c = controller(), form = w.document.querySelector('form');
        const event = new w.Event('submit', { bubbles: true, cancelable: true });
        assert.equal(form.dispatchEvent(event), false);
        requests[0].fail(503);
        assert.deepEqual(values(w), ['0', '1', '2']);
        assert.equal(c.valid, false);
        c.status.find('button')[0].click();
        requests[1].done({ items: nodes });
        assert.equal(c.valid, true);
        assert.equal(c.label.text(), nodes.map(n => n.label).join(' / '));
        assert.equal(c.label.find('img').length, 0);
        assert.equal(form.dispatchEvent(new w.Event('submit', { cancelable: true })), true);
    } finally { w.close(); }
});

test('deleted hasMany and table rows do not block submission, while retained rows still do', () => {
    const row = (key, contents) => `<div class="has-many-rows-form">${contents}<input class="fom-removed" name="rows[${key}][_remove_]" value="0"></div>`;
    const nested = key => fixtures.edit.replace(/name="([^"]+)"/g, `name="rows[${key}][$1]"`);
    const html = `<form>${row(1, nested(1))}${row(2, nested(2))}</form>`;
    const { w, requests } = page('edit', 1, html);
    try {
        const form = w.document.querySelector('form');
        const submit = () => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
        requests[0].fail(422);
        requests[1].fail(503);
        assert.equal(submit(), false);
        const firstRow = w.$('.has-many-rows-form').first();
        firstRow.hide().find('.fom-removed').val('1');
        // Deleting an invalid sibling must not bypass the retained row's validation.
        assert.equal(submit(), false);
        const retained = w.$('[data-admin-cascader]').eq(1).data('adminCascader');
        retained.resolve();
        requests[2].done({ items: nodes });
        assert.equal(submit(), true);
        assert.equal(new w.FormData(form).get('rows[1][_remove_]'), '1');
        assert.equal(new w.FormData(form).get('rows[1][province_id]'), '0');
        firstRow.show().find('.fom-removed').val('0');
        assert.equal(submit(), false);
    } finally { w.close(); }
});

test('ancestor deletion skips nested cascaders while a deleted sibling does not', () => {
    const nested = fixtures.edit.replace(/name="([^"]+)"/g, 'name="rows[1][children][2][$1]"');
    const html = `<form>${nested}<input class="fom-removed" name="rows[1][_remove_]" value="0"><input class="fom-removed" name="rows[1][children][3][_remove_]" value="1"></form>`;
    const { w, requests } = page('edit', 1, html);
    try {
        const form = w.document.querySelector('form');
        const submit = () => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
        requests[0].fail(422);
        assert.equal(submit(), false);
        form.elements.namedItem('rows[1][_remove_]').value = '1';
        assert.equal(submit(), true);
    } finally { w.close(); }
});

test('Escape closes only the cascader inside a Bootstrap modal', () => {
    const html = `<div class="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form>${fixtures.empty}</form></div></div></div>`;
    const { w, requests, controller } = page('empty', 1, html);
    try {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/bootstrap/js/bootstrap.min.js'), 'utf8'));
        w.$.support.transition = false;
        const modal = w.$('.modal').modal({ show: true, keyboard: true });
        const c = controller();
        c.open(); requests[0].done({ items: [nodes[0]], nextCursor: null });
        const escape = () => new w.KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, which: 27, bubbles: true, cancelable: true });
        c.columns[0].search[0].dispatchEvent(escape());
        assert.equal(c.opened, false);
        assert.equal(modal.hasClass('in'), true);
        assert.equal(w.document.activeElement, c.toggle[0]);
        c.open();
        c.toggle[0].dispatchEvent(escape());
        assert.equal(c.opened, false);
        assert.equal(modal.hasClass('in'), true);
        // Once the panel is closed, Escape retains Bootstrap's normal modal behavior.
        c.toggle[0].dispatchEvent(escape());
        assert.equal(modal.hasClass('in'), false);
    } finally { w.close(); }
});

test('switching an ancestor discards downstream columns and ignores stale responses', () => {
    const { w, requests, controller } = page();
    try {
        const c = controller();
        c.open(); requests[0].done({ items: [nodes[0], { value: '3', label: 'Other', leaf: false }], nextCursor: null });
        choose(c, 0, 0);
        choose(c, 0, 1);
        requests[2].done({ items: [{ value: 'new', label: 'Latest', leaf: true }], nextCursor: null });
        requests[1].done({ items: [nodes[1]], nextCursor: null });
        assert.equal(c.columns[1].list.text(), 'Latest');
        choose(c, 1);
        assert.deepEqual(values(w), ['3', 'new', '']);
        c.open(); c.hide();
        requests[3].done({ items: [nodes[0]], nextCursor: null });
        assert.equal(c.columns.length, 0);
    } finally { w.close(); }
});

test('single-column storage submits only terminal ID and supports reset', async () => {
    const { w, requests, controller } = page('single');
    try {
        const c = controller();
        assert.equal(requests[0].options.data.value, '2');
        requests[0].done({ items: nodes });
        c.commit([{ value: '4', label: 'Other', leaf: true }]);
        assert.deepEqual(values(w), ['4']);
        w.document.querySelector('form').reset();
        await new Promise(resolve => setTimeout(resolve, 10));
        assert.deepEqual(values(w), ['2']);
        requests[1].done({ items: nodes });
        assert.equal(c.valid, true);
    } finally { w.close(); }
});

test('branch selection clears trailing fields and instance state is isolated', () => {
    const { w, requests, controller } = page('branch', 2);
    try {
        requests[0].done({ items: [nodes[0]] }); requests[1].done({ items: [nodes[0]] });
        const c = controller();
        c.commit(nodes);
        c.open(); requests[2].done({ items: [nodes[0]], nextCursor: null });
        requests[3].done({ items: [nodes[1]], nextCursor: null });
        requests[4].done({ items: [nodes[2]], nextCursor: null });
        // A repeated child ID under a different ancestor must not inherit selection.
        c.expand([{ value: '3', label: 'Other', leaf: false }], false, false);
        requests[5].done({ items: [nodes[1]], nextCursor: null });
        assert.equal(c.columns[1].list.find('[aria-selected="true"]').length, 0);
        c.panel.find('.admin-cascader-select')[0].click();
        assert.deepEqual(values(w), ['0', '', '']);
        assert.deepEqual(values(w, 1), ['0', '', '']);
        c.commit([]);
        assert.deepEqual(values(w, 1), ['0', '', '']);
    } finally { w.close(); }
});

test('readonly and disabled fields resolve display but cannot change', () => {
    for (const type of ['readonly', 'disabled']) {
        const { w, requests, controller } = page(type);
        try {
            requests[0].done({ items: nodes });
            const c = controller(); c.open(); c.commit([]);
            assert.equal(c.opened, false);
            assert.deepEqual(values(w), ['0', '1', '2']);
            assert.equal(new w.FormData(w.document.querySelector('form')).has('province_id'), type === 'readonly');
        } finally { w.close(); }
    }
});

test('paging, retry, keyboard navigation and dynamic row cleanup', async () => {
    const { w, requests, controller } = page();
    try {
        const c = controller(); c.open(); requests[0].fail(503);
        c.panel.find('.admin-cascader-retry')[0].click();
        requests[1].done({ items: [nodes[0]], nextCursor: '50' });
        c.panel.find('.admin-cascader-more')[0].click();
        assert.equal(requests[2].options.data.cursor, '50');
        requests[2].done({ items: [{ value: '9', label: 'More', leaf: true }], nextCursor: null });
        assert.equal(c.columns[0].list.find('.admin-cascader-node').length, 2);
        c.columns[0].search.trigger(w.$.Event('keydown', { key: 'ArrowDown' }));
        assert.equal(w.document.activeElement, c.columns[0].element.find('.admin-cascader-node')[0]);
        c.panel.trigger(w.$.Event('keydown', { key: 'Escape' }));
        assert.equal(c.opened, false);
        assert.equal(w.document.activeElement, c.toggle[0]);
        c.open();
        w.$(w.document).trigger(w.$.Event('keydown', { key: 'Escape' }));
        assert.equal(c.opened, false);
        const row = w.$(fixtures.empty).appendTo(w.document.querySelector('form'));
        await new Promise(resolve => setTimeout(resolve, 10));
        const dynamic = row.find('[data-admin-cascader]').data('adminCascader');
        assert.ok(dynamic);
        row.remove();
        await new Promise(resolve => setTimeout(resolve, 10));
        assert.equal(dynamic.destroyed, true);
        assert.equal(w.document.getElementById(dynamic.panel.attr('id')), null);
    } finally { w.close(); }
});
