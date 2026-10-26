const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const jquery = read('resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js');
const tabs = read('resources/assets/iframe-tabs/bootstrap-tab.js');

function page() {
    const dom = new JSDOM(
        '<!doctype html><html><body><div class="page-tabs-content"></div><div id="tab-content"></div></body></html>',
        { url: 'http://localhost/admin', runScripts: 'outside-only', virtualConsole: new VirtualConsole() }
    );
    const window = dom.window;
    window.use_icon = false;
    window.layer = { load: () => 1, close() {} };
    window.eval(jquery);
    window.eval(tabs);

    return window;
}

test('tab iframes delegate the unload policy to their document', () => {
    const window = page();

    window.addTabs({ id: 'users', url: 'users', title: 'Users' });

    const iframe = window.document.querySelector('#tab-content iframe');
    assert.ok(iframe, 'the tab iframe is created');
    assert.equal(iframe.getAttribute('allow'), 'unload');
});
