const { readFileSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(path.join(__dirname, '../../resources/assets/classic-menu-search.js'), 'utf8');

function setup(t) {
    const { window } = new JSDOM(`
        <body class="sidebar-mini">
        <div data-menu-search hidden>
            <input data-menu-search-input type="search">
            <button data-menu-search-clear hidden>Clear</button>
        </div>
        <ul class="sidebar-menu" id="classic-sidebar-menu">
            <li class="header">Menu</li>
            <li id="partners" class="treeview" data-menu-search-title="Partner Management"><a href="#">第三方管理</a>
                <ul class="treeview-menu">
                    <li id="integrations" class="treeview"><a href="#">Integrations</a>
                        <ul class="treeview-menu">
                            <li id="settings" data-menu-search-uri="Partner/Settings"><a href="/admin/Partner/Settings">第三方平台设置</a></li>
                        </ul>
                    </li>
                    <li id="sync" data-menu-search-uri="Partner/Sync"><a href="/admin/Partner/Sync">同步任务</a></li>
                </ul>
            </li>
            <li id="sales" class="treeview active"><a href="#">Sales</a>
                <ul class="treeview-menu" style="display: block">
                    <li id="monthly"><a href="/admin/Sales/Monthly">Audited Sales [Monthly]</a></li>
                </ul>
            </li>
            <li id="dashboard" data-menu-search-uri="/"><a href="/admin">Dashboard</a></li>
            <li id="admin-group" class="treeview"><a href="#">Admin</a>
                <ul class="treeview-menu">
                    <li id="logs" data-menu-search-title="Operation log" data-menu-search-uri="auth/logs"><a href="/admin/auth/logs">操作日志</a></li>
                </ul>
            </li>
        </ul>
        <p data-menu-search-empty hidden>No matching menus</p>
        </body>
    `, { url: 'https://example.test/admin', runScripts: 'outside-only' });
    t.after(() => window.close());
    window.matchMedia = () => ({ matches: true, addEventListener() {} });
    window.eval(source);

    const { document } = window;
    const input = document.querySelector('input');
    const tree = document.getElementById('classic-sidebar-menu');
    const element = id => document.getElementById(id);
    const expanded = id => element(id).classList.contains('is-menu-search-expanded');

    function search(value) {
        input.value = value;
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
        return Array.from(tree.querySelectorAll('li[id]:not(.is-menu-search-hidden)'), item => item.id);
    }

    return { window, document, input, tree, element, expanded, search };
}

test('the classic sidebar matches parent names, menu names and URLs without regard to case', t => {
    const { document, expanded, search } = setup(t);
    assert.deepEqual(search('第三方管理'), ['partners', 'integrations', 'settings', 'sync']);
    assert.ok(expanded('partners'));
    assert.ok(expanded('integrations'));
    assert.deepEqual(search('iNtEgRaTiOnS SETTINGS'), ['partners', 'integrations', 'settings']);
    assert.deepEqual(search('sYnC'), ['partners', 'sync']);
    assert.deepEqual(search('EXAMPLE.TEST/admin/PARTNER/SETTINGS'), ['partners', 'integrations', 'settings']);
    assert.deepEqual(search('[mOnThLy]'), ['sales', 'monthly']);
    assert.deepEqual(search('missing'), []);
    assert.equal(document.querySelector('[data-menu-search-empty]').hidden, false);
});

test('common URL prefixes are ignored in ordinary menu queries', t => {
    const { search } = setup(t);
    assert.deepEqual(search('aDmIn'), ['admin-group', 'logs']);
    assert.deepEqual(search('example.test'), []);
    assert.deepEqual(search('https://EXAMPLE.TEST/Admin/Auth/Logs'), ['admin-group', 'logs']);
});

test('translated menu names retain their original titles as search terms', t => {
    const { search } = setup(t);
    assert.deepEqual(search('OpErAtIoN LoG'), ['admin-group', 'logs']);
    assert.deepEqual(search('操作日志'), ['admin-group', 'logs']);
    assert.deepEqual(search('PARTNER MANAGEMENT sync'), ['partners', 'sync']);
});

test('clearing the sidebar search restores the original menu expansion state', t => {
    const { window, document, input, tree, element, expanded, search } = setup(t);
    search('settings');
    element('partners').querySelector('a').click();
    assert.equal(expanded('partners'), false);

    const key = options => input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, ...options }));
    key({ isComposing: true });
    assert.equal(input.value, 'settings');
    key({});
    assert.equal(input.value, '');
    assert.equal(tree.classList.contains('is-menu-search-active'), false);
    assert.equal(tree.querySelectorAll('.is-menu-search-hidden, .is-menu-search-expanded').length, 0);
    assert.ok(element('sales').classList.contains('active'));
    assert.equal(element('sales').querySelector('ul').style.display, 'block');

    search('missing');
    document.querySelector('[data-menu-search-clear]').click();
    assert.equal(input.value, '');
    assert.equal(document.activeElement, input);
    assert.equal(document.querySelector('[data-menu-search-empty]').hidden, true);
});

test('collapsing the desktop sidebar clears a filter whose input is no longer visible', async t => {
    const { window, document, input, tree, search } = setup(t);
    search('settings');
    document.body.classList.add('sidebar-collapse');
    await new Promise(resolve => window.setTimeout(resolve, 0));
    assert.equal(input.value, '');
    assert.equal(tree.classList.contains('is-menu-search-active'), false);
});
