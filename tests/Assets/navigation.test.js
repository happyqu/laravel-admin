const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const jquery = read('resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js');
const core = read('resources/assets/laravel-admin/laravel-admin.js');
const navigation = read('resources/assets/laravel-admin/admin-navigation.js');

function html(content, title = 'Page', extra = '') {
    return `<!doctype html><html><head><title>${title}</title><meta name="csrf-token" content="fresh-token">
        <script src="/core.js"></script>${extra}</head><body><div id="admin-content">${content}</div>
        <script>throw new Error('The full page bootstrap must not execute again');</script></body></html>`;
}

async function page(content) {
    const dom = new JSDOM(html(content), { url: 'http://localhost/admin/users', runScripts: 'outside-only', virtualConsole: new VirtualConsole() });
    const window = dom.window;
    const requests = [];
    const errors = [];
    const scripts = {};
    let scroll = 0;
    window.scrollTo = (x, y) => { scroll = y; };
    Object.defineProperty(window, 'pageYOffset', { get: () => scroll });
    window.eval(jquery);
    const $ = window.jQuery;
    $.fn.editable = { defaults: {} };
    $.fn.popover = function () { return this; };
    window.LA = function () {};
    window.toastr = { error: message => errors.push(message), success() {} };
    window.swal = function () {};
    window.NProgress = { configure() {}, start() {}, done() {} };
    window.eval(core);

    $.ajaxSettings.xhr = () => ({});
    $.ajax = options => {
        const deferred = $.Deferred();
        if (options.dataType === 'script') {
            const code = scripts[options.url];
            if (code) { window.eval(code); deferred.resolve(); }
            else { deferred.reject(new Error('Unexpected asset: ' + options.url)); }
            return deferred.promise();
        }
        const transport = options.xhr ? options.xhr() : {};
        let contentType = 'text/html';
        const xhr = deferred.promise({
            status: 200, statusText: '',
            getResponseHeader: () => contentType,
            abort() { xhr.statusText = 'abort'; deferred.reject(xhr); },
        });
        const request = {
            options, xhr,
            resolve(body, url = options.url, type = 'text/html') {
                contentType = type;
                transport.responseURL = url;
                deferred.resolve(body);
            },
            reject(status, body = {}, type = 'application/json') {
                xhr.status = status;
                contentType = type;
                xhr.responseText = JSON.stringify(body);
                if (/json/i.test(type)) { xhr.responseJSON = body; }
                deferred.reject(xhr);
            },
        };
        requests.push(request);
        return xhr;
    };
    window.eval(navigation);
    await new Promise(resolve => setTimeout(resolve, 0));
    return { dom, window, $, requests, errors, scripts, scroll: () => scroll };
}

async function until(condition) {
    for (let attempt = 0; attempt < 50; attempt++) {
        if (condition()) return;
        await new Promise(resolve => setTimeout(resolve, 5));
    }
    assert.fail('Expected browser update did not occur');
}

function iframePage(app, openerId, currentId = 'iframe_editor') {
    Object.defineProperty(app.window, 'frameElement', { value: { id: currentId }, configurable: true });
    const source = read('src/Concerns/RegistersIframeTabs.php');
    const script = source.match(/\$script = <<<'EOT'\r?\n([\s\S]*?)\r?\nEOT;/)[1];
    app.window.eval(script.replace('__ADMIN_IFRAME_PAYLOAD__', JSON.stringify({
        iframeId: openerId, bindUrls: 'popup', bindSelecter: '', layerSize: '900px,90%',
    })));
}

test('ordinary AJAX saves request the redirect destination once and keep its URL', async t => {
    const app = await page('<form id="edit" method="post" action="/admin/users/1"><input name="name" value="Alice"><button>Save</button></form>');
    t.after(() => app.window.close());
    // The opener id may survive a same-frame edit; this is still the current tab.
    iframePage(app, 'iframe_list', 'iframe_list');
    const task = app.$.admin.submitForm(app.$('#edit')[0]);
    app.requests[0].resolve(JSON.stringify({ status: true, message: 'Saved', redirect: '/admin/users?page=2', after_save: '' }), undefined, 'application/json');
    await until(() => app.requests.length === 2);
    assert.equal(app.requests[1].options.url, 'http://localhost/admin/users?page=2');
    app.requests[1].resolve(html('<p id="saved-list">Saved list</p>'));
    await task;
    assert.equal(app.requests.length, 2); // One save and one destination GET.
    assert.equal(app.window.location.search, '?page=2');
    assert.ok(app.$('#saved-list').length);
});

test('popup AJAX saves refresh the opener once without loading the list inside the editor', async t => {
    const app = await page('<iframe id="iframe_list"></iframe><form id="edit" method="post" action="/admin/users/1"><button>Save</button></form>');
    t.after(() => app.window.close());
    const events = [];
    const opener = app.$('#iframe_list')[0].contentWindow;
    opener.$ = { admin: { reload() { events.push('refresh-list'); } } };
    app.window.layer = { getFrameIndex() { return 1; }, close() { events.push('close-popup'); } };
    iframePage(app, 'iframe_list');
    const task = app.$.admin.submitForm(app.$('#edit')[0]);
    app.requests[0].resolve(JSON.stringify({ status: true, message: 'Saved', redirect: '/admin/users?page=2', after_save: '' }), undefined, 'application/json');
    await task;
    assert.deepEqual(events, ['refresh-list', 'close-popup']);
    assert.equal(app.requests.length, 1); // No redundant GET in the editor.
});

test('continue editing in a popup follows the destination and leaves the opener alone', async t => {
    const app = await page('<iframe id="iframe_list"></iframe><form id="edit" method="post" action="/admin/users/1"><button>Save</button></form>');
    t.after(() => app.window.close());
    const events = [];
    app.$('#iframe_list')[0].contentWindow.$ = { admin: { reload() { events.push('refresh'); } } };
    app.window.layer = { getFrameIndex() { return 1; }, close() { events.push('close'); } };
    iframePage(app, 'iframe_list');
    const task = app.$.admin.submitForm(app.$('#edit')[0]);
    app.requests[0].resolve(JSON.stringify({ status: true, message: 'Saved', redirect: '/admin/users/1/edit', after_save: '1' }), undefined, 'application/json');
    await until(() => app.requests.length === 2);
    app.requests[1].resolve(html('<form id="still-editing"></form>'));
    await task;
    assert.deepEqual(events, []);
    assert.equal(app.window.location.pathname, '/admin/users/1/edit');
});

test('pagination replaces content, executes new page scripts and keeps the shell', async t => {
    const app = await page('<div class="pagination"><a href="?page=2">Next</a></div>');
    t.after(() => app.dom.window.close());
    const originalAdmin = app.$.admin;
    app.$('.pagination a')[0].click();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].options.type, 'GET');
    assert.equal(app.requests[0].options.headers.Accept, 'text/html');
    app.requests[0].resolve(html('<p id="page-two">Second page</p><script>window.pageInitialized = true;</script>', 'Users - page 2'), 'http://localhost/admin/users?page=2');
    await until(() => app.window.pageInitialized);
    assert.ok(app.window.document.getElementById('page-two'));
    assert.equal(app.window.location.search, '?page=2');
    assert.equal(app.window.document.title, 'Users - page 2');
    assert.equal(app.$.admin, originalAdmin);
    assert.equal(app.$.admin.token, 'fresh-token');
});

test('GET filters serialize fields into the URL without submitting files or tokens as JSON', async t => {
    const app = await page('<form id="filter" method="get" action="/admin/users"><input name="name" value="Alice Smith"></form>');
    t.after(() => app.window.close());
    const task = app.$.admin.submitForm(app.window.document.getElementById('filter'));
    const request = app.requests[0];
    assert.equal(new URL(request.options.url).searchParams.get('name'), 'Alice Smith');
    assert.equal(request.options.type, 'GET');
    request.resolve(html('Filtered'));
    await task;
    assert.equal(app.window.location.search, '?name=Alice+Smith');
});

test('POST forms retain CSRF, method override, submitter and file fields, then follow the redirect', async t => {
    const app = await page(`<form id="edit" method="post" action="/admin/users/1" data-admin-form="true">
        <input name="_token" value="csrf"><input name="_method" value="PUT"><input name="name" value="Alice">
        <input type="file" name="avatar"><button type="submit" name="save_action" value="keep">Save</button></form>`);
    t.after(() => app.window.close());
    const form = app.window.document.getElementById('edit');
    form.dispatchEvent(new app.window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('button') }));
    const request = app.requests[0];
    assert.equal(request.options.type, 'POST');
    assert.equal(request.options.processData, false);
    assert.equal(request.options.contentType, false);
    assert.equal(request.options.data.get('_token'), 'csrf');
    assert.equal(request.options.data.get('_method'), 'PUT');
    assert.equal(request.options.data.get('save_action'), 'keep');
    assert.ok(request.options.data.get('avatar') instanceof app.window.File);
    await until(() => form.querySelector('button').disabled);
    request.resolve(html('<div id="saved">Saved</div>'), 'http://localhost/admin/users');
    await until(() => !!app.window.document.getElementById('saved'));
    assert.equal(app.requests.length, 1);
    assert.equal(app.window.location.pathname, '/admin/users');
});

test('HTML validation redirects preserve the form URL and show server errors', async t => {
    const app = await page('<form id="edit" method="post" action="/admin/users" data-admin-form="true"><input name="name" value="Alice"><button>Save</button></form>');
    t.after(() => app.window.close());
    const task = app.$.admin.submitForm(app.window.document.getElementById('edit'));
    app.requests[0].resolve(html('<form><div class="has-error">Name is required</div></form>'), 'http://localhost/admin/users/create');
    await task;
    assert.equal(app.window.location.pathname, '/admin/users/create');
    assert.match(app.$('#admin-content').text(), /Name is required/);
});

test('JSON 422 validation keeps entered values, escapes errors, and unlocks submit', async t => {
    const app = await page('<form id="edit" method="post" data-admin-form="true"><div class="form-group"><input name="name" value="keep me"></div><button>Save</button></form>');
    t.after(() => app.window.close());
    const form = app.window.document.getElementById('edit');
    const task = app.$.admin.submitForm(form);
    await until(() => form.querySelector('button').disabled);
    app.requests[0].reject(422, {errors: {name: ['<script>bad</script>']}});
    await task;
    assert.equal(form.querySelector('input').value, 'keep me');
    assert.equal(form.querySelector('button').disabled, false);
    assert.ok(form.querySelector('.has-error'));
    assert.equal(form.querySelector('.admin-ajax-error script'), null);
    assert.match(form.textContent, /<script>bad<\/script>/);
});

test('network failures never retry a save, and allow another attempt', async t => {
    const app = await page('<form id="edit" method="post" data-admin-form="true"><button>Save</button></form>');
    t.after(() => app.window.close());
    const form = app.window.document.getElementById('edit');
    const task = app.$.admin.submitForm(form);
    app.requests[0].reject(500);
    await task;
    assert.equal(app.requests.length, 1);
    assert.equal(form.querySelector('button').disabled, false);
    assert.equal(app.errors.length, 1);
    const retry = app.$.admin.submitForm(form);
    assert.equal(app.requests.length, 2);
    app.requests[1].resolve(html('Saved'));
    await retry;
});

test('browser back and forward fetch the correct URL and restore scroll position', async t => {
    const app = await page('First page');
    t.after(() => app.window.close());
    app.window.scrollTo(0, 140);
    const task = app.$.admin.navigate('/admin/users?page=2');
    app.requests[0].resolve(html('Second page'));
    await task;
    app.window.scrollTo(0, 260);
    app.$(app.window).trigger('scroll');
    await until(() => app.window.history.state.scrollY === 260);
    app.window.history.back();
    await until(() => app.requests.length === 2);
    assert.equal(app.requests[1].options.url, 'http://localhost/admin/users');
    app.requests[1].resolve(html('First page'));
    await until(() => app.scroll() === 140);
    app.window.history.forward();
    await until(() => app.requests.length === 3);
    app.requests[2].resolve(html('Second page'));
    await until(() => app.$('#admin-content').text() === 'Second page');
    assert.equal(app.window.location.search, '?page=2');
    assert.equal(app.scroll(), 260);
});

test('a later pagination request supersedes an unfinished earlier request', async t => {
    const app = await page('Initial');
    t.after(() => app.window.close());
    const first = app.$.admin.navigate('?page=2');
    const second = app.$.admin.navigate('?page=3');
    assert.equal(app.requests[0].xhr.statusText, 'abort');
    app.requests[1].resolve(html('Third page'));
    await Promise.all([first, second]);
    assert.equal(app.window.location.search, '?page=3');
    assert.equal(app.$('#admin-content').text(), 'Third page');
    assert.equal(app.errors.length, 0);
});

test('new field assets load before initialization and are loaded once', async t => {
    const app = await page('Initial');
    t.after(() => app.window.close());
    app.scripts['http://localhost/field.js'] = 'window.fieldLoads = (window.fieldLoads || 0) + 1;';
    const body = html('<script src="/field.js"></script><script>window.fieldReady = window.fieldLoads;</script>');
    for (let i = 0; i < 2; i++) {
        const task = app.$.admin.navigate('?page=' + (i + 2));
        app.requests[i].resolve(body);
        await task;
    }
    assert.equal(app.window.fieldLoads, 1);
    assert.equal(app.window.fieldReady, 1);
});

test('page event handlers are cleaned up between AJAX renders', async t => {
    const app = await page('Initial');
    t.after(() => app.window.close());
    const body = html(`<button type="button" class="pick">Pick</button><script>
        $('body').on('click.adminPage', '.pick', function () { window.picks = (window.picks || 0) + 1; });
        </script>`);
    for (let i = 0; i < 2; i++) {
        const task = app.$.admin.navigate('?page=' + (i + 2));
        app.requests[i].resolve(body);
        await task;
    }
    app.$('.pick').trigger('click');
    assert.equal(app.window.picks, 1);
});

test('iframe popup handlers are not duplicated by AJAX navigation', async t => {
    const app = await page('Initial');
    t.after(() => app.window.close());
    let popups = 0;
    app.window.layer = { open() { popups++; return 1; } };
    const php = read('src/Concerns/RegistersIframeTabs.php');
    const script = php.match(/\$script = <<<'EOT'\r?\n([\s\S]*?)\r?\nEOT;/)[1].replace('__ADMIN_IFRAME_PAYLOAD__', JSON.stringify({
        iframeId: '', listIframeId: '', bindUrls: 'popup', bindSelecter: '.popup', layerSize: '900px,90%',
    }));
    const body = html('<a class="popup" href="/admin/users/1/edit">Edit</a><script>' + script + '</script>');
    for (let i = 0; i < 2; i++) {
        const task = app.$.admin.navigate('?page=' + (i + 2));
        app.requests[i].resolve(body);
        await task;
    }
    app.$('.popup').trigger('click');
    assert.equal(popups, 1);
    assert.equal(app.requests.length, 2);
});

test('invalid forms, downloads and explicit native opt-outs are not sent with AJAX', async t => {
    const app = await page('<form id="edit" method="post"><input required name="name"><button>Save</button></form><a href="?_export_=all">Export</a><form id="native" data-admin-ajax="false"></form>');
    t.after(() => app.window.close());
    assert.equal(await app.$.admin.submitForm(app.window.document.getElementById('edit')), false);
    assert.equal(app.$.admin.canSubmitForm(app.window.document.getElementById('native')), false);
    app.$('a')[0].click();
    assert.equal(app.requests.length, 0);
});

test('a custom failure envelope keeps the form and shows msg without refreshing', async t => {
    const app = await page('<form id="edit" method="post"><input name="name" value="Alice"><button>Save</button></form>');
    t.after(() => app.window.close());
    const form = app.window.document.getElementById('edit');
    const task = app.$.admin.submitForm(form);
    app.requests[0].resolve(JSON.stringify({ success: false, msg: '系统异常', errcode: 50021 }), undefined, 'application/json');
    assert.equal(await task, false);
    assert.equal(app.requests.length, 1);
    assert.equal(form.querySelector('.admin-ajax-error').textContent, '系统异常');
    assert.equal(form.elements.name.value, 'Alice');
});

test('an action load failure envelope reports msg and does not open a confirmation', async t => {
    const app = await page('<a id="delete" data-admin-action="definition" data-admin-mode="dialog" data-admin-load="/admin/_load_action_" data-admin-execute="/admin/_handle_action_" data-_key="1">Delete</a>');
    t.after(() => app.window.close());
    let confirmations = 0;
    app.$.admin.swal = () => { confirmations++; };
    app.window.eval(read('resources/assets/actions.js'));
    app.$('#delete').trigger('click');
    app.requests[0].resolve({ success: false, msg: '系统异常', errcode: 50021 });
    await until(() => app.errors.length);
    assert.deepEqual(app.errors, ['系统异常']);
    assert.equal(confirmations, 0);
    assert.equal(app.requests.length, 1);
});

test('a failed delete cannot run a refresh command and can be retried', async t => {
    const app = await page('<a id="delete" data-admin-action="definition" data-admin-mode="dialog" data-admin-load="/admin/_load_action_" data-admin-execute="/admin/_handle_action_" data-_key="1">Delete</a>');
    t.after(() => app.window.close());
    let confirmation;
    app.$.admin.swal = settings => { confirmation = settings; return Promise.resolve({}); };
    app.window.eval(read('resources/assets/actions.js'));
    app.$('#delete').trigger('click');
    app.requests[0].resolve({ mode: 'dialog', ticket: 'execution', settings: { title: 'Delete?' } });
    await until(() => confirmation);
    const task = confirmation.preConfirm();
    assert.equal(app.requests[1].options.data.get('_action_token'), 'execution');
    app.requests[1].resolve({ success: false, msg: '删除失败', then: { action: 'refresh' } });
    await assert.rejects(task);
    assert.deepEqual(app.errors, ['删除失败']);
    assert.equal(app.requests.length, 2);
    const retry = confirmation.preConfirm();
    assert.equal(app.requests.length, 3);
    app.requests[2].reject(500, { success: false, msg: '系统异常' });
    let rejected = false;
    await retry.catch(() => { rejected = true; });
    assert.equal(rejected, true);
    assert.deepEqual(app.errors, ['删除失败', '系统异常']);
});

test('inline delete confirmation opens immediately, cancel sends nothing, and confirm sends one POST', async t => {
    const app = await page('<a id="delete" data-admin-action="execution" data-admin-mode="dialog" data-admin-load="/admin/_load_action_" data-admin-execute="/admin/_handle_action_" data-_key="2">Delete</a>');
    t.after(() => app.window.close());
    app.$('#delete').attr('data-admin-dialog', JSON.stringify({ title: 'Delete?', showCancelButton: true, showLoaderOnConfirm: true }));
    const confirmations = [];
    app.$.admin.swal = settings => { confirmations.push(settings); return Promise.resolve({ dismiss: 'cancel' }); };
    app.window.eval(read('resources/assets/actions.js'));
    app.$('#delete').trigger('click');
    assert.equal(confirmations.length, 1); // Synchronous: no loading request first.
    assert.equal(app.requests.length, 0);
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(app.requests.length, 0); // Cancel never contacts the server.
    app.$('#delete').trigger('click');
    const confirmation = confirmations[1];
    const task = confirmation.preConfirm();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].options.method, 'POST');
    assert.equal(app.requests[0].options.url, '/admin/_handle_action_');
    assert.equal(app.requests[0].options.data.get('_action_token'), 'execution');
    assert.equal(confirmation.allowOutsideClick(), false);
    assert.equal(confirmation.allowEscapeKey(), false);
    await assert.rejects(confirmation.preConfirm());
    assert.equal(app.requests.length, 1); // A second confirmation cannot duplicate the delete.
    app.requests[0].resolve({ status: true });
    await task;
    assert.equal(confirmation.allowOutsideClick(), true);
});

test('batch confirmations do not load tickets until confirmed and retain the original selection', async t => {
    const app = await page('<div class="grid-box"><input type="checkbox" data-id="2" class="rows-checkbox" checked><a id="batch" data-admin-action="definition" data-admin-mode="dialog" data-admin-prepare="1" data-admin-batch="1" data-admin-selection="rows-checkbox" data-admin-load="/admin/_load_action_" data-admin-execute="/admin/_handle_action_">Batch</a></div>');
    t.after(() => app.window.close());
    app.$('#batch').attr('data-admin-dialog', JSON.stringify({ title: 'Confirm batch?' }));
    let confirmation;
    app.$.admin.swal = settings => { confirmation = settings; return Promise.resolve({ dismiss: 'cancel' }); };
    app.window.eval(read('resources/assets/actions.js'));
    app.$('#batch').trigger('click');
    assert.ok(confirmation);
    assert.equal(app.requests.length, 0);
    app.$('input').prop('checked', false);
    const task = confirmation.preConfirm();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].options.method, 'GET');
    assert.deepEqual(Array.from(app.requests[0].options.data._key), ['2']);
    app.requests[0].resolve({ mode: 'dialog', ticket: 'selected-execution' });
    await until(() => app.requests.length === 2);
    assert.equal(app.requests[1].options.method, 'POST');
    assert.equal(app.requests[1].options.data.get('_action_token'), 'selected-execution');
    app.requests[1].resolve({ status: true });
    await task;
});

test('a rejected batch preparation never sends the mutation', async t => {
    const app = await page('<a id="batch" data-admin-action="definition" data-admin-mode="dialog" data-admin-prepare="1" data-admin-load="/admin/_load_action_" data-admin-execute="/admin/_handle_action_">Batch</a>');
    t.after(() => app.window.close());
    app.$('#batch').attr('data-admin-dialog', JSON.stringify({ title: 'Confirm?' }));
    let confirmation;
    app.$.admin.swal = settings => { confirmation = settings; return Promise.resolve({}); };
    app.window.eval(read('resources/assets/actions.js'));
    app.$('#batch').trigger('click');
    const task = confirmation.preConfirm();
    app.requests[0].resolve({ success: false, msg: 'Permission denied' });
    await assert.rejects(task);
    assert.equal(app.requests.length, 1);
    assert.deepEqual(app.errors, ['Permission denied']);
});

async function logPage(action) {
    const app = await page(`<div id="admin-log-viewer"><form class="log-delete-form" method="post" action="${action}"><input name="_token" value="csrf"><input name="_method" value="DELETE"><button>Delete</button></form></div>`);
    const script = read('resources/views/log-viewer/logs.blade.php').match(/<script>\s*([\s\S]*?)<\/script>/)[1]
        .replace(/@json\(trans\('([^']+)'\)\)/g, (_, key) => JSON.stringify(key))
        .replace('@json($end)', '0').replace('@json($tailPath)', '""');
    app.window.confirm = () => { throw new Error('Native confirmation must not be used'); };
    app.window.eval(script);
    await until(() => app.$._data(app.$('form')[0], 'events')?.submit);
    return app;
}

for (const action of ['/admin/logs/file/delete', '/admin/logs/delete-all']) {
    test('log deletion uses the shared confirmation and sends only after confirm: ' + action, async t => {
        const app = await logPage(action);
        t.after(() => app.window.close());
        const dialogs = [];
        let dismiss;
        app.$.admin.swal = settings => { dialogs.push(settings); return new Promise(resolve => { dismiss = resolve; }); };
        app.$('form').trigger('submit').trigger('submit');
        assert.equal(dialogs.length, 1);
        assert.equal(dialogs[0].title, 'admin.delete_confirm');
        assert.equal(app.requests.length, 0);
        dismiss({ dismiss: 'cancel' });
        await new Promise(resolve => setTimeout(resolve, 0));
        assert.equal(app.requests.length, 0);
        app.$('form').trigger('submit');
        assert.equal(dialogs.length, 2);
        const task = dialogs[1].preConfirm();
        assert.equal(app.requests.length, 1);
        assert.equal(new URL(app.requests[0].options.url).pathname, action);
        assert.equal(app.requests[0].options.data.get('_method'), 'DELETE');
        assert.equal(app.requests[0].options.data.get('_token'), 'csrf');
        await assert.rejects(dialogs[1].preConfirm());
        assert.equal(app.requests.length, 1);
        app.requests[0].resolve(html('<div id="admin-log-viewer">Logs</div>'), 'http://localhost/admin/logs');
        await task;
    });
}
