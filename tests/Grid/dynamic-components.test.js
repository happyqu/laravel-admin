const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const components = JSON.parse(execFileSync('php', ['tests/Grid/fixtures/components.php'], { cwd: root, encoding: 'utf8' }));

function page(type, { defer = false } = {}) {
    const items = [components[type + '1'], components[type + '2']];
    const w = new JSDOM('<table><tbody>' + items.map(item => '<tr><td>' + item.html + '</td></tr>').join('') + '</tbody></table>', {
        runScripts: 'outside-only', url: 'http://localhost/',
    }).window;
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    const calls = [];
    const requests = [];
    w.$.get = (url, success) => {
        calls.push(new URL(url, w.location.href));
        const request = w.$.Deferred();
        requests.push(request);
        request.done(success);
        if (!defer) request.resolve('<p>Loaded</p>');
        return request.promise();
    };
    items.forEach(item => w.eval(item.script));
    return { w, calls, items, requests };
}

for (const type of ['expand', 'modal']) {
    test(type + ' preserves each row ticket and exact key in AJAX requests', () => {
        const { w, calls, items } = page(type);
        try {
            // Click the second row first to detect handlers using the first row's URL.
            for (const index of [1, 0]) {
                if (type === 'expand') {
                    w.$('.grid-expand-table').eq(index).trigger('click');
                } else {
                    const trigger = w.$('[data-toggle="modal"]').eq(index);
                    w.$(trigger.attr('data-target')).trigger(w.$.Event('show.bs.modal', { relatedTarget: trigger[0] }));
                }
                assert.equal(calls.length, index === 1 ? 1 : 2);
                const query = calls.at(-1).searchParams;
                assert.equal(query.get('_component_token_'), items[index].token);
                assert.equal(query.get('key'), items[index].key);
                assert.equal(query.has('amp;_component_token_'), false);
                assert.match(w.$('#grid-' + (type === 'expand' ? 'collapse' : 'modal') + '-preview-' + (index + 1)).text(), /Loaded/);
            }
            if (type === 'expand') {
                w.$('.grid-expand-table').eq(1).trigger('click');
                assert.equal(calls.length, 2, 'already loaded rows should not request again');
                assert.equal(w.$('.grid-expand-table').eq(0).find('i').hasClass('fa-angle-double-up'), true);
            }
        } finally { w.close(); }
    });
}

for (const status of [0, 403, 419, 500]) {
    test('modal replaces failed requests with an error and retries the exact URL (status ' + status + ')', () => {
        const { w, calls, requests } = page('modal', { defer: true });
        try {
            const trigger = w.$('[data-toggle="modal"]').eq(1);
            const modal = w.$(trigger.attr('data-target'));
            modal.trigger(w.$.Event('show.bs.modal', { relatedTarget: trigger[0] }));
            assert.equal(modal.find('.loading').length, 1);
            requests[0].reject({ status, responseText: '<img src=x onerror=alert(1)>' });
            assert.equal(modal.find('.loading').length, 0);
            assert.match(modal.find('[role="alert"]').text(), /Request failed/);
            assert.equal(modal.find('img').length, 0);
            assert.equal(w.$('#grid-modal-preview-1 [role="alert"]').length, 0);

            modal.find('.modal-load-retry').trigger('click');
            assert.equal(calls.length, 2);
            assert.equal(calls[1].href, calls[0].href);
            assert.equal(modal.find('.loading').length, 1);
            assert.equal(modal.find('.modal-load-retry').length, 0);
            requests[1].reject({ status });
            assert.equal(modal.find('.modal-load-retry').length, 1);
            modal.find('.modal-load-retry').trigger('click');
            assert.equal(calls.length, 3);
            assert.equal(calls[2].href, calls[0].href);
            requests[2].resolve('<p>Recovered</p>');
            assert.match(modal.find('.modal-body').text(), /Recovered/);
            assert.equal(modal.find('.loading, [role="alert"], .modal-load-retry').length, 0);
        } finally { w.close(); }
    });
}

test('modal ignores an older request failure after reopening and loading successfully', () => {
    const { w, requests } = page('modal', { defer: true });
    try {
        const trigger = w.$('[data-toggle="modal"]').eq(0);
        const modal = w.$(trigger.attr('data-target'));
        modal.trigger(w.$.Event('show.bs.modal', { relatedTarget: trigger[0] }));
        modal.trigger(w.$.Event('show.bs.modal', { relatedTarget: trigger[0] }));
        requests[1].resolve('<p>Latest content</p>');
        requests[0].reject({ status: 500 });
        assert.match(modal.find('.modal-body').text(), /Latest content/);
        assert.equal(modal.find('[role="alert"]').length, 0);
    } finally { w.close(); }
});
