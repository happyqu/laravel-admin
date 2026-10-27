const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const jquery = fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8');
const source = fs.readFileSync(path.join(root, 'src/Concerns/RegistersIframeTabs.php'), 'utf8');
const script = source.match(/\$script = <<<'EOT'\r?\n([\s\S]*?)\r?\nEOT;/)[1];

function boot(w, payload = {}) {
    w.eval(script.replace('__ADMIN_IFRAME_PAYLOAD__', JSON.stringify({
        bindUrls: 'popup', bindSelecter: 'a.edit', layerSize: '900px,90%', ...payload,
    })));
}

function page(t) {
    const w = new JSDOM('<a class="edit" href="/admin/sys/config/1/edit">Edit</a>', {
        url: 'https://doublec.test/admin/sys/config', runScripts: 'outside-only',
    }).window;
    t.after(() => w.close());
    w.eval(jquery);
    const events = [];
    let opened;
    w.$.admin = { reload() { events.push('refresh-list'); } };
    w.toastr = { success() { events.push('notify'); } };
    w.layer = {
        open(options) { opened = options; return 3; },
        getFrameIndex() { return 3; },
        close(index) { events.push('close-popup:' + index); },
    };
    boot(w);
    w.$('a.edit').trigger('click');
    const url = new URL(opened.content, w.location.href);
    const openerId = url.searchParams.get('_iframe_id_');
    const frame = w.document.createElement('iframe');
    frame.src = url.href;
    w.document.body.appendChild(frame);
    const editor = frame.contentWindow;
    editor.document.write('<form id="edit"><input name="value"></form>');
    editor.eval(jquery);
    editor.$.admin = {};
    return { w, editor, events, openerId };
}

test('standalone list popup AJAX save refreshes its opener and closes the editor', t => {
    const { editor, events, openerId } = page(t);
    assert.ok(openerId, 'the edit URL must record the standalone opener');
    boot(editor, { iframeId: openerId });
    const form = editor.document.getElementById('edit');
    assert.equal(editor.$(form).find('[name="_iframe_id_"]').val(), openerId);
    assert.equal(editor.$.admin.afterFormSaved(form, { message: 'Saved', after_save: '' }), true);
    assert.deepEqual(events, ['refresh-list', 'notify', 'close-popup:3']);
});

test('standalone list popup native redirect refreshes its opener and closes the editor', t => {
    const { editor, events, openerId } = page(t);
    assert.ok(openerId);
    boot(editor, { listIframeId: openerId, listAfterSave: '', successMessage: 'Saved' });
    assert.deepEqual(events, ['refresh-list', 'notify', 'close-popup:3']);
});

test('continue editing in a standalone popup keeps the editor open', t => {
    const { editor, events, openerId } = page(t);
    assert.ok(openerId);
    boot(editor, { iframeId: openerId });
    assert.equal(editor.$.admin.afterFormSaved(editor.document.getElementById('edit'), { after_save: '1' }), false);
    assert.deepEqual(events, []);
});

test('returning to the standalone list itself does not reload or close it again', t => {
    const { w, events, openerId } = page(t);
    assert.ok(openerId);
    boot(w, { listIframeId: openerId, listAfterSave: '', successMessage: 'Saved' });
    assert.deepEqual(events, []);
});
