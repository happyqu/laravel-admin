const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Form/fixtures/switch.php'], { cwd: root, encoding: 'utf8' }));

function page(mode) {
    const fixture = fixtures[mode];
    const w = new JSDOM('<form>' + fixture.html + '</form>', { runScripts: 'outside-only', url: 'http://localhost/' }).window;
    for (const asset of ['AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js', 'bootstrap-switch/dist/js/bootstrap-switch.min.js']) {
        w.eval(fs.readFileSync(path.join(root, 'resources/assets', asset), 'utf8'));
    }
    for (const script of fixture.scripts) w.eval(script);
    return w;
}

for (const [mode, initial, name] of [
    ['default', false, 'enabled'], ['custom', true, 'enabled'], ['old', true, 'rows[new_1][enabled]'],
]) {
    test(`switch ${mode} keeps checkbox and submitted token synchronized`, () => {
        const w = page(mode);
        try {
            const checkbox = w.$('input[type="checkbox"]');
            const token = () => new w.FormData(w.document.querySelector('form')).get(name);
            assert.equal(checkbox.bootstrapSwitch('state'), initial);
            assert.equal(token(), initial ? 'on' : 'off');
            let changes = 0;
            w.$('input[type="hidden"]').on('change', () => changes++);
            checkbox.bootstrapSwitch('state', !initial);
            assert.equal(token(), initial ? 'off' : 'on');
            assert.equal(changes, 1);
            checkbox.bootstrapSwitch('state', initial);
            assert.equal(token(), initial ? 'on' : 'off');
        } finally { w.close(); }
    });
}

test('switch accepts quoted labels and custom colors in the real plugin', () => {
    const w = page('custom');
    try {
        const checkbox = w.$('input[type="checkbox"]');
        assert.equal(checkbox.bootstrapSwitch('onText'), "It's open\n</script>");
        assert.equal(checkbox.bootstrapSwitch('offText'), '关闭');
        assert.equal(checkbox.bootstrapSwitch('onColor'), 'success');
        assert.equal(checkbox.bootstrapSwitch('offColor'), 'danger');
    } finally { w.close(); }
});

test('disabled switch remains locked after initialization', () => {
    const w = page('disabled');
    try {
        assert.equal(w.$('input[type="checkbox"]').bootstrapSwitch('disabled'), true);
        w.$('.bootstrap-switch-container').trigger('click');
        assert.equal(w.$('input[type="checkbox"]').bootstrapSwitch('state'), true);
    } finally { w.close(); }
});
