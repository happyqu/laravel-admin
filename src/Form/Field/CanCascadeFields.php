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
            'column' => $this->column(),
            'index'  => count($this->conditions) - 1,
            'class'  => $this->getCascadeClass($value),
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
    protected function getCascadeClass($value)
    {
        if (is_array($value)) {
            $value = implode('-', $value);
        }

        $class = preg_replace('/\s+/', '-', $this->getElementClassString());

        return sprintf('cascade-%s-%s', $class, bin2hex($value));
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

        extract($condition);

        $old = old($this->column(), $this->value());

        switch ($operator) {
            case '=':
                return $old == $value;
            case '>':
                return $old > $value;
            case '<':
                return $old < $value;
            case '>=':
                return $old >= $value;
            case '<=':
                return $old <= $value;
            case '!=':
                return $old != $value;
            case 'in':
                return in_array($old, $value);
            case 'notIn':
                return !in_array($old, $value);
            case 'has':
                return in_array($value, Arr::wrap($old));
            case 'oneIn':
                return count(array_intersect(Arr::wrap($value), Arr::wrap($old))) >= 1;
            case 'oneNotIn':
                return count(array_intersect(Arr::wrap($value), Arr::wrap($old))) == 0;
            default:
                throw new \Exception("Operator [$operator] not support.");
        }
    }

    /**
     * Js Value.
     */
    protected function getValueByJs()
    {
        return json_encode(old($this->column(), $this->value()),
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

        $cascadeGroups = collect($this->conditions)->map(function ($condition) {
            return [
                'class'    => $this->getCascadeClass($condition['value']),
                'operator' => $condition['operator'],
                'value'    => $condition['value'],
            ];
        })->toJson();

        $script = <<<SCRIPT
;(function () {
    var operator_table = {
        '=': function(a, b) {
            if ($.isArray(a) && $.isArray(b)) {
                return $(a).not(b).length === 0 && $(b).not(a).length === 0;
            }

            return a == b;
        },
        '>': function(a, b) { return a > b; },
        '<': function(a, b) { return a < b; },
        '>=': function(a, b) { return a >= b; },
        '<=': function(a, b) { return a <= b; },
        '!=': function(a, b) {
             if ($.isArray(a) && $.isArray(b)) {
                return !($(a).not(b).length === 0 && $(b).not(a).length === 0);
             }

             return a != b;
        },
        'in': function(a, b) { return $.inArray(a, b) != -1; },
        'notIn': function(a, b) { return $.inArray(a, b) == -1; },
        'has': function(a, b) { return $.inArray(b, a) != -1; },
        'oneIn': function(a, b) { return ($.isArray(a) ? a : []).filter(v => b.includes(v)).length >= 1; },
        'oneNotIn': function(a, b) { return ($.isArray(a) ? a : []).filter(v => b.includes(v)).length == 0; },
    };
    var cascade_groups = {$cascadeGroups};
        
    var default_value = {$this->getValueByJs()};
    cascade_groups.forEach(function (event) {
        $('div.cascade-group.' + event.class).toggleClass('hide', !operator_table[event.operator](default_value, event.value));
    });
    
    $('{$this->getElementClassSelector()}').on('{$this->cascadeEvent}', function (e) {

        {$this->getFormFrontValue()}

        cascade_groups.forEach(function (event) {
            var group = $('div.cascade-group.'+event.class);
            if( operator_table[event.operator](checked, event.value) ) {
                group.removeClass('hide');
            } else {
                group.addClass('hide');
            }
        });
    })
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
var checked = $('{$this->getElementClassSelector()}:checked').map(function(){
  return $(this).val();
}).get();
SCRIPT;
        }

        if ($this instanceof Select || $this instanceof Radio) {
            return 'var checked = $(this).val();';
        }

        throw new \InvalidArgumentException('Invalid form field type');
    }
}
