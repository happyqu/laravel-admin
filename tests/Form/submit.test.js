const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

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

function submitForm(fields) {
    const form = {};
    let handler;
    let prevented = false;
    const $ = target => {
        if (target === 'form.model-form-test') {
            return { submit(callback) { handler = callback; } };
        }
        assert.equal(target, form);
        return {
            find(selector) {
                assert.equal(selector, 'div.cascade-group.hide :input');
                return {
                    attr(name, value) {
                        assert.equal(name, 'disabled');
                        for (const field of fields.filter(field => field.hiddenCascade)) {
                            field.disabled = value;
                        }
                    },
                };
            },
        };
    };
    vm.runInNewContext(script, { $ });
    assert.equal(typeof handler, 'function');
    const result = handler.call(form, { preventDefault() { prevented = true; } });
    return { submitted: !prevented && result !== false, fields };
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
