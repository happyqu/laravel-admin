<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Support\Arr;

class KeyValue extends Field
{
    use HasCollectionEditor;
    /**
     * @var array
     */
    protected $value = ['' => ''];

    /**
     * Fill data to the field.
     *
     * @param array $data
     *
     * @return void
     */
    public function fill($data)
    {
        $this->data = $data;

        $this->value = Arr::get($data, $this->column, $this->value);

        $this->formatValue();
    }

    /**
     * {@inheritdoc}
     */
    public function getValidator(array $input)
    {
        if (!$this->isWritable()) {
            return false;
        }
        if ($this->validator) {
            return $this->validator->call($this, $input);
        }

        if (!is_string($this->column)) {
            return false;
        }

        $rules = $attributes = [];

        $fieldRules = $this->getRules();

        if (!Arr::has($input, $this->column)) {
            return false;
        }

        $value = Arr::get($input, $this->column);
        if (is_array($value)) {
            Arr::set($input, $this->column, $value + ['keys' => [], 'values' => []]);
        }
        $rules[$this->column] = 'array';
        $rules["{$this->column}.keys"] = 'present|array';
        $rules["{$this->column}.values"] = 'present|array';
        $rules["{$this->column}.keys.*"] = ['required', 'distinct', function ($attribute, $key, $fail) {
            if (!is_string($key) && !is_int($key)) {
                $fail('Invalid key.');
            }
        }];
        if ($fieldRules) {
            $rules["{$this->column}.values.*"] = $fieldRules;
        }
        $attributes["{$this->column}.keys.*"] = __('Key');
        $attributes["{$this->column}.values.*"] = __('Value');

        return validator($input, $rules, $this->getValidationMessages(), $attributes)
            ->after(function ($validator) use ($input) {
                $keys = Arr::get($input, $this->column.'.keys');
                $values = Arr::get($input, $this->column.'.values');
                if (is_array($keys) && is_array($values) && count($keys) !== count($values)) {
                    $validator->errors()->add($this->column, 'Keys and values must have the same number of items.');
                }
            });
    }

    public function prepare($value)
    {
        if (!is_array($value) || !is_array($value['keys'] ?? []) || !is_array($value['values'] ?? [])
            || count($value['keys'] ?? []) !== count($value['values'] ?? [])) {
            throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Invalid key-value input.']);
        }
        foreach ($value['keys'] ?? [] as $key) {
            if (!is_string($key) && !is_int($key)) {
                throw \Illuminate\Validation\ValidationException::withMessages([$this->column => 'Invalid key.']);
            }
        }

        return array_combine($value['keys'] ?? [], $value['values'] ?? []);
    }

    public function render()
    {
        $this->setupScript();

        Admin::style('td .form-group {margin-bottom: 0 !important;}');

        return parent::render();
    }
}
