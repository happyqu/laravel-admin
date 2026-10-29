<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\NestedForm;
use Encore\Admin\Widgets\Form as WidgetForm;

class Table extends HasMany
{
    const PRESENT_FLAG = '_present';

    protected function inputName(): string
    {
        return $this->elementName ?: $this->column;
    }

    protected function domName(): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', ($this->elementScope ? $this->elementScope.'-' : '').$this->inputName());
    }

    protected function templateKey(): string
    {
        // Each nesting level must replace only its own row placeholder.
        return '__LA_TABLE_'.md5($this->inputName()).'__';
    }

    public function sanitize($input): array
    {
        if (is_array($input)) {
            unset($input[self::PRESENT_FLAG]);
        }
        return parent::sanitize($input);
    }

    public function getValidator(array $input)
    {
        if (isset($input[$this->column]) && is_array($input[$this->column])) {
            unset($input[$this->column][self::PRESENT_FLAG]);
        }
        return parent::getValidator($input);
    }

    /**
     * @var string
     */
    protected $viewMode = 'table';

    /**
     * Table constructor.
     *
     * @param string $column
     * @param array  $arguments
     */
    public function __construct($column, $arguments = [])
    {
        $this->column = $column;

        if (count($arguments) == 1) {
            $this->label = $this->formatLabel();
            $this->builder = $arguments[0];
        }

        if (count($arguments) == 2) {
            list($this->label, $this->builder) = $arguments;
        }
    }

    /**
     * @return array
     */
    protected function buildRelatedForms()
    {
//        if (is_null($this->form)) {
//            return [];
//        }

        $forms = [];

        $oldKey = trim(str_replace(['[', ']'], ['.', ''], $this->inputName()), '.');
        if (is_array($values = old($oldKey))) {
            unset($values[self::PRESENT_FLAG]);
            foreach ($values as $key => $data) {
                if (!is_array($data) || ($data[NestedForm::REMOVE_FLAG_NAME] ?? 0) == 1) {
                    continue;
                }

                $forms[$key] = $this->buildNestedForm($this->column, $this->builder, $key)->fill($data);
            }
        } else {
            foreach ($this->value ?? [] as $key => $data) {
                if (isset($data['pivot'])) {
                    $data = array_merge($data, $data['pivot']);
                }
                if (is_array($data)) {
                    $forms[$key] = $this->buildNestedForm($this->column, $this->builder, $key)->fill($data);
                }
            }
        }

        return $forms;
    }

    public function prepare($input)
    {
        if (!is_array($input)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Expected an array.']);
        }
        unset($input[self::PRESENT_FLAG]);
        if (!$this->options['allowDelete'] && array_diff_key($this->original ?: [], $input)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'This related operation is disabled.']);
        }
        foreach ($input as $key => $record) {
            if (!is_array($record)) {
                throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Expected an object.']);
            }
            if ((!$this->options['allowDelete'] && ($record[NestedForm::REMOVE_FLAG_NAME] ?? 0))
                || (!$this->options['allowCreate'] && !array_key_exists($key, $this->original ?: []))) {
                throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'This related operation is disabled.']);
            }
        }
        $form = $this->buildNestedForm($this->column, $this->builder);

        $prepare = $form->setOriginal($this->original ?: [])->prepare($input, true);

        return collect($prepare)->reject(function ($item) {
            return $item[NestedForm::REMOVE_FLAG_NAME] == 1;
        })->map(function ($item) {
            unset($item[NestedForm::REMOVE_FLAG_NAME]);

            return $item;
        })->values()->toArray();
    }

    protected function getKeyName()
    {
        if (is_null($this->form)) {
            return;
        }

        return 'id';
    }

    protected function buildNestedForm($column, \Closure $builder, $key = null, $rowKey = null)
    {
        $form = new NestedForm($this->inputName());
        $form->scopeElements($this->domName())->templateKey($this->templateKey());

        if ($this->form instanceof WidgetForm) {
            $form->setWidgetForm($this->form);
        } else {
            $form->setForm($this->form);
        }

        $form->setKey($key);

        call_user_func($builder, $form);

        $form->hidden(NestedForm::REMOVE_FLAG_NAME)->default(0)->addElementClass(NestedForm::REMOVE_FLAG_CLASS);

        return $form;
    }

    public function render()
    {
        return $this->renderTable();
    }

    protected function setupScriptForTableView($templateScript)
    {
        $domName = $this->domName();
        $name = json_encode($this->inputName(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $key = json_encode($this->templateKey());
        $initializers = json_encode($templateScript, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        \Encore\Admin\Admin::script(<<<JS
;(function () {
    var root = $('#has-many-{$domName}'), index = 0;
    root.off('click.adminJsonTable').on('click.adminJsonTable', '.add, .remove', function (event) {
        if ($(this).closest('[data-json-table]')[0] !== root[0]) return;
        event.preventDefault();
        event.stopPropagation();
        if ($(this).hasClass('add')) {
            do { index++; } while (root.find(':input[name]').filter(function () {
                return this.name.indexOf({$name} + '[new_' + index + ']') === 0;
            }).length);
            var html = root.children('template').html().split({$key}).join(index);
            root.children('table').children('tbody').append(html);
            $.globalEval({$initializers}.split({$key}).join(index));
        } else {
            var row = $(this).closest('.has-many-{$domName}-form');
            if (row.attr('data-persisted') !== 'true') {
                row.remove();
            } else {
                row.hide().find(':input').removeAttr('required');
                row.children('td.hidden').children('input.{$removeClass}').val(1);
            }
        }
    });
})();
JS);
    }
}
