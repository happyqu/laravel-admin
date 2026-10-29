<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;
use Encore\Admin\Support\HasSwitchStates;
use Illuminate\Support\Arr;

class SwitchField extends Field
{
    use HasSwitchStates;

    protected static $css = [
        '/vendor/laravel-admin/bootstrap-switch/dist/css/bootstrap3/bootstrap-switch.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/bootstrap-switch/dist/js/bootstrap-switch.min.js',
    ];

    protected $size = 'small';

    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    public function prepare($value)
    {
        if (is_string($value) && isset($this->states[$value])) {
            return $this->states[$value]['value'];
        }

        return $value;
    }

    public function variables(): array
    {
        $variables = parent::variables();
        $oldInput = session()->getOldInput();
        $key = $this->getErrorKey();
        if (Arr::has($oldInput, $key)) {
            $value = Arr::get($oldInput, $key);
            // Submitted form values are transport tokens, not stored values.
            $state = in_array($value, ['on', 'off'], true) ? $value : $this->switchState($value);
        } else {
            $state = $this->switchState($this->value());
        }

        return array_merge($variables, ['switchState' => $state]);
    }

    public function render()
    {
        if (!$this->shouldRender()) {
            return '';
        }

        $selector = json_encode($this->getElementClassSelector().'.la_checkbox', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $options = json_encode([
            'size' => $this->size,
            'onText' => $this->states['on']['text'],
            'offText' => $this->states['off']['text'],
            'onColor' => $this->states['on']['color'],
            'offColor' => $this->states['off']['color'],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $this->script = <<<EOT

$({$selector}).bootstrapSwitch($.extend({}, {$options}, {
    onSwitchChange: function(event, state) {
        $(event.target).closest('.bootstrap-switch').next().val(state ? 'on' : 'off').change();
    }
}));

EOT;

        return parent::render();
    }
}
