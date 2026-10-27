const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', ['tests/Widgets/fixtures/scripts.php'], { cwd: root, encoding: 'utf8' }));
function page(html) {
    const w = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/admin/widgets' }).window;
    w.eval(fs.readFileSync(path.join(root, 'resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
    return w;
}

test('widget tabs scope hash lookup and events to their own content tabs', () => {
    const w = page(fixtures.first.html + fixtures.second.html + '<ul class="nav-tabs"><li><a id="unrelated" href="#external">External</a></li></ul>');
    try {
        const shown = [];
        let pushes = 0;
        w.$.fn.tab = function () { this.each(function () { shown.push(this.hash); }); return this; };
        w.location.hash = '#tab_first-two';
        w.eval(fixtures.first.script);
        w.eval(fixtures.second.script);
        assert.deepEqual(shown, ['#tab_first-two']);
        w.eval(fixtures.first.script);
        const originalPush = w.history.pushState.bind(w.history);
        w.history.pushState = (...args) => { pushes++; originalPush(...args); };
        w.$('#first-tabs a[href="#tab_first-one"]').trigger('shown.bs.tab');
        assert.equal(pushes, 1);
        w.$('#unrelated').trigger('shown.bs.tab');
        assert.equal(pushes, 1);
        w.location.hash = '#invalid[%22';
        assert.doesNotThrow(() => w.eval(fixtures.first.script));
    } finally { w.close(); }
});

test('widget confirmation keeps existing submit handlers and supports custom IDs', async () => {
    const w = page('<form id="confirm:form"><input name="title" value="Ready"></form>');
    try {
        const form = w.document.getElementById('confirm:form');
        let existing = 0;
        let saves = 0;
        let confirmed = false;
        w.$(form).on('submit.custom', () => { existing++; });
        w.$.admin = {
            swal: () => Promise.resolve({ value: confirmed }),
            canSubmitForm: () => true,
            submitForm: () => { saves++; },
        };
        w.eval(fixtures.confirm);
        w.eval(fixtures.confirm);
        w.$(form).triggerHandler('submit');
        await Promise.resolve();
        assert.equal(existing, 1);
        assert.equal(saves, 0);
        confirmed = true;
        w.$(form).triggerHandler('submit');
        await Promise.resolve();
        assert.equal(existing, 2);
        assert.equal(saves, 1);
    } finally { w.close(); }
});
