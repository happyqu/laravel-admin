const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

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
