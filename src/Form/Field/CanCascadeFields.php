<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Illuminate\Support\Arr;

/**
 * @property Form $form
 */
trait CanCascadeFields
{
    /**
     * @var array
     */
    protected $conditions = [];

    protected $cascadeId;

    public function hasCascadeConditions(): bool
    {
        return !empty($this->conditions);
    }

    public function cascadeStoredValue(array $stored)
    {
        if (Arr::has($stored, $this->column())) {
            // Use each control's own normalization (including relation/pivot IDs).
            $field = clone $this;
            $field->setOriginal($stored);

            return $field->original() ?? $this->value();
        }

        return $this->original() ?? $this->value();
    }

    /**
     * @param $operator
     * @param $value
     * @param $closure
     *
     * @return $this
     */
    public function when($operator, $value, $closure = null)
    {
        if (func_num_args() == 2) {
            $closure = $value;
            $value = $operator;
            $operator = '=';
        }

        $this->formatValues($operator, $value);

        $this->addDependents($operator, $value, $closure);

        return $this;
    }

    /**
     * @param string $operator
     * @param mixed  $value
     */
    protected function formatValues(string $operator, &$value)
    {
        if (in_array($operator, ['in', 'notIn'])) {
            $value = Arr::wrap($value);
        }

        if (is_array($value)) {
            $value = array_map('strval', $value);
        } else {
            $value = strval($value);
        }
    }

    /**
     * @param string   $operator
     * @param mixed    $value
     * @param \Closure $closure
     */
    protected function addDependents(string $operator, $value, \Closure $closure)
    {
        $this->conditions[] = compact('operator', 'value', 'closure');

        $this->form->cascadeGroup($closure, [
            'column'     => $this->column(),
            'index'      => count($this->conditions) - 1,
            'class'      => $this->getCascadeClass($value, count($this->conditions) - 1),
            'controller' => $this,
            'operator'   => $operator,
            'value'      => $value,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function fill($data)
    {
        parent::fill($data);

        $this->applyCascadeConditions();
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    protected function getCascadeClass($value, $index = 0)
    {
        $this->cascadeId ??= uniqid('cascade-');

        return $this->cascadeId.'-'.$index;
    }

    /**
     * Apply conditions to dependents fields.
     *
     * @return void
     */
    protected function applyCascadeConditions()
    {
        if ($this->form) {
            $this->form->fields()
                ->filter(function (Form\Field $field) {
                    return $field instanceof CascadeGroup
                        && $field->dependsOn($this)
                        && $this->hitsCondition($field);
                })->each->visiable();
        }
    }

    /**
     * @param CascadeGroup $group
     *
     * @throws \Exception
     *
     * @return bool
     */
    protected function hitsCondition(CascadeGroup $group)
    {
        $condition = $this->conditions[$group->index()];

        return \Encore\Admin\Form\Cascade::matches($condition['operator'],
            old($this->column(), $this->value()), $condition['value']);
    }

    /**
     * Js Value.
     */
    protected function getValueByJs()
    {
        // Match the string values returned by select, radio and checkbox inputs.
        $normalize = static fn ($value) => is_scalar($value) ? (string) $value : $value;
        $value = old($this->column(), $this->value());
        $value = is_array($value) ? array_map($normalize, $value) : $normalize($value);

        return json_encode($value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Add cascade scripts to contents.
     *
     * @return void
     */
    protected function addCascadeScript()
    {
        if (empty($this->conditions)) {
            return;
        }

        $cascadeGroups = collect($this->conditions)->map(function ($condition, $index) {
            return [
                'class'    => $this->getCascadeClass($condition['value'], $index),
                'operator' => $condition['operator'],
                'value'    => $condition['value'],
            ];
        })->toJson();

        $runtime = \Encore\Admin\Form\CascadeScript::runtime();
        $namespace = '.adminCascade'.str_replace('-', '', $this->getCascadeClass('', 0));
        $script = <<<SCRIPT
;(function () {
    {$runtime}
    var cascade_groups = {$cascadeGroups};
    // Each rendered instance owns its groups; its controls are siblings in the same container.
    $('div.cascade-group.' + cascade_groups[0].class).each(function () {
        var scope = $(this).parent();
        var controls = scope.find('{$this->getElementClassSelector()}');
        function update() {
            {$this->getFormFrontValue()}
            cascade_groups.forEach(function (event) {
                scope.find('div.cascade-group.' + event.class)
                    .toggleClass('hide', !$.admin.cascade.matches(event.operator, checked, event.value));
            });
            $.admin.cascade.sync(scope);
        }
        controls.off('{$this->cascadeEvent}{$namespace}').on('{$this->cascadeEvent}{$namespace}', update);
        update();
    });
})();
SCRIPT;

        Admin::script($script);
    }

    /**
     * @return string
     */
    protected function getFormFrontValue()
    {
        if ($this instanceof Checkbox) {
            return <<<SCRIPT
var checked = controls.filter(':checked').map(function(){
  return $(this).val();
}).get();
SCRIPT;
        }

        if ($this instanceof Select || $this instanceof Radio) {
            return $this instanceof Radio
                ? "var checked = controls.filter(':checked').val();"
                : 'var checked = controls.first().val();';
        }

        throw new \InvalidArgumentException('Invalid form field type');
    }
}
