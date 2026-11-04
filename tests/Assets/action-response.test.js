const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

for (const status of [true, false]) {
    test(`tool form ${status ? 'closes and refreshes after success' : 'stays open after failure'}`, async () => {
        const dom = new JSDOM('<a data-admin-action="ticket" data-admin-mode="form" data-admin-keyed="0" data-admin-load="/load" data-admin-execute="/execute">Tool</a>', {
            url: 'http://localhost/', runScripts: 'outside-only',
        });
        try {
            const window = dom.window;
            window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
            const $ = window.jQuery;
            let hides = 0, refreshes = 0, notifications = 0;
            const timers = [];
            window.setTimeout = callback => { timers.push(callback); };
            $.fn.modal = function (command) {
                if (command === 'hide') {
                    const event = $.Event('hide.bs.modal');
                    this.trigger(event);
                    assert.equal(event.isDefaultPrevented(), false);
                    hides++;
                    this.trigger('hidden.bs.modal');
                }
                return this;
            };
            $.fn.button = function () { return this; };
            $.admin = {
                token: 'csrf', isResponseError: data => data.status === false,
                responseMessage: () => '',
                toastr: {
                    success: () => { notifications++; },
                    error: () => { notifications++; },
                },
                reload: () => { refreshes++; },
            };
            $.ajax = options => Promise.resolve(options.url === '/load' ? {
                mode: 'form', ticket: 'execution', assets: {},
                html: '<div class="modal" id="tool-form"><div class="modal-dialog"><form><div class="modal-body"><input name="title"></div><button type="submit">Save</button></form></div></div>',
            } : {
                status, then: { action: 'refresh', value: true },
                toastr: { type: status ? 'success' : 'error', content: 'Result' },
            });
            window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/actions.js'), 'utf8'));
            $('a').trigger('click');
            await new Promise(resolve => setImmediate(resolve));
            assert.equal($('#tool-form form').length, 1);
            $('#tool-form form').trigger('submit');
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(hides, status ? 1 : 0);
            assert.equal(notifications, 1);
            assert.equal(refreshes, 0);
            timers.forEach(callback => callback());
            assert.equal(refreshes, status ? 1 : 0);
            assert.equal($('#tool-form form').length, status ? 0 : 1);
        } finally {
            dom.window.close();
        }
    });
}

test('toolbar actions do not send a placeholder key as a record selection', async () => {
    const dom = new JSDOM('<a data-admin-action="ticket" data-admin-mode="dialog" data-admin-keyed="0" data-admin-load="/load" data-admin-execute="/execute" data-_key="0">Tool</a>', {
        url: 'http://localhost/', runScripts: 'outside-only',
    });
    try {
        const window = dom.window;
        window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
        const $ = window.jQuery;
        let loadData;
        $.admin = {
            token: 'csrf', isResponseError: data => data.status === false,
            responseMessage: () => '', toastr: { error: message => assert.fail(message) },
            swal: () => Promise.resolve({}),
        };
        $.ajax = options => {
            loadData = options.data;
            return Promise.resolve({ mode: 'dialog', ticket: 'execution', settings: { title: 'Tool' }, assets: {} });
        };
        window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/actions.js'), 'utf8'));
        $('a').trigger('click');
        await new Promise(resolve => setTimeout(resolve, 0));

        assert.equal(loadData._key.length, 0);
    } finally {
        dom.window.close();
    }
});

test('action success dialog follows confirmation and refresh waits for acknowledgement', async () => {
    const dom = new JSDOM('<a data-admin-action="ticket" data-admin-mode="dialog" data-admin-execute="/action" data-admin-dialog="{}">Run</a>', {
        url: 'http://localhost/', runScripts: 'outside-only',
    });
    try {
        const window = dom.window;
        window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
        const $ = window.jQuery;
        const dialogs = [];
        let confirmationClosed = false, refreshes = 0;
        $.admin = {
            token: 'csrf', isResponseError: data => data.status === false,
            responseMessage: () => '', toastr: { error: message => assert.fail(message) },
            reload: options => { assert.equal(options.adminLoading, false); refreshes++; },
            swal(settings) {
                if (dialogs.length) assert.equal(confirmationClosed, true);
                let resolve;
                const promise = new Promise(done => { resolve = done; });
                dialogs.push({ settings, resolve });
                return promise;
            },
        };
        $.ajax = () => Promise.resolve({ status: true, swal: { title: 'Saved', type: 'success' }, then: { action: 'refresh' } });
        window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/actions.js'), 'utf8'));
        $('a').trigger('click');
        const result = await dialogs[0].settings.preConfirm(true);
        assert.equal(dialogs.length, 1);
        assert.equal(refreshes, 0);
        confirmationClosed = true;
        dialogs[0].resolve({ value: result });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(dialogs.length, 2);
        assert.equal(dialogs[1].settings.title, 'Saved');
        assert.equal(refreshes, 0);
        dialogs[1].resolve({ value: true });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(refreshes, 1);
    } finally {
        dom.window.close();
    }
});

test('resource delete confirmation posts multipart data with the DELETE override and signed ticket', async () => {
    const dom = new JSDOM('<a data-admin-action="signed" data-admin-mode="dialog" data-admin-execute="/admin/records/7" data-admin-method="DELETE" data-admin-dialog="{}" data-_key="7">Delete</a>', {
        url: 'http://localhost/admin/records', runScripts: 'outside-only',
    });
    try {
        const window = dom.window;
        window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
        const $ = window.jQuery;
        let settings, sent;
        $.admin = {
            token: 'csrf', isResponseError: data => data.status === false,
            responseMessage: () => '', toastr: { error: message => assert.fail(message) },
            swal: value => { settings = value; return new Promise(() => {}); },
        };
        $.ajax = options => { sent = options; return Promise.resolve({status: true}); };
        window.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/actions.js'), 'utf8'));
        $('a').trigger('click');
        await settings.preConfirm(true);
        assert.equal(sent.url, '/admin/records/7');
        assert.equal(sent.method, 'POST');
        assert.equal(sent.contentType, false);
        assert.equal(sent.data.get('_method'), 'DELETE');
        assert.equal(sent.data.get('_token'), 'csrf');
        assert.equal(sent.data.get('_action_token'), 'signed');
        assert.equal(sent.data.get('_key[0]'), '7');
    } finally {
        dom.window.close();
    }
});
