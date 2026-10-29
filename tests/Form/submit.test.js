const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const test = require('node:test');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

// Exercise the JavaScript emitted by the PHP builder, rather than a copied handler.
const script = execFileSync('php', ['-r', `
require 'vendor/autoload.php';
new class extends Encore\\Admin\\Form\\Builder {
    public function __construct() {
        $this->formClass = 'model-form-test';
        $this->addCascadeScript();
    }
};
echo implode("\\n", Encore\\Admin\\Admin::$script);
`], { cwd: path.join(__dirname, '../..'), encoding: 'utf8' });

const widgetScript = execFileSync('php', ['-r', `
require 'vendor/autoload.php';
Encore\\Admin\\Facades\\Admin::swap(new Encore\\Admin\\Admin());
new class extends Encore\\Admin\\Widgets\\Form {
    public function __construct() {
        $this->attributes = ['id' => 'widget-form-test'];
        $this->addCascadeScript();
    }
};
echo implode("\\n", Encore\\Admin\\Admin::$script);
`], { cwd: path.join(__dirname, '../..'), encoding: 'utf8' });

function submitForm(fields, emittedScript = script, selector = 'form.model-form-test') {
    const formId = selector.includes('#') ? 'widget-form-test' : 'model-form-test';
    const w = new JSDOM('<form id="' + formId + '" class="model-form-test">' + fields.map(field =>
        '<div class="' + (field.hiddenCascade ? 'cascade-group hide' : '') + '"><input name="' + field.name +
        '" ' + (field.disabled ? 'disabled' : '') + '></div>').join('') + '</form>', {runScripts: 'outside-only'}).window;
    try {
        w.eval(fs.readFileSync(path.join(__dirname, '../../resources/assets/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js'), 'utf8'));
        w.eval(emittedScript);
        const form = w.document.querySelector('form');
        let submitted = false;
        form.addEventListener('submit', event => { submitted = true; event.preventDefault(); });
        form.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
        for (const field of fields) field.disabled = form.elements.namedItem(field.name).disabled;
        return {submitted, fields};
    } finally { w.close(); }
}

test('ordinary model forms allow the browser to submit', () => {
    const result = submitForm([{ name: 'title', hiddenCascade: false, disabled: false }]);
    assert.equal(result.submitted, true);
    assert.equal(result.fields[0].disabled, false);
});

test('cascade forms exclude hidden inputs without cancelling submission', () => {
    const result = submitForm([
        { name: 'visible', hiddenCascade: false, disabled: false },
        { name: 'hidden', hiddenCascade: true, disabled: false },
    ]);
    assert.equal(result.submitted, true);
    assert.deepEqual(result.fields.map(field => field.disabled), [false, true]);
});

test('widget cascade preparation allows native submission too', () => {
    const result = submitForm([
        { name: 'visible', hiddenCascade: false, disabled: false },
        { name: 'hidden', hiddenCascade: true, disabled: false },
    ], widgetScript, 'form#widget-form-test');
    assert.equal(result.submitted, true);
    assert.deepEqual(result.fields.map(field => field.disabled), [false, true]);
});
