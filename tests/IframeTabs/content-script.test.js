const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../src/Concerns/RegistersIframeTabs.php'), 'utf8');
const script = source.match(/\$script = <<<'EOT'\r?\n([\s\S]*?)\r?\nEOT;/)[1];

function savedPage(mode, iframeId = '', currentId = 'iframe_editor') {
    const events = [];
    const opener = { contentWindow: { location: { reload() { events.push('reload-list'); } } } };
    const top = {
        document: { getElementById(id) { return id === 'iframe_list' ? opener : null; } },
        toastr: { success() { events.push('notify'); } },
        closeTabByPageId(id) { events.push('close-tab:' + id); },
    };
    const parent = mode === 'popup' ? {
        document: { getElementById() { return null; } },
        layer: { getFrameIndex() { return 1; }, close() { events.push('close-popup'); } },
    } : top;
    const window = { top, parent, name: 'editor', frameElement: { id: currentId } };
    const payload = {
        iframeId, listIframeId: 'iframe_list', listAfterSave: '',
        bindUrls: mode, successMessage: 'Saved',
    };

    const $ = () => ({ length: 0, each() {}, off() { return this; }, addClass() { return this; }, on() { return this; } });
    $.admin = {};
    vm.runInNewContext(script.replace('__ADMIN_IFRAME_PAYLOAD__', JSON.stringify(payload)), { window, parent, $ });

    return events;
}

test('popup save refreshes the list in the top container before closing', () => {
    assert.deepEqual(savedPage('popup'), ['reload-list', 'notify', 'close-popup']);
});

test('new tab save closes the editor after redirect drops the opener query parameter', () => {
    assert.deepEqual(savedPage('new_tab'), ['reload-list', 'notify', 'close-tab:editor']);
});

test('new tab save keeps the opener tab when its query parameter survives', () => {
    assert.deepEqual(savedPage('new_tab', 'iframe_list'), ['reload-list', 'notify', 'close-tab:editor']);
});

test('a native save returning to its own list frame does not refresh or close it again', () => {
    assert.deepEqual(savedPage('new_tab', '', 'iframe_list'), []);
});
