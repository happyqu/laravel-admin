const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const files = [
    'resources/views/index.blade.php',
    'resources/views/content.blade.php',
    'resources/views/login.blade.php',
    'resources/views/partials/header.blade.php',
    'resources/views/partials/sidebar.blade.php',
    'resources/views/partials/footer.blade.php',
    'resources/views/partials/menu.blade.php',
    'resources/views/navigation/classic-sidebar.blade.php',
    'resources/views/grid/table.blade.php',
    'resources/views/form.blade.php',
    'resources/views/grid/inline-edit/comm.blade.php',
];

test('the AdminLTE 4 shell uses the expected layout landmarks', () => {
    const index = read('resources/views/index.blade.php');
    const header = read('resources/views/partials/header.blade.php');
    const sidebar = read('resources/views/navigation/classic-sidebar.blade.php');
    const content = read('resources/views/content.blade.php');

    assert.match(index, /class="app-wrapper"/);
    assert.match(index, /class="app-main"/);
    assert.match(index, /id="admin-content"/);
    assert.match(index, /data-admin-theme/);
    assert.match(header, /class="app-header/);
    assert.match(header, /data-lte-toggle="sidebar"/);
    assert.match(sidebar, /class="app-sidebar/);
    assert.match(sidebar, /class="nav sidebar-menu/);
    assert.match(sidebar, /data-lte-toggle="treeview"/);
    assert.match(content, /app-content-header/);
    assert.match(content, /app-content/);
});

test('first-party Blade views do not contain AdminLTE 2 / BS3 legacy landmarks', () => {
    const forbidden = [
        /AdminLTE\//,
        /data-toggle\s*=/,
        /data-dismiss\s*=/,
        /\biCheck\b/,
        /bootstrapSwitch/,
        /bootstrapDualListbox/,
        /\bdatetimepicker\b/i,
        /class="[^"]*(?<![\w-])box(?![\w-])/,
        /class="[^"]*\bpull-(left|right)\b/,
        /class="[^"]*\bbtn-default\b/,
    ];

    for (const file of files) {
        const contents = read(file);
        for (const pattern of forbidden) {
            assert.doesNotMatch(contents, pattern, `${file} still contains ${pattern}`);
        }
    }
});

test('the old AdminLTE 2 asset tree is gone', () => {
    assert.equal(fs.existsSync(path.join(root, 'resources/assets/AdminLTE')), false);
    assert.equal(fs.existsSync(path.join(root, 'resources/assets/iCheck')), false);
    assert.equal(fs.existsSync(path.join(root, 'resources/assets/bootstrap3-editable')), false);
});
